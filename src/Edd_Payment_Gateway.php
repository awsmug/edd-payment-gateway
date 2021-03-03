<?php

namespace Awsm\Edd\Payment;

use Awsm\WP_Wrapper\Interfaces\Actions;
use Awsm\WP_Wrapper\Interfaces\Filters;
use Awsm\WP_Wrapper\Interfaces\Task;
use Awsm\WP_Wrapper\Tools\Hookable_Hidden_Methods_Trait;

/**
 * Class Edd_Payment_Gateway.
 *
 * @package AWSM\Edd\Payment
 *
 * @sine 1.0.0
 */
abstract class Edd_Payment_Gateway implements Actions, Filters, Task {
	use Hookable_Hidden_Methods_Trait;

	/**
	 * Gateway name.
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	protected $name = '';

	/**
	 * Shown name in admin. Gets $name if not set.
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	protected $admin_label = '';

	/**
	 * Shown name in checkout. Gets $name if not set.
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	protected $checkout_label = '';

	/**
	 * Gateway slug.
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	protected $slug;

	/**
	 * Show credit card forms.
	 *
	 * @var boolean
	 *
	 * @since 1.0.0
	 */
	protected $show_form = false;

	/**
	 * Gateway settings.
	 *
	 * @var array
	 *
	 * @since 1.0.0
	 */
	private $settings = array();

	/**
	 * Function for setting up payment gateway.
	 *
	 * @since 1.0.0
	 */
	protected function init_settings () {
		if ( ! $this->has_settings() ) {
			return;
		}

		$settings_fields = $this->settings_fields();

		foreach ( $settings_fields[ $this->slug ] AS $field_name => $field ) {
			if ( $field['type'] === 'header' || $field['type'] === 'descriptive_text' ) {
				continue;
			}
			$this->settings[ $field_name ] = edd_get_option( $field_name, '' );
		}
	}

	/**
	 * Get setting.
	 *
	 * @param string $name Name of setting.
	 *
	 * @return bool|mixed
	 *
	 * @since 1.0.0
	 */
	public function get_setting( $name ) {
		if ( ! array_key_exists( $name, $this->settings ) ) {
			return false;
		}

		return $this->settings[ $name ];
	}

	/**
	 * Setting up payment gateway.
	 *
	 * @return mixed
	 *
	 * @since 1.0.0
	 */
	protected abstract function setup();

