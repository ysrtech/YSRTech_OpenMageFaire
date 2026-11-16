<?php

class YSRTech_Faire_Block_Adminhtml_Order_Grid extends Mage_Adminhtml_Block_Widget_Grid
{
    public function __construct()
    {
        parent::__construct();
        $this->setId('ysrtechFaireOrderGrid');
        $this->setDefaultSort('faire_created_at');
        $this->setDefaultDir('DESC');
        $this->setUseAjax(true);
        $this->setSaveParametersInSession(true);
    }

    protected function _prepareCollection()
    {
        $collection = new Varien_Data_Collection_Db(Mage::getSingleton('core/resource')->getConnection('core_read'));
        $collection->getSelect()->from(Mage::getSingleton('core/resource')->getTableName('ysrtech_faire/order'));
        $this->setCollection($collection);
        return parent::_prepareCollection();
    }

    protected function _prepareColumns()
    {
        $h = Mage::helper('ysrtech_faire');
        $this->addColumn('display_id', ['header' => $h->__('Faire Order'), 'index' => 'display_id', 'width' => '110px']);
        $this->addColumn('faire_created_at', ['header' => $h->__('Ordered'), 'index' => 'faire_created_at', 'type' => 'datetime', 'width' => '150px']);
        $this->addColumn('retailer', ['header' => $h->__('Retailer'), 'index' => 'retailer']);
        $this->addColumn('total', ['header' => $h->__('Subtotal'), 'index' => 'total', 'width' => '110px']);
        $this->addColumn('faire_state', [
            'header' => $h->__('Faire Status'), 'index' => 'faire_state', 'type' => 'options', 'width' => '130px',
            'options' => array_combine($s = ['NEW', 'PROCESSING', 'PRE_TRANSIT', 'IN_TRANSIT', 'DELIVERED', 'BACKORDERED', 'PENDING_RETAILER_CONFIRMATION', 'CANCELED'], $s),
        ]);
        $this->addColumn('increment_id', ['header' => $h->__('Order #'), 'index' => 'increment_id', 'width' => '110px', 'frame_callback' => [$this, 'renderOrderLink']]);
        $this->addColumn('status', [
            'header' => $h->__('Import'), 'index' => 'status', 'type' => 'options', 'width' => '100px',
            'options' => ['imported' => $h->__('Imported'), 'error' => $h->__('Error'), 'skipped' => $h->__('Not imported'), 'new' => $h->__('New')],
        ]);
        $this->addColumn('accepted_at', ['header' => $h->__('Accepted'), 'index' => 'accepted_at', 'type' => 'datetime', 'width' => '150px']);
        $this->addColumn('error', ['header' => $h->__('Message'), 'index' => 'error', 'frame_callback' => [$this, 'renderError']]);
        return parent::_prepareColumns();
    }

    public function renderOrderLink($value, $row)
    {
        if (!$row->getOrderId()) {
            return '';
        }
        return '<a href="' . $this->getUrl('*/sales_order/view', ['order_id' => $row->getOrderId()]) . '">' . $this->escapeHtml($value) . '</a>';
    }

    public function renderError($value, $row)
    {
        $html = $this->escapeHtml(mb_substr((string) $value, 0, 200));
        if ($row->getStatus() === 'error') {
            $html .= ' <a href="' . $this->getUrl('*/*/retry', ['id' => $row->getLinkId()]) . '">' . $this->__('Import again') . '</a>';
        }
        return $html;
    }

    public function getGridUrl()
    {
        return $this->getUrl('*/*/grid', ['_current' => true]);
    }

    public function getRowUrl($row)
    {
        return $row->getOrderId() ? $this->getUrl('*/sales_order/view', ['order_id' => $row->getOrderId()]) : false;
    }
}
