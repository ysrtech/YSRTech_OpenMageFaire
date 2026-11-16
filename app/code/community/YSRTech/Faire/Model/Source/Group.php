<?php

class YSRTech_Faire_Model_Source_Group
{
    public function toOptionArray()
    {
        $options = Mage::getResourceModel('customer/group_collection')->setRealGroupsFilter()->load()->toOptionArray();
        array_unshift($options, ['value' => '', 'label' => Mage::helper('ysrtech_faire')->__('-- None --')]);
        return $options;
    }
}
