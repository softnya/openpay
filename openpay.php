<?php
/**
 * @package     ZOOcart
 * @version     3.3.15
 * @author      Softnya Consultores - http://softnya.com
 * @license     GNU General Public License v2 or later
 */

defined('_JEXEC') or die;

class plgZoocart_PaymentOpenpay extends JPaymentDriver {

	/**
	 * Path to the log file
	 *
	 * @var string
	 */
	protected $log_file = 'zoolanders/zoocart-openpay.log.php';

	public function getPaymentFee($data = array()) {
		if ($this->params->get('fee_type' ,'net') == 'perc') {
			$perc = ((float) $this->params->get('fee', 0)) / 100;

			if ($data['order']) {
				return ($data['order']->subtotal-$data['order']->payment) * $perc;
			} else {
				$total  = $this->app->zoocart->cart->getTotal($this->app->user->get()->id)
						- $this->app->zoocart->cart->getPaymentFee();
				return $total * $perc;
			}
		}

		return (float) $this->params->get('fee', 0);
	}

	protected function getRenderData($data = array()) {

		$data = parent::getRenderData($data);
		$data['test'] = $this->params->get('test', 0);

		$data['account'] = $this->params->get('account', '');
		$data['privatekey'] = $this->params->get('privatekey', '');
		$data['publickey'] = $this->params->get('publickey', '');
		$data['description'] = $this->params->get('description', '');

		$zoo = App::getInstance('zoo');
		$data['currency'] = $zoo->zoocart->currency->getDefaultCurrency()->code;
		$data['oid'] = $data['order']->id;
		$data['item_number'] = $data['order']->id;
		$data['item_name'] = JText::_('PLG_ZOOCART_ORDER') . ' ' . $data['order']->id;
		$data['amount'] = $data['order']->total;
		$data['tax']	= $data['order']->tax_total;
		$data['return_url'] = JURI::current();//$zoo->zoocart->payment->getReturnUrl();
		$data['cancel_url'] = $zoo->zoocart->payment->getCancelUrl();

		return $data;
	}

	public function zoocartCallback(&$data = array()) {

		$app = App::getInstance('zoo');
		$data = $app->data->create($data);

		$id = (int) $data->get('custom', null);
		if($id) {
			$order = $app->zoocart->table->orders->get($id);
		} else {
			$order = $data->get('order', null);
		}

		$this->log('########################');
		$this->log('1. ORDER ID: ', $id);
		$this->log('2. ORDER DATA: ', $order);
		$this->log('3. POST DATA: ', $data);

		// Check against frauds
		$valid = $this->isValidIPN($data);

		if ($valid) {
			$valid = ($data->get('txn_type') == 'web_accept' || $data->get('parent_txn_id'));

			if($valid) {
				$valid = (bool) $order->id;
			}

			$this->log('5. IPN TYPE: ', $data->get('txn_type'));

			$mc_gross = floatval(abs($data->get('mc_gross', 0)));

			// Check that total is correct
			if ($valid) {
				$total = $order->getTotal();
				// A positive value means "payment". The prices MUST match!
				// Important: NEVER, EVER compare two floating point values for equality.
				$valid = ($total - $mc_gross) < 0.01;
			}

			$this->log('6. ORDER TOTAL: ', $total);
			$this->log('7. PAYPAL TOTAL: ', $mc_gross);

			if (!$valid) {
				$status = JPaymentDriver::ZC_PAYMENT_FAILED;
				$this->log('8. FINISH STATUS: ', $status);
				$data->errorMessage = 'PLG_ZOOCART_PAYMENT_PAYPAL_IPN_IS_NOT_VALID';
			} else {
				$this->log('8. FINISH STATUS: ', $data->get('payment_status'));
				// Check the payment_status
				switch($data->get('payment_status'))
				{
					case 'Canceled_Reversal':
						$status = JPaymentDriver::ZC_PAYMENT_CANCELED;
						break;

					case 'Processed':
					case 'Completed':
						$status = JPaymentDriver::ZC_PAYMENT_PAYED;
						break;

					case 'Created':
					case 'Pending':
						$status = JPaymentDriver::ZC_PAYMENT_PENDING;
						break;

					case 'Voided':
						$status = JPaymentDriver::ZC_PAYMENT_VOIDED;
						break;

					case 'Refunded':
						$status = JPaymentDriver::ZC_PAYMENT_REFUNDED;
						break;

					case 'Denied':
					case 'Expired':
					case 'Failed':
					case 'Reversed':
					default:
						$status = JPaymentDriver::ZC_PAYMENT_FAILED;
						break;
				}

				// check the duplicated transaction
				foreach ($order->getPayments() as $payment) {
					if ($payment->transaction_id == $data->get('txn_id') && $payment->status == $status) {
						return array(); // return if found duplicated payment
					}
				}

				$data->errorMessage = array('PLG_ZOOCART_PAYMENT_PAYPAL_PAYMENT_STATUS', $data->get('payment_status'));
			}

			return array('status' => $status, 'transaction_id' => $data->get('txn_id'), 'order_id' => $order->id, 'total' => $order->getTotal());
		}

		$data->errorMessage = 'PLG_ZOOCART_PAYMENT_PAYPAL_IPN_IS_NOT_VALID';

		return array('status' => JPaymentDriver::ZC_PAYMENT_FAILED, 'transaction_id' => $data->get('txn_id'), 'order_id' => $order->id, 'total' => $order->getTotal());
	}

	/**
	 * Validates the incoming data against PayPal's IPN to make sure this is not a
	 * fraudelent request.
	 *
	 * Code credits goes to Nicholas from Akeebabackup.com
	 */
	private function isValidIPN($data)
	{
		if ($this->params->get('test', 0)) {
			$url = 'https://www.sandbox.paypal.com/cgi-bin/webscr';
			$host = 'www.sandbox.paypal.com';
		} else {
			$url = 'https://www.paypal.com/cgi-bin/webscr';
			$host = 'www.paypal.com';
		}

		$exclude_vars = array('option', 'Itemid', 'controller', 'task', 'app_id', 'format', 'payment_method');

		$req = 'cmd=_notify-validate';
		foreach($data as $key => $value) {
			if(!in_array($key, $exclude_vars)) {
				$value = urlencode($value);
				$req .= "&$key=$value";
			}
		}

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $req);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array("Host: $host", 'User-Agent: '.$_SERVER['HTTP_USER_AGENT']));
		$res = curl_exec($ch);
		curl_close($ch);

		$this->log('4. Valid response: ', $res);

		if (!$res) {
			// HTTP ERROR
			return false;
		} else {
			if (strcmp ($res, "VERIFIED") == 0) {
				return true;
			} else if (strcmp ($res, "INVALID") == 0) {
				return false;
			}
		}

		return false;
	}

	/**
	 * Log the values
	 *
	 * @param        $text  - description of values
	 * @param string $value - value to log
	 */
	protected function log($text, $value = '') {

		if (!$this->params->get('log', 0)) {
			return;
		}

		jimport('joomla.log.log');
		JLog::addLogger(array('text_file' => $this->log_file), JLog::ALL & ~JLog::WARNING);

		JLog::add($text.' '.($value ? json_encode($value) : ''));
	}
}