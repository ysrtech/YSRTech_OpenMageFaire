<?php

class YSRTech_Faire_Helper_Data extends Mage_Core_Helper_Abstract
{
    protected $_moduleName = 'YSRTech_Faire';

    public const LOG_FILE = 'ysrtech_faire.log';

    /**
     * A setting of a website (each brand has its own Faire account).
     */
    public function getConfig($path, $website)
    {
        return Mage::app()->getWebsite($website)->getConfig('ysrtech_faire/' . $path);
    }

    public function getFlag($path, $website)
    {
        return (bool) (int) $this->getConfig($path, $website);
    }

    public function getSecret($path, $website)
    {
        $value = (string) $this->getConfig($path, $website);
        return $value !== '' ? trim((string) Mage::helper('core')->decrypt($value)) : '';
    }

    /**
     * Websites connected to Faire.
     *
     * @return Mage_Core_Model_Website[]
     */
    public function getEnabledWebsites()
    {
        $websites = [];
        foreach (Mage::app()->getWebsites() as $website) {
            if ($this->getFlag('api/enabled', $website) && $this->hasCredentials($website)) {
                $websites[(int) $website->getId()] = $website;
            }
        }
        return $websites;
    }

    public function hasCredentials($website)
    {
        if ($this->getConfig('api/auth', $website) === 'oauth') {
            return $this->getSecret('api/app_id', $website) !== '' && $this->getSecret('api/app_secret', $website) !== ''
                && $this->getSecret('api/oauth_token', $website) !== '';
        }
        return $this->getSecret('api/access_token', $website) !== '';
    }

    /**
     * Store view whose names, descriptions and images go to Faire.
     */
    public function getContentStore($website)
    {
        $website = Mage::app()->getWebsite($website);
        $id = (int) $this->getConfig('catalog/store', $website);
        return $id ? Mage::app()->getStore($id) : $website->getDefaultStore();
    }

    /**
     * Store view Faire orders are placed in.
     */
    public function getOrderStore($website)
    {
        $website = Mage::app()->getWebsite($website);
        $id = (int) $this->getConfig('orders/store', $website);
        return $id ? Mage::app()->getStore($id) : $website->getDefaultStore();
    }

    /**
     * ISO alpha-3 (Faire) → alpha-2 (OpenMage).
     */
    public function countryFromIso3($iso3)
    {
        $iso3 = strtoupper(trim((string) $iso3));
        if (strlen($iso3) === 2) {
            return $iso3;
        }
        $country = Mage::getModel('directory/country')->getCollection()->addFieldToFilter('iso3_code', $iso3)->getFirstItem();
        return $country->getId() ?: null;
    }

    public function countryToIso3($iso2)
    {
        $country = Mage::getModel('directory/country')->loadByCode(strtoupper((string) $iso2));
        return $country->getIso3Code() ?: null;
    }

    public function toMinor($amount)
    {
        return (int) round((float) $amount * 100);
    }

    /**
     * Command line (cron, shell): building an order reads the store currency from the session,
     * which can only start before any output. Start it first.
     */
    public function initCliSession()
    {
        if (PHP_SAPI === 'cli' && session_status() === PHP_SESSION_NONE && !headers_sent()) {
            Mage::getSingleton('core/session', ['name' => 'frontend']);
        }
    }

    public function log($message, $level = Zend_Log::INFO)
    {
        Mage::log(rtrim((string) $message), $level, self::LOG_FILE, true);
    }
}
