<?php

/**
 * Tracking numbers of OpenMage shipments of Faire orders → Faire (POST
 * /orders/{id}/shipments, SHIP_ON_YOUR_OWN). Each tracking number is sent once; failures are
 * retried by the next runs (5 attempts).
 */
class YSRTech_Faire_Model_Shipments
{
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

    public function sync()
    {
        $resource = Mage::getSingleton('core/resource');
        $read = $resource->getConnection('core_read');
        $select = $read->select()
            ->from(['t' => $resource->getTableName('sales/shipment_track')], ['entity_id', 'track_number', 'carrier_code', 'title', 'parent_id'])
            ->join(['l' => $resource->getTableName('ysrtech_faire/order')], 'l.order_id = t.order_id', ['faire_order_id', 'increment_id'])
            ->join(['o' => $resource->getTableName('sales/order')], 'o.entity_id = t.order_id', ['base_currency_code'])
            ->joinLeft(['s' => $resource->getTableName('ysrtech_faire/shipment')], 's.track_id = t.entity_id', ['status', 'attempts'])
            ->where('l.website_id = ?', (int) $this->_website->getId())
            ->where('s.link_id IS NULL OR (s.status = ? AND s.attempts < 5)', 'error');
        $sent = 0;
        $errors = 0;
        foreach ($read->fetchAll($select) as $track) {
            $shipment = [
                'order_id'      => $track['faire_order_id'],
                'carrier'       => $this->mapCarrier($track['carrier_code'], $track['title']),
                'tracking_code' => trim((string) $track['track_number']),
                'maker_cost'    => ['amount_minor' => 0, 'currency' => $track['base_currency_code']],
                'shipping_type' => 'SHIP_ON_YOUR_OWN',
            ];
            if ($this->_dryRun) {
                $this->_say('would send ' . json_encode($shipment));
                continue;
            }
            try {
                $result = $this->_api->post('orders/' . rawurlencode($track['faire_order_id']) . '/shipments', ['shipments' => [$shipment]]);
                $list = (array) ($result['shipments'] ?? []);
                $last = $list ? end($list) : [];
                $this->_save($track, ['status' => 'sent', 'error' => null, 'faire_shipment_id' => $last['id'] ?? null, 'sent_at' => Varien_Date::now()]);
                $sent++;
                $this->_say(sprintf('order %s: tracking %s (%s) sent to Faire', $track['increment_id'], $shipment['tracking_code'], $shipment['carrier']));
            } catch (Exception $e) {
                $errors++;
                $this->_save($track, ['status' => 'error', 'error' => $e->getMessage()]);
                $this->_say(sprintf('order %s: tracking %s not sent: %s', $track['increment_id'], $shipment['tracking_code'], $e->getMessage()));
            }
        }
        return [$sent, $errors];
    }

    /**
     * OpenMage carrier code → Faire carrier (setting "Carriers"), else the tracking title.
     */
    public function mapCarrier($code, $title)
    {
        foreach (preg_split('/\r?\n/', (string) Mage::helper('ysrtech_faire')->getConfig('shipments/carriers', $this->_website)) as $line) {
            if (strpos($line, '=>') === false) {
                continue;
            }
            [$from, $to] = array_map('trim', explode('=>', $line, 2));
            if ($from !== '' && strcasecmp($from, (string) $code) === 0) {
                return strtoupper($to);
            }
        }
        $guess = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', trim((string) $title)));
        return $guess !== '' ? $guess : strtoupper((string) $code);
    }

    protected function _save(array $track, array $data)
    {
        $resource = Mage::getSingleton('core/resource');
        $write = $resource->getConnection('core_write');
        $table = $resource->getTableName('ysrtech_faire/shipment');
        $exists = $write->fetchOne($write->select()->from($table, 'link_id')->where('track_id = ?', (int) $track['entity_id']));
        if ($exists) {
            $write->update($table, $data + ['attempts' => new Zend_Db_Expr('attempts + 1')], ['link_id = ?' => (int) $exists]);
        } else {
            $write->insert($table, ['track_id' => (int) $track['entity_id'], 'faire_order_id' => $track['faire_order_id'], 'attempts' => 1] + $data);
        }
    }
}
