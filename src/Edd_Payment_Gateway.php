<?php

namespace Awsm\Edd\Payment;

use Awsm\WP_Wrapper\Interfaces\Actions;
use Awsm\WP_Wrapper\Interfaces\Filters;
use Awsm\WP_Wrapper\Interfaces\Task;
use Awsm\WP_Wrapper\Traits\Hookable_Hidden_Methods;

/**
 * Class Edd_Payment_Gateway.
 *
 * @package AWSM\Edd\Payment
 *
 * @sine 1.0.0
 */
abstract class Edd_Payment_Gateway implements Actions, Filters, Task {
	use Hookable_Hidden_Methods;

	/**
	 * Gateway name.
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	protected $name = '';

	/**
	 * Gateway slug.
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	protected $slug;

	/**
	 * Gateway arguments.
	 *
	 * @var array
	 *
	 * @since 1.0.0
	 */
	protected $gateway_args = array();

	/**
	 * Show credit card forms.
	 *
	 * @var boolean
	 *
	 * @since 1.0.0
	 */
	protected $show_cc_form = true;

	/**
	 * Function for setting up payment gateway.
	 *
	 * @since 1.0.0
	 */
	abstract protected function setup();

	/**
	 * Running necessary scripts.
	 *
	 * @throws Payment_Exception Exception if name or slug is missng.Payment_Exception
	 *
	 * @since 1.0.0
	 */
	public function run() {
		$this->setup();

		if( empty( $this->name ) || empty( $this->slug ) ) {
			throw new Payment_Exception( 'Payment gateway name or slug must not be empty.' );
		}

		$this->set_hookable_hidden_methods([
			'add_gateway',
			'listener',
			'verify_nonce',
			'process_purchase',
			'process_payment_notification',
			'register_section',
			'register_settings',
		]);

		$this->add_actions();
		$this->add_filters();
	}

	/**
	 * Adding filters.
	 *
	 * @since 1.0.0
	 */
	public function add_filters() {
		add_filter( 'edd_payment_gateways', array( $this, 'add_gateway' ) );
	}

	/**
	 * Adding actions.
	 *
	 * @since 1.0.0
	 */
	public function add_actions() {
		add_action( 'edd_gateway_' . $this->slug, array( $this, 'verify_nonce' ), 1 );
		add_action( 'edd_gateway_' . $this->slug, array( $this, 'process_purchase' ) );
		add_action( 'init', array( $this, 'process_payment_notification' ) );

		if ( is_admin() && $this->has_settings() ) {
			add_action( 'edd_settings_sections_gateways', array( $this, 'register_section' ) );
			add_action( 'edd_settings_gateways', array( $this, 'register_settings' ) );
		}

		if ( ! $this->show_cc_form ) {
			add_action( 'edd_' . $this->slug . '_cc_form', '__return_false' );
		}
	}

	/**
	 * Add payment gateway.
	 *
	 * @param array $gateways
	 *
	 * @return mixed
	 *
	 * @since 1.0.0
	 */
	private function add_gateway( array $gateways ) : array {
		$gateways[ $this->slug ] = $this->gateway_args;
		return $gateways;
	}

	/**
	 * Register the payment gateways setting section.
	 *
	 * @param  array $gateway_sections Array of sections for the gateways tab.
	 * @return array                   Added Amazon Payments into sub-sections.
	 *
	 * @since 1.0.0
	 */
	private function register_section( $gateway_sections ) {
		$gateway_sections[ $this->slug ] = $this->name;
		return $gateway_sections;
	}

	/**
	 * Register gateway settings.
	 *
	 * @param array $settings Gateway settings.
	 *
	 * @return array Filtered gateway settings.
	 *
	 * @since 1.0.0
	 */
	protected function register_settings( array $settings ) :array {
		return $settings;
	}

	/**
	 * Check if the gateway has settings.
	 *
	 * @return bool True if there are settings, false if not.
	 *
	 * @since 1.0.0
	 */
	protected function has_settings() {
		if( count( $this->register_settings( [] ) ) === 0 ) {
			return false;
		}

		return true;
	}

	/**
	 * Nonce verification.
	 *
	 * @param array $purchase_data
	 */
	private function verify_nonce( array $purchase_data ) {
		if ( ! wp_verify_nonce( $purchase_data['gateway_nonce'], 'edd-gateway' ) ) {
			wp_die( __( 'Nonce verification has failed', 'easy-digital-downloads' ), __( 'Error', 'easy-digital-downloads' ), array( 'response' => 403 ) );
		}
	}

	/**
	 * Processing purchase data.
	 *
	 * @param array $purchase_data Purchase data.
	 *
	 * @return array Filtered purchase data.
	 *
	 * @since 1.0.0
	 */
	private function process_purchase( array $purchase_data ) {
		$payment_data = $this->create_payment_data( $purchase_data );
		$payment_id = \edd_insert_payment( $payment_data );

		$this->process_payment( $payment_data, $payment_id );
	}

	/**
	 * Processing payment.
	 *
	 * @param array $purchase_data Purchase data.
	 * @param int   $payment_id    Payment id.
	 *
	 * @return array Filtered purchase data.
	 *
	 * @since 1.0.0
	 */
	public abstract function process_payment( array $purchase_data, int $payment_id );

	/**
	 * Listening to incoming requests.
	 *
	 * @since 1.0.0
	 */
	private function process_payment_notification() {
		if ( isset( $_GET['edd-listener'] ) && $_GET['edd-listener'] === $this->slug ) {
			$input = file_get_contents( 'php://input' );

			if ( empty( $input ) ) {
				$this->payment_error( null, __( 'Missing POST data.', 'wpenon' ), true );
			}

			$this->payment_listener( $input );
		}
	}

