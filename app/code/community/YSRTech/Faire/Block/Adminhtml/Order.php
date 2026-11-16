<?php

class YSRTech_Faire_Block_Adminhtml_Order extends Mage_Adminhtml_Block_Widget_Grid_Container
{
    public function __construct()
    {
        $this->_blockGroup = 'ysrtech_faire';
        $this->_controller = 'adminhtml_order';
        $this->_headerText = Mage::helper('ysrtech_faire')->__('Faire Orders');
        parent::__construct();
        $this->_removeButton('add');
    }
}
