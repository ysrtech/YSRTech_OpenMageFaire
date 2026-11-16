<?php

class YSRTech_Faire_Model_Source_Region
{
    public function toOptionArray()
    {
        $h = Mage::helper('ysrtech_faire');
        return [
            ['value' => '', 'label' => $h->__('None (the brand\'s default prices)')],
            ['value' => 'USA', 'label' => $h->__('United States (USA)')],
            ['value' => 'EUROPEAN_UNION', 'label' => $h->__('European Union')],
        ];
    }
}