	/**
	 * Payment listener.
	 *
	 * @param array $input Incoming data.
	 *
	 * @since 1.0.0
	 */
	protected abstract function payment_listener( $input );

	/**
	 * Creating payment data.
	 *
	 * @param array $purchase_data   Purchase data.
	 * @param array $additional_data Additional data.
	 *
	 * @return array Payment data.
	 */
	private function create_payment_data( array $purchase_data, array $additional_data = array() ) {
		$payment_data = array(
			'price'        => $purchase_data['price'],
			'date'         => $purchase_data['date'],
			'user_email'   => $purchase_data['user_email'],
			'purchase_key' => $purchase_data['purchase_key'],
			'currency'     => edd_get_currency(),
			'downloads'    => $purchase_data['downloads'],
			'user_info'    => $purchase_data['user_info'],
			'cart_details' => $purchase_data['cart_details'],
			'gateway'      => static::$name,
			'status'       => 'pending'
		);

		return array_merge( $payment_data, $additional_data );
	}

	/**
	 * Payment complete.
	 *
	 * @param int  $payment_id     Payment id.
	 * @param null $transaction_id Transaction id.
	 * @param null $redirect_to    Redirect url.
	 *
	 * @since 1.0.0
	 */
	protected function payment_complete( int $payment_id, int $transaction_id = null, string $redirect_to = null ) {
		edd_update_payment_status( $payment_id, 'publish' );

		if ( $transaction_id ) {
			edd_set_payment_transaction_id( $payment_id, $transaction_id );
		}

		edd_record_log( sprintf( 'Payment succeeded for payment id #%s.', $payment_id ) );

		do_action( 'awsm_edd_payment_complete', $payment_id, $this->slug );

		$this->purchase_complete( $payment_id, $redirect_to );
	}

	/**
	 * Payment complete.
	 *
	 * @param int  $payment_id     Payment id.
	 * @param int $transaction_id Transaction id.
	 * @param int $redirect_to    Redirect url.
	 *
	 * @since 1.0.0
	 */
	protected function payment_pending( int $payment_id, int $transaction_id = null, string $redirect_to = null ) {
		edd_update_payment_status( $payment_id, 'pending' );

		if ( $transaction_id ) {
			edd_set_payment_transaction_id( $payment_id, $transaction_id );
		}

		edd_record_log( sprintf( 'Payment pending for payment id #%s.', $payment_id ) );

		do_action( 'awsm_edd_payment_pending', $payment_id, $this->slug );

		$this->purchase_complete( $payment_id, $redirect_to );
	}

	/**
	 * Completing purchase.
	 *
	 * @param int         $payment_id  Payment id.
	 * @param string|null $redirect_to Redirect url.
	 *
	 * @since 1.0.0
	 */
	private function purchase_complete( int $payment_id, string $redirect_to = null ) {
		edd_empty_cart();

		if ( $redirect_to ) {
			wp_redirect( $redirect_to );
			exit;
		} else {
			edd_send_to_success_page();
		}
	}

	/**
	 * Payment error.
	 *
	 * @param int $payment_id Payment id.
	 * @param string $message Payment message.
	 *
	 * @param bool $sendback_checkout Send back to checkout
	 */
	protected function payment_error( int $payment_id, string $message, $sendback_checkout = false ) {
		edd_update_payment_status( $payment_id, 'failed' );

		$message = sprintf( 'Payment error for payment id #%s: %s', $payment_id, $message );

		do_action( 'awsm_edd_payment_error', $payment_id, $this->slug );

		\edd_record_gateway_error( __( 'Payment Error' ), $message, $payment_id );

		if ( $payment_id ) {
			\edd_update_payment_status( $payment_id, 'failed' );
		}
		if ( $sendback_checkout ) {
			\edd_send_back_to_checkout( '?payment-mode=' . $this->slug );
			exit;
		}
	}

	protected function payment_notification_error( $payment_id, $message, $abort = false ) {
		$log_message = sprintf( 'Payment process error for payment id #%s: %s', $payment_id, $message ) . chr(13);

		edd_record_gateway_error( sprintf( __( '%s Notification Error', 'wpenon' ), $this->slug ), $log_message, $payment_id );

		if ( $abort ) {
			wp_send_json_error( array( 'message' => $message ), 400 );
		}
	}

	/**
	 * Get success url.
	 *
	 * @param int $payment_id Payment id.
	 *
	 * @return string Success url.
	 *
	 * @since 1.0.0
	 */
	protected function get_success_url( $payment_id ) {
		$success_url = get_permalink( edd_get_option( 'success_page', false ) );

		$url = add_query_arg( array(
			'payment-confirmation' => $this->slug,
			'payment-id'           => $payment_id,
		), $success_url );

		$success_url = apply_filters( 'awsm_edd_payment_success_url', $url, $payment_id );

		return $success_url;
	}

	/**
	 * Get failed url.
	 *
	 * @param int $payment_id Payment id.
	 *
	 * @return string Failed url.
	 *
	 * @since 1.0.0
	 */
	protected function get_failed_url( $payment_id ) {
		$url = edd_get_failed_transaction_uri( '?payment-id=' . $payment_id );
		return apply_filters( 'wepenon_payment_failed_url', $url );
	}

	/**
	 * Get listener url.
	 *
	 * @param int $payment_id Payment id.
	 *
	 * @return string Listener url.
	 *
	 * @since 1.0.0
	 */
	protected function get_listener_url() {
		if ( ! empty( $this->slug ) ) {
			return home_url( '/edd-listener/' . $this->slug . '/' );
		}

		return false;
	}
}