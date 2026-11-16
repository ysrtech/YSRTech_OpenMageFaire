<?php

/**
 * Faire orders of a website → OpenMage orders.
 *
 *  - imported once per Faire order (ysrtech_faire_order), when NEW or PROCESSING, created on
 *    or after the "Import orders from" date
 *  - placed in the configured store view with the Faire wholesale prices, payment "Faire",
 *    shipping "Faire"; no email to anyone (Faire talks to the retailer)
 *  - accepted on Faire (PROCESSING, expected ship date) when "Accept automatically" is on
 *  - a Faire cancellation cancels the OpenMage order while it has not been invoiced or shipped
 */
class YSRTech_Faire_Model_Orders
{
    public const IMPORT_STATES = ['NEW', 'PROCESSING'];

    /** @var Mage_Core_Model_Website */
    protected $_website;

    /** @var YSRTech_Faire_Model_Api */
    protected $_api;

    protected $_dryRun = false;

    protected $_output;

    protected $_stats = ['imported' => 0, 'accepted' => 0, 'canceled' => 0, 'skipped' => 0, 'errors' => 0];

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

    /**
     * Orders updated since the last run (or the start date).
     */
    public function sync()
    {
        $helper = $this->_helper();
        $flagCode = 'ysrtech_faire_orders_' . $this->_website->getId();
        $flag = Mage::getModel('core/flag', ['flag_code' => $flagCode])->loadSelf();
        $since = (string) $flag->getFlagData();
        $start = trim((string) $helper->getConfig('orders/start_date', $this->_website));
        $query = ['sort_by' => 'UPDATED_AT'];
        if ($since !== '') {
            $query['updated_at_min'] = $since;
        } elseif ($start !== '') {
            $query['updated_at_min'] = date('c', strtotime($start));
        }
        $newest = $since;
        $runStarted = gmdate('Y-m-d\TH:i:s.000\Z', time() - 300);   // overlap: clocks, slow writes
        foreach ($this->_api->each('orders', 'orders', $query) as $order) {
            try {
                $this->processOrder($order);
            } catch (Exception $e) {
                $this->_stats['errors']++;
                $this->_say(sprintf('order %s: %s', $order['display_id'] ?? $order['id'] ?? '?', $e->getMessage()));
                $this->_saveLink($order, ['status' => 'error', 'error' => $e->getMessage()]);
            }
            if (!empty($order['updated_at']) && $order['updated_at'] > $newest) {
                $newest = $order['updated_at'];
            }
        }
        if (!$this->_dryRun) {
            $flag->setFlagData(min($newest ?: $runStarted, $runStarted))->save();
        }
        $this->_say(sprintf(
            'orders: %d imported, %d accepted, %d canceled, %d skipped, %d error(s)',
            $this->_stats['imported'], $this->_stats['accepted'], $this->_stats['canceled'], $this->_stats['skipped'], $this->_stats['errors'],
        ));
        return $this->_stats;
    }

    public function processOrder(array $order)
    {
        $helper = $this->_helper();
        $link = $this->_getLink($order['id']);
        $state = (string) ($order['state'] ?? '');

        if ($link && $link['order_id']) {
            if ($state === 'CANCELED' && $link['faire_state'] !== 'CANCELED') {
                $this->_cancel($link, $order);
            }
            $this->_saveLink($order, []);
            return;
        }
        $start = trim((string) $helper->getConfig('orders/start_date', $this->_website));
        if ($start !== '' && !empty($order['created_at']) && strtotime($order['created_at']) < strtotime($start)) {
            return;   // before the go-live date
        }
        if (!in_array($state, self::IMPORT_STATES, true)) {
            if (!$link) {
                $this->_stats['skipped']++;
            }
            $this->_saveLink($order, ['status' => 'skipped', 'error' => 'State ' . $state . ' is not imported.']);
            return;
        }
        // already created (e.g. the link was lost): link it again, never a second order
        $existing = Mage::getModel('sales/order')->getCollection()->addFieldToFilter('ext_order_id', (string) $order['id'])->getFirstItem();
        if ($existing->getId()) {
            $this->_saveLink($order, ['order_id' => (int) $existing->getId(), 'increment_id' => $existing->getIncrementId(), 'status' => 'imported', 'error' => null]);
            return;
        }
        if ($this->_dryRun) {
            $this->_say(sprintf('would import %s (%s, %d item(s))', $order['display_id'] ?? $order['id'], $state, count($order['items'] ?? [])));
            return;
        }
        $mageOrder = $this->createOrder($order);
        $this->_stats['imported']++;
        $this->_saveLink($order, [
            'order_id'     => (int) $mageOrder->getId(),
            'increment_id' => $mageOrder->getIncrementId(),
            'status'       => 'imported',
            'error'        => null,
            'imported_at'  => Varien_Date::now(),
        ]);
        $this->_say(sprintf('order %s imported as %s', $order['display_id'] ?? $order['id'], $mageOrder->getIncrementId()));

        if ($state === 'NEW' && $helper->getFlag('orders/auto_accept', $this->_website)) {
            $this->accept($order['id']);
        }
    }

