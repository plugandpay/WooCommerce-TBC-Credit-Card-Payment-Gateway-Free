<?php
/**
 * Plugin Name: WooCommerce TBC Credit Card Payment Gateway (Free)
 * Plugin URI:  https://plugandpay.ge/product/woocommerce-tbc-credit-card-payment-gateway-v1/
 * Description: Accept Visa/Mastercard payments in your WooCommerce shop using TBC gateway.
 * Version:     1.0.2
 * Author:      Plug and Pay Ltd.
 * Author URI:  https://plugandpay.ge/
 * License:     GPL2
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Domain Path: /languages
 * Text Domain: woo-tbc
 * WC requires at least: 3.0.0
 * WC tested up to: 3.4.4

 * Intellectual Property rights, and copyright, reserved by Plug and Pay, Ltd. as allowed by law include,
 * but are not limited to, the working concept, function, and behavior of this software,
 * the logical code structure and expression as written.
 *
 * @package     WooCommerce TBC Credit Card Payment Gateway (Free)
 * @author      Plug and Pay Ltd. https://plugandpay.ge/
 * @copyright   Copyright (c) Plug and Pay Ltd. (support@plugandpay.ge)
 * @since       1.0.3
 * @license     http://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Composer autoload.
require __DIR__ . '/vendor/autoload.php';

use WeAreDe\TbcPay\TbcPayProcessor;

/**
 * Register the payment gateway.
 *
 * @since 1.0.0
 * @param array $gateways Payment gateways.
 */
function add_woo_gateway_tbc_class( $gateways ) {
	$gateways[] = 'WC_Gateway_TBC';
	return $gateways;
}
add_filter( 'woocommerce_payment_gateways', 'add_woo_gateway_tbc_class' );

/**
 * Initialize class on plugins_loaded.
 */
