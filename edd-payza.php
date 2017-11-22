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
			wp_redirect( $redirect_url );
			exit;
		} else {
			edd_send_back_to_checkout( '?payment-mode=payza' );
		}
	} else {
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
		edd_debug_log( 'Payza IPN Notification:' . print_r( $_POST, true ) );

		if ( isset( $_POST['token'] ) ) {
			require_once EDD_PAYZA_PLUGIN_DIR . '/payza.gateway.php';
			$ipn_handler    = new wp_payza_ipn( edd_get_currency(), true );
			$transaction_id = $ipn_handler->handle_ipn( $_POST['token'] );
			if ( $transaction_id ) {
				edd_update_payment_status( $transaction_id, 'publish' );
			}
		} elseif ( isset( $_POST['apc_1'] ) ) {
			$payment_id = intval( $_POST['apc_1'] );
			$payment = edd_get_payment( $payment_id );

			if ( false === $payment ) {
				echo 'Invalid Payment ID';
				die();
			}

			$transaction_type = strtolower( sanitize_text_field( $_POST['ap_transactionstate'] ) );
			if ( 'completed' === $transaction_type && $payment->status !== 'publish' ) {

				$payment_total = floatval( $payment->total );
				$ipn_total     = floatval( $_POST['ap_totalamount'] );

				if ( $payment_total < $ipn_total ) {

					// If the payment total doesn't match what the IPN is sending, mark it as failed.
					$payment->add_note( sprintf( __( 'Payment failed: Payment Total was %d but IPN total was %d', 'edd-payza' ), $payment_total, $ipn_total ) );
					$payment->status = 'failed';
					$payment->save();

				} else {

					$payment->transaction_id = sanitize_text_field( $_POST['ap_referencenumber'] );
					$payment->status         = 'publish';
					$payment->save();

				}

			} elseif ( 'refunded' === $transaction_type ) {

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
