<?php

defined ('_JEXEC') or die('Restricted access');

/**
 *
 * @version $Id: akatua.php 5177 2014-05-06 12:44:10T $
 * @package VirtueMart
 * @subpackage payment
 * @copyright Copyright (C) 20014 Laila Alhassan - All rights reserved.
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL, see LICENSE.php
 * VirtueMart is free software. This version may have been modified pursuant
 * to the GNU General Public License, and as distributed it includes or
 * is derivative of works licensed under the GNU General Public License or
 * other free or open source software licenses.
 * See /administrator/components/com_virtuemart/COPYRIGHT.php for copyright notices and details.
 *
 * http://virtuemart.org
 */
if (!class_exists ('vmPSPlugin')) {
	require(JPATH_VM_PLUGINS . DS . 'vmpsplugin.php');
}

class plgVmPaymentAkatua extends vmPSPlugin {
	// instance of class
	public static $_this = FALSE;

	var $settings;

	function __construct (& $subject, $config) {
		parent::__construct ($subject, $config);
		$this->_loggable = TRUE;
		$this->tableFields = array_keys ($this->getTableSQLFields ());
		$this->_tablepkey = 'id';
		$this->_tableId = 'id';
		$varsToPush = array(
			'payment_logos'          => array('', 'char'),
			'akatua_application_id' => array('', 'char'),
			'akatua_application_secret' => array('', 'char'),
			'akatua_logo_url' => array('', 'char'),
			'mode' => array(0, 'int'),
			'payment_currency' => array('', 'int'),
			'debug' => array(0, 'int'),
			'status_pending' => array('', 'char'),
			'status_success' => array('', 'char'),
			'status_canceled' => array('', 'char'),
			'countries' => array('', 'char'),
			'min_amount' => array('', 'int'),
			'max_amount' => array('', 'int'),
			'cost_per_transaction' => array('', 'int'),
			'cost_percent_total' => array('', 'int'),
			'tax_id' => array(0, 'int')
		);
		$this->setConfigParameterable ($this->_configTableFieldName, $varsToPush);
	}

	public function getVmPluginCreateTableSQL () {
		return $this->createTableSQL ('Payment Akatua Table');
	}

	function getTableSQLFields () {
		$SQLfields = array(
			'id' 										=> 'int(11) UNSIGNED NOT NULL AUTO_INCREMENT',
			'virtuemart_order_id'						=> 'int(1) UNSIGNED',
			'order_number'								=> 'char(64)',
			'virtuemart_paymentmethod_id' 				=> 'mediumint(1) UNSIGNED',
			'payment_name'								=> 'varchar(5000)',
			'payment_order_total'						=> 'decimal(15,5) NOT NULL DEFAULT \'0.00000\'',
			'payment_currency'							=> 'char(3) ',
			'cost_per_transaction'						=> 'decimal(10,2)',
			'cost_percent_total'						=> 'decimal(10,2)',
			'tax_id'									=> 'smallint(1)',
			'akatua_response_transaction_id'			=> 'varchar(30)',
			'akatua_response_status'					=> 'char(16) NULL DEFAULT NULL',
			'akatua_response_raw'						=> 'varchar(512) NULL DEFAULT NULL',
		);
		return $SQLfields;
	}

	function plgVmConfirmedOrder ($cart, $order) {
		if (!($method = $this->getVmPluginMethod ($order['details']['BT']->virtuemart_paymentmethod_id))) {
			return NULL; // Another method was selected, do nothing
		}
		if (!$this->selectedThisElement ($method->payment_element)) {
			return FALSE;
		}
		$this->settings = $method;
		$session = JFactory::getSession ();
		#$return_context = $session->getId ();
		$this->_debug = $method->debug;
		$this->logInfo ('plgVmConfirmedOrder order number: ' . $order['details']['BT']->order_number, 'message');

		if (!class_exists ('VirtueMartModelOrders')) {
			require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
		}
		if (!class_exists ('VirtueMartModelCurrency')) {
			require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'currency.php');
		}

