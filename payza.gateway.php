<?php
/**
 * Payza Payment Gateway
 *
 * @author Abid Omar
 */
class wp_payza_gateway
{
	/**
	 * The Payza Merchant Email
	 * @var string
	 */
	public $merchant_id;

	/**
	 * Purchase Type (item, service, subscription...)
	 * @var string
	 */
	public $purchase_type;

	/**
	 * Return and Status URL
	 * @var string
	 */
	public $return_url;

	/**
	 * Cancel URL
	 * @var string
	 */
	public $cancel_url;

	/**
	 * IPN URL
	 * @var string
	 */
	public $ipn_url;

	/**
	 * Sandbox mode for Payza
	 * @var int
	 */
	public $sandbox = false;

	/**
	 * Purchase Description
	 * @var string
	 */
	public $description = null;

	/**
	 * Transaction total amount
	 * @var int
	 */
	public $amount;

	/**
	 * Transaction currency
	 * @var string
	 */
	public $currency;

	/**
	 * Payza real Payment URL
	 * @var string
	 */
	public $real_api_url = 'https://secure.payza.com/checkout';

	/**
	 * Payza sandbox Payment URL
	 * @var string
	 */
	public $sandbox_api_url = 'https://sandbox.payza.com/sandbox/payprocess.aspx';

	/**
	 *
	 */
	public $test_mode;

	/**
	 * Checkout URL
	 *
	 * @var string
	 */
	private $checkout_url;

	/**
	 * Saves error info.
	 * @var
	 */
	public $debug_info;


	/**
	 * Creates a new instance of the Payza Class
	 *
	 * @param string $merchant_id Merchant Email Address
	 * @param string $purchase_type Purchase Type (usually item)
	 * @param string $currency Currency
	 * @param string $return_url Return URL
	 * @param string $cancel_url Cancel URL
	 * @param string $ipn_url IPN URL
	 * @param boolean $sandbox_mode (false)
	 * @param string $description Description (null)
	 */
	function __construct($merchant_id, $purchase_type, $currency, $return_url, $cancel_url, $ipn_url, $sandbox_mode = false, $description = null)
	{
		// Fill the Class Properties
		$this->merchant_id = $merchant_id;
		$this->purchase_type = $purchase_type;
		$this->currency = $currency;
		$this->return_url = $return_url;
		$this->cancel_url = $cancel_url;
		$this->ipn_url = $ipn_url;
		$this->sandbox = $sandbox_mode;
		$this->description = $description;
		$this->test_mode = $sandbox_mode;

		$this->checkout_url = $this->real_api_url;
	}

	/**
	 * Creates a new transaction and returns the redirect URL
	 *
	 * @param integer $transaction_id
	 * @param mixed $cart_details
	 * @return string $redirect_url
	 */
	public function transaction($transaction_id, $cart_details = false)
	{
		// Required Details
		$args = array(
			'ap_merchant'     => $this->merchant_id,
			'ap_purchasetype' => $this->purchase_type,
			'ap_currency'     => $this->currency,
			'ap_returnurl'    => $this->return_url,
			'ap_cancelurl'    => $this->cancel_url,
			'apc_1'           => $transaction_id, // Transaction ID
			'ap_alerturl'     => $this->ipn_url, //$this->ipn_url,//$this->ipn_url, // IPN URL
			'ap_ipnversion'   => 2 // IPN Version 2.0
		);

		if ( $this->test_mode ) {
			$args['ap_testmode'] = 1;
		}

		// Cart Details
		if ($cart_details) {
			$payment      = edd_get_payment( $transaction_id );
			$cart_details = $this->get_cart_details($cart_details);

			$cart_details['ap_taxamount'] = $payment->tax;
			$args = array_merge($cart_details, $args);
		} else {
			$this->debug_info = 'Cart Details empty';
			return false;
		}

		// Build the redirect URL
		$query_str = http_build_query( apply_filters( 'edd_payza_transaction_args', $args ) );
		$redirect_url = $this->checkout_url . '?' . $query_str;

		return $redirect_url;
	}

