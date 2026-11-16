<?php

/**
 * Links between OpenMage and Faire (products, orders, shipments) and the product attributes
 * of the "Faire" group.
 *
 * @var Mage_Catalog_Model_Resource_Setup $installer
 */
$installer = $this;
$installer->startSetup();
$connection = $installer->getConnection();

$table = $installer->getTable('ysrtech_faire/product');
if (!$connection->isTableExists($table)) {
    $connection->createTable($connection->newTable($table)
        ->addColumn('link_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, ['identity' => true, 'unsigned' => true, 'nullable' => false, 'primary' => true])
        ->addColumn('website_id', Varien_Db_Ddl_Table::TYPE_SMALLINT, null, ['unsigned' => true, 'nullable' => false])
        ->addColumn('product_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, ['unsigned' => true, 'nullable' => false])
        ->addColumn('faire_product_id', Varien_Db_Ddl_Table::TYPE_TEXT, 32, ['nullable' => true])
        ->addColumn('variants', Varien_Db_Ddl_Table::TYPE_TEXT, '64k', ['nullable' => true], 'JSON: sku => Faire variant id')
        ->addColumn('payload_hash', Varien_Db_Ddl_Table::TYPE_TEXT, 40, ['nullable' => true])
        ->addColumn('images_hash', Varien_Db_Ddl_Table::TYPE_TEXT, 40, ['nullable' => true])
        ->addColumn('status', Varien_Db_Ddl_Table::TYPE_TEXT, 16, ['nullable' => false, 'default' => 'pending'], 'pending, synced, paused, error')
        ->addColumn('error', Varien_Db_Ddl_Table::TYPE_TEXT, '64k', ['nullable' => true])
        ->addColumn('synced_at', Varien_Db_Ddl_Table::TYPE_DATETIME, null, ['nullable' => true])
        ->addIndex($installer->getIdxName('ysrtech_faire/product', ['website_id', 'product_id'], Varien_Db_Adapter_Interface::INDEX_TYPE_UNIQUE), ['website_id', 'product_id'], ['type' => Varien_Db_Adapter_Interface::INDEX_TYPE_UNIQUE])
        ->setComment('YSRTech Faire: products'));
}

$table = $installer->getTable('ysrtech_faire/order');
if (!$connection->isTableExists($table)) {
    $connection->createTable($connection->newTable($table)
        ->addColumn('link_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, ['identity' => true, 'unsigned' => true, 'nullable' => false, 'primary' => true])
        ->addColumn('website_id', Varien_Db_Ddl_Table::TYPE_SMALLINT, null, ['unsigned' => true, 'nullable' => false])
        ->addColumn('faire_order_id', Varien_Db_Ddl_Table::TYPE_TEXT, 32, ['nullable' => false])
        ->addColumn('display_id', Varien_Db_Ddl_Table::TYPE_TEXT, 32, ['nullable' => true])
        ->addColumn('faire_state', Varien_Db_Ddl_Table::TYPE_TEXT, 40, ['nullable' => true])
        ->addColumn('retailer', Varien_Db_Ddl_Table::TYPE_TEXT, 255, ['nullable' => true])
        ->addColumn('order_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, ['unsigned' => true, 'nullable' => true])
        ->addColumn('increment_id', Varien_Db_Ddl_Table::TYPE_TEXT, 50, ['nullable' => true])
        ->addColumn('total', Varien_Db_Ddl_Table::TYPE_TEXT, 32, ['nullable' => true], 'e.g. 123.40 USD')
        ->addColumn('status', Varien_Db_Ddl_Table::TYPE_TEXT, 16, ['nullable' => false, 'default' => 'new'], 'imported, error, skipped')
        ->addColumn('error', Varien_Db_Ddl_Table::TYPE_TEXT, '64k', ['nullable' => true])
        ->addColumn('faire_created_at', Varien_Db_Ddl_Table::TYPE_DATETIME, null, ['nullable' => true])
        ->addColumn('imported_at', Varien_Db_Ddl_Table::TYPE_DATETIME, null, ['nullable' => true])
        ->addColumn('accepted_at', Varien_Db_Ddl_Table::TYPE_DATETIME, null, ['nullable' => true])
        ->addColumn('updated_at', Varien_Db_Ddl_Table::TYPE_DATETIME, null, ['nullable' => true])
        ->addIndex($installer->getIdxName('ysrtech_faire/order', ['faire_order_id'], Varien_Db_Adapter_Interface::INDEX_TYPE_UNIQUE), ['faire_order_id'], ['type' => Varien_Db_Adapter_Interface::INDEX_TYPE_UNIQUE])
        ->addIndex($installer->getIdxName('ysrtech_faire/order', ['order_id']), ['order_id'])
        ->setComment('YSRTech Faire: orders'));
}

$table = $installer->getTable('ysrtech_faire/shipment');
if (!$connection->isTableExists($table)) {
    $connection->createTable($connection->newTable($table)
        ->addColumn('link_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, ['identity' => true, 'unsigned' => true, 'nullable' => false, 'primary' => true])
        ->addColumn('track_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, ['unsigned' => true, 'nullable' => false])
        ->addColumn('faire_order_id', Varien_Db_Ddl_Table::TYPE_TEXT, 32, ['nullable' => false])
        ->addColumn('faire_shipment_id', Varien_Db_Ddl_Table::TYPE_TEXT, 32, ['nullable' => true])
        ->addColumn('status', Varien_Db_Ddl_Table::TYPE_TEXT, 16, ['nullable' => false, 'default' => 'sent'], 'sent, error')
        ->addColumn('error', Varien_Db_Ddl_Table::TYPE_TEXT, '64k', ['nullable' => true])
        ->addColumn('attempts', Varien_Db_Ddl_Table::TYPE_SMALLINT, null, ['unsigned' => true, 'nullable' => false, 'default' => 0])
        ->addColumn('sent_at', Varien_Db_Ddl_Table::TYPE_DATETIME, null, ['nullable' => true])
        ->addIndex($installer->getIdxName('ysrtech_faire/shipment', ['track_id'], Varien_Db_Adapter_Interface::INDEX_TYPE_UNIQUE), ['track_id'], ['type' => Varien_Db_Adapter_Interface::INDEX_TYPE_UNIQUE])
        ->setComment('YSRTech Faire: shipments sent'));
}

// product attributes, group "Faire" in every attribute set
$common = [
    'group'        => 'Faire',
    'required'     => false,
    'user_defined' => true,
    'visible'      => true,
    'searchable'   => false,
    'filterable'   => false,
    'comparable'   => false,
    'visible_on_front' => false,
    'used_in_product_listing' => false,
];
$attributes = [
    'faire_enabled' => [
        'type' => 'int', 'input' => 'select', 'label' => 'Sell on Faire',
        'source' => 'eav/entity_attribute_source_boolean', 'default' => 0,
        'global' => Mage_Catalog_Model_Resource_Eav_Attribute::SCOPE_WEBSITE,
        'note' => 'The product is listed on Faire for this website (YSRTech_Faire).',
    ],
    'faire_wholesale_price' => [
        'type' => 'decimal', 'input' => 'price', 'backend' => 'catalog/product_attribute_backend_price', 'label' => 'Faire Wholesale Price',
        'global' => Mage_Catalog_Model_Resource_Eav_Attribute::SCOPE_WEBSITE,
        'note' => 'Per unit. Empty: the wholesale price from the Faire settings.',
    ],
    'faire_case_size' => [
        'type' => 'int', 'input' => 'text', 'label' => 'Faire Case Size', 'class' => 'validate-digits',
        'global' => Mage_Catalog_Model_Resource_Eav_Attribute::SCOPE_GLOBAL,
        'note' => 'Units per case; retailers order in multiples. Empty: the Faire settings.',
    ],
    'faire_taxonomy_type' => [
        'type' => 'varchar', 'input' => 'text', 'label' => 'Faire Product Type',
        'global' => Mage_Catalog_Model_Resource_Eav_Attribute::SCOPE_GLOBAL,
        'note' => 'Faire taxonomy type id (tt_...). Empty: the Faire settings.',
    ],
];
foreach ($attributes as $code => $definition) {
    if (!$installer->getAttributeId(Mage_Catalog_Model_Product::ENTITY, $code)) {
        $installer->addAttribute(Mage_Catalog_Model_Product::ENTITY, $code, $definition + $common);
    }
}

$installer->endSetup();