    /**
     * Accept the order on Faire (PROCESSING), with the expected ship date.
     */
    public function accept($faireOrderId)
    {
        $days = max(0, (int) $this->_helper()->getConfig('orders/ship_days', $this->_website));
        $date = new DateTime('now', new DateTimeZone('UTC'));
        $date->modify('+' . $days . ' weekdays');
        $this->_api->put('orders/' . rawurlencode($faireOrderId) . '/processing', ['expected_ship_date' => $date->format('Y-m-d\TH:i:s.000\Z')]);
        $this->_stats['accepted']++;
        $resource = Mage::getSingleton('core/resource');
        $resource->getConnection('core_write')->update(
            $resource->getTableName('ysrtech_faire/order'),
            ['accepted_at' => Varien_Date::now(), 'faire_state' => 'PROCESSING'],
            ['faire_order_id = ?' => $faireOrderId],
        );
        $this->_say("order {$faireOrderId} accepted on Faire, expected ship date " . $date->format('Y-m-d'));
    }

    /**
     * @return Mage_Sales_Model_Order
     */
    public function createOrder(array $order)
    {
        $helper = $this->_helper();
        $store = $this->getStoreForOrder($order);
        $items = array_filter((array) ($order['items'] ?? []), function ($item) {
            return ($item['state'] ?? '') !== 'CANCELED' && (int) ($item['quantity'] ?? 0) > 0;
        });
        if (!$items) {
            Mage::throwException('The order has no items.');
        }
        $address = (array) ($order['address'] ?? []);
        $country = $helper->countryFromIso3($address['country_code'] ?? '');
        if (!$country) {
            Mage::throwException('Unknown country "' . ($address['country_code'] ?? '') . '".');
        }

        $quote = Mage::getModel('sales/quote')->setStoreId((int) $store->getId());
        $quote->setIsSuperMode(true)   // Faire sold it: no stock or minimum checks
            ->setInventoryProcessed(false)
            ->setCustomerIsGuest(1)
            ->setCheckoutMethod(Mage_Checkout_Model_Type_Onepage::METHOD_GUEST)
            ->setCustomerGroupId((int) $helper->getConfig('orders/customer_group', $this->_website))
            ->setCustomerEmail($this->_orderEmail($store, $order))
            ->setCustomerFirstname($this->_firstName($order, $address))
            ->setCustomerLastname($this->_lastName($order, $address));
        $noTax = $helper->getFlag('orders/no_tax', $this->_website);

        foreach ($items as $item) {
            $sku = (string) ($item['sku'] ?? '');
            $productId = $sku !== '' ? (int) Mage::getModel('catalog/product')->getIdBySku($sku) : 0;
            if (!$productId) {
                Mage::throwException('SKU "' . $sku . '" (' . ($item['product_name'] ?? '') . ') is not in the catalog.');
            }
            $product = Mage::getModel('catalog/product')->setStoreId($store->getId())->load($productId);
            $product->setIsSuperMode(true)->setSkipCheckRequiredOption(true);
            if ($noTax) {
                $product->setTaxClassId(0);
            }
            $qty = (int) $item['quantity'];
            $result = $quote->addProduct($product, new Varien_Object(['qty' => $qty]));
            if (is_string($result)) {
                Mage::throwException($sku . ': ' . $result);
            }
            $price = $this->_unitPrice($item, $store);
            $result->setCustomPrice($price)->setOriginalCustomPrice($price);
            $result->getProduct()->setIsSuperMode(true);
            if (!empty($item['includes_tester'])) {
                $result->setAdditionalData('Faire: includes a tester');
            }
        }

        $data = [
            'firstname'  => $this->_firstName($order, $address),
            'lastname'   => $this->_lastName($order, $address),
            'company'    => (string) ($address['company_name'] ?? ''),
            'street'     => trim(($address['address1'] ?? '') . "\n" . ($address['address2'] ?? '')),
            'city'       => (string) ($address['city'] ?? ''),
            'postcode'   => (string) ($address['postal_code'] ?? ''),
            'country_id' => $country,
            'telephone'  => (string) ($address['phone_number'] ?? '') ?: '-',
            'email'      => $quote->getCustomerEmail(),
        ];
        $region = Mage::getModel('directory/region')->loadByCode((string) ($address['state_code'] ?? ''), $country);
        if ($region->getId()) {
            $data['region_id'] = $region->getId();
            $data['region'] = $region->getName();
        } else {
            $data['region'] = (string) ($address['state'] ?? '');
        }
        $quote->getBillingAddress()->addData($data);
        $shipping = $quote->getShippingAddress()->addData($data)->setSameAsBilling(1);
        $shipping->setCollectShippingRates(true)->collectShippingRates()
            ->setShippingMethod(YSRTech_Faire_Model_Carrier_Faire::CODE . '_' . YSRTech_Faire_Model_Carrier_Faire::CODE);
        $quote->getPayment()->importData(['method' => YSRTech_Faire_Model_Payment_Faire::CODE]);
        $quote->collectTotals()->save();

        /** @var Mage_Sales_Model_Service_Quote $service */
        $service = Mage::getModel('sales/service_quote', $quote);
        $service->submitAll();
        $mageOrder = $service->getOrder();
        if (!$mageOrder) {
            Mage::throwException('The order could not be created.');
        }
        $quote->setIsActive(0)->save();

        $total = $order['payout_costs']['total_payout'] ?? null;
        $lines = [
            sprintf('Faire order %s (%s), %s.', $order['display_id'] ?? '', $order['id'], $order['source'] ?? 'MARKETPLACE'),
        ];
        if (!empty($order['ship_after'])) {
            $lines[] = 'Ship after ' . substr($order['ship_after'], 0, 10) . '.';
        }
        if ($total) {
            $lines[] = sprintf('Faire payout: %.2f %s.', $total['amount_minor'] / 100, $total['currency']);
        }
        if (!empty($order['purchase_order_number'])) {
            $lines[] = 'Retailer PO: ' . $order['purchase_order_number'] . '.';
        }
        if (!empty($order['notes'])) {
            $lines[] = 'Notes: ' . $order['notes'];
        }
        if (!empty($order['is_fulfilled_by_faire'])) {
            $lines[] = 'Fulfilled by Faire: do not ship.';
        }
        $mageOrder->setExtOrderId((string) $order['id'])
            ->setCanSendNewEmailFlag(false)
            ->addStatusHistoryComment(implode(' ', $lines))->setIsCustomerNotified(false);
        $mageOrder->save();
        return $mageOrder;
    }