		$address = ((isset($order['details']['ST'])) ? $order['details']['ST'] : $order['details']['BT']);

		if (!class_exists ('TableVendors')) {
			require(JPATH_VM_ADMINISTRATOR . DS . 'table' . DS . 'vendors.php');
		}
		$vendorModel = VmModel::getModel ('Vendor');
		$vendorModel->setId (1);
		$vendor = $vendorModel->getVendor ();
		$vendorModel->addImages ($vendor, 1);
		$this->getPaymentCurrency ($method);
		$currency_code_3 = shopFunctions::getCurrencyByID ($method->payment_currency, 'currency_code_3');

		$paymentCurrency = CurrencyDisplay::getInstance ($method->payment_currency);
		$totalInPaymentCurrency = round ($paymentCurrency->convertCurrencyTo ($method->payment_currency, $order['details']['BT']->order_total, FALSE), 2);
		$cd = CurrencyDisplay::getInstance ($cart->pricesCurrency);
		if ($totalInPaymentCurrency <= 0) {
			vmInfo (JText::_ ('VMPAYMENT_AKATUA_PAYMENT_AMOUNT_INCORRECT'));
			return FALSE;
		}
		if (empty($method->akatua_application_id)) {
			vmInfo (JText::_ ('VMPAYMENT_AKATUA_APPLICATION_ID_NOT_SET'));
			return FALSE;
		}

		// Prepare data that should be stored in the database
		$dbValues['order_number'] = $order['details']['BT']->order_number;
		$dbValues['payment_name'] = $this->renderPluginName ($method, $order);
		$dbValues['virtuemart_paymentmethod_id'] = $cart->virtuemart_paymentmethod_id;
		$dbValues['cost_per_transaction'] = $method->cost_per_transaction;
		$dbValues['cost_percent_total'] = $method->cost_percent_total;
		$dbValues['payment_currency'] = $method->payment_currency;
		$dbValues['payment_order_total'] = $totalInPaymentCurrency;
		$dbValues['tax_id'] = $method->tax_id;
		$this->storePSPluginInternalData ($dbValues);

		$testmode = ($method->mode == "test") ? 1 : 0;
		$desc = base64_encode('Order Number ' . $order['details']['BT']->order_number);
		$time = time();

		$session = JFactory::getSession();
		$return_context = $session->getId();

