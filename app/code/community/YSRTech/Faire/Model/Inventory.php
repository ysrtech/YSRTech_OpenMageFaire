<?php

/**
 * Stock levels of the website's Faire variants, by SKU (PATCH /product-inventory/by-skus,
 * on_hand_quantity). Faire subtracts its own open orders (committed quantity).
 */
class YSRTech_Faire_Model_Inventory
{
    public const BATCH = 100;

    /** @var Mage_Core_Model_Website */
    protected $_website;

    /** @var YSRTech_Faire_Model_Api */
    protected $_api;

    protected $_dryRun = false;

    protected $_output;

    public function __construct()
    {
        $this->_api = Mage::getModel('ysrtech_faire/api');
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
        Mage::helper('ysrtech_faire')->log('[' . $this->_website->getCode() . '] ' . $line);
        if ($this->_output) {
            call_user_func($this->_output, $line);
        }
    }

    /**
     * @return array [updated, errors]
     */
    public function sync()
    {
        $helper = Mage::helper('ysrtech_faire');
        $skus = array_keys(Mage::getModel('ysrtech_faire/catalog')->setWebsite($this->_website)->getVariantMap());
        if (!$skus) {
            $this->_say('inventory: no products on Faire yet');
            return [0, 0];
        }
        $quantities = $this->getQuantities($skus);
        $rows = [];
        foreach ($quantities as $sku => $qty) {
            $rows[] = ['sku' => (string) $sku, 'on_hand_quantity' => $qty];
        }
        if ($this->_dryRun) {
            $this->_say('inventory: ' . json_encode($rows));
            return [count($rows), 0];
        }
        $updated = 0;
        $errors = 0;
        foreach (array_chunk($rows, self::BATCH) as $batch) {
            try {
                $this->_api->patch('product-inventory/by-skus', ['inventories' => $batch]);
                $updated += count($batch);
            } catch (YSRTech_Faire_Model_Api_Exception $e) {
                // one unknown SKU fails the batch: send them one by one
                if (!in_array($e->getCode(), [400, 404], true) || count($batch) === 1) {
                    $errors += count($batch);
                    $this->_say('inventory: ' . $e->getMessage());
                    continue;
                }
                foreach ($batch as $row) {
                    try {
                        $this->_api->patch('product-inventory/by-skus', ['inventories' => [$row]]);
                        $updated++;
                    } catch (Exception $single) {
                        $errors++;
                        $this->_say("inventory {$row['sku']}: " . $single->getMessage());
                    }
                }
            }
        }
        $this->_say(sprintf('inventory: %d SKU(s) updated, %d error(s)', $updated, $errors));
        return [$updated, $errors];
    }

    /**
     * Quantity to show on Faire per SKU: the stock quantity less the safety buffer, 0 when out
     * of stock, the configured quantity when stock is not managed.
     *
     * @return array sku => int
     */
    public function getQuantities(array $skus)
    {
        $helper = Mage::helper('ysrtech_faire');
        $buffer = max(0, (int) $helper->getConfig('inventory/buffer', $this->_website));
        $unmanaged = max(0, (int) $helper->getConfig('inventory/unmanaged_qty', $this->_website));
        $resource = Mage::getSingleton('core/resource');
        $read = $resource->getConnection('core_read');
        $ids = $read->fetchPairs($read->select()->from($resource->getTableName('catalog/product'), ['sku', 'entity_id'])->where('sku IN (?)', $skus));
        $result = [];
        foreach ($skus as $sku) {
            if (!isset($ids[$sku])) {
                $result[$sku] = 0;
                continue;
            }
            $item = Mage::getModel('cataloginventory/stock_item')->loadByProduct((int) $ids[$sku]);
            if (!$item->getId() || !$item->getManageStock()) {
                $result[$sku] = $unmanaged;
            } elseif (!$item->getIsInStock()) {
                $result[$sku] = 0;
            } else {
                $result[$sku] = max(0, (int) floor((float) $item->getQty()) - $buffer);
            }
        }
        return $result;
    }
}