function init_woo_gateway_tbc() {

	/**
	 * Tbc credit card payment gateway class
	 *
	 * @class   WC_Gateway_TBC
	 * @extends WC_Payment_Gateway
	 */
	class WC_Gateway_TBC extends WC_Payment_Gateway {

		/**
		 * @var boolean Enabled or disable logging
		 * @static
		 */
		public static $log_enabled = false;

		/**
		 * @var boolean WC_Logger instance
		 * @static
		 */
		public static $log = false;

		/**
		 * Constructor.
		 */
		function __construct() {
			$this->id                 = 'tbc';
			$this->has_fields         = false;
			$this->order_button_text  = __( 'Proceed to TBC', 'woo-tbc' );
			$this->method_title       = __( 'TBC (Free)', 'woo-tbc' );
			$this->method_description = __( 'Accept Visa/Mastercard payments in your WooCommerce shop using TBC gateway.', 'woo-tbc' );
			$this->supports           = array(
				'products',
			);

			$this->init_form_fields();
			$this->init_settings();

			$this->title       = $this->get_option( 'title' );
			$this->description = $this->get_option( 'description' );
			$this->debug       = 'yes' === $this->get_option( 'debug', 'no' );
			$this->cert_path   = $this->get_option( 'cert_path' );
			$this->cert_pass   = $this->get_option( 'cert_pass' );
			$this->ok_slug     = $this->get_option( 'ok_slug' );
			$this->fail_slug   = $this->get_option( 'fail_slug' );

			self::$log_enabled = $this->debug;

			add_action( 'admin_notices', array( $this, 'admin_notices' ) );
			add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'order_details' ) );
			add_action( 'woocommerce_api_redirect_to_payment_form', array( $this, 'redirect_to_payment_form' ) );
			add_action( 'woocommerce_api_' . $this->ok_slug, array( $this, 'return_from_payment_form_ok' ) );
			add_action( 'woocommerce_api_' . $this->fail_slug, array( $this, 'return_from_payment_form_fail' ) );
			add_action( 'woocommerce_api_close_business_day', array( $this, 'close_business_day' ) );
			add_action( 'woocommerce_api_is_wearede', array( $this, 'is_wearede_plugin' ) );
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

			$this->Tbc             = new TbcPayProcessor( $this->cert_path, $this->cert_pass, $_SERVER['REMOTE_ADDR'] );
			$this->Tbc->submit_url = sprintf( 'https://%s.ufc.ge:18443/ecomm2/MerchantHandler', $this->get_option( 'merchant_host' ) );
		}

		/**
		 * Create a log entry
		 *
		 * @param string $message
		 * @uses  WC_Gateway_TBC::$log_enabled
		 * @uses  WC_Gateway_TBC::$log
		 * @static
		 */
		public static function log( $message ) {
			if ( self::$log_enabled ) {
				if ( empty( self::$log ) ) {
					self::$log = new WC_Logger();
				}
				self::$log->add( 'tbc', $message );
			}
		}

		/**
		 * Initialise gateway settings
		 */
		public function init_form_fields() {
			$this->form_fields = include( 'includes/gateway-settings.php' );
		}

		/**
		 * Display notices in admin dashboard
		 *
		 * Check if required parameters: cert_path and cert_pass are set.
		 * Display errors notice if they are missing,
		 * both of these parameters are required for correct functioning of the plugin.
		 * Check happens only when plugin is enabled not to clutter admin interface.
		 *
		 * @return null|void
		 */
		public function admin_notices() {
			if ( $this->enabled == 'no' ) {
				return;
			}

			// Check for required parameters
			if ( ! $this->cert_path ) {
				echo '<div class="error"><p>' . sprintf( __( 'Tbc error: Please enter certificate path <a href="%s">here</a>', 'woo-tbc' ), admin_url( 'admin.php?page=wc-settings&tab=checkout&section=wc_gateway_tbc') ) . '</p></div>';
			}

			if ( ! $this->cert_pass ) {
				echo '<div class="error"><p>' . sprintf( __( 'Tbc error: Please enter certificate passphrase <a href="%s">here</a>', 'woo-tbc' ), admin_url( 'admin.php?page=wc-settings&tab=checkout&section=wc_gateway_tbc') ) . '</p></div>';
			}
		}

		/**
		 * Convert currency code to number
		 *
		 * e.g. USD -> 840
		 *
		 * @param  string $code currency code
		 * @return string currency number
		 */
		public function get_iso4217_number( $code ) {
			$iso4217  = new Alcohol\ISO4217();
			$currency = $iso4217->getByAlpha3( $code );
			return $currency['numeric'];
		}

		/**
		 * Process the payment
		 *
		 * This runs on ajax call from checkout page, when user clicks pay button
		 *
		 * @param  integer $order_id
		 * @uses   WC_Gateway_TBC::get_iso4217_number()
		 * @uses   WC_Gateway_TBC::get_payment_form_url()
		 * @return array
		 */
		public function process_payment( $order_id ) {
			$order    = wc_get_order( $order_id );
			$currency = $order->get_currency() ? $order->get_currency() : get_woocommerce_currency();
			$amount   = $order->get_total();

			// Special data transformation for Tbc API
			$this->Tbc->amount      = $amount * 100;
			$this->Tbc->currency    = $this->get_iso4217_number( $currency );
			$this->Tbc->description = sprintf( __( '%s - Order %s', 'woo-tbc' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $order->get_id() );
			$this->Tbc->language    = strtoupper( substr( get_bloginfo('language'), 0, -3 ) );

			// Log order details
			$this->log( sprintf( __( 'Info ~ Order id: %s - amount: %s (%s) %s (%s), language: %s.', 'woo-tbc' ), $order->get_id(), $amount, $this->Tbc->amount, $currency, $this->Tbc->currency, $this->Tbc->language ) );

			// init contact with Tbc
			try {
				$start = $this->Tbc->sms_start_transaction();
				if ( ! isset($start['error']) && isset($start['TRANSACTION_ID']) ) {
					$trans_id = $start['TRANSACTION_ID'];
				} else {
					if ( isset($start['error']) ) {
						// Log returned error
						$this->log( sprintf( __( 'Error ~ Order id: %s - Error msg: %s.', 'woo-tbc' ), $order->get_id(), $start['error'] ) );
					} else {
						// Log generic error
						$this->log( sprintf( __( 'Error ~ Order id: %s - no TRANSACTION_ID from Tbc.', 'woo-tbc' ), $order->get_id() ) );
					}
					throw new Exception( __( 'Tbc did not return TRANSACTION_ID.', 'woo-tbc' ) );
				}
			} catch ( Exception $e ) {
				// Add private note to order details
				$order_note = $e->getMessage();
				$order->update_status( 'failed', $order_note );

				return array(
					'result' => 'failure',
				);
			}

			$this->log( sprintf( __( 'Success ~ Order id: %s -> transaction id: %s obtained successfully', 'woo-tbc' ), $order->get_id(), $trans_id ) );

			// Save trans_id for reference
			update_post_meta( $order->get_id(), '_transaction_id', $trans_id );

			$this->log( sprintf( __( 'Info ~ Order id: %s, redirecting user to Tbc gateway', 'woo-tbc' ), $order->get_id() ) );

			return array(
				'result'   => 'success',
				'messages' => __( 'Success! redirecting to Tbc now ...', 'woo-tbc' ),
				// Redirect user to tbc payment form
				'redirect' => $this->get_payment_form_url( $trans_id ),
			);
		}

		/**
		 * OK endpoint
		 *
		 * Landing page for customers returning from Tbc after payment
		 * here we verify that transaction was indeed successful
		 * and update order status accordingly
		 *
		 * @uses WC_Gateway_TBC::get_order_id_by_transaction_id()
		 */
		public function return_from_payment_form_ok() {

			try {
				if ( ! isset($_REQUEST['trans_id']) ) {
					$this->log( __( 'Error ~ Tbc did not return trans_id in $_REQUEST on OK page', 'woo-tbc' ) );
					throw new Exception( __( 'Tbc did not return transaction id!', 'woo-tbc' ) );
				}
				$trans_id = $_REQUEST['trans_id'];

				$order_id = $this->get_order_id_by_transaction_id( $trans_id );
				if ( ! $order_id ) {
					$this->log( sprintf( __( 'Error ~ could not find order id associated with transaction id: %s', 'woo-tbc' ), $trans_id ) );
					throw new Exception( __( 'We could not find your order id!', 'woo-tbc' ) );
				}

				$order = wc_get_order( $order_id );
				if ( ! $order ) {
					$this->log( sprintf( __( 'Error ~ could not find order associated with order id: %s', 'woo-tbc' ), $order_id ) );
					throw new Exception( __( 'We could not find your order!', 'woo-tbc' ) );
				}
			} catch ( Exception $e ) {
				wc_add_notice( $e->getMessage(), 'error' );
				wp_redirect( wc_get_page_permalink( 'checkout' ) );
				exit();
			}

			try {
				$transaction = $this->Tbc->get_transaction_result( $trans_id );
				if ( ! isset($transaction['RESULT']) || $transaction['RESULT'] != 'OK' ) {
					$this->log( sprintf( __( 'Error ~ could not verify transaction result, Tbc did not return OK: %s', 'woo-tbc' ), json_encode( $transaction ) ) );
					throw new Exception( __( 'We could not verify transaction result, logs should contain more information about this failure.', 'woo-tbc' ) );
				}
			} catch ( Exception $e ) {
				// Add private note to order details
				$order_note = $e->getMessage();
				$order->update_status( 'failed', $order_note );
				wc_add_notice( $order_note, 'error' );
				wp_redirect( wc_get_page_permalink( 'checkout' ) );
				exit();
			}

			// Payment complete
			$order->payment_complete();

			// Add order note
			$complete_message = __( 'Tbc charge complete', 'woo-tbc' );
			$order->add_order_note( $complete_message );
			$this->log( sprintf( __( 'Success ~ %s, transaction id: %s, order id: %s', 'woo-tbc' ), $complete_message, $trans_id, $order_id ) );

			// Remove cart
			WC()->cart->empty_cart();

			// redirect to thank you
			wp_redirect( $this->get_return_url( $order ) );
			exit();
		}

		/**
		 * FAIL endpoint
		 *
		 * Landing page for customers returning from Tbc after technical failure
		 * this can be improved by logging logged in user details
		 */
		public function return_from_payment_form_fail() {
			$error = __( 'Technical faulure in ECOMM system', 'woo-tbc' );
			$this->log( sprintf( __( 'Error ~ %s', 'woo-tbc' ), $error ) );
			wp_die( $error );
		}

		/**
		 * Create payment form url
		 *
		 * @param  string $trans_id
		 * @return string
		 */
		public function get_payment_form_url( $trans_id ) {
			return sprintf( '%s/wc-api/redirect_to_payment_form?merchant_host=%s&transaction_id=%s', get_bloginfo( 'url' ), $this->get_option( 'merchant_host' ), rawurlencode( $trans_id ) );
		}

		/**
		 * Redirect user to Tbc payment page
		 */
		public function redirect_to_payment_form() { ?>

			<html>
				<head>
					<title>TBC</title>
					<script type="text/javascript" language="javascript">
						function redirect() {
							document.returnform.submit();
						}
					</script>
				</head>

				<body onLoad="javascript:redirect()">
					<form name="returnform" action="<?php echo sprintf( 'https://%s.ufc.ge/ecomm2/ClientHandler', $_GET['merchant_host'] ); ?>" method="POST">
						<input type="hidden" name="trans_id" value="<?php echo rawurldecode( $_GET['transaction_id'] ); ?>">

						<noscript>
							<center>
								<?php _e( 'Please click the submit button below.', 'woo-tbc' ); ?><br>
								<input type="submit" name="submit" value="Submit">
							</center>
						</noscript>
					</form>
				</body>

			</html>

		<?php exit(); }

		/**
		 * Used for troubleshooting
		 */
		public function is_wearede_plugin() {
			echo json_encode( array( 'status' => 'true', 'version' => '1.0.2' ) );
			exit();
		}

		/**
		 * Add gateway data to (edit) order page
		 *
		 * This is redundant because woo already displays _transaction_id in the order description
		 * e.g. "Payment via {gateway} ({_transaction_id}). Customer IP: {ip}"
		 * but we can use it for other things in the future.
		 *
		 * @param object $order
		 */
		public function order_details( $order ) { ?>

			<div class="order_data_column">
				<h4><?php _e( 'Tbc' ); ?></h4>
				<?php

					echo '<p><strong>' . __( 'Transaction id', 'woo-tbc' ) . ':</strong>' . get_post_meta( $order->get_id(), '_transaction_id', true ) . '</p>';

				?>
			</div>

		<?php }

		/**
		 * Close business day
		 *
		 * Required by gateway
		 * must run every 24 hours via cron
		 */
		public function close_business_day() {
			print_r( $this->Tbc->close_day() );
			exit();
		}

		/**
		 * Get order id by transaction id
		 *
		 * @param  string $trans_id
		 * @return string $order_id
		 */
		public function get_order_id_by_transaction_id( $trans_id ) {
			global $wpdb;

			$meta = $wpdb->get_results(
				$wpdb->prepare(
					"
					SELECT * FROM $wpdb->postmeta
					 WHERE meta_key = '_transaction_id'
					   AND meta_value = %s
					 LIMIT 1
					",
					$trans_id
				)
			);

			if ( ! empty($meta) && is_array($meta) && isset($meta[0]) ) {
				$meta = $meta[0];
			}

			if ( is_object($meta) ) {
				return $meta->post_id;
			}

			return false;
		}

	}

}
add_action( 'plugins_loaded', 'init_woo_gateway_tbc' );
