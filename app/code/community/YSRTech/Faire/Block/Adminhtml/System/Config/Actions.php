<?php

/**
 * Configuration page (website scope): test the connection, sync now.
 */
class YSRTech_Faire_Block_Adminhtml_System_Config_Actions extends Mage_Adminhtml_Block_System_Config_Form_Field
{
    protected function _getElementHtml(Varien_Data_Form_Element_Abstract $element)
    {
        $h = Mage::helper('ysrtech_faire');
        $website = (string) $this->getRequest()->getParam('website');
        if ($website === '') {
            return '<p>' . $this->escapeHtml($h->__('Switch to the website scope (top left) to test and sync.')) . '</p>';
        }
        $url = Mage::getSingleton('adminhtml/url')->getUrl('adminhtml/ysrtech_faire/run');
        $formKey = Mage::getSingleton('core/session')->getFormKey();
        $buttons = '';
        foreach (['test' => $h->__('Test Connection'), 'products' => $h->__('Send Products'), 'inventory' => $h->__('Send Stock'), 'orders' => $h->__('Import Orders'), 'shipments' => $h->__('Send Tracking')] as $type => $label) {
            $buttons .= sprintf('<button type="button" class="scalable" style="margin:0 6px 6px 0" onclick="ysrtechFaireRun(\'%s\')"><span>%s</span></button>', $type, $this->escapeHtml($label));
        }
        $websiteJs = Mage::helper('core')->jsonEncode($website);
        return <<<HTML
{$buttons}
<pre id="ysrtech_faire_output" style="display:none;margin-top:6px;background:#1c1c1c;color:#ddd;padding:10px;max-height:320px;overflow:auto;white-space:pre-wrap;font-size:12px"></pre>
<script type="text/javascript">
//<![CDATA[
window.ysrtechFaireRun = function (type) {
  var out = \$('ysrtech_faire_output'); out.show().update('…');
  new Ajax.Request('{$url}', {
    parameters: {form_key: '{$formKey}', type: type, website: {$websiteJs}},
    onSuccess: function (r) { var j = r.responseJSON || {}; out.update((j.output || r.responseText).escapeHTML()); },
    onFailure: function (r) { out.update('HTTP ' + r.status); }
  });
};
//]]>
</script>
HTML;
    }
}
