<?php

class YSRTech_Faire_Model_Source_RetailSource
{
    public function toOptionArray()
    {
        $h = Mage::helper('ysrtech_faire');
        return [
            ['value' => 'price', 'label' => $h->__('Price')],
            ['value' => 'msrp', 'label' => $h->__('MSRP (Price when empty)')],
        ];
    }
}
