<?php

/**
 * Sales > Faire Orders, and the actions of the configuration page.
 */
class YSRTech_Faire_Adminhtml_Ysrtech_FaireController extends Mage_Adminhtml_Controller_Action
{
    protected function _isAllowed(): bool
    {
        $session = Mage::getSingleton('admin/session');
        if (in_array($this->getRequest()->getActionName(), ['run', 'massFaire'], true)) {
            return $session->isAllowed($this->getRequest()->getActionName() === 'run' ? 'system/config/ysrtech_faire' : 'catalog/products');
        }
        return $session->isAllowed('sales/ysrtech_faire');
    }

    public function indexAction()
    {
        $this->loadLayout()->_setActiveMenu('sales/ysrtech_faire')->_title($this->__('Sales'))->_title($this->__('Faire Orders'))
            ->_addContent($this->getLayout()->createBlock('ysrtech_faire/adminhtml_order'))
            ->renderLayout();
    }

    public function gridAction()
    {
        $this->getResponse()->setBody($this->getLayout()->createBlock('ysrtech_faire/adminhtml_order_grid')->toHtml());
    }

    /**
     * Import again an order that failed (after fixing the cause, e.g. a missing SKU).
     */
    public function retryAction()
    {
        $resource = Mage::getSingleton('core/resource');
        $read = $resource->getConnection('core_read');
        $link = $read->fetchRow($read->select()->from($resource->getTableName('ysrtech_faire/order'))->where('link_id = ?', (int) $this->getRequest()->getParam('id')));
        if (!$link || $link['order_id']) {
            $this->_getSession()->addError($this->__('This Faire order is already imported.'));
            return $this->_redirect('*/*/');
        }
        try {
            $orders = Mage::getModel('ysrtech_faire/orders')->setWebsite($link['website_id']);
            $faireOrder = Mage::getModel('ysrtech_faire/api')->setWebsite($link['website_id'])->get('orders/' . rawurlencode($link['faire_order_id']));
            $orders->processOrder($faireOrder);
            $link = $read->fetchRow($read->select()->from($resource->getTableName('ysrtech_faire/order'))->where('link_id = ?', (int) $link['link_id']));
            if ($link['order_id']) {
                $this->_getSession()->addSuccess($this->__('Faire order %s imported as %s.', $link['display_id'], $link['increment_id']));
            } else {
                $this->_getSession()->addNotice($this->__('Faire order %s: %s', $link['display_id'], $link['error'] ?: $link['faire_state']));
            }
        } catch (Exception $e) {
            $this->_getSession()->addError($e->getMessage());
        }
        $this->_redirect('*/*/');
    }

    /**
     * AJAX (configuration page): type, website.
     */
    public function runAction()
    {
        $response = ['output' => ''];
        if (!$this->getRequest()->isPost() || !$this->_validateFormKey()) {
            $response['output'] = 'Invalid request, reload the page.';
        } else {
            try {
                $website = (string) $this->getRequest()->getPost('website');
                set_time_limit(600);
                $response['output'] = implode("\n", Mage::getModel('ysrtech_faire/runner')->run((string) $this->getRequest()->getPost('type'), $website));
            } catch (Exception $e) {
                $response['output'] = 'Error: ' . $e->getMessage();
            }
        }
        $this->getResponse()->setHeader('Content-Type', 'application/json', true)->setBody(Mage::helper('core')->jsonEncode($response));
    }

    /**
     * Catalog > Manage Products mass action: Sell on Faire Yes / No (website of the store
     * switcher, else all websites of the products).
     */
    public function massFaireAction()
    {
        $ids = array_map('intval', (array) $this->getRequest()->getParam('product'));
        $value = (int) (bool) $this->getRequest()->getParam('faire');
        $storeId = (int) $this->getRequest()->getParam('store');
        $stores = $storeId ? [$storeId] : [0];
        if (!$storeId) {
            // website-scope attribute: write the default and every website's default store view
            foreach (Mage::app()->getWebsites() as $website) {
                $stores[] = (int) $website->getDefaultStore()->getId();
            }
        }
        foreach (array_unique($stores) as $store) {
            Mage::getSingleton('catalog/product_action')->updateAttributes($ids, ['faire_enabled' => $value], $store);
        }
        $this->_getSession()->addSuccess($this->__('%d product(s): Sell on Faire = %s.', count($ids), $value ? $this->__('Yes') : $this->__('No')));
        $this->_redirect('*/catalog_product/', ['store' => $storeId ?: null]);
    }
}
