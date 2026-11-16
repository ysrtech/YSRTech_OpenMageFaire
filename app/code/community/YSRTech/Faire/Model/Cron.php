<?php

class YSRTech_Faire_Model_Cron
{
    protected function _each($type, callable $run)
    {
        $helper = Mage::helper('ysrtech_faire');
        $helper->initCliSession();
        foreach ($helper->getEnabledWebsites() as $website) {
            try {
                $run($website);
            } catch (Exception $e) {
                $helper->log('[' . $website->getCode() . "] {$type}: " . $e->getMessage(), Zend_Log::ERR);
                Mage::logException($e);
            }
        }
    }

    public function orders()
    {
        $this->_each('orders', function ($website) {
            if (Mage::helper('ysrtech_faire')->getFlag('orders/enabled', $website)) {
                Mage::getModel('ysrtech_faire/orders')->setWebsite($website)->sync();
            }
        });
    }

    public function inventory()
    {
        $this->_each('inventory', function ($website) {
            if (Mage::helper('ysrtech_faire')->getFlag('inventory/enabled', $website)) {
                Mage::getModel('ysrtech_faire/inventory')->setWebsite($website)->sync();
            }
        });
    }

    public function shipments()
    {
        $this->_each('shipments', function ($website) {
            if (Mage::helper('ysrtech_faire')->getFlag('shipments/enabled', $website)) {
                Mage::getModel('ysrtech_faire/shipments')->setWebsite($website)->sync();
            }
        });
    }

    public function products()
    {
        $this->_each('products', function ($website) {
            if (Mage::helper('ysrtech_faire')->getFlag('catalog/enabled', $website)) {
                Mage::getModel('ysrtech_faire/catalog')->setWebsite($website)->sync();
            }
        });
    }
}
