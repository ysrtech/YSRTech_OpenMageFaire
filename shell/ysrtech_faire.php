<?php

require_once __DIR__ . DIRECTORY_SEPARATOR . 'abstract.php';

/**
 * YSRTech_Faire from the command line.
 */
class YSRTech_Faire_Shell extends Mage_Shell_Abstract
{
    public function run()
    {
        $command = $_SERVER['argv'][1] ?? '';
        if (!in_array($command, ['test', 'products', 'inventory', 'orders', 'shipments', 'all'], true)) {
            echo $this->usageHelp();
            return;
        }
        $helper = Mage::helper('ysrtech_faire');
        $helper->initCliSession();
        $websites = $this->getArg('website')
            ? [Mage::app()->getWebsite((string) $this->getArg('website'))]
            : array_values($helper->getEnabledWebsites());
        if (!$websites) {
            fwrite(STDERR, "No website connected to Faire (or --website <code>).\n");
            exit(1);
        }
        $ids = $this->getArg('ids') ? array_map('intval', explode(',', (string) $this->getArg('ids'))) : null;
        $types = $command === 'all' ? ['products', 'inventory', 'orders', 'shipments'] : [$command];
        foreach ($websites as $website) {
            foreach ($types as $type) {
                echo "== {$website->getCode()}: {$type}\n";
                try {
                    foreach (Mage::getModel('ysrtech_faire/runner')->run($type, $website, (bool) $this->getArg('dry-run'), $ids) as $line) {
                        echo $line, "\n";
                    }
                } catch (Exception $e) {
                    echo 'Error: ', $e->getMessage(), "\n";
                }
            }
        }
    }

    public function usageHelp()
    {
        return <<<USAGE
Usage: php shell/ysrtech_faire.php <command> [--website <code>] [--dry-run] [--ids 1,2]

  test        check the connection (brand profile)
  products    send the products with "Sell on Faire" = Yes (--ids: only these)
  inventory   send stock levels
  orders      import new Faire orders, follow cancellations
  shipments   send tracking numbers
  all         products, inventory, orders and shipments

  --dry-run   show what would be sent, change nothing

USAGE;
    }
}

$shell = new YSRTech_Faire_Shell();
$shell->run();
