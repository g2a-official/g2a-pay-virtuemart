<?php
/**
 * @author    G2A Team
 * @copyright Copyright (c) 2016 G2A.COM
 * @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
defined('_JEXEC') or die('Direct Access to ' . basename(__FILE__) . 'is not allowed.');

jimport('joomla.form.formfield');
class JFormFieldG2aipnurl extends JFormField
{
    public $type = 'g2aipnurl';

    public function getInput()
    {
        $url_params = array(
            'option' => 'com_virtuemart',
            'view'   => 'pluginresponse',
            'task'   => 'pluginnotification',
            'tmpl'   => 'component',
        );
        $ipn_url = JURI::root() . 'index.php?' . http_build_query($url_params, '', '&amp;');
        $msg     = '';
        $msg .= '<div>';
        $msg .= '<strong>' . vmText::_('VMPAYMENT_G2APAY_CONF_DYNAMIC_RETURN_URL') . '</strong>';
        $msg .= '<br />';
        $msg .= '<input class="required" readonly size="180" value="' . $ipn_url . '" />';
        $msg .= '</div>';

        return $msg;
    }
}