	/**
	 * Running necessary scripts.
	 *
	 * @throws Gateway_Exception Exception if name or slug is missng.Payment_Exception
	 *
	 * @since 1.0.0
	 */
	public function run() {
		$this->init_settings();
		$this->setup();

		if( empty( $this->name ) || empty( $this->slug ) ) {
			throw new Gateway_Exception( 'Payment gateway name or slug must not be empty.' );
		}

		$this->set_hookable_hidden_methods([
			'add_gateway',
			'checkout_html',
			'error_checks',
			'init',
			'listener',
			'process_purchase',
			'process_payment_notification',
			'register_section',
			'settings_fields',
			'verify_nonce',
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
		add_filter( 'edd_payment_confirm_' . $this->slug,  array( $this, 'confirmation_page' ) );

		if ( is_admin() && $this->has_settings() ) {
			add_filter( 'edd_settings_gateways', array( $this, 'settings_fields' ) );
		}
	}

	/**
	 * Adding actions.
	 *
	 * @since 1.0.0
	 */
	public function add_actions() {
		add_action( 'plugins_loaded', array( $this, 'init' ), 1 );
		add_action( 'init', array( $this, 'process_payment_notification' ) );

		add_action( 'edd_checkout_user_error_checks', array( $this, 'error_checks' ), 10, 3 );
		add_action( 'edd_gateway_' . $this->slug, array( $this, 'verify_nonce' ), 1 );
		add_action( 'edd_gateway_' . $this->slug, array( $this, 'process_purchase' ) );
		add_action( 'edd_' . $this->slug . '_cc_form', array( $this, 'checkout_html' ) );

		if ( is_admin() && $this->has_settings() ) {
			add_action( 'edd_settings_sections_gateways', array( $this, 'register_section' ) );
		}
	}

	/**
	 * Functionality after WP Query.
	 *
	 * @since 1.0.0
	 */
	private function init(){
		if ( edd_is_checkout() && $this->show_form ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'checkout_scripts' ) );
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
		$gateways[ $this->slug ] = $this->get_args();
		return $gateways;
	}

	/**
	 * Get gateway args.
	 *
	 * @return array Gateway arguments.
	 *
	 * @since 1.0.0
	 */
	private function get_args(){
		$args = [
			'admin_label' => empty( $this->admin_label ) ? $this->name: $this->admin_label,
			'checkout_label' => empty( $this->checkout_label ) ? $this->name: $this->checkout_label,
		];

		return $args;
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
	 * Settings fields.
	 *
	 * @param array $settings Gateway settings.
	 *
	 * @return array Filtered gateway settings.
	 *
	 * @since 1.0.0
	 */
	protected function settings_fields( array $settings = array() ) : array {
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
		$settings_fields = $this->settings_fields();

		if( array_key_exists( $this->slug, $settings_fields) && count( $settings_fields[ $this->slug ] ) > 0 ) {
			return true;
		}

		return false;
	}

	/**
	 * Checkout HTML.
	 *
	 * @since 1.0.0
	 */
	protected function checkout_html() {
	}

	/**
	 * Checkout Scripts.
	 *
	 * @since 1.0.0
	 */
	protected function checkout_scripts() {
	}

	/**
	 * Error checking.
	 *
	 * @param $user
	 * @param $valid_data
	 * @param array $post_data
	 *
	 * @since 1.0.0
	 */
	private function error_checks( $user, $valid_data, array $post_data ) {
		if ( $post_data['payment-mode'] !== $this->slug ) {
			return;
		}

		try {
			$this->validate( $post_data );
		} catch ( Validation_Exception $exception ) {
			edd_set_error( 'validation_failed', $exception->getMessage() );
		} catch ( Gateway_Exception $exception ) {
			$this->log( $exception->getMessage(), 'error' );
			edd_set_error( 'payment_failed', __( 'Payment failed. Please try another payment method.', 'awsm-edd-payment-gateway' ) );
		}
	}

	/**
	 * Validate data.
	 *
	 * @param array $post_data Posted data to validate.
	 *
	 * @throws Validation_Exception Exception on validation.
	 *
	 * @since 1.0.0
	 */
	protected function validate( $post_data ) {
	}

	/**
	 * Nonce verification.
	 *
	 * @param array $purchase_data
	 */
	private function verify_nonce( array $purchase_data ) {
		if ( ! wp_verify_nonce( $purchase_data['gateway_nonce'], 'edd-gateway' ) ) {
			wp_die( __( 'Nonce verification has failed', 'easy-digital-downloads' ), __( 'Error', 'awsm-edd-payment-gateway' ), array( 'response' => 403 ) );
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

		try {
			$this->process_payment( $payment_data, $purchase_data['post_data'], $payment_id );

			edd_insert_payment_note( $payment_id, sprintf( __( 'Processed payment "%s" with payment gateway "%s"', 'awsm-edd-payment-gateway' ), $payment_id, $this->name ) );
			edd_empty_cart();

			wp_redirect( $this->get_success_url() );
			exit;

		} catch ( Validation_Exception $exception ) {
			edd_set_error( 'validation_failed', $exception->getMessage() );
			edd_send_back_to_checkout( '?payment-mode=' . $this->slug );
		} catch ( Gateway_Exception $exception ) {
			$this->log( $exception->getMessage(), 'error' );
			edd_set_error( 'payment_failed', __( 'Payment failed. Please try another payment method.', 'awsm-edd-payment-gateway' ) );
			edd_send_back_to_checkout( '?payment-mode=' . $this->slug );
		}
	}

	/**
	 * Processing payment.
	 *
	 * @param array $purchase_data Purchase data.
	 * @param array $post_data     Posted data.
	 * @param int   $payment_id    Payment id.
	 *
	 * @return array Filtered purchase data.
	 *
	 * @since 1.0.0
	 */
	public abstract function process_payment( array $purchase_data, array $post_data, int $payment_id );

	/**
	 * Sets a Transaction ID in post meta for the given Payment ID.
	 *
	 * @param int    $payment_id     Payment ID.
	 * @param string $transaction_id The transaction ID from the gateway.
	 *
	 * @return mixed Meta ID if successful, false if unsuccessful.
	 *
	 * @since 1.0.0
	 */
	protected function set_payment_transaction_id( $payment_id, $transaction_id ) {
		return edd_set_payment_transaction_id( $payment_id, $transaction_id );
	}

	/**
	 * Retrieve the payment ID based on the transaction ID.
	 *
	 * @param string $key The transaction ID to search for.
	 *
	 * @return int Payment ID.
	 *
	 * @since 1.0.0
	 */
	protected function get_payment_id_by_transaction_id( $transaction_id ) {
		return edd_get_purchase_id_by_transaction_id( $transaction_id );
	}

	/**
	 * Listening to incoming requests.
	 *
	 * @since 1.0.0
	 */
	private function process_payment_notification() {
		if ( isset( $_GET['edd-listener'] ) && $_GET['edd-listener'] === $this->slug ) {
			$input = file_get_contents( 'php://input' );

			if ( $input ) {
				$this->payment_listener( $input );
				return;
			}

			$this->payment_error( null, __( 'Missing POST data.', 'wpenon' ), true );
			return;
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
			'gateway'      => $this->slug,
			'status'       => 'pending'
		);

		return array_merge( $payment_data, $additional_data );
	}

	/**
	 * Output confirmation page content.
	 * 
	 * @param string $content Content of confirmation page. Can be overwritten by child class.
	 * 
	 * @since 1.0.0
	 */
	public function confirmation_page ( $content ) {
		if ( ! isset( $_GET['payment-id'] ) && ! edd_get_purchase_session() ) {
			return $content;
		}
	
		edd_empty_cart();	

		ob_start();
		edd_get_template_part( 'payment', 'processing' );
		$content = ob_get_clean();
	
		return $content;	
	}

	/**
	 * Payment complete.
	 *
	 * @param int  $payment_id     Payment id.
	 *
	 * @since 1.0.0
	 */
	protected function payment_complete( int $payment_id ) {
		edd_update_payment_status( $payment_id, 'publish' );
		$this->log( sprintf( 'Payment succeeded for payment id #%s.', $payment_id ) );
		do_action( 'awsm_edd_payment_complete', $payment_id, $this->slug );
	}

	/**
	 * Payment complete.
	 *
	 * @param int  $payment_id     Payment id.
	 *
	 * @since 1.0.0
	 */
	protected function payment_pending( int $payment_id ) {
		edd_update_payment_status( $payment_id, 'pending' );
		$this->log( sprintf( 'Payment pending for payment id #%s.', $payment_id ) );
		do_action( 'awsm_edd_payment_pending', $payment_id, $this->slug );
	}

	/**
	 * Payment error.
	 *
	 * @param int $payment_id Payment id.
	 * @param bool $sendback_checkout Send back to checkout.
	 */
	protected function payment_failed( int $payment_id ) {
		edd_update_payment_status( $payment_id, 'failed' );
		$this->log( sprintf( 'Payment pending for payment id #%s.', $payment_id ) );
		do_action( 'awsm_edd_payment_failed', $payment_id, $this->slug );
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
			return add_query_arg( 'edd-listener', $this->slug, home_url() );
		}

		return false;
	}

	/**
	 * Logging functionality.
	 *
	 * @param string $message Log message.
	 * @param string $type    Type 'log' or 'error'.
	 *
	 * @return mixed ID of the new log entry.
	 *
	 * @since 1.0.0
	 */
	protected function log( $message, $type = 'log' ) {
		switch ( $type ) {
			case 'error':
				$title = sprintf( 'Error in payment gateway "%s"', $this->name );
				$log = edd_record_gateway_error( $title, $message );
				break;
			case 'log':
			default:
				$title = sprintf( 'Payment gateway "%s"', $this->name );
				$log = edd_record_log( $title, $message );
				break;
		}

		return $log;
	}
}