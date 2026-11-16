<?php

class YSRTech_Faire_Model_Observer
{
    /**
     * adminhtml_block_html_before: "Sell on Faire" in the product grid's mass actions.
     */
    public function addProductMassAction(Varien_Event_Observer $observer)
    {
        $block = $observer->getEvent()->getBlock();
        if (!$block instanceof Mage_Adminhtml_Block_Widget_Grid_Massaction || $block->getParentBlock() === null
            || !$block->getParentBlock() instanceof Mage_Adminhtml_Block_Catalog_Product_Grid
        ) {
            return;
        }
        $h = Mage::helper('ysrtech_faire');
        $block->addItem('ysrtech_faire', [
            'label'      => $h->__('Sell on Faire'),
            'url'        => Mage::getSingleton('adminhtml/url')->getUrl('adminhtml/ysrtech_faire/massFaire', ['store' => (int) Mage::app()->getRequest()->getParam('store')]),
            'additional' => [
                'faire' => [
                    'name'   => 'faire',
                    'type'   => 'select',
                    'class'  => 'required-entry',
                    'label'  => $h->__('Sell on Faire'),
                    'values' => [1 => $h->__('Yes'), 0 => $h->__('No')],
                ],
            ],
        ]);
    }
}
