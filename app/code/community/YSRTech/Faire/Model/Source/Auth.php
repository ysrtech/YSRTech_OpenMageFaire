<?php

class YSRTech_Faire_Model_Source_Auth
{
    public function toOptionArray()
    {
        $h = Mage::helper('ysrtech_faire');
        return [
            ['value' => 'token', 'label' => $h->__('Brand access token')],
            ['value' => 'oauth', 'label' => $h->__('OAuth app (application id and secret + access token)')],
        ];
    }
}