		$post_variables = Array(
			'application_id'	=> $method->akatua_application_id,
			'signature'			=> hash_hmac("sha256",$method->akatua_application_id.":$desc:$time",$method->akatua_application_secret),
			'timestamp'			=> $time,
			'test_mode'			=> $testmode,
			'transaction_type'	=> "checkout",
			'description'		=> $desc,
			'amount'			=> $totalInPaymentCurrency,
			'invoice'			=> $order['details']['BT']->order_number,
			'custom1'			=> $return_context,

			'logo_url'			=> $method->akatua_logo_url,
			'success_url'		=> JROUTE::_ (JURI::root () . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&on=' . $order['details']['BT']->order_number . '&pm=' . $order['details']['BT']->virtuemart_paymentmethod_id . '&Itemid=' . JRequest::getInt ('Itemid')),
			'fail_url'			=> JROUTE::_ (JURI::root () . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginUserPaymentCancel&on=' . $order['details']['BT']->order_number . '&pm=' . $order['details']['BT']->virtuemart_paymentmethod_id . '&Itemid=' . JRequest::getInt ('Itemid')),
			'callback_url'		=> JROUTE::_ (JURI::root () . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginnotification&tmpl=component'),
		);

		$html = '<html><head><title>Redirection</title></head><body><div style="margin: auto; text-align: center;">';
		$html .= '<form action="https://secure.akatua.com/checkout" method="post" name="vm_akatua_form">';
		$html .= '<input type="submit"  value="' . JText::_ ('VMPAYMENT_AKATUA_REDIRECT_MESSAGE') . '" />';
		foreach ($post_variables as $name => $value) {
			$html .= '<input type="hidden" name="'.$name.'" value="'.$value.'" />';
		}
		$html .= '</form></div>';
		$html .= ' <script type="text/javascript">';
		$html .= ' document.vm_akatua_form.submit();';
		$html .= ' </script></body></html>';

		$cart->_confirmDone = FALSE;
		$cart->_dataValidated = FALSE;
		$cart->setCartIntoSession ();
		JRequest::setVar ('html', $html);
	}

	function plgVmgetPaymentCurrency ($virtuemart_paymentmethod_id, &$paymentCurrencyId) {
		if (!($method = $this->getVmPluginMethod ($virtuemart_paymentmethod_id))) {
			return NULL; // Another method was selected, do nothing
		}
		if (!$this->selectedThisElement ($method->payment_element)) {
			return FALSE;
		}
		$this->getPaymentCurrency ($method);
		$paymentCurrencyId = $method->payment_currency;
	}

	function plgVmOnPaymentResponseReceived (&$html) {
		if (!class_exists ('VirtueMartCart')) {
			require(JPATH_VM_SITE . DS . 'helpers' . DS . 'cart.php');
		}
		if (!class_exists ('shopFunctionsF')) {
			require(JPATH_VM_SITE . DS . 'helpers' . DS . 'shopfunctionsf.php');
		}
		if (!class_exists ('VirtueMartModelOrders')) {
			require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
		}

		$virtuemart_paymentmethod_id = JRequest::getInt ('pm', 0);
		$order_number = JRequest::getString ('on', 0);
		$vendorId = 0;
		if (!($method = $this->getVmPluginMethod ($virtuemart_paymentmethod_id))) {
			return NULL; // Another method was selected, do nothing
		}
		if (!$this->selectedThisElement ($method->payment_element)) {
			return NULL;
		}

		if (!($virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber ($order_number))) {
			return NULL;
		}
		if (!($paymentTable = $this->getDataByOrderId ($virtuemart_order_id))) {
			// JError::raiseWarning(500, $db->getErrorMsg());
			return '';
		}
		$payment_name = $this->renderPluginName ($method);
		$html = $this->_getPaymentResponseHtml ($paymentTable, $payment_name);

		$cart = VirtueMartCart::getCart ();
		$cart->emptyCart ();
		return TRUE;
	}

	function plgVmOnUserPaymentCancel () {
		if (!class_exists ('VirtueMartModelOrders')) {
			require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
		}
		$order_number = JRequest::getString ('on', '');
		$virtuemart_paymentmethod_id = JRequest::getInt ('pm', '');
		if (empty($order_number) or empty($virtuemart_paymentmethod_id) or !$this->selectedThisByMethodId ($virtuemart_paymentmethod_id)) {
			return NULL;
		}
		if (!($virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber ($order_number))) {
			return NULL;
		}
		if (!($paymentTable = $this->getDataByOrderId ($virtuemart_order_id))) {
			return NULL;
		}
		VmInfo (Jtext::_ ('VMPAYMENT_AKATUA_PAYMENT_CANCELLED'));
		$session = JFactory::getSession ();
		$return_context = $session->getId ();
		if (strcmp ($paymentTable->akatua_custom, $return_context) === 0) {
			$this->handlePaymentUserCancel ($virtuemart_order_id);
		}
		return TRUE;
	}

	function plgVmOnPaymentNotification () {

		if (!class_exists ('VirtueMartModelOrders')) {
			require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
		}
		$callback_data = JRequest::get('get');

		if (!isset($callback_data['invoice'])) {
			return NULL;
		}
		$order_number = $callback_data['invoice'];
		if (!($virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber ($callback_data['invoice']))) {
			return NULL;
		}
		$vendorId = 0;
		if (!($payments = $this->getDatasByOrderId ($virtuemart_order_id))) {
			return NULL;
		}
		$method = $this->getVmPluginMethod ($payments[0]->virtuemart_paymentmethod_id);
		if (!$this->selectedThisElement ($method->payment_element)) {
			return FALSE;
		}

		$this->settings = $method;

		if (!isset($callback_data['transaction_id'])) {
			return NULL;
		}

		$verify = $this->__verify_callback($callback_data['transaction_id']);

		if ($verify['status'] != "success") {
			$this->logInfo ('Akatua Verification Response: ' . $verify['message'], 'message');
			return NULL;
		}

		if ($callback_data['invoice'] != $verify['response']['invoice']) {
			return false;
		}

		$akatua_data = array_merge($callback_data,$verify['response']);

		$this->logInfo ('akatua_data ' . implode ('   ', $akatua_data), 'message');

		$modelOrder = VmModel::getModel('orders');
		$order = array();

		$order['customer_notified'] = 1;

		// 1. check the status is Completed
		if (strcmp($akatua_data['status'], 'completed') == 0) {
			// 2. check that transaction_id has not been previously processed
			if ($this->_check_txn_id_already_processed ($payments, $akatua_data['transaction_id'])) {
				return;
			}
			// 3. check  amount and currency is correct
			if (!$this->_check_amount_currency ($payments, $akatua_data)) {
				return;
			}
			// now we can process the payment
			$order['order_status'] = $method->status_success;
			$order['comments'] = JText::sprintf ('VMPAYMENT_AKATUA_PAYMENT_STATUS_CONFIRMED', $order_number);
		}
		elseif (strcmp($akatua_data['status'], 'pending') == 0) {
			$order['comments'] = JText::sprintf ('VMPAYMENT_AKATUA_PAYMENT_STATUS_PENDING', $order_number);
			$order['order_status'] = $method->status_pending;
		}
		elseif (isset($akatua_data['status'])) {
			$order['order_status'] = $method->status_canceled;
		}
		else {
			$order['comments'] = JText::_ ('VMPAYMENT_AKATUA_IPN_NOTIFICATION_RECEIVED');
			$order['customer_notified'] = 0;
		}

		$this->_storeInternalData ($method, $akatua_data, $virtuemart_order_id, $payments[0]->virtuemart_paymentmethod_id);
		$this->logInfo ('plgVmOnPaymentNotification return new_status:' . $order['order_status'], 'message');

		$modelOrder->updateStatusForOneOrder($virtuemart_order_id, $order, TRUE);

		if (isset($akatua_data['custom1'])) {
			$this->emptyCart($akatua_data['custom1'], $order_number);
		}
	}

	function _check_txn_id_already_processed ($payments, $txn_id) {
		foreach ($payments as $payment) {
			if ($payment->akatua_response_transaction_id == $txn_id && $payment->akatua_response_status == "completed") {
				return TRUE;
			}
		}
		return FALSE;
	}

	function _check_amount_currency ($payments, $akatua_data) {
		if ($payments[0]->payment_order_total == $akatua_data['amount']) {
			return TRUE;
		}
/*
		$currency_code_3 = shopFunctions::getCurrencyByID ($payments[0]->payment_currency, 'currency_code_3');
		if ($currency_code_3 == "GHS") {
			return TRUE;
		}
*/
		return FALSE;
	}


	/**
	 * @param $method
	 * @param $akatua_data
	 * @param $virtuemart_order_id
	 */
	function _storeInternalData ($method, $akatua_data, $virtuemart_order_id, $virtuemart_paymentmethod_id) {

		$db = JFactory::getDBO ();
		$query = 'SHOW COLUMNS FROM `' . $this->_tablename . '` ';
		$db->setQuery ($query);
		$columns = $db->loadResultArray (0);
		$post_msg = '';
		foreach ($akatua_data as $key => $value) {
			$post_msg .= $key . "=" . $value . "<br />";
			$table_key = 'akatua_response_' . $key;
			if (in_array ($table_key, $columns)) {
				$response_fields[$table_key] = $value;
			}
		}

		$response_fields['payment_name'] = $this->renderPluginName ($method);
		$response_fields['akatua_response_raw'] = $post_msg;
		$response_fields['order_number'] = $akatua_data['invoice'];
		$response_fields['virtuemart_order_id'] = $virtuemart_order_id;
		$response_fields['virtuemart_paymentmethod_id'] = $virtuemart_paymentmethod_id;

		$this->storePSPluginInternalData ($response_fields);
	}

	/**
	 * @param $virtuemart_order_id
	 * @return mixed|string
	 */
	function _getTablepkeyValue ($virtuemart_order_id) {

		$db = JFactory::getDBO ();
		$q = 'SELECT ' . $this->_tablepkey . ' FROM `' . $this->_tablename . '` '
			. 'WHERE `virtuemart_order_id` = ' . $virtuemart_order_id;
		$db->setQuery ($q);

		if (!($pkey = $db->loadResult ())) {
			JError::raiseWarning (500, $db->getErrorMsg ());
			return '';
		}
		return $pkey;
	}

	/**
	 * Display stored payment data for an order
	 *
	 * @see components/com_virtuemart/helpers/vmPSPlugin::plgVmOnShowOrderBEPayment()
	 */
	function plgVmOnShowOrderBEPayment ($virtuemart_order_id, $payment_method_id) {

		if (!$this->selectedThisByMethodId ($payment_method_id)) {
			return NULL; // Another method was selected, do nothing
		}

		if (!($payments = $this->_getInternalData ($virtuemart_order_id))) {
			// JError::raiseWarning(500, $db->getErrorMsg());
			return '';
		}

		$html = '<table class="adminlist" width="50%">' . "\n";
		$html .= $this->getHtmlHeaderBE ();
		$code = "akatua_response_";
		$first = TRUE;
		foreach ($payments as $payment) {
			$html .= '<tr class="row1"><td>' . JText::_ ('VMPAYMENT_AKATUA_DATE') . '</td><td align="left">' . $payment->created_on . '</td></tr>';
			// Now only the first entry has this data when creating the order
			if ($first) {
				$html .= $this->getHtmlRowBE ('AKATUA_PAYMENT_NAME', $payment->payment_name);
				// keep that test to have it backwards compatible. Old version was deleting that column  when receiving an IPN notification
				if ($payment->payment_order_total and  $payment->payment_order_total !=  0.00) {
					$html .= $this->getHtmlRowBE ('AKATUA_PAYMENT_ORDER_TOTAL', $payment->payment_order_total . " " . shopFunctions::getCurrencyByID ($payment->payment_currency, 'currency_code_3'));
				}
				$first = FALSE;
			}
			foreach ($payment as $key => $value) {
				// only displays if there is a value or the value is different from 0.00 and the value
				if ($value) {
					if (substr ($key, 0, strlen ($code)) == $code) {
						$html .= $this->getHtmlRowBE ($key, $value);
					}
				}
			}

		}
		$html .= '</table>' . "\n";
		return $html;
	}

	/**
	 * @param $virtuemart_order_id
	 * @param string $order_number
	 * @return mixed|string
	 */
	function _getInternalData ($virtuemart_order_id, $order_number = '') {
		$db = JFactory::getDBO ();
		$q = 'SELECT * FROM `' . $this->_tablename . '` WHERE ';
		if ($order_number) {
			$q .= " `order_number` = '" . $order_number . "'";
		} else {
			$q .= ' `virtuemart_order_id` = ' . $virtuemart_order_id;
		}
		$db->setQuery ($q);
		if (!($payments = $db->loadObjectList ())) {
			// JError::raiseWarning(500, $db->getErrorMsg());
			return '';
		}
		return $payments;
	}

	function _getPaymentResponseHtml ($akatuaTable, $payment_name) {
		$html = '<table>' . "\n";
		$html .= $this->getHtmlRow ('AKATUA_PAYMENT_NAME', $payment_name);
		if (!empty($akatuaTable)) {
			$html .= $this->getHtmlRow ('AKATUA_ORDER_NUMBER', $akatuaTable->order_number);
		}
		$html .= '</table>' . "\n";
		return $html;
	}

	protected function checkConditions ($cart, $method, $cart_prices) {
		$this->convert($method);
		$address = (($cart->ST == 0) ? $cart->BT : $cart->ST);
		$amount = $cart_prices['salesPrice'];
		$amount_cond = ($amount >= $method->min_amount AND $amount <= $method->max_amount OR ($method->min_amount <= $amount AND ($method->max_amount == 0)));
		$countries = array();
		if (!empty($method->countries)) {
			if (!is_array ($method->countries)) {
				$countries[0] = $method->countries;
			} else {
				$countries = $method->countries;
			}
		}
		if (!is_array ($address)) {
			$address = array();
			$address['virtuemart_country_id'] = 0;
		}
		if (!isset($address['virtuemart_country_id'])) {
			$address['virtuemart_country_id'] = 0;
		}
		if (in_array ($address['virtuemart_country_id'], $countries) || count ($countries) == 0) {
			if ($amount_cond) {
				return TRUE;
			}
		}
		return FALSE;
	}

	function convert ($method) {
		$method->min_amount = (float)$method->min_amount;
		$method->max_amount = (float)$method->max_amount;
	}

/*==================================== AKATUA SPECIFIC FUNCTIONS =======================================*/

	private function __verify_callback($transaction_id) {
		$url = "https://secure.akatua.com/api/v1/getTransactionDetails";

		$data['transaction_id'] = $transaction_id;
		$request = $this->__make_httprequest("GET",$url,$data);
		if ($request) {
			$json = json_decode($request,true);
			if (isset($json->success)) {
				$response['status'] = "success";
				$response['message'] = "Verification successful";
				$response['response'] = $json['response'];
				return $response;
			}
			else {
				$response['status'] = "error";
				$response['message'] = $json['errorText'];
				return $response;
			}
		}
	}

	private function __get_headers($json_data) {
		$out[] = "Content-Type: application/json";
		$out[] = "Akatua-Application-ID: ".$this->settings->akatua_application_id;
		$out[] = "Akatua-Signature: ".hash_hmac("sha256",$json_data,$this->settings->akatua_application_secret);
		return $out;
	}

	private function __make_httprequest($method="GET",$url,$data=array()) {
		$data['timestamp'] = time();
		if ($this->settings->mode == "test") $data['test_mode'] = 1;

		$json = json_encode($data);
		$headers = $this->__get_headers($json);
		$method = strtoupper($method);

		if (function_exists('curl_version') && strpos(ini_get('disable_functions'),'curl_exec') === false) {
			$ch = curl_init($url);
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
			curl_setopt($ch, CURLOPT_HEADER, false);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
			$result = curl_exec($ch);
			$error = curl_error($ch);
			if ($error) throw new Exception($error);
			curl_close($ch);
		}
		else {
			$urlbits = parse_url($url);
			$host = $urlbits['host'];
			$path = $urlbits['path'];
			$remote = fsockopen("ssl://{$host}", 443, $errno, $errstr, 30);
			if (!$remote) {
				throw new Exception("$errstr ($errno)");
			}
			$req = "{$method} {$path} HTTP/1.1\r\n";
			$req .= "Host: {$host}\r\n";
			foreach($headers as $header) {
				$req .= $header."\r\n";
			}
			$req .= "Content-Length: ".strlen($json)."\r\n";
			$req .= "Connection: Close\r\n\r\n";
			$req .= $json;
			fwrite($remote, $req);
			$response = '';
			while (!feof($remote)) {
				$response .= fgets($remote, 1024);
			}
			fclose($remote);
			$responsebits = explode("\r\n\r\n", $response, 2);
			$header = isset($responsebits[0]) ? $responsebits[0] : '';
			$result = isset($responsebits[1]) ? $responsebits[1] : '';
		}
		return $result;
	}

	function getItemName($name) {
		return substr(strip_tags($name), 0, 127);
	}

	function getProductAmount($productPricesUnformatted) {
		if ($productPricesUnformatted['salesPriceWithDiscount']) {
			return vmPSPlugin::getAmountValueInCurrency($productPricesUnformatted['salesPriceWithDiscount'], $this->settings->payment_currency);
		} else {
			return vmPSPlugin::getAmountValueInCurrency($productPricesUnformatted['salesPrice'], $this->settings->payment_currency);
		}
	}


	/**
	 * We must reimplement this triggers for joomla 1.7
	 */

	 /**
	 * Create the table for this plugin if it does not yet exist.
	 * This functions checks if the called plugin is active one.
	 * When yes it is calling the standard method to create the tables
	*/
	function plgVmOnStoreInstallPaymentPluginTable ($jplugin_id) {
		return $this->onStoreInstallPluginTable ($jplugin_id);
	}

	/**
	 * This event is fired after the payment method has been selected. It can be used to store
	 * additional payment info in the cart.
	*/
	public function plgVmOnSelectCheckPayment (VirtueMartCart $cart, &$msg) {
		return $this->OnSelectCheck ($cart);
	}

	/**
	 * plgVmDisplayListFEPayment
	 * This event is fired to display the pluginmethods in the cart (edit shipment/payment) for exampel
	*/
	public function plgVmDisplayListFEPayment (VirtueMartCart $cart, $selected = 0, &$htmlIn) {
		return $this->displayListFE ($cart, $selected, $htmlIn);
	}

	/*
	 * plgVmonSelectedCalculatePricePayment
	 * Calculate the price (value, tax_id) of the selected method
	 * It is called by the calculator
	 * This function does NOT to be reimplemented. If not reimplemented, then the default values from this function are taken.
	*/

	public function plgVmonSelectedCalculatePricePayment (VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name) {
		return $this->onSelectedCalculatePrice ($cart, $cart_prices, $cart_prices_name);
	}

	/**
	 * plgVmOnCheckAutomaticSelectedPayment
	 * Checks how many plugins are available. If only one, the user will not have the choice. Enter edit_xxx page
	 * The plugin must check first if it is the correct type
	 */
	function plgVmOnCheckAutomaticSelectedPayment (VirtueMartCart $cart, array $cart_prices = array(), &$paymentCounter) {
		return $this->onCheckAutomaticSelected ($cart, $cart_prices, $paymentCounter);
	}

	/**
	 * This method is fired when showing the order details in the frontend.
	 * It displays the method-specific data.
	 */
	public function plgVmOnShowOrderFEPayment ($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name) {
		$this->onShowOrderFE ($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
	}

	/**
	 * This method is fired when showing when priting an Order
	 * It displays the the payment method-specific data.
	 */
	function plgVmonShowOrderPrintPayment ($order_number, $method_id) {
		return $this->onShowOrderPrint ($order_number, $method_id);
	}

	/**
	 * This method is fired when showing the order details in the frontend, for every orderline.
	 * It can be used to display line specific package codes, e.g. with a link to external tracking and
	 * tracing systems
	*/
	function plgVmDeclarePluginParamsPayment ($name, $id, &$data) {
		return $this->declarePluginParams ('payment', $name, $id, $data);
	}

	function plgVmSetOnTablePluginParamsPayment ($name, $id, &$table) {
		return $this->setOnTablePluginParams ($name, $id, $table);
	}
}
// No closing tag
