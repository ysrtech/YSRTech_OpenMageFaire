<?php

/**
 * Products of a website on Faire: the products with "Sell on Faire" = Yes, each a Faire
 * product with one variant (simple products) or one variant per child (configurable
 * products). Unchanged products are skipped (hash of the payload); products no longer sold
 * are paused on Faire (SALES_PAUSED), never deleted.
 */
class YSRTech_Faire_Model_Catalog
{
    /** @var Mage_Core_Model_Website */
    protected $_website;

    /** @var YSRTech_Faire_Model_Api */
    protected $_api;

    protected $_dryRun = false;

    protected $_output;

    protected $_stats = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'paused' => 0, 'errors' => 0];

    public function __construct()
    {
        $this->_api = Mage::getModel('ysrtech_faire/api');
    }

    protected function _helper()
    {
        return Mage::helper('ysrtech_faire');
    }

    public function setWebsite($website)
    {
        $this->_website = Mage::app()->getWebsite($website);
        $this->_api->setWebsite($this->_website);
        return $this;
    }

    public function setDryRun($flag)
    {
        $this->_dryRun = (bool) $flag;
        return $this;
    }

    public function setOutput(?callable $output)
    {
        $this->_output = $output;
        return $this;
    }

    public function setApi(YSRTech_Faire_Model_Api $api)
    {
        $this->_api = $api;
        return $this;
    }

    protected function _say($line)
    {
        $this->_helper()->log('[' . $this->_website->getCode() . '] ' . $line);
        if ($this->_output) {
            call_user_func($this->_output, $line);
        }
    }

    public function getStats()
    {
        return $this->_stats;
    }

    // ------------------------------------------------------------------ sync

    /**
     * @param int[]|null $productIds only these products
     */
    public function sync(?array $productIds = null)
    {
        $eligible = $this->getEligibleProductIds($productIds);
        $links = $this->_links();
        foreach ($eligible as $productId) {
            try {
                $this->syncProduct($productId, $links[$productId] ?? null);
            } catch (Exception $e) {
                $this->_stats['errors']++;
                $this->_say("product {$productId}: " . $e->getMessage());
                $this->_saveLink($productId, ['status' => 'error', 'error' => $e->getMessage()]);
            }
        }
        // products listed on Faire that are no longer sold: pause them
        foreach ($links as $productId => $link) {
            if (in_array($productId, $eligible, true) || ($productIds !== null && !in_array($productId, $productIds, true))
                || !$link['faire_product_id'] || $link['status'] === 'paused'
            ) {
                continue;
            }
            try {
                if (!$this->_dryRun) {
                    $this->_api->patch('products/' . $link['faire_product_id'], ['sale_state' => 'SALES_PAUSED']);
                    $this->_saveLink($productId, ['status' => 'paused', 'error' => null, 'payload_hash' => null, 'synced_at' => Varien_Date::now()]);
                }
                $this->_stats['paused']++;
                $this->_say("product {$productId}: paused on Faire ({$link['faire_product_id']})");
            } catch (Exception $e) {
                $this->_stats['errors']++;
                $this->_say("product {$productId}: pause failed: " . $e->getMessage());
            }
        }
        return $this->_stats;
    }

    public function syncProduct($productId, ?array $link = null)
    {
        try {
            $this->_syncProduct($productId, $link);
        } catch (YSRTech_Faire_Model_Api_Exception $e) {
            // deleted on Faire: forget the link and create the product again
            if ($e->getCode() !== 404 || !$link || !$link['faire_product_id']) {
                throw $e;
            }
            $this->_say("product {$productId}: {$link['faire_product_id']} no longer exists on Faire, creating it again");
            $this->_saveLink($productId, ['faire_product_id' => null, 'variants' => null, 'payload_hash' => null, 'images_hash' => null, 'status' => 'pending']);
            $this->_syncProduct($productId, null);
        }
    }

    protected function _syncProduct($productId, ?array $link = null)
    {
        $product = $this->_loadProduct($productId);
        $payload = $this->buildPayload($product);
        $images = $payload['images'] ?? [];
        unset($payload['images']);
        $hash = sha1(json_encode($payload));
        $imagesHash = sha1(json_encode($images));

        if ($link && $link['faire_product_id'] && $link['payload_hash'] === $hash && $link['images_hash'] === $imagesHash && $link['status'] === 'synced') {
            $this->_stats['unchanged']++;
            return;
        }
        if ($this->_dryRun) {
            $this->_say(sprintf('%s %s: %s', $link && $link['faire_product_id'] ? 'update' : 'create', $product->getSku(), json_encode($payload + ['images' => $images], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
            return;
        }

        if (!$link || !$link['faire_product_id']) {
            $create = $payload + ['images' => $images];
            $create['lifecycle_state'] = $this->_helper()->getFlag('catalog/publish', $this->_website) ? 'PUBLISHED' : 'DRAFT';
            $result = $this->_api->post('products', $create);
            if (empty($result['id'])) {
                Mage::throwException('Faire did not return a product id.');
            }
            $this->_saveLink($product->getId(), [
                'faire_product_id' => $result['id'],
                'variants'         => json_encode($this->_variantMap($result)),
                'payload_hash'     => $hash,
                'images_hash'      => $imagesHash,
                'status'           => 'synced',
                'error'            => null,
                'synced_at'        => Varien_Date::now(),
            ]);
            $this->_stats['created']++;
            $this->_say("{$product->getSku()}: created on Faire ({$result['id']})");
            return;
        }

        $faireId = $link['faire_product_id'];
        $variants = $payload['variants'];
        $productFields = array_diff_key($payload, array_flip(['variants', 'idempotence_token']));
        $productFields['sale_state'] = 'FOR_SALE';
        if ($link['images_hash'] !== $imagesHash) {
            $productFields['images'] = $images;
        }
        $this->_api->patch('products/' . $faireId, $productFields);
        $map = json_decode((string) $link['variants'], true) ?: [];
        foreach ($variants as $variant) {
            $sku = $variant['sku'];
            if (isset($map[$sku])) {
                $update = array_diff_key($variant, ['idempotence_token' => 1]);
                $update['sale_state'] = 'FOR_SALE';
                $this->_api->patch('products/' . $faireId . '/variants/' . $map[$sku], $update);
            } else {
                $created = $this->_api->post('products/' . $faireId . '/variants', $variant);
                if (!empty($created['id'])) {
                    $map[$sku] = $created['id'];
                }
            }
        }
        // variants (children) no longer in the product
        foreach (array_diff_key($map, array_flip(array_column($variants, 'sku'))) as $sku => $variantId) {
            $this->_api->patch('products/' . $faireId . '/variants/' . $variantId, ['sale_state' => 'SALES_PAUSED']);
        }
        $this->_saveLink($product->getId(), [
            'variants'     => json_encode($map),
            'payload_hash' => $hash,
            'images_hash'  => $imagesHash,
            'status'       => 'synced',
            'error'        => null,
            'synced_at'    => Varien_Date::now(),
        ]);
        $this->_stats['updated']++;
        $this->_say("{$product->getSku()}: updated on Faire ({$faireId})");
    }

    protected function _variantMap(array $faireProduct)
    {
        $map = [];
        foreach ((array) ($faireProduct['variants'] ?? []) as $variant) {
            if (!empty($variant['sku']) && !empty($variant['id'])) {
                $map[$variant['sku']] = $variant['id'];
            }
        }
        return $map;
    }

    // ------------------------------------------------------------------ products

    /**
     * Enabled products of the website with "Sell on Faire" = Yes, visible on their own.
     *
     * @return int[]
     */
    public function getEligibleProductIds(?array $only = null)
    {
        $store = $this->_helper()->getContentStore($this->_website);
        $collection = Mage::getResourceModel('catalog/product_collection')
            ->setStoreId($store->getId())
            ->addWebsiteFilter((int) $this->_website->getId())
            ->addAttributeToFilter('faire_enabled', 1, 'left')   // a website value without default value counts
            ->addAttributeToFilter('status', Mage_Catalog_Model_Product_Status::STATUS_ENABLED)
            ->addAttributeToFilter('visibility', ['neq' => Mage_Catalog_Model_Product_Visibility::VISIBILITY_NOT_VISIBLE])
            ->addAttributeToFilter('type_id', ['in' => [Mage_Catalog_Model_Product_Type::TYPE_SIMPLE, Mage_Catalog_Model_Product_Type::TYPE_CONFIGURABLE]]);
        if ($only !== null) {
            $collection->addIdFilter($only ?: [0]);
        }
        return array_map('intval', $collection->getAllIds());
    }

    protected function _loadProduct($productId)
    {
        $store = $this->_helper()->getContentStore($this->_website);
        $product = Mage::getModel('catalog/product')->setStoreId($store->getId())->load($productId);
        if (!$product->getId()) {
            Mage::throwException('Product not found.');
        }
        return $product;
    }

    /**
     * The Faire product (create payload; images included).
     */
    public function buildPayload(Mage_Catalog_Model_Product $product)
    {
        $helper = $this->_helper();
        $website = $this->_website;
        $caseSize = max(1, (int) ($product->getData('faire_case_size') ?: $helper->getConfig('catalog/case_size', $website)));
        $minimum = max(1, (int) $helper->getConfig('catalog/minimum_quantity', $website));
        $minimum = (int) (ceil($minimum / $caseSize) * $caseSize);

        $payload = [
            'idempotence_token'      => 'om-w' . $website->getId() . '-p' . $product->getId(),
            'name'                   => mb_substr(trim((string) $product->getName()), 0, 255),
            'description'            => mb_substr($this->toPlainText($product->getDescription() ?: $product->getShortDescription()), 0, 65000),
            'short_description'      => mb_substr($this->toPlainText($product->getShortDescription(), true), 0, 255),
            'unit_multiplier'        => $caseSize,
            'minimum_order_quantity' => $minimum,
        ];
        $country = $this->_madeIn($product);
        if ($country) {
            $payload['made_in_country'] = $country;
        }
        $taxonomy = trim((string) ($product->getData('faire_taxonomy_type') ?: $helper->getConfig('catalog/taxonomy_type', $website)));
        if ($taxonomy !== '') {
            $payload['taxonomy_type'] = ['id' => $taxonomy];
        }
        $payload['images'] = $this->_images($product);

        $children = [];
        if ($product->getTypeId() === Mage_Catalog_Model_Product_Type::TYPE_CONFIGURABLE) {
            $type = $product->getTypeInstance(true);
            $attributes = $type->getConfigurableAttributesAsArray($product);
            foreach ($type->getUsedProducts(null, $product) as $child) {
                if ((int) $child->getStatus() !== Mage_Catalog_Model_Product_Status::STATUS_ENABLED) {
                    continue;
                }
                $child = $this->_loadProduct($child->getId());
                $options = [];
                foreach ($attributes as $attribute) {
                    $value = $child->getAttributeText($attribute['attribute_code']);
                    if ($value !== false && $value !== null && $value !== '') {
                        $options[] = ['name' => (string) ($attribute['store_label'] ?: $attribute['label']), 'value' => (string) $value];
                    }
                }
                $children[] = [$child, $options];
            }
            $sets = [];
            foreach ($children as [, $options]) {
                foreach ($options as $option) {
                    $sets[$option['name']][$option['value']] = true;
                }
            }
            if ($sets) {
                $payload['variant_option_sets'] = array_map(function ($name, $values) {
                    return ['name' => $name, 'values' => array_keys($values)];
                }, array_keys($sets), $sets);
            }
        } else {
            $children[] = [$product, []];
        }
        $payload['variants'] = [];
        foreach ($children as [$child, $options]) {
            $payload['variants'][] = $this->_variant($child, $options, $product);
        }
        if (!$payload['variants']) {
            Mage::throwException('No enabled variant.');
        }
        return $payload;
    }

    protected function _variant(Mage_Catalog_Model_Product $child, array $options, Mage_Catalog_Model_Product $parent)
    {
        $helper = $this->_helper();
        $prices = [];
        foreach ($this->getPriceLists() as [$store, $region]) {
            [$wholesale, $retail, $currency] = $this->getPrices($child, $store);
            $price = [
                'wholesale_price' => ['amount_minor' => $helper->toMinor($wholesale), 'currency' => $currency],
                'retail_price'    => ['amount_minor' => $helper->toMinor($retail), 'currency' => $currency],
            ];
            if ($region === 'USA') {
                $price['geo_constraint'] = ['country' => 'USA'];
            } elseif ($region === 'EUROPEAN_UNION') {
                $price['geo_constraint'] = ['country_group' => 'EUROPEAN_UNION'];
            }
            $prices[] = $price;
        }
        $variant = [
            'idempotence_token' => 'om-w' . $this->_website->getId() . '-v' . $child->getId(),
            'sku'               => (string) $child->getSku(),
            'options'           => $options,
            'prices'            => $prices,
        ];
        if ($child->getId() !== $parent->getId()) {
            $variant['name'] = mb_substr((string) $child->getName(), 0, 255);
        }
        $gtinCode = trim((string) $helper->getConfig('catalog/gtin_attribute', $this->_website));
        if ($gtinCode !== '') {
            $gtin = preg_replace('/\D/', '', (string) $child->getData($gtinCode));
            if (in_array(strlen($gtin), [8, 12, 13, 14], true)) {
                $variant['gtin'] = $gtin;
            }
        }
        return $variant;
    }

    /**
     * Wholesale and retail price per unit, in the price store view's base currency:
     *   wholesale: "Faire Wholesale Price", else the wholesale customer group's price, else a
     *              percentage of the retail price
     *   retail:    the price (or MSRP, as configured)
     *
     * @return array [wholesale, retail, currency]
     */
    public function getPrices(Mage_Catalog_Model_Product $product, ?Mage_Core_Model_Store $store = null)
    {
        $helper = $this->_helper();
        if (!$store) {
            $priceStoreId = (int) $helper->getConfig('catalog/price_store', $this->_website) ?: (int) $helper->getContentStore($this->_website)->getId();
            $store = Mage::app()->getStore($priceStoreId);
        }
        $priced = Mage::getModel('catalog/product')->setStoreId($store->getId())->load($product->getId());
        $retail = (float) $priced->getPrice();
        if ($helper->getConfig('catalog/retail_source', $this->_website) === 'msrp' && (float) $priced->getMsrp() > 0) {
            $retail = (float) $priced->getMsrp();
        }
        $wholesale = (float) Mage::getResourceSingleton('catalog/product')->getAttributeRawValue($product->getId(), 'faire_wholesale_price', $store->getId());
        if ($wholesale <= 0) {
            $group = $helper->getConfig('catalog/wholesale_group', $this->_website);
            if ($group !== false && $group !== null && $group !== '') {   // unset settings come back as false
                $groupPrice = $this->_groupPrice($priced, (int) $group, (int) $store->getWebsiteId());
                if ($groupPrice > 0 && $groupPrice < $retail) {
                    $wholesale = $groupPrice;
                }
            }
        }
        if ($wholesale <= 0) {
            $wholesale = round($retail * max(1, min(100, (float) $helper->getConfig('catalog/wholesale_percent', $this->_website))) / 100, 2);
        }
        if ($retail <= 0) {
            Mage::throwException('No price for ' . $product->getSku() . '.');
        }
        return [$wholesale, $retail, $store->getBaseCurrencyCode()];
    }

    /**
     * The price lists sent with every variant: [store view, region]. The main one ("Prices
     * from Store View", "Price Region"), plus one per line of "Prices per Region"
     * (REGION => store view code), each in its store view's website prices and base currency.
     *
     * @return array[]
     */
    public function getPriceLists()
    {
        $helper = $this->_helper();
        $mainId = (int) $helper->getConfig('catalog/price_store', $this->_website) ?: (int) $helper->getContentStore($this->_website)->getId();
        $mainRegion = (string) $helper->getConfig('catalog/price_region', $this->_website);
        $lists = [$mainRegion => [Mage::app()->getStore($mainId), $mainRegion]];
        foreach (preg_split('/\r?\n/', (string) $helper->getConfig('catalog/regional_prices', $this->_website)) as $line) {
            if (strpos($line, '=>') === false) {
                continue;
            }
            [$region, $storeCode] = array_map('trim', explode('=>', $line, 2));
            $region = strtoupper($region);
            if (!in_array($region, ['USA', 'EUROPEAN_UNION'], true)) {
                Mage::throwException('Prices per Region: unknown region "' . $region . '" (USA or EUROPEAN_UNION).');
            }
            try {
                $lists[$region] = [Mage::app()->getStore($storeCode), $region];   // a region once
            } catch (Exception $e) {
                Mage::throwException('Prices per Region: store view "' . $storeCode . '" does not exist.');
            }
        }
        return array_values($lists);
    }

    /**
     * The customer group's price for one unit: its group price or qty-1 tier price, for the
     * website or all websites (read from the product, no customer session needed).
     */
    protected function _groupPrice(Mage_Catalog_Model_Product $product, $groupId, $websiteId)
    {
        $best = 0.0;
        foreach (['group_price', 'tier_price'] as $code) {
            $attribute = $product->getResource()->getAttribute($code);
            if ($attribute && $product->getData($code) === null) {
                $attribute->getBackend()->afterLoad($product);
            }
            foreach ((array) $product->getData($code) as $row) {
                if ((int) ($row['cust_group'] ?? -1) !== $groupId && empty($row['all_groups'])) {
                    continue;
                }
                if (!in_array((int) ($row['website_id'] ?? 0), [0, $websiteId], true)) {
                    continue;
                }
                if ($code === 'tier_price' && (float) ($row['price_qty'] ?? 1) > 1) {
                    continue;
                }
                $price = (float) ($row['website_price'] ?? $row['price'] ?? 0);
                if ($price > 0 && ($best <= 0 || $price < $best)) {
                    $best = $price;
                }
            }
        }
        return $best;
    }

    protected function _madeIn(Mage_Catalog_Model_Product $product)
    {
        $code = (string) $product->getData('country_of_manufacture') ?: (string) $this->_helper()->getConfig('catalog/made_in', $this->_website);
        return $code !== '' ? $this->_helper()->countryToIso3($code) : null;
    }

    /**
     * Original image files (not cached thumbnails), base image first, at most 10.
     */
    protected function _images(Mage_Catalog_Model_Product $product)
    {
        $mediaUrl = Mage::app()->getStore($product->getStoreId())->getBaseUrl(Mage_Core_Model_Store::URL_TYPE_MEDIA) . 'catalog/product';
        $files = [];
        $base = (string) $product->getImage();
        if ($base !== '' && $base !== 'no_selection') {
            $files[] = $base;
        }
        $gallery = $product->getMediaGallery('images');
        if (is_array($gallery)) {
            usort($gallery, function ($a, $b) {
                return (int) $a['position'] <=> (int) $b['position'];
            });
            foreach ($gallery as $image) {
                if (empty($image['disabled']) && !in_array($image['file'], $files, true)) {
                    $files[] = $image['file'];
                }
            }
        }
        $images = [];
        foreach (array_slice($files, 0, 10) as $i => $file) {
            $images[] = ['url' => $mediaUrl . '/' . ltrim($file, '/'), 'sequence' => $i];
        }
        return $images;
    }

    /**
     * Faire shows plain text: paragraphs and list items become lines.
     */
    public function toPlainText($html, $singleLine = false)
    {
        $text = (string) $html;
        $text = preg_replace('/\{\{[^}]*\}\}/', '', $text);
        $text = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $text);
        $text = preg_replace('#<li[^>]*>#i', "\n• ", $text);
        $text = preg_replace('#<br\s*/?>|</(p|div|h[1-6]|li|ul|ol|tr)>#i', "\n", $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t\r]+/", ' ', $text);
        $text = preg_replace("/ *\n */", "\n", $text);
        $text = trim(preg_replace("/\n{3,}/", "\n\n", $text));
        if ($singleLine) {
            // list items and lines become "item · item"
            $text = preg_replace('/^• /', '', $text);
            $text = trim(preg_replace('/\s+/', ' ', preg_replace('/\n+(• )?/', ' · ', $text)));
        }
        return $text;
    }

    // ------------------------------------------------------------------ links

    protected function _links()
    {
        $resource = Mage::getSingleton('core/resource');
        $read = $resource->getConnection('core_read');
        $rows = $read->fetchAll($read->select()->from($resource->getTableName('ysrtech_faire/product'))
            ->where('website_id = ?', (int) $this->_website->getId()));
        $links = [];
        foreach ($rows as $row) {
            $links[(int) $row['product_id']] = $row;
        }
        return $links;
    }

    protected function _saveLink($productId, array $data)
    {
        if ($this->_dryRun) {
            return;
        }
        $resource = Mage::getSingleton('core/resource');
        $write = $resource->getConnection('core_write');
        $write->insertOnDuplicate(
            $resource->getTableName('ysrtech_faire/product'),
            ['website_id' => (int) $this->_website->getId(), 'product_id' => (int) $productId] + $data,
            array_keys($data),
        );
    }

    /**
     * SKU → Faire variant id of the website's products on Faire.
     */
    public function getVariantMap()
    {
        $map = [];
        foreach ($this->_links() as $link) {
            if ($link['status'] !== 'paused') {
                $map += json_decode((string) $link['variants'], true) ?: [];
            }
        }
        return $map;
    }
}
