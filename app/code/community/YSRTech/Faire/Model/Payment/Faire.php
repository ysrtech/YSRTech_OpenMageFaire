<?php

/**
 * "Faire": the retailer paid Faire, Faire pays the brand. Only for imported Faire orders,
 * never offered in the checkout.
 */
class YSRTech_Faire_Model_Payment_Faire extends Mage_Payment_Model_Method_Abstract
{
    public const CODE = 'ysrtechfaire';

    protected $_code = self::CODE;
    protected $_canUseCheckout = false;
    protected $_canUseInternal = false;
    protected $_canUseForMultishipping = false;
    protected $_canCapture = true;
    protected $_isInitializeNeeded = true;

    /**
     * Orders start in Processing, without invoice: Faire pays later (invoice then, or when
     * shipping), and a Faire cancellation can still cancel the order.
     */
    public function initialize($paymentAction, $stateObject)
    {
        $stateObject->setState(Mage_Sales_Model_Order::STATE_PROCESSING)
            ->setStatus(Mage::getSingleton('sales/order_config')->getStateDefaultStatus(Mage_Sales_Model_Order::STATE_PROCESSING))
            ->setIsNotified(false);
        return $this;
    }

    public function isAvailable($quote = null)
    {
        return $quote !== null && (bool) $quote->getIsSuperMode() && parent::isAvailable($quote);
    }
}
