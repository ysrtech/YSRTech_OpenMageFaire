<?php

/**
 * Runs one sync for a website and returns its output lines (admin buttons, shell).
 */
class YSRTech_Faire_Model_Runner
{
    /**
     * @param string $type test | products | inventory | orders | shipments
     * @return string[]
     */
    public function run($type, $website, $dryRun = false, ?array $ids = null)
    {
        $lines = [];
        $output = function ($line) use (&$lines) {
            $lines[] = $line;
        };
        $website = Mage::app()->getWebsite($website);
        $helper = Mage::helper('ysrtech_faire');
        if (!$helper->hasCredentials($website)) {
            return ['No Faire credentials for website ' . $website->getCode() . '.'];
        }
        $switches = ['products' => 'catalog/enabled', 'inventory' => 'inventory/enabled', 'orders' => 'orders/enabled', 'shipments' => 'shipments/enabled'];
        $names = ['products' => 'Send Products', 'inventory' => 'Send Stock Levels', 'orders' => 'Import Orders', 'shipments' => 'Send Tracking Numbers'];
        if (isset($switches[$type]) && !$dryRun && !$helper->getFlag($switches[$type], $website)) {
            return [sprintf('"%s" is turned off for %s (System > Configuration > YSRTech > Faire). A dry run still shows what would be sent.', $names[$type], $website->getCode())];
        }
        switch ($type) {
            case 'test':
                $profile = Mage::getModel('ysrtech_faire/api')->setWebsite($website)->get('brands/profile');
                $lines[] = sprintf('Connected: %s (%s), currency %s', $profile['name'] ?? '?', $profile['brand_id'] ?? '?', $profile['currency'] ?? '?');
                break;
            case 'products':
                $stats = Mage::getModel('ysrtech_faire/catalog')->setWebsite($website)->setDryRun($dryRun)->setOutput($output)->sync($ids);
                $lines[] = sprintf('Products: %d created, %d updated, %d unchanged, %d paused, %d error(s).', $stats['created'], $stats['updated'], $stats['unchanged'], $stats['paused'], $stats['errors']);
                break;
            case 'inventory':
                Mage::getModel('ysrtech_faire/inventory')->setWebsite($website)->setDryRun($dryRun)->setOutput($output)->sync();
                break;
            case 'orders':
                Mage::getModel('ysrtech_faire/orders')->setWebsite($website)->setDryRun($dryRun)->setOutput($output)->sync();
                break;
            case 'shipments':
                [$sent, $errors] = Mage::getModel('ysrtech_faire/shipments')->setWebsite($website)->setDryRun($dryRun)->setOutput($output)->sync();
                $lines[] = sprintf('Tracking numbers: %d sent, %d error(s).', $sent, $errors);
                break;
            default:
                $lines[] = 'Unknown action.';
        }
        return $lines;
    }
}
