<?php

/**
 * "Faire" shipping for imported Faire orders (shipping is settled through Faire); never
 * offered to shoppers.
 */
class YSRTech_Faire_Model_Carrier_Faire extends Mage_Shipping_Model_Carrier_Abstract implements Mage_Shipping_Model_Carrier_Interface
{
    public const CODE = 'ysrtechfaire';

    protected $_code = self::CODE;
    protected $_isFixed = true;

    public function collectRates(Mage_Shipping_Model_Rate_Request $request)
    {
        // only for the quotes built by the order import (super mode)
        $items = $request->getAllItems();
        $quote = $items ? reset($items)->getQuote() : null;
        if (!$quote || !$quote->getIsSuperMode() || !$this->getConfigFlag('active')) {
            return false;
        }
        $result = Mage::getModel('shipping/rate_result');
        $method = Mage::getModel('shipping/rate_result_method');
        $method->setCarrier($this->_code)
            ->setCarrierTitle($this->getConfigData('title'))
            ->setMethod($this->_code)
            ->setMethodTitle($this->getConfigData('name'))
            ->setPrice(0)
            ->setCost(0);
        $result->append($method);
        return $result;
    }

    public function getAllowedMethods()
    {
        return [$this->_code => $this->getConfigData('name')];
    }

    public function isTrackingAvailable()
    {
        return false;
    }
}
