<?php

class YSRTech_Faire_Model_Source_Store
{
    public function toOptionArray()
    {
        $options = Mage::getSingleton('adminhtml/system_store')->getStoreValuesForForm(false, false);
        array_unshift($options, ['value' => '', 'label' => Mage::helper('ysrtech_faire')->__('-- The website\'s default store view --')]);
        return $options;
    }
}