	/**
	 * Format the Easy Digital Download Cart details to a format
	 * compatible with the Payza payment gateway
	 *
	 * @param array $cart_details
	 * @return array $formatted_cart
	 */
	private function get_cart_details($cart_details)
	{
		$formatted_cart = array();
		$key = 1;
		foreach( $cart_details as $i => $item ) {
			$item_amount = $item['item_price'] - ( $item['discount'] / $item['quantity'] );

			if( $item_amount <= 0 ) {
				$item_amount = 0;
			}

			$formatted_cart['ap_itemname_' . $key]  = $item['name'];
			$formatted_cart['ap_quantity_' . $key]  = $item['quantity'];
			$formatted_cart['ap_amount_' . $key]    = $item_amount;
			$key++;
		}
		return $formatted_cart;
	}
}

/**
 * IPN Handler
 *
 * @author Abid Omar
 */
class wp_payza_ipn
{
	/**
	 * Currency
	 * @var string
	 */
	public $currency;

	/**
	 * Request time out
	 *
	 * @var integer
	 */
	public $timeout;

	/**
	 * SSL Verification
	 *
	 * @var boolean
	 */
	public $sslverify;

	/**
	 * Debug Information
	 *
	 * @var string
	 */
	public $debug_info;

	/**
	 * Real IPN Handler
	 *
	 * @var string
	 */
	private $real_ipn_handler = 'https://secure.payza.com/ipn2.ashx';

	/**
	 * Sandbox IPN Handler
	 *
	 * @var string
	 */
	private $sandbox_ipn_handler = 'https://sandbox.Payza.com/sandbox/IPN2.ashx';

	/**
	 * IPN Handler
	 *
	 * @var string
	 */
	private $ipn_handler;

	/**
	 * Creates a new instance of the IPN Class
	 *
	 * @param string $currency
	 * @param bool $sandbox_mode
	 */
	function __construct($currency = "USD", $sandbox_mode = false)
	{
		// Sandbox Mode
		$this->ipn_handler = $this->real_ipn_handler;
		if ($sandbox_mode) {
			$this->ipn_handler = $this->sandbox_ipn_handler;
		}

		// Currency
		$this->currency = $currency;

		// SSL Verification
		$this->sslverify = apply_filters('https_local_ssl_verify', false);
	}

	/**
	 * Handle IPN request
	 *
	 * @static
	 * @param string $token
	 * @return boolean $success
	 */
	public function handle_ipn($token)
	{
		$response = $this->retrieve_response($token);
		if (strlen($response) > 0 && $response != 'INVALID TOKEN') {
			// Extract Data
			parse_str($response, $data);
			$transaction_id = $data['apc_1']; // Transaction ID
			$currency = $data['ap_currency']; // Currency
			$status = $data['ap_status']; // Status

			// Retrieve transaction details
			$payment_meta = get_post_meta($transaction_id, '_edd_payment_meta', true);
			$amount = edd_format_amount($payment_meta['amount']); // amount

			if ($this->currency != $currency) {
				return false;
			}

			if ($amount != $data['ap_totalamount']) {
				return false;
			}

			if ($status != 'Success') {
				return false;
			}

			return $transaction_id; // Payment Successful!
		}
		return false;
	}

	/**
	 * Retrieve the transaction details
	 *
	 * @static
	 * @param string $token Transaction Token
	 * @return string $response Transaction details
	 */
	private function retrieve_response($token)
	{
		$body = array(
			'token' => $token
		);
		$request = array(
			'body' => $body,
			'timeout' => $this->timeout,
			'sslverify' => $this->sslverify
		);
		$response = wp_remote_post($this->ipn_handler, $request);

		// HTTP Request fails
		if (is_wp_error($response)) {
			$this->debug_info = $response;
			return false;
		}

		// Status code returned other than 200
		if ($response['response']['code'] != 200) {
			$this->debug_info = 'Response code different than 200';
			return false;
		}

		// Request succeeded, return the Session ID
		return $response['body'];
	}
}