    /**
     * The store view for an order: the one set for its currency ("Store Views by Currency",
     * e.g. USD => the US store view), else "Store View for Orders".
     */
    public function getStoreForOrder(array $order)
    {
        $helper = $this->_helper();
        $currency = '';
        foreach ((array) ($order['items'] ?? []) as $item) {
            if (!empty($item['price']['currency'])) {
                $currency = strtoupper($item['price']['currency']);
                break;
            }
        }
        foreach (preg_split('/\r?\n/', (string) $helper->getConfig('orders/stores_by_currency', $this->_website)) as $line) {
            if (strpos($line, '=>') === false) {
                continue;
            }
            [$code, $storeCode] = array_map('trim', explode('=>', $line, 2));
            if ($currency !== '' && strcasecmp($code, $currency) === 0) {
                try {
                    return Mage::app()->getStore($storeCode);
                } catch (Exception $e) {
                    Mage::throwException('Store view "' . $storeCode . '" (Store Views by Currency) does not exist.');
                }
            }
        }
        return $helper->getOrderStore($this->_website);
    }

    /**
     * Wholesale unit price in the store's base currency (converted when Faire uses another).
     */
    protected function _unitPrice(array $item, Mage_Core_Model_Store $store)
    {
        if (!empty($item['price']['amount_minor'])) {
            $amount = $item['price']['amount_minor'] / 100;
            $currency = strtoupper((string) ($item['price']['currency'] ?? $store->getBaseCurrencyCode()));
        } else {
            $amount = ((int) ($item['price_cents'] ?? 0)) / 100;
            $currency = 'USD';
        }
        $base = $store->getBaseCurrencyCode();
        if ($currency !== $base) {
            $rate = (float) Mage::getModel('directory/currency')->load($currency)->getRate($base);
            if ($rate <= 0) {
                $inverse = (float) Mage::getModel('directory/currency')->load($base)->getRate($currency);
                $rate = $inverse > 0 ? 1 / $inverse : 0;
            }
            if ($rate <= 0) {
                Mage::throwException("No currency rate {$currency} → {$base} (System > Manage Currency Rates).");
            }
            $amount = round($amount * $rate, 2);
        }
        return $amount;
    }

    protected function _orderEmail(Mage_Core_Model_Store $store, array $order)
    {
        $email = trim((string) $this->_helper()->getConfig('orders/email', $this->_website));
        if ($email === '') {
            $email = (string) Mage::getStoreConfig('trans_email/ident_sales/email', $store);
        }
        return $email ?: 'faire@example.com';
    }

    protected function _firstName(array $order, array $address)
    {
        $first = trim((string) ($order['customer']['first_name'] ?? ''));
        if ($first === '') {
            $first = strtok(trim((string) ($address['name'] ?? '')), ' ') ?: 'Faire';
        }
        return $first;
    }

    protected function _lastName(array $order, array $address)
    {
        $last = trim((string) ($order['customer']['last_name'] ?? ''));
        if ($last === '') {
            $parts = explode(' ', trim((string) ($address['name'] ?? '')), 2);
            $last = $parts[1] ?? ($address['company_name'] ?? 'Retailer');
        }
        return $last ?: 'Retailer';
    }

    protected function _cancel(array $link, array $order)
    {
        $mageOrder = Mage::getModel('sales/order')->load((int) $link['order_id']);
        if (!$mageOrder->getId()) {
            return;
        }
        if ($mageOrder->canCancel() && !$mageOrder->hasShipments()) {
            $mageOrder->cancel()->addStatusHistoryComment('Canceled on Faire.')->setIsCustomerNotified(false);
            $mageOrder->save();
            $this->_stats['canceled']++;
            $this->_say("order {$mageOrder->getIncrementId()} canceled (canceled on Faire)");
        } else {
            $mageOrder->addStatusHistoryComment('Canceled on Faire, but this order is already invoiced or shipped: check it.')->setIsCustomerNotified(false);
            $mageOrder->save();
            $this->_say("order {$mageOrder->getIncrementId()}: canceled on Faire but already shipped/invoiced");
        }
    }

    // ------------------------------------------------------------------ links

    protected function _getLink($faireOrderId)
    {
        $resource = Mage::getSingleton('core/resource');
        $read = $resource->getConnection('core_read');
        return $read->fetchRow($read->select()->from($resource->getTableName('ysrtech_faire/order'))->where('faire_order_id = ?', $faireOrderId)) ?: null;
    }

    protected function _saveLink(array $order, array $data)
    {
        if ($this->_dryRun || empty($order['id'])) {
            return;
        }
        $retailer = trim((string) ($order['address']['company_name'] ?? '') ?: (string) ($order['address']['name'] ?? ''));
        $total = $order['payout_costs']['subtotal_after_brand_discounts'] ?? null;
        $row = [
            'website_id'       => (int) $this->_website->getId(),
            'faire_order_id'   => (string) $order['id'],
            'display_id'       => (string) ($order['display_id'] ?? ''),
            'faire_state'      => (string) ($order['state'] ?? ''),
            'retailer'         => mb_substr($retailer, 0, 255),
            'total'            => $total ? sprintf('%.2f %s', $total['amount_minor'] / 100, $total['currency']) : null,
            'faire_created_at' => !empty($order['created_at']) ? gmdate('Y-m-d H:i:s', strtotime($order['created_at'])) : null,
            'updated_at'       => Varien_Date::now(),
        ] + $data;
        $resource = Mage::getSingleton('core/resource');
        $resource->getConnection('core_write')->insertOnDuplicate(
            $resource->getTableName('ysrtech_faire/order'),
            $row,
            array_values(array_diff(array_keys($row), ['faire_order_id'])),
        );
    }
}
