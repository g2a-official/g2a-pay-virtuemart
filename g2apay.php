<?php
/**
 * @author    G2A Team
 * @copyright Copyright (c) 2016 G2A.COM
 * @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
defined('_JEXEC') or die('Restricted access');

require_once 'g2apay' . DIRECTORY_SEPARATOR . 'G2APayClient.php';
require_once 'g2apay' . DIRECTORY_SEPARATOR . 'G2APayRest.php';
if (!class_exists('vmPSPlugin')) {
    require JPATH_VM_PLUGINS . DIRECTORY_SEPARATOR . 'vmpsplugin.php';
}

class plgVmPaymentG2apay extends vmPSPlugin
{
    const PRODUCTION_URL                   = 'https://checkout.pay.g2a.com/index/';
    const SANDBOX_URL                      = 'https://checkout.test.pay.g2a.com/index/';
    const STATUS_CANCELED                  = 'canceled';
    const STATUS_COMPLETE                  = 'complete';
    const STATUS_REFUNDED                  = 'refunded';
    const STATUS_PARTIALY_REFUNDED         = 'partial_refunded';
    const LOCALHOST                        = '127.0.0.1';
    const DIR_HELPERS                      = 'helpers';
    const DIR_MODELS                       = 'models';
    const G2APAY_IPN_TABLE_NAME            = 'g2apay_ipn';
    const G2APAY_REFUND_HISTORY_TABLE_NAME = 'g2apay_refund_history';
    const VIRTUEMART_COUNTRIES_TABLE_NAME  = 'virtuemart_countries';
    const VIRTUEMART_STATES_TABLE_NAME     = 'virtuemart_states';

    private $currentMethod;
    /**
     * plgVmPaymentG2apay constructor.
     * @param $subject
     * @param $config
     */
    public function __construct(&$subject, $config)
    {
        parent::__construct($subject, $config);

        $this->_loggable   = true;
        $this->_tablepkey  = 'id';
        $this->_tableId    = 'id';
        $this->tableFields = array_keys($this->getTableSQLFields());

        $varsToPush = array(
            'apihash'       => array('', 'char'),
            'apisecret'     => array('', 'char'),
            'merchantemail' => array('', 'char'),
            'sandbox'       => array(0, 'int'),

            'status_pending'          => array('', 'char'),
            'status_success'          => array('', 'char'),
            'status_canceled'         => array('', 'char'),
            'status_refunded'         => array('', 'char'),
            'status_partial_refunded' => array('', 'char'),

            'payment_currency'     => array(0, 'int'),
            'cost_per_transaction' => array(0, 'int'),
            'cost_percent_total'   => array(0, 'int'),
            'tax_id'               => array(0, 'int'),
        );

        $this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
    }

    /**
     * Create the table for this plugin if it does not yet exist.
     *
     * @return string
     */
    public function getVmPluginCreateTableSQL()
    {
        return $this->createTableSQL('G2APay Table');
    }

    /**
     * Fields to create the G2APay Table.
     *
     * @return array
     */
    public function getTableSQLFields()
    {
        return array(
            'id'                          => 'int(11) UNSIGNED NOT NULL AUTO_INCREMENT',
            'virtuemart_order_id'         => 'int(1) UNSIGNED',
            'order_number'                => 'char(64)',
            'virtuemart_paymentmethod_id' => 'mediumint(1) UNSIGNED',
            'payment_name'                => 'varchar(5000)',
            'payment_order_total'         => 'decimal(15,5) NOT NULL',
            'payment_currency'            => 'smallint(1)',
            'tax_id'                      => 'smallint(1)',
        );
    }

    /**
     * Do not show payment method on frontend even if it is published when required params are not set.
     *
     * @param VirtueMartCart $cart
     * @param int $method
     * @param array $cart_prices
     * @return bool
     */
    protected function checkConditions($cart, $method, $cart_prices)
    {
        if (empty($method->apisecret) || empty($method->apihash) || empty($method->merchantemail)) {
            return false;
        }

        return true;
    }

    /**
     * @param $cart
     * @param $order
     * @return bool|null|void
     */
    public function plgVmConfirmedOrder($cart, $order)
    {
        if (!($this->currentMethod = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {
            return; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement($this->currentMethod->payment_element)) {
            return false;
        }

        if (!class_exists('VirtueMartModelOrders')) {
            require VMPATH_ADMIN . DIRECTORY_SEPARATOR . self::DIR_MODELS . DIRECTORY_SEPARATOR . 'orders.php';
        }
        if (!class_exists('VirtueMartModelCurrency')) {
            require VMPATH_ADMIN . DIRECTORY_SEPARATOR . self::DIR_MODELS . DIRECTORY_SEPARATOR . 'currency.php';
        }
        if (!class_exists('TableVendors')) {
            require JPATH_VM_ADMINISTRATOR . DIRECTORY_SEPARATOR . 'tables' . DIRECTORY_SEPARATOR . 'vendors.php';
        }

        $postVars                                = $this->prepareVarsArray($order);

        $orderDetails                            = $order['details']['BT'];
        $dbValues                                = array();
        $dbValues['order_number']                = $orderDetails->order_number;
        $dbValues['payment_name']                = 'G2A Pay';
        $dbValues['virtuemart_paymentmethod_id'] = $cart->virtuemart_paymentmethod_id;
        $dbValues['payment_currency']            = $orderDetails->user_currency_id;
        $dbValues['payment_order_total']         = $orderDetails->order_total;
        $dbValues['tax_id']                      = $this->currentMethod->tax_id;
        $this->storePSPluginInternalData($dbValues);

        /** @var $client G2APayClient */
        $client = new G2APayClient($this->getPaymentUrl() . 'createQuote');
        $client->setMethod(G2APayClient::METHOD_POST);
        $response = $client->request($postVars);
        try {
            if (empty($response['token'])) {
                throw new Exception('Empty Token');
            }
            header('Location: ' . $this->getPaymentUrl() . 'gateway?token=' . $response['token']);
        } catch (Exception $ex) {
            $urlParams = array(
                'option' => 'com_virtuemart',
                'view'   => 'cart',
                'Itemid' => vRequest::getInt('Itemid'),
            );
            $app = JFactory::getApplication();
            $app->redirect($this->createUrl($urlParams), 'Some error occurs processing payment', 'error');
        }

        $cart->_confirmDone   = false;
        $cart->_dataValidated = false;
        $cart->setCartIntoSession();
    }

    /**
     * @param $currency_id
     * @return mixed
     */
    public function getCurrencyObject($currency_id)
    {
        $currency_model = VmModel::getModel('currency');

        return $currency_model->getCurrency($currency_id);
    }

    /**
     * @param $shipmentmethod_id
     * @return mixed
     */
    public function getShipmentMethodObject($shipmentmethod_id)
    {
        $shipmentmethod_model = VmModel::getModel('shipmentmethod');

        return $shipmentmethod_model->getShipment($shipmentmethod_id);
    }

    /**
     * Return price in correct format.
     *
     * @param $amount
     * @return float
     */
    public static function getValidAmount($amount)
    {
        return number_format((float) $amount, 2, '.', '');
    }

    /**
     * This method prepare array which is send to G2A Pay based on order, payment configuration
     * and customer address.
     *
     * @param $order
     * @return array
     */
    private function prepareVarsArray($order)
    {
        $order_details     = $order['details']['BT'];
        $return_url_params = array(
            'option'   => 'com_virtuemart',
            'view'     => 'pluginresponse',
            'task'     => 'pluginresponsereceived',
            'on'       => $order_details->order_number,
            'pm'       => $order_details->virtuemart_paymentmethod_id,
            'DR'       => '{DR}',
        );
        $cancel_url_params = array(
            'option'   => 'com_virtuemart',
            'view'     => 'pluginresponse',
            'task'     => 'pluginUserPaymentCancel',
            'on'       => $order_details->order_number,
            'pm'       => $order_details->virtuemart_paymentmethod_id,
            'DR'       => '{DR}',
        );
        $return_url  =  $this->createUrl($return_url_params);
        $cancel_url  = $this->createUrl($cancel_url_params);
        $vars        = array(
            'api_hash'    => $this->currentMethod->apihash,
            'hash'        => $this->calculateHash($order_details),
            'order_id'    => $order_details->virtuemart_order_id,
            'amount'      => self::getValidAmount($order_details->order_total),
            'currency'    => $this->getCurrencyObject($order_details->order_currency)->currency_code_3,
            'url_failure' => $cancel_url,
            'url_ok'      => $return_url,
            'items'       => $this->getItemsArray($order),
            'addresses'   => $this->generateAddressesArray($order),
        );

        return $vars;
    }

    /**
     * @param $httpParams
     * @return string
     */
    public function createUrl($httpParams = array())
    {
        return JROUTE::_(JURI::root() . 'index.php?' . http_build_query($httpParams));
    }

    /**
     * @param $order
     * @return array
     */
    private function generateAddressesArray($order)
    {
        $addresses        = array();
        $billing_address  = $order['details']['BT'];
        $shipping_address = $order['details']['ST'];

        $addresses['billing'] = array(
            'firstname' => $billing_address->first_name,
            'lastname'  => $billing_address->last_name,
            'line_1'    => $billing_address->address_1,
            'line_2'    => is_null($billing_address->address_2) ? '' : $billing_address->address_2,
            'zip_code'  => $billing_address->zip,
            'company'   => is_null($billing_address->company) ? '' : $billing_address->company,
            'city'      => $billing_address->city,
            'county'    => $this->getStateNameById($billing_address->virtuemart_state_id),
            'country'   => $this->getCountryCodeById($billing_address->virtuemart_country_id),
        );

        if (isset($shipping_address)) {
            $addresses['shipping'] = array(
                'firstname' => $shipping_address->first_name,
                'lastname'  => $shipping_address->last_name,
                'line_1'    => $shipping_address->address_1,
                'line_2'    => is_null($shipping_address->address_2) ? '' : $shipping_address->address_2,
                'zip_code'  => $shipping_address->zip,
                'company'   => is_null($shipping_address->company) ? '' : $shipping_address->company,
                'city'      => $shipping_address->city,
                'county'    => $this->getStateNameById($shipping_address->virtuemart_state_id),
                'country'   => $this->getCountryCodeById($shipping_address->virtuemart_country_id),
            );

            return $addresses;
        }

        $addresses['shipping'] = $addresses['billing'];

        return $addresses;
    }

    /**
     * @param $coutryId
     * @return string
     */
    private function getCountryCodeById($coutryId)
    {
        $db    = JFactory::getDbo();
        $query = $db->getQuery(true);
        $query->select('country_2_code');
        $query->from('#__' . self::VIRTUEMART_COUNTRIES_TABLE_NAME);
        $query->where($db->quoteName('virtuemart_country_id') . ' = ' . $coutryId);
        $db->setQuery($query);
        $result = null;
        $result = $db->loadAssoc();

        if (!empty($result['country_2_code'])) {
            return $result['country_2_code'];
        }
    }

    /**
     * @param $stateId
     * @return string
     */
    private function getStateNameById($stateId)
    {
        $db    = JFactory::getDbo();
        $query = $db->getQuery(true);
        $query->select('state_name');
        $query->from('#__' . self::VIRTUEMART_STATES_TABLE_NAME);
        $query->where($db->quoteName('virtuemart_state_id') . ' = ' . $stateId);
        $db->setQuery($query);
        $result = null;
        $result = $db->loadAssoc();

        if (!empty($result['state_name'])) {
            return $result['state_name'];
        }
    }

    /**
     * @param $params
     * @return string
     */
    public function calculateHash($params)
    {
        if ($params instanceof stdClass) {
            $unhashedString = $params->virtuemart_order_id
                . self::getValidAmount($params->order_total)
                . $this->getCurrencyObject($params->order_currency)->currency_code_3 . $this->currentMethod->apisecret;
        } else {
            $unhashedString = $params['transactionId'] . $params['userOrderId'] . $params['amount']
                . $this->currentMethod->apisecret;
        }

        return hash('sha256', $unhashedString);
    }

    /**
     * @param $order
     * @return array
     */
    public function getItemsArray($order)
    {
        $itemsInfo  = array();
        foreach ($order['items'] as $orderItem) {
            $productUrl = JROUTE::_(JURI::root() . $orderItem->link);

            $itemsInfo[] = array(
                'sku'    => $orderItem->product_sku,
                'name'   => $orderItem->order_item_name,
                'amount' => self::getValidAmount($orderItem->product_subtotal_with_tax),
                'qty'    => (integer) $orderItem->product_quantity,
                'id'     => $orderItem->virtuemart_order_item_id,
                'price'  => self::getValidAmount($orderItem->product_final_price),
                'url'    => $productUrl,
            );
        }

        $orderDetails    = $order['details']['BT'];
        $itemsInfo[]     = array(
            'sku'    => $orderDetails->virtuemart_shipmentmethod_id,
            'name'   => $this->getShipmentMethodObject($orderDetails->virtuemart_shipmentmethod_id)->shipment_name,
            'amount' => self::getValidAmount($orderDetails->order_shipment + $orderDetails->order_shipment_tax),
            'qty'    => 1,
            'id'     => $orderDetails->virtuemart_shipmentmethod_id,
            'price'  => self::getValidAmount($orderDetails->order_shipment + $orderDetails->order_shipment_tax),
            'url'    => $this->createUrl(),
        );

        //$orderDetails->coupon_discount === 0.00 if there is no aplied discount coupon otherwise contains coupon
        //discount value for example -1.50
        if ($orderDetails->coupon_discount < 0) {
            $itemsInfo[] = array(
                'sku'    => '1',
                'name'   => $orderDetails->coupon_code,
                'amount' => self::getValidAmount($orderDetails->coupon_discount),
                'qty'    => 1,
                'id'     => '1',
                'price'  => self::getValidAmount($orderDetails->coupon_discount),
                'url'    => $this->createUrl(),
            );
        }

        return $itemsInfo;
    }

    /**
     * @param $virtuemart_paymentmethod_id
     * @param $paymentCurrencyId
     * @return bool|void
     */
    public function plgVmgetPaymentCurrency($virtuemart_paymentmethod_id, &$paymentCurrencyId)
    {
        if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
            return; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement($method->payment_element)) {
            return false;
        }
        $this->getPaymentCurrency($method);
        $paymentCurrencyId = $method->payment_currency;
    }

    /**
     * @param $html
     * @return null|string
     */
    public function plgVmOnPaymentResponseReceived(&$html)
    {
        if (!class_exists('VirtueMartCart')) {
            require JPATH_VM_SITE . DIRECTORY_SEPARATOR . self::DIR_HELPERS . DIRECTORY_SEPARATOR . 'cart.php';
        }
        if (!class_exists('shopFunctionsF')) {
            require JPATH_VM_SITE . DIRECTORY_SEPARATOR . self::DIR_HELPERS . DIRECTORY_SEPARATOR . 'shopfunctionsf.php';
        }
        if (!class_exists('VirtueMartModelOrders')) {
            require JPATH_VM_ADMINISTRATOR . DIRECTORY_SEPARATOR . self::DIR_MODELS . DIRECTORY_SEPARATOR . 'orders.php';
        }

        $virtuemart_paymentmethod_id = vRequest::getInt('pm', 0);
        $order_number                = vRequest::getString('on', 0);

        if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
            return; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement($method->payment_element)) {
            return;
        }
        if (!($virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($order_number))) {
            return;
        }
        if (!($payments = $this->getDatasByOrderNumber($order_number))) {
            return '';
        }

        $cart = VirtueMartCart::getCart();
        $cart->emptyCart();

        return true;
    }

    /**
     * @return bool|void
     */
    public function plgVmOnUserPaymentCancel()
    {
        if (!class_exists('VirtueMartModelOrders')) {
            require JPATH_VM_ADMINISTRATOR . DIRECTORY_SEPARATOR . self::DIR_MODELS . DIRECTORY_SEPARATOR . 'orders.php';
        }

        $order_number                = vRequest::getString('on', '');
        $virtuemart_paymentmethod_id = vRequest::getInt('pm', '');
        if (empty($order_number) || empty($virtuemart_paymentmethod_id)
            || !$this->selectedThisByMethodId($virtuemart_paymentmethod_id)) {
            return;
        }
        if (!($virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($order_number))) {
            return;
        }
        if (!($paymentTable = $this->getDataByOrderNumber($order_number))) {
            return;
        }

        VmInfo(vmText::_('VMPAYMENT_G2APAY_PAYMENT_CANCELLED'));

        $this->handlePaymentUserCancel($virtuemart_order_id);

        return true;
    }

    /**
     * @param $virtuemart_order_id
     * @param $payment_method_id
     */
    public function plgVmOnShowOrderBEPayment($virtuemart_order_id, $payment_method_id)
    {
        if (!$this->selectedThisByMethodId($payment_method_id)) {
            return; // Another method was selected, do nothing
        }
        $orderModel   = VmModel::getModel('orders');
        $order        = $orderModel->getOrder($virtuemart_order_id);
        $orderDetails = $order['details']['BT'];
        $jInput       = JFactory::getApplication()->input;
        $output       = '';
        if ($amount = $jInput->getString('g2apay_refund_amount')) {
            $amount = str_replace(',', '.', $amount);
            $this->proceedRefund($orderDetails, self::getValidAmount($amount));
        }
        $refundForm = '<td valign="top">
                <form method="post" action="' . JUri::getInstance()->toString() . '">
                <table class="adminlist table" cellspacing="0" cellpadding="0">
						<thead>
						<tr><th colspan="2">G2A Pay Refund</th></tr>
						</thead>
					    <tbody><tr>
						<td>Amount to refund: </td>
		            <td><input type="text" id="g2apay_refund_amount" name="g2apay_refund_amount" required/></td></tr>
                    <tr><td colspan="2"><input style="margin-bottom: 5px" type="submit" value="Proceed Refund"></td></tr>
                    </tbody></table>
                </form></td>';
        $orderRefundHistoryTable = $this->createRefundHistoryTable($virtuemart_order_id);
        $this->currentMethod     = $this->getVmPluginMethod($orderDetails->virtuemart_paymentmethod_id);
        if ($orderDetails->order_status === $this->currentMethod->status_refunded && empty($orderRefundHistoryTable)) {
            return;
        }
        $output .= '</td></tr><tr>';
        if ($orderDetails->order_status !== $this->currentMethod->status_refunded
            && $this->getTransactionId($orderDetails->virtuemart_order_id)) {
            $output .= $refundForm;
        }
        $output .= $orderRefundHistoryTable;
        $output .= '</tr>';
        echo $output;
    }

    /**
     * @param $orderId
     * @return string
     */
    public function createRefundHistoryTable($orderId)
    {
        $refundHistoryTable = '';

        $db    = JFactory::getDbo();
        $query = $db->getQuery(true);
        $query->select('*');
        $query->from($db->quoteName(self::G2APAY_REFUND_HISTORY_TABLE_NAME));
        $query->where($db->quoteName('order_id') . ' = ' . $orderId);
        $db->setQuery($query);
        $results = null;
        $results = $db->loadAssocList();

        if (!$results) {
            return $refundHistoryTable;
        }

        $refundHistoryTable =
            '<td valign="top" width="50%"><table class="adminlist table">
                <caption><strong>G2A Pay Refund History: </strong></caption>
	            <thead>
		            <tr>
                        <th class="title">Id</th>
                        <th class="title">Refund amount</th>
                        <th class="title">Refund date</th>
		            </tr>
	            </thead>
	        <tbody>';

        foreach ($results as $result) {
            $refundHistoryTable .= '<tr>
                    <td>' . $result['id'] . '</td>
                    <td>' . self::getValidAmount($result['refunded_amount']) . '</td>
                    <td>' . $result['refund_date'] . '</td>
		        </tr>';
        }

        $refundHistoryTable .= '</tbody></table></td>';

        return $refundHistoryTable;
    }

    /**
     * @param $orderData
     * @param $amount
     */
    private function proceedRefund($orderData, $amount)
    {
        $app = JFactory::getApplication();
        try {
            $transactionId = '';
            if ($amount <= 0) {
                throw new Exception('Invalid amount. ');
            }
            $transactionId = $this->getTransactionId($orderData->virtuemart_order_id);
            if (!$transactionId) {
                throw new Exception('Can not proceed with refund. Order payment not confirmed by IPN');
            }
            if ($amount > self::getValidAmount($orderData->order_total)) {
                throw new Exception('Refund amount can not be greater than order total price');
            }
            $this->currentMethod = $this->getVmPluginMethod($orderData->virtuemart_paymentmethod_id);
            $restClient          = new G2APayRest($transactionId, $this->currentMethod);
            if (!$restClient->refundOrder($orderData, $amount)) {
                throw new Exception('Some error occurred processing online refund for amount: ' . $amount);
            }
            $app->redirect(JUri::getInstance()->toString(),
                JText::_('Online refund successfully executed for amount: ' . $amount), 'success');
        } catch (Exception $e) {
            $app->redirect(JUri::getInstance()->toString(), JText::_($e->getMessage()), 'error');
        }
    }

    /**
     * @param $orderId
     * @return string|bool
     */
    public function getTransactionId($orderId)
    {
        $db    = JFactory::getDbo();
        $query = $db->getQuery(true);
        $query->select($db->quoteName(array('transaction_id')));
        $query->from($db->quoteName(self::G2APAY_IPN_TABLE_NAME));
        $query->where($db->quoteName('order_id') . ' = ' . $orderId);
        $db->setQuery($query);
        $result = $db->loadAssoc();

        return isset($result['transaction_id']) ? $result['transaction_id'] : false;
    }

    /**
     * @return bool|mixed
     */
    public function plgVmOnPaymentNotification()
    {
        if (!class_exists('VirtueMartModelOrders')) {
            require VMPATH_ADMIN . DIRECTORY_SEPARATOR . self::DIR_MODELS . DIRECTORY_SEPARATOR . 'orders.php';
        }

        if ($_SERVER['REQUEST_METHOD'] !== G2APayClient::METHOD_POST) {
            $this->logInfo('Invalid request method');

            return false;
        }
        $postParams = $this->createArrayOfRequestParams();
        $orderId    = $postParams['userOrderId'];

        if (!isset($orderId)) {
            return false;
        }

        if (!($payments = $this->getDatasByOrderId($orderId))) {
            return false;
        }

        $this->currentMethod = $this->getVmPluginMethod($payments[0]->virtuemart_paymentmethod_id);
        if (!$this->selectedThisElement($this->currentMethod->payment_element)) {
            return false;
        }

        $orderModel = VmModel::getModel('orders');
        $order      = $orderModel->getOrder($orderId);

        if (!$this->isRequestValid($postParams, $order)) {
            return $this->processRequest($orderId, $this->currentMethod->status_canceled, $orderModel);
        }
        if (!$this->isCalculatedHashMatch($postParams)) {
            return $this->processRequest($orderId, $this->currentMethod->status_pending, $orderModel);
        }
        if (isset($postParams['status']) && $postParams['status'] === self::STATUS_COMPLETE) {
            $this->saveIpn($orderId, $postParams['transactionId']);

            return $this->processRequest($orderId, $this->currentMethod->status_success, $orderModel);
        }
        if (isset($postParams['status']) && $postParams['status'] === self::STATUS_PARTIALY_REFUNDED) {
            $this->saveRefund($orderId, self::getValidAmount($postParams['refundedAmount']));

            return $this->processRequest($orderId, $this->currentMethod->status_partial_refunded, $orderModel);
        }
        if (isset($postParams['status']) && $postParams['status'] === self::STATUS_REFUNDED) {
            $this->saveRefund($orderId, self::getValidAmount($postParams['refundedAmount']));

            return $this->processRequest($orderId, $this->currentMethod->status_refunded, $orderModel);
        }
    }

    /**
     * @param $orderId
     * @param $transactionId
     */
    public function saveIpn($orderId, $transactionId)
    {
        $db      = JFactory::getDbo();
        $columns = array('order_id', 'transaction_id');
        $values  = array($db->quote($orderId), $db->quote($transactionId));
        $query   = $db->getQuery(true);
        $query->insert($db->quoteName(self::G2APAY_IPN_TABLE_NAME))
            ->columns($db->quoteName($columns))
            ->values(implode(',', $values));
        $db->setQuery($query)->execute();
    }

    /**
     * @param $orderId
     * @param $refundedAmount
     */
    public function saveRefund($orderId, $refundedAmount)
    {
        $db      = JFactory::getDbo();
        $columns = array('order_id', 'refunded_amount');
        $values  = array($db->quote($orderId), $db->quote($refundedAmount));
        $query   = $db->getQuery(true);
        $query->insert($db->quoteName(self::G2APAY_REFUND_HISTORY_TABLE_NAME))
            ->columns($db->quoteName($columns))
            ->values(implode(',', $values));
        $db->setQuery($query)->execute();
    }

    /**
     * @param $orderId
     * @param $status
     * @param $orderModel
     * @return mixed
     */
    private function processRequest($orderId, $status, $orderModel)
    {
        $order['order_status']      = $status;
        $order['comments']          = '';
        $order['customer_notified'] = 1;
        $orderModel->updateStatusForOneOrder($orderId, $order, true);

        $this->emptyCart(null, $orderId);

        return true;
    }

    /**
     * Modify request from G2A Pay to array format.
     *
     * @return array
     */
    private function createArrayOfRequestParams()
    {
        $vars   = array();
        $filter = JFilterInput::getInstance();
        foreach ($_REQUEST as $key => $value) {
            $key        = $filter->clean($key);
            $value      = vRequest::getString($key);
            $vars[$key] = $value;
        }

        return $vars;
    }

    /**
     * @param $vars
     * @param $orderDb
     * @return bool
     */
    private function isRequestValid($vars, $orderDb)
    {
        if (!$this->comparePrices($vars, $orderDb)) {
            $this->logInfo('Price does not match');

            return false;
        }
        if ($vars['status'] === self::STATUS_CANCELED) {
            $this->logInfo(self::STATUS_CANCELED);

            return false;
        }

        return true;
    }

    /**
     * @param $vars
     * @param $orderDb
     * @return bool
     */
    private function comparePrices($vars, $orderDb)
    {
        //if it's partial refund prices don't have to match
        if (isset($vars['status']) && $vars['status'] === self::STATUS_PARTIALY_REFUNDED) {
            return true;
        }
        $price = self::getValidAmount($orderDb['details']['BT']->order_total);
        if (isset($vars['amount']) && $vars['amount'] == $price) {
            return true;
        }

        return false;
    }

    /**
     * @param $vars
     * @return bool
     */
    private function isCalculatedHashMatch($vars)
    {
        if ($this->calculateHash($vars) !== $vars['hash']) {
            $this->logInfo('Calculated hash does not match');

            return false;
        }

        return true;
    }

    /**
     * @param $jplugin_id
     * @return mixed
     */
    public function plgVmOnStoreInstallPaymentPluginTable($jplugin_id)
    {
        $db  = JFactory::getDbo();
        $sql = 'CREATE TABLE IF NOT EXISTS ' . self::G2APAY_IPN_TABLE_NAME . ' (
        id INT NOT NULL AUTO_INCREMENT,
        order_id INT NOT NULL,
        transaction_id varchar(70) NOT NULL,
        PRIMARY KEY (id)
        )';
        $db->setQuery($sql)->execute();
        $sql = 'CREATE TABLE IF NOT EXISTS ' . self::G2APAY_REFUND_HISTORY_TABLE_NAME . ' (
        id INT NOT NULL AUTO_INCREMENT,
        order_id INT NOT NULL,
        refunded_amount VARCHAR(15) NOT NULL,
        refund_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ,
        PRIMARY KEY (id)
        )';
        $db->setQuery($sql)->execute();

        return $this->onStoreInstallPluginTable($jplugin_id);
    }

    /**
     * @param VirtueMartCart $cart
     * @return mixed
     */
    public function plgVmOnSelectCheckPayment(VirtueMartCart $cart)
    {
        return $this->OnSelectCheck($cart);
    }

    /**
     * @param VirtueMartCart $cart
     * @param int $selected
     * @param $htmlIn
     * @return mixed
     */
    public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected = 0, &$htmlIn)
    {
        return $this->displayListFE($cart, $selected, $htmlIn);
    }

    /**
     * @param VirtueMartCart $cart
     * @param array $cart_prices
     * @param $cart_prices_name
     * @return mixed
     */
    public function plgVmonSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name)
    {
        return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
    }

    /**
     * @param VirtueMartCart $cart
     * @param array $cart_prices
     * @param $paymentCounter
     * @return mixed
     */
    public function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices = array(),
                                                         &$paymentCounter)
    {
        return $this->onCheckAutomaticSelected($cart, $cart_prices,  $paymentCounter);
    }

    /**
     * @param $virtuemart_order_id
     * @param $virtuemart_paymentmethod_id
     * @param $payment_name
     */
    public function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name)
    {
        $this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
    }

    /**
     * @param $order_number
     * @param $method_id
     * @return mixed
     */
    public function plgVmonShowOrderPrintPayment($order_number, $method_id)
    {
        return $this->onShowOrderPrint($order_number, $method_id);
    }

    /**
     * @param $name
     * @param $id
     * @param $data
     * @return mixed
     */
    public function plgVmDeclarePluginParamsPayment($name, $id, &$data)
    {
        return $this->declarePluginParams('payment', $name, $id, $data);
    }

    /**
     * @param $name
     * @param $id
     * @param $table
     * @return mixed
     */
    public function plgVmSetOnTablePluginParamsPayment($name, $id, &$table)
    {
        return $this->setOnTablePluginParams($name, $id, $table);
    }

    /**
     * @param $data
     * @return mixed
     */
    public function plgVmDeclarePluginParamsPaymentVM3(&$data)
    {
        return $this->declarePluginParams('payment', $data);
    }

    /**
     * @return string
     */
    public function getPaymentUrl()
    {
        if ($this->currentMethod->sandbox) {
            return self::SANDBOX_URL;
        }

        return self::PRODUCTION_URL;
    }
}
