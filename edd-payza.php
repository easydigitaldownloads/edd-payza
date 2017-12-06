<?php
/*
Plugin Name: Easy Digital Downloads - Payza Payment Gateway
Plugin URL: http://easydigitaldownloads.com/extension/payza
Description: Adds a payment gateway for payza.com.com / Payza to Easy Digital Downloads
Version: 1.0.5
Author: Abid Omar and Pippin Williamson
Contributors: abidomar, mordauk
Author URI: http://omarabid.com
*/

if ( !defined( 'EDD_PAYZA_PLUGIN_DIR' ) ) {
	define( 'EDD_PAYZA_PLUGIN_DIR', dirname( __FILE__ ) );
}
// plugin folder url
if ( !defined( 'EDD_PAYZA_PLUGIN_URL' ) ) {
	define( 'EDD_PAYZA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

if( class_exists( 'EDD_License' ) && is_admin() ) {
	$license = new EDD_License( __FILE__, 'Payza Payment Gateway', '1.0.5', 'Pippin Williamson' );
}

/**
 * Registers the payment gateway
 *
 * @param array   $gateways
 * @return array $gateways
 */
function edd_payza_register_gateway( $gateways ) {
	$gateways['payza'] = array( 'admin_label' => 'Payza', 'checkout_label' => __( 'Payza', 'eddap' ) );
	return $gateways;
}
add_filter( 'edd_payment_gateways', 'edd_payza_register_gateway' );

/**
 * Remove the default credit card form
 */
add_action( 'edd_payza_cc_form', '__return_false' );


/**
 * Register the payment icon
 */
function edd_payza_payment_icon( $icons ) {
	$icons[EDD_PAYZA_PLUGIN_URL . '/payzaicon.png'] = 'Payza';
	return $icons;
}
add_filter( 'edd_accepted_payment_icons', 'edd_payza_payment_icon' );


/**
 * Process the payment through Payza
 *
 * @param array   $purchase_data
 */
function edds_process_payza_payment( $purchase_data ) {
	global $edd_options;

	edd_debug_log( 'EDD Payza - Process Payment Log #1. The edds_process_payza_payment function is running for ' . $purchase_data['user_email'] );

	// record the pending payment
	$payment_data = array(
		'price' => $purchase_data['price'],
		'date' => $purchase_data['date'],
		'user_email' => $purchase_data['user_email'],
		'purchase_key' => $purchase_data['purchase_key'],
		'currency' => edd_get_currency(),
		'downloads' => $purchase_data['downloads'],
		'cart_details' => $purchase_data['cart_details'],
		'user_info' => $purchase_data['user_info'],
		'status' => 'pending'
	);

	// Inserts a new payment
	$payment = edd_insert_payment( $payment_data );

	if ( $payment ) {
		require_once 'payza.gateway.php';

		edd_debug_log( 'EDD Payza - Process Payment Log #2. A pending payment record was generated for ' . $purchase_data['user_email'] );

		// Request details
		$merchant_id = trim( $edd_options[ 'payza_merchant_id' ] );
		$currency    = edd_get_currency();
		$return_url  = edd_get_success_page_uri( '?payment-confirmation=payza' );
		$cancel_url  = edd_get_failed_transaction_uri();
		$ipn_url     = home_url( 'index.php' ) . '?edd-listener=PAYZA_IPN';

		// Create a new instance of the mb class
		$payza = new wp_payza_gateway ( $merchant_id, 'item', $currency, $return_url, $cancel_url, $ipn_url, edd_is_test_mode() );

		// Get a new session ID
		$redirect_url = $payza->transaction( $payment, $purchase_data['cart_details'] );

		if ( $redirect_url ) {
			// Redirects the user
			edd_debug_log( 'EDD Payza - Process Payment Log #3. The customer (' . $purchase_data['user_email'] . ') was redirected to Payza to complete payment: ' . $redirect_url );
			wp_redirect( $redirect_url );
			exit;
		} else {
			edd_debug_log( 'EDD Payza - Process Payment Log #3. The customer (' . $purchase_data['user_email'] . ') was redirected back to the checkout page on this website.' );
			edd_send_back_to_checkout( '?payment-mode=payza' );
		}
	} else {
		edd_debug_log( 'EDD Payza - Process Payment Log #2. The customer (' . $purchase_data['user_email'] . ') was redirected back to the checkout page on this website.' );
		edd_send_back_to_checkout( '?payment-mode=payza' );
	}
}

add_action( 'edd_gateway_payza', 'edds_process_payza_payment' );

/**
 * Confirm the Payment through IPN
 *
 */
function edds_confirm_payza_payment() {
	if ( isset( $_GET['edd-listener'] ) && $_GET['edd-listener'] === 'PAYZA_IPN' ) {

		edd_debug_log( 'EDD Payza - IPN Notification Log #1. The edds_confirm_payza_payment function is running. The POST data from Payza is: ' . print_r( $_POST, true ) );

		if ( isset( $_POST['token'] ) ) {
			require_once EDD_PAYZA_PLUGIN_DIR . '/payza.gateway.php';
			$ipn_handler    = new wp_payza_ipn( edd_get_currency(), true );
			$transaction_id = $ipn_handler->handle_ipn( $_POST['token'] );

			if ( $transaction_id ) {
				edd_debug_log( 'EDD Payza - IPN Notification Log #11. Setting status of payment ' . $transaction_id . ' to "publish"' );
				edd_update_payment_status( $transaction_id, 'publish' );
			} else{
				edd_debug_log( 'EDD Payza - IPN Notification Log #11. The Transaction/Payment ID was not set.' );
			}
		} elseif ( isset( $_POST['apc_1'] ) ) {
			$payment_id = intval( $_POST['apc_1'] );
			$payment = edd_get_payment( $payment_id );

			if ( false === $payment ) {
				edd_debug_log( 'EDD Payza - IPN Notification Log #2. The payment ID returned from Payza did not match any payment in EDD. The payment ID value from Payza in the IPN is: ' . $_POST['apc_1'] . '.' );
				echo 'Invalid Payment ID';
				die();
			}

			$transaction_type = strtolower( sanitize_text_field( $_POST['ap_transactionstate'] ) );
			if ( 'completed' === $transaction_type && $payment->status !== 'publish' ) {

				$payment_total = floatval( $payment->total );
				$ipn_total     = floatval( $_POST['ap_totalamount'] );

				if ( $payment_total < $ipn_total ) {
					edd_debug_log( 'EDD Payza - IPN Notification Log #2. ' . sprintf( __( 'Payment failed: Payment Total was %d but IPN total was %d', 'edd-payza' ), $payment_total, $ipn_total )  );
					// If the payment total doesn't match what the IPN is sending, mark it as failed.
					$payment->add_note( sprintf( __( 'Payment failed: Payment Total was %d but IPN total was %d', 'edd-payza' ), $payment_total, $ipn_total ) );
					$payment->status = 'failed';
					$payment->save();

				} else {

					edd_debug_log( 'EDD Payza - IPN Notification Log #2. IPN successfully confirmed. Setting status of payment ' . $payment->ID . ' to "publish"' );

					$payment->transaction_id = sanitize_text_field( $_POST['ap_referencenumber'] );
					$payment->status         = 'publish';
					$payment->save();

				}

			} elseif ( 'refunded' === $transaction_type ) {

				edd_debug_log( 'EDD Payza - IPN Notification Log #2. IPN successfully confirmed. Setting status of payment ' . $payment->ID . ' to "refunded"' );

				$payment->status = 'refunded';
				$payment->save();

			}

		}
		die();
	}
}
add_action( 'init', 'edds_confirm_payza_payment' );

/**
 * Register our settings section
 *
 * @return array
 */
function edd_payza_settings_section( $sections ) {
	$sections['edd-payza'] = __( 'Payza', 'eddap' );

	return $sections;
}
add_filter( 'edd_settings_sections_gateways', 'edd_payza_settings_section' );

/**
 * Adds the Payza Settings form
 *
 * @param array   $settings
 * @return array $settings
 */
function edd_payza_add_settings( $settings ) {
	$ap_settings = array(
		array(
			'id'   => 'payza_settings',
			'name' => '<strong>' . __( 'Payza Gateway Settings', 'eddap' ) . '</strong>',
			'desc' => __( 'Configure your Payza Settings', 'eddap' ),
			'type' => 'header',
		),
		array(
			'id'   => 'payza_merchant_id',
			'name' => __( 'Merchant Email Address', 'eddap' ),
			'desc' => __( 'Enter your Payza merchant Email', 'eddap' ),
			'type' => 'text',
			'size' => 'regular',
		)
	);

	if ( version_compare( EDD_VERSION, 2.5, '>=' ) ) {
		$ap_settings = array( 'edd-payza' => $ap_settings );
	}

	return array_merge( $settings, $ap_settings );
}

add_filter( 'edd_settings_gateways', 'edd_payza_add_settings' );
