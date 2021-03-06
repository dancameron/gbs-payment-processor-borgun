<?php

/**
 * Paypal offsite payment processor.
 *
 * @package GBS
 * @subpackage Payment Processing_Processor
 */
class Group_Buying_Borgun_EC extends Group_Buying_Offsite_Processors {
	const API_ENDPOINT_SANDBOX = 'https://test.borgun.is/SecurePay/default.aspx';
	const API_ENDPOINT_LIVE = 'https://test.borgun.is/SecurePay/default.aspx';
	const API_REDIRECT_SANDBOX = 'https://test.borgun.is/SecurePay/default.aspx';
	const API_REDIRECT_LIVE = 'https://test.borgun.is/SecurePay/default.aspx';
	const MODE_TEST = 'sandbox';
	const MODE_LIVE = 'live';

	const API_MERCHANTID_OPTION = 'gb_borgun_merchantid';
	const API_USERNAME_OPTION = 'gb_paypal_username';
	const API_SIGNATURE_OPTION = 'gb_paypal_signature';
	const API_PASSWORD_OPTION = 'gb_paypal_password';
	const API_MODE_OPTION = 'gb_paypal_mode';
	const API_SECRETCODE_OPTION = 'gb_borgun_secrecode';
	const API_PAGETYPE_OPTION = 'gb_borgun_pagetype';
	const API_SKIPRECEIPTPAGE_OPTION = 'gb_borgun_skipreceiptpage';
	const API_PAYMENTGATEWAYID_OPTION = 'gb_borgun_paymentgatewayid';
	const API_MERCHANTLOGO_OPTION = 'gb_borgun_merchantlogo';

	const CANCEL_URL_OPTION = 'gb_paypal_cancel_url';
	const RETURN_URL_OPTION = 'gb_paypal_return_url';
	const ERROR_URL_OPTION = 'gb_paypal_error_url';
	const SUCCESSSERVER_URL_OPTION = 'gb_paypal_error_url';

	const CURRENCY_CODE_OPTION = 'gb_paypal_currency';
	const PAYMENT_METHOD = 'Borgun EC';
	const TOKEN_KEY = 'gb_token_key'; // Combine with $blog_id to get the actual meta key
	const PAYER_ID = 'gb_payer_id'; // Combine with $blog_id to get the actual meta key
	const LOGS = 'gb_offsite_logs';

	protected static $instance;
	private static $token;
	protected static $api_mode = self::MODE_TEST;
	private static $api_username;
	private static $api_password;
	private static $api_signature;
	private static $api_secretcode = '';
	private static $api_pagetype;
	private static $api_skipReceiptPage;
	private static $api_paymentgatwayid;
	private static $api_merchantlogo;

	private static $api_checkhash = '';

	private static $cancel_url = '';
	private static $return_url = '';
	private static $error_url = 'http://127.0.0.1/?phpinfo=1';
	private static $returnurlsuccessserver_url = 'http://127.0.0.1/kanina/checkout/';

	//private static $returnurlsuccessserver_url = 'http://127.0.0.1/?phpinfo=1';

	private static $user_name = 'Testi pr��funarson';
	private static $page_language = 'IS';

	private static $currency_code = 'USD';
	private static $version = '64';
	private static $api_merchantid;


	public static function get_instance() {
		if ( !( isset( self::$instance ) && is_a( self::$instance, __CLASS__ ) ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function get_api_url() {
		if ( self::$api_mode == self::MODE_LIVE ) {
			return self::API_ENDPOINT_LIVE;
		} else {
			return self::API_ENDPOINT_SANDBOX;
		}
	}

	private function get_redirect_url() {
		if ( self::$api_mode == self::MODE_LIVE ) {
			return self::API_REDIRECT_LIVE;
		} else {
			return self::API_REDIRECT_SANDBOX;
		}
	}

	public function get_payment_method() {
		return self::PAYMENT_METHOD;
	}

	protected function __construct() {
		parent::__construct();

		self::$api_merchantid = get_option( self::API_MERCHANTID_OPTION );
		self::$api_secretcode = get_option( self::API_SECRETCODE_OPTION, '99887766' );
		self::$returnurlsuccessserver_url = get_option( self::SUCCESSSERVER_URL_OPTION, 'http://127.0.0.1/kanina/checkout/' );

		self::$api_pagetype = get_option( self::API_PAGETYPE_OPTION );
		self::$api_skipReceiptPage = get_option( self::API_SKIPRECEIPTPAGE_OPTION );
		self::$api_merchantlogo = get_option( self::API_MERCHANTLOGO_OPTION );

		self::$api_username = get_option( self::API_USERNAME_OPTION );
		self::$api_password = get_option( self::API_PASSWORD_OPTION );
		self::$api_signature = get_option( self::API_SIGNATURE_OPTION );
		self::$api_paymentgatwayid = get_option( self::API_PAYMENTGATEWAYID_OPTION );

		self::$api_mode = get_option( self::API_MODE_OPTION, self::MODE_TEST );
		self::$currency_code = get_option( self::CURRENCY_CODE_OPTION, 'USD' );
		self::$cancel_url = get_option( self::CANCEL_URL_OPTION, Group_Buying_Carts::get_url() );
		self::$return_url = Group_Buying_Checkouts::get_url();

		add_action( 'admin_init', array( $this, 'register_settings' ), 10, 0 );
		add_action( 'purchase_completed', array( $this, 'complete_purchase' ), 10, 1 );
		add_action( self::CRON_HOOK, array( $this, 'capture_pending_payments' ) );


		add_filter( 'gb_checkout_payment_controls', array( $this, 'payment_controls' ), 20, 2 );

		add_action( 'gb_send_offsite_for_payment', array( $this, 'send_offsite' ), 10, 1 );
		add_action( 'gb_load_cart', array( $this, 'back_from_borgun' ), 10, 0 );


	}

	public static function register() {
		self::add_payment_processor( __CLASS__, self::__( 'Borgun Payments Standard' ) );
	}

	public static function public_name() {
		return self::__( 'Borgun' );
	}

	public static function checkout_icon() {
		return '<img src="https://www.paypal.com/en_US/i/btn/btn_xpressCheckout.gif" title="Paypal Payments" id="paypal_icon"/>';
	}


	public function send_offsite( Group_Buying_Checkouts $checkout ) {

		$cart = $checkout->get_cart();

		if ( $cart->get_total( self::get_payment_method() ) < 0.01 ) {
			// Nothing to do here, another payment handler intercepted and took care of everything
			// See if we can get that payment and just return it
			$payments = Group_Buying_Payment::get_payments_for_purchase( $cart->get_id() );
			foreach ( $payments as $payment_id ) {
				$payment = Group_Buying_Payment::get_instance( $payment_id );
				return $payment;
			}
		}

		if ( $_REQUEST['gb_checkout_action'] == Group_Buying_Checkouts::PAYMENT_PAGE ) {

			$post_data = $this->set_nvp_data( $checkout );
			if ( !$post_data ) {
				//return; // paying for it some other way
			}

			//we have to transfer the user on client side to payment site.
			self::redirectBorgun( $post_data );
		}
	}


	public static function returned_from_offsite() {
		return isset( $_REQUEST['authorizationcode'] );
	}

	/**
	 * We're on the checkout page, just back from Borgun.
	 *
	 * @return void
	 */
	public function back_from_borgun() {
		if ( self::returned_from_offsite() ) {
			print_r( $_REQUEST );
			$_REQUEST['gb_checkout_action'] = 'back_from_borgun';
		} elseif ( !isset( $_REQUEST['gb_checkout_action'] ) ) {
			self::unset_token();
		}
	}


	/**
	 * Process a payment
	 *
	 * @param Group_Buying_Checkouts $checkout
	 * @param Group_Buying_Purchase $purchase
	 * @return Group_Buying_Payment|bool FALSE if the payment failed, otherwise a Payment object
	 */
	public function process_payment( Group_Buying_Checkouts $checkout, Group_Buying_Purchase $purchase ) {

		if ( $purchase->get_total( self::get_payment_method() ) < 0.01 ) {
			// Nothing to do here, another payment handler intercepted and took care of everything
			// See if we can get that payment and just return it
			$payments = Group_Buying_Payment::get_payments_for_purchase( $purchase->get_id() );
			foreach ( $payments as $payment_id ) {
				$payment = Group_Buying_Payment::get_instance( $payment_id );
				return $payment;
			}
		}

		$post_data = $this->process_nvp_data( $checkout, $purchase );


		$response = wp_remote_post( self::get_api_url(), array(
				'method' => 'POST',
				'body' => $post_data,
				'timeout' => apply_filters( 'http_request_timeout', 15 ),
				'sslverify' => false
			) );


		if ( is_wp_error( $response ) ) {
			return FALSE;
		}
		if ( $response['response']['code'] != '200' ) {
			return FALSE;
		}

		$response = wp_parse_args( wp_remote_retrieve_body( $response ) );

		if ( strpos( $response['ACK'], 'Success' ) !== 0 ) {
			$this->set_error_messages( $response['L_LONGMESSAGE0'] );
			return FALSE;
		}
		if ( strpos( $response['ACK'], 'SuccessWithWarning' ) === 0 ) {
			$this->set_error_messages( $response['L_LONGMESSAGE0'] );
		}
		// create loop of deals for the payment post
		$deal_info = array();
		foreach ( $purchase->get_products() as $item ) {
			if ( isset( $item['payment_method'][self::get_payment_method()] ) ) {
				if ( !isset( $deal_info[$item['deal_id']] ) ) {
					$deal_info[$item['deal_id']] = array();
				}
				$deal_info[$item['deal_id']][] = $item;
			}
		}
		if ( isset( $checkout->cache['shipping'] ) ) {
			$shipping_address = array();
			$shipping_address['first_name'] = $checkout->cache['shipping']['first_name'];
			$shipping_address['last_name'] = $checkout->cache['shipping']['last_name'];
			$shipping_address['street'] = $checkout->cache['shipping']['street'];
			$shipping_address['city'] = $checkout->cache['shipping']['city'];
			$shipping_address['zone'] = $checkout->cache['shipping']['zone'];
			$shipping_address['postal_code'] = $checkout->cache['shipping']['postal_code'];
			$shipping_address['country'] = $checkout->cache['shipping']['country'];
		}
		// create new payment
		$payment_id = Group_Buying_Payment::new_payment( array(
				'payment_method' => self::get_payment_method(),
				'purchase' => $purchase->get_id(),
				'amount' => $response['PAYMENTINFO_0_AMT'],
				'data' => array(
					'api_response' => $response,
					'uncaptured_deals' => $deal_info
				),
				// 'transaction_id' => $response[], // TODO set the transaction ID
				'deals' => $deal_info,
				'shipping_address' => $shipping_address,
			), Group_Buying_Payment::STATUS_AUTHORIZED );
		if ( !$payment_id ) {
			return FALSE;
		}
		$payment = Group_Buying_Payment::get_instance( $payment_id );
		do_action( 'payment_authorized', $payment );

		$this->create_recurring_payment_profiles( $checkout, $purchase );
		self::unset_token();

		return $payment;
	}


	/**
	 * Complete the purchase after the process_payment action, otherwise vouchers will not be activated.
	 *
	 * @param Group_Buying_Purchase $purchase
	 * @return void
	 */
	public function complete_purchase( Group_Buying_Purchase $purchase ) {

		echo "complete_purchase";
		$items_captured = array(); // Creating simple array of items that are captured
		foreach ( $purchase->get_products() as $item ) {
			$items_captured[] = $item['deal_id'];
		}
		print_r( $purchase );
		$payments = Group_Buying_Payment::get_payments_for_purchase( $purchase->get_id() );
		foreach ( $payments as $payment_id ) {
			$payment = Group_Buying_Payment::get_instance( $payment_id );
			do_action( 'payment_captured', $payment, $items_captured );
			//do_action( 'payment_complete', $payment );
			$payment->set_status( Group_Buying_Payment::STATUS_COMPLETE );
		}
	}

	/**
	 * Build the NVP data array for submitting the current checkout to PayPal as an Authorization request
	 *
	 * @param Group_Buying_Checkouts $checkout
	 * @param Group_Buying_Purchase $purchase
	 * @return array
	 */
	private function set_nvp_data( Group_Buying_Checkouts $checkout ) {

		$cart = $checkout->get_cart();

		$user = get_userdata( get_current_user_id() );
		$filtered_total = $this->get_payment_request_total( $checkout );
		if ( $filtered_total < 0.01 ) {
			return array();
		}
		$nvpData = array();

		//$nvpData['USER'] = self::$api_username;
		//$nvpData['PWD'] = self::$api_password;
		//$nvpData['SIGNATURE'] = self::$api_signature;
		$nvpData['merchantid'] = self::$api_merchantid;
		//$nvpData['PAGETYPE'] = self::$api_pagetype;
		$nvpData['paymentgatewayid'] = self::$api_paymentgatwayid;
		//$nvpData['MERCHANTLOGO'] = self::$api_merchantlogo;


		//$nvpData['VERSION'] = self::$version;

		//$nvpData['CANCELURL'] = self::$cancel_url;
		$nvpData['returnurlcancel'] = self::$cancel_url;
		$nvpData['returnurlerror'] = self::$error_url;

		$nvpData['returnurlsuccessserver'] = self::$returnurlsuccessserver_url;



		//$nvpData['RETURNURL'] = self::$return_url;
		$nvpData['returnurlsuccess'] = self::$return_url;

		$nvpData['amount'] = $this->get_payment_request_total( $checkout );

		//$nvpData['checkhash'] = self::$api_checkhash;

		//$nvpData['orderid'] = self::$orderid;


		//$nvpData['METHOD'] = 'SetExpressCheckout';
		//$nvpData['PAYMENTREQUEST_0_PAYMENTACTION'] = 'Authorization';
		$nvpData['buyeremail'] = $user->user_email;

		//$nvpData['LANDINGPAGE'] = 'Billing';
		//$nvpData['SOLUTIONTYPE'] = 'Sole';

		//$nvpData['PAYMENTREQUEST_0_AMT'] = gb_get_number_format( $filtered_total );

		//$nvpData['itemcount_0'] = gb_get_number_format( $ );


		//$nvpData['PAYMENTREQUEST_0_CURRENCYCODE'] = self::get_currency_code();

		$nvpData['currency'] = self::get_currency_code();

		//$nvpData['PAYMENTREQUEST_0_ITEMAMT'] = gb_get_number_format( $cart->get_subtotal() );
		//$nvpData['PAYMENTREQUEST_0_SHIPPINGAMT'] = gb_get_number_format( $cart->get_shipping_total() );
		//$nvpData['PAYMENTREQUEST_0_TAXAMT'] = gb_get_number_format( $cart->get_tax_total() );

		$nvpData['buyername'] = self::$user_name;
		$nvpData['language'] = self::$page_language;



		//$nvpData['BUTTONSOURCE'] = self::PLUGIN_NAME;

		if ( isset( $checkout->cache['shipping'] ) ) {
			$nvpData['NOSHIPPING'] = 2;
			$nvpData['ADDROVERRIDE'] = 1;
			$nvpData['PAYMENTREQUEST_0_SHIPTONAME'] = $checkout->cache['shipping']['first_name'].' '.$checkout->cache['shipping']['last_name'];
			$nvpData['PAYMENTREQUEST_0_SHIPTOSTREET'] = $checkout->cache['shipping']['street'];
			$nvpData['PAYMENTREQUEST_0_SHIPTOCITY'] = $checkout->cache['shipping']['city'];
			$nvpData['PAYMENTREQUEST_0_SHIPTOSTATE'] = $checkout->cache['shipping']['zone'];
			$nvpData['PAYMENTREQUEST_0_SHIPTOZIP'] = $checkout->cache['shipping']['postal_code'];
			$nvpData['PAYMENTREQUEST_0_SHIPTOCOUNTRYCODE'] = $checkout->cache['shipping']['country'];
		}

		$i = 0;
		$j = 0;
		if (
			$nvpData['PAYMENTREQUEST_0_ITEMAMT'] == gb_get_number_format( 0 ) ||
			( $filtered_total < $cart->get_total()
				&& ( $cart->get_subtotal() + $filtered_total - $cart->get_total() ) == 0
			)
		) {
			//handle orders that are free but have tax or shipping
			if ( $nvpData['PAYMENTREQUEST_0_SHIPPINGAMT'] != gb_get_number_format( 0 ) ) {
				$nvpData['PAYMENTREQUEST_0_ITEMAMT'] = $nvpData['PAYMENTREQUEST_0_SHIPPINGAMT'];
				$nvpData['PAYMENTREQUEST_0_SHIPPINGAMT'] = gb_get_number_format( 0 );
			} elseif ( $nvpData['PAYMENTREQUEST_0_TAXAMT'] != gb_get_number_format( 0 ) ) {
				$nvpData['PAYMENTREQUEST_0_ITEMAMT'] = $nvpData['PAYMENTREQUEST_0_TAXAMT'];
				$nvpData['PAYMENTREQUEST_0_TAXAMT'] = gb_get_number_format( 0 );
			}
		} else {
			// we can add individual item info if there's actually an item cost
			foreach ( $cart->get_items() as $key => $item ) {
				$deal = Group_Buying_Deal::get_instance( $item['deal_id'] );
				//$nvpData['L_PAYMENTREQUEST_0_NAME'.$i] = $deal->get_title( $item['data'] );
				$nvpData['itemdescription_'.$i] = $deal->get_title( $item['data'] );

				//$nvpData['L_PAYMENTREQUEST_0_AMT'.$i] = gb_get_number_format( $deal->get_price( NULL, $item['data'] ) );
				$nvpData['itemunitamount_'.$i] = gb_get_number_format( $deal->get_price( NULL, $item['data'] ) );

				$nvpData['itemamount_'.$i] = gb_get_number_format( $deal->get_price( NULL, $item['data'] ) ) * $item['quantity'];

				//$nvpData['L_PAYMENTREQUEST_0_NUMBER'.$i] = $item['deal_id'];
				$nvpData['orderid'] =$item['deal_id'];
				//$nvpData['L_PAYMENTREQUEST_0_QTY'.$i] = $item['quantity'];

				$nvpData['itemcount_'.$i] = $item['quantity'];


				if ( !empty( $item['data']['recurring'] ) ) {
					$nvpData['L_BILLINGTYPE'.$j] = 'RecurringPayments';
					$nvpData['L_BILLINGAGREEMENTDESCRIPTION'.$j] = $deal->get_title( $item['data'] );
				}
				$i++;
			}
			if ( $filtered_total < $cart->get_total() ) {
				$nvpData['L_PAYMENTREQUEST_0_NAME'.$i] = self::__( 'Applied Credit' );
				$nvpData['L_PAYMENTREQUEST_0_AMT'.$i] = gb_get_number_format( $filtered_total - $cart->get_total() );
				$nvpData['L_PAYMENTREQUEST_0_QTY'.$i] = '1';
				$nvpData['PAYMENTREQUEST_0_ITEMAMT'] = gb_get_number_format( $cart->get_subtotal() + $filtered_total - $cart->get_total() );
				$i++;
			}
		}

		$nvpData = apply_filters( 'gb_paypal_ec_set_nvp_data', $nvpData );
		if ( self::DEBUG ) {
			error_log( '----------Borgun EC SetCheckout Data----------' );
			error_log( print_r( $nvpData, TRUE ) );
		}

		//Last but not least md5 hash !!!
		$uc_borgun_orderhash_check = $item['deal_id'] . self::$returnurlsuccessserver_url . self::$api_secretcode;
		//$nvpData['testhash'] = $item['deal_id'] . self::$returnurlsuccessserver_url . self::$api_secretcode;
		$uc_borgun_orderhash_check = md5( $uc_borgun_orderhash_check );

		//$nvpData['checkhash'] = $uc_borgun_orderhash_check;
		$nvpData['checkhash'] = 'ba652b6c52ec917f16eea2906295b276';

		$nvpData['checkhashTESTSTRING'] = $uc_borgun_orderhash_check = $item['deal_id'] . self::$returnurlsuccessserver_url . self::$api_secretcode;

		return apply_filters( 'gb_set_nvp_data', $nvpData, $checkout, $i );
	}

	public function redirect() {
		//echo "redirect";
		wp_redirect ( self::get_redirect_url() . self::$token );
		exit();
	}


	public static function redirectBorgun( $PostData ) {

		$_input = '  <input type="hidden" name="%s" value="%s"  />';
		$_html = array();

		$_html[] = "<html>";
		$_html[] = "<head><title>Processing Payment...</title><meta http-equiv=\"Content-Type\" content=\"text/html; charset=UTF-8\" /></head>";
		//$_html[] = "<body onLoad=\"document.forms['3d_secure'].submit();\">";
		$_html[] = "<body>";
		$_html[] = '<center><img src="'. gb_get_header_logo() .'"></center>';
		$_html[] =  "<center><h2>";
		$_html[] = self::__( "Vinsamlegast b����i��, ���� ver��ur fluttur eftir augnablik �� vefs����u Borgunar" );
		$_html[] =  "</h2></center>";


		$_html[] = '<form name="3d_secure" action="'. self::get_api_url() .'" method="post">';

		foreach ( $PostData as $key => $value ) {
			$_html[] = '<input type="hidden" value="'. $value .'" name="'. $key .'" />';
		}


		$_html[] =  "<center><br/><br/>";
		$_html[] =  self::__( "Ef ���� ert ekki fluttur sj��lfkrafa �� Borgun, ��ttu ���� h��r �� takkann fyrir ne��an" );
		$_html[] =  "<br/><br/>\n";
		$_html[] =  '<input type="submit" value="'.self::__( 'Borgun' ).'"></center>';


		$_html[] = '</form>';
		$_html[] = '</body>';
		$return = implode( "\n", $_html );



		print $return;

		exit();
	}


	public static function set_token( $token ) {
		global $blog_id;
		update_user_meta( get_current_user_id(), $blog_id.'_'.self::TOKEN_KEY, $token );
	}

	public static function unset_token() {
		global $blog_id;
		delete_user_meta( get_current_user_id(), $blog_id.'_'.self::TOKEN_KEY );
	}

	public static function get_token() {
		global $blog_id;
		return get_user_meta( get_current_user_id(), $blog_id.'_'.self::TOKEN_KEY, TRUE );
	}

	public static function set_payerid( $get_payerid ) {
		global $blog_id;
		update_user_meta( get_current_user_id(), $blog_id.'_'.self::PAYER_ID, $get_payerid );
	}

	public static function get_payerid() {
		global $blog_id;
		return get_user_meta( get_current_user_id(), $blog_id.'_'.self::PAYER_ID, TRUE );
	}


	/**
	 * Build the NVP data array for submitting the current checkout to PayPal as an Authorization request
	 *
	 * @param Group_Buying_Checkouts $checkout
	 * @param Group_Buying_Purchase $purchase
	 * @return array
	 */
	private function process_nvp_data( Group_Buying_Checkouts $checkout, Group_Buying_Purchase $purchase ) {
		$cart = $checkout->get_cart();

		$user = get_userdata( get_current_user_id() );
		$filtered_total = $this->get_payment_request_total( $checkout );
		if ( $filtered_total < 0.01 ) {
			return array();
		}
		$nvpData = array();

		$nvpData['USER'] = self::$api_username;
		$nvpData['PWD'] = self::$api_password;
		$nvpData['SIGNATURE'] = self::$api_signature;
		$nvpData['MERCHANTID'] = self::$api_merchantid;
		$nvpData['PAGETYPE'] = self::$api_pagetype;
		$nvpData['PAYMENTGATEWAYID'] = self::$api_paymentgatwayid;
		$nvpData['MERCHANTLOGO'] = self::$api_merchantlogo;



		$nvpData['VERSION'] = self::$version;

		$nvpData['TOKEN'] = self::get_token();
		$nvpData['PAYERID'] = self::get_payerid();

		$nvpData['METHOD'] = 'DoExpressCheckoutPayment';
		$nvpData['PAYMENTREQUEST_0_PAYMENTACTION'] = 'Authorization';
		$nvpData['IPADDRESS'] = $_SERVER ['REMOTE_ADDR'];

		$nvpData['PAYMENTREQUEST_0_AMT'] = gb_get_number_format( $filtered_total );
		$nvpData['PAYMENTREQUEST_0_CURRENCYCODE'] = self::get_currency_code();
		$nvpData['currency'] = self::get_currency_code();

		$nvpData['PAYMENTREQUEST_0_ITEMAMT'] = gb_get_number_format( $cart->get_subtotal() );
		$nvpData['PAYMENTREQUEST_0_SHIPPINGAMT'] = gb_get_number_format( $cart->get_shipping_total() );
		$nvpData['PAYMENTREQUEST_0_TAXAMT'] = gb_get_number_format( $cart->get_tax_total() );
		$nvpData['PAYMENTREQUEST_0_INVNUM'] = $purchase->get_id();
		$nvpData['BUTTONSOURCE'] = self::PLUGIN_NAME;

		if ( isset( $checkout->cache['shipping'] ) ) {
			$nvpData['NOSHIPPING'] = 2;
			$nvpData['ADDROVERRIDE'] = 1;
			$nvpData['PAYMENTREQUEST_0_SHIPTONAME'] = $checkout->cache['shipping']['first_name'].' '.$checkout->cache['shipping']['last_name'];
			$nvpData['PAYMENTREQUEST_0_SHIPTOSTREET'] = $checkout->cache['shipping']['street'];
			$nvpData['PAYMENTREQUEST_0_SHIPTOCITY'] = $checkout->cache['shipping']['city'];
			$nvpData['PAYMENTREQUEST_0_SHIPTOSTATE'] = $checkout->cache['shipping']['zone'];
			$nvpData['PAYMENTREQUEST_0_SHIPTOZIP'] = $checkout->cache['shipping']['postal_code'];
			$nvpData['PAYMENTREQUEST_0_SHIPTOCOUNTRYCODE'] = $checkout->cache['shipping']['country'];
		}

		$i = 0;
		if (
			$nvpData['PAYMENTREQUEST_0_ITEMAMT'] == gb_get_number_format( 0 ) ||
			( $filtered_total < $cart->get_total()
				&& ( $cart->get_subtotal() + $filtered_total - $cart->get_total() ) == 0
			)
		) {
			// handle free/credit purchases (paypal requires minimum 0.01 item amount)
			if ( $nvpData['PAYMENTREQUEST_0_SHIPPINGAMT'] != gb_get_number_format( 0 ) ) {
				$nvpData['PAYMENTREQUEST_0_ITEMAMT'] = $nvpData['PAYMENTREQUEST_0_SHIPPINGAMT'];
				$nvpData['PAYMENTREQUEST_0_SHIPPINGAMT'] = gb_get_number_format( 0 );
			} elseif ( $nvpData['PAYMENTREQUEST_0_TAXAMT'] != gb_get_number_format( 0 ) ) {
				$nvpData['PAYMENTREQUEST_0_ITEMAMT'] = $nvpData['PAYMENTREQUEST_0_TAXAMT'];
				$nvpData['PAYMENTREQUEST_0_TAXAMT'] = gb_get_number_format( 0 );
			}
		} else {
			foreach ( $cart->get_items() as $key => $item ) {
				$deal = Group_Buying_Deal::get_instance( $item['deal_id'] );
				$nvpData['L_PAYMENTREQUEST_0_NAME'.$i] = $deal->get_title( $item['data'] );
				$nvpData['itemdescription'.$i] = $deal->get_title( $item['data'] );

				$nvpData['L_PAYMENTREQUEST_0_AMT'.$i] = gb_get_number_format( $deal->get_price( NULL, $item['data'] ) );
				$nvpData['L_PAYMENTREQUEST_0_NUMBER'.$i] = $item['deal_id'];
				$nvpData['L_PAYMENTREQUEST_0_QTY'.$i] = $item['quantity'];

				$nvpData['itemcount_'.$i] = $item['quantity'];

				$i++;
			}
			if ( $filtered_total < $cart->get_total() ) {
				$nvpData['L_PAYMENTREQUEST_0_NAME'.$i] = self::__( 'Applied Credit' );
				$nvpData['L_PAYMENTREQUEST_0_AMT'.$i] = gb_get_number_format( $filtered_total - $cart->get_total() );
				$nvpData['L_PAYMENTREQUEST_0_QTY'.$i] = '1';
				$nvpData['PAYMENTREQUEST_0_ITEMAMT'] = gb_get_number_format( $cart->get_subtotal() + $filtered_total - $cart->get_total() );
			}
		}

		$nvpData = apply_filters( 'gb_paypal_ec_nvp_data', $nvpData, $checkout, $i, $purchase );

		return $nvpData;
	}

	/**
	 * Capture a pre-authorized payment
	 *
	 * @param Group_Buying_Purchase $purchase
	 * @return void
	 */
	public function capture_purchase( Group_Buying_Purchase $purchase ) {
		$payments = Group_Buying_Payment::get_payments_for_purchase( $purchase->get_id() );
		foreach ( $payments as $payment_id ) {
			$payment = Group_Buying_Payment::get_instance( $payment_id );
			$this->capture_payment( $payment );
		}
	}

	/**
	 * Try to capture all pending payments
	 *
	 * @return void
	 */
	public function capture_pending_payments() {
		$payments = Group_Buying_Payment::get_pending_payments();
		foreach ( $payments as $payment_id ) {
			$payment = Group_Buying_Payment::get_instance( $payment_id );
			$this->capture_payment( $payment );
		}
	}

	public  function capture_payment( Group_Buying_Payment $payment ) {
		// is this the right payment processor? does the payment still need processing?
		if ( $payment->get_payment_method() == $this->get_payment_method() && $payment->get_status() != Group_Buying_Payment::STATUS_COMPLETE ) {
			$data = $payment->get_data();
			// Do we have a transaction ID to use for the capture?
			if ( isset( $data['api_response']['PAYMENTINFO_0_TRANSACTIONID'] ) && $data['api_response']['PAYMENTINFO_0_TRANSACTIONID'] ) {
				$transaction_id = $data['api_response']['PAYMENTINFO_0_TRANSACTIONID'];
				$items_to_capture = $this->items_to_capture( $payment );
				if ( $items_to_capture ) {
					$status = ( count( $items_to_capture ) < count( $data['uncaptured_deals'] ) )?'NotComplete':'Complete';
					$post_data = $this->capture_nvp_data( $transaction_id, $items_to_capture, $status );
					if ( self::DEBUG ) {
						error_log( '----------Borgun EC DoCapture Request----------' );
						error_log( print_r( $post_data, TRUE ) );
					}
					$response = wp_remote_post( $this->get_api_url(), array(
							'body' => $post_data,
							'timeout' => apply_filters( 'http_request_timeout', 15 ),
							'sslverify' => false
						) );
					if ( !is_wp_error( $response ) && $response['response']['code'] == '200' ) {
						$response = wp_parse_args( wp_remote_retrieve_body( $response ) );
						if ( self::DEBUG ) {
							error_log( '----------Borgun EC DoCapture Response----------' );
							error_log( print_r( $response, TRUE ) );
						}
						if ( strpos( $response['ACK'], 'Success' ) === 0 ) {
							foreach ( $items_to_capture as $deal_id => $amount ) {
								unset( $data['uncaptured_deals'][$deal_id] );
							}
							if ( !isset( $data['capture_response'] ) ) {
								$data['capture_response'] = array();
							}
							$data['capture_response'][] = $response;
							$payment->set_data( $data );
							do_action( 'payment_captured', $payment, array_keys( $items_to_capture ) );
							if ( $status == 'Complete' ) {
								$payment->set_status( Group_Buying_Payment::STATUS_COMPLETE );
								do_action( 'payment_complete', $payment );
							} else {
								$payment->set_status( Group_Buying_Payment::STATUS_PARTIAL );
							}
						} else {
							$this->set_error_messages( $response, FALSE );
						}
					}
				}
			}
		}
	}

	/**
	 * The the NVP data for submitting a DoCapture request
	 *
	 * @param string  $transaction_id
	 * @param array   $items
	 * @param string  $status
	 * @return array
	 */
	private function capture_nvp_data( $transaction_id, $items, $status = 'Complete' ) {
		$total = 0;
		foreach ( $items as $price ) {
			$total += $price;
		}
		$nvpData = array();

		$nvpData['USER'] = self::$api_username;
		$nvpData['PWD'] = self::$api_password;
		$nvpData['SIGNATURE'] = self::$api_signature;
		$nvpData['MERCHANTID'] = self::$api_merchantid;
		$nvpData['PAGETYPE'] = self::$api_pagetype;
		$nvpData['PAYMENTGATEWAYID'] = self::$api_paymentgatwayid;
		$nvpData['MERCHANTLOGO'] = self::$api_merchantlogo;



		$nvpData['VERSION'] = self::$version;

		$nvpData['METHOD'] = 'DoCapture';
		$nvpData['AUTHORIZATIONID'] = $transaction_id;
		$nvpData['AMT'] = gb_get_number_format( $total );
		$nvpData['CURRENCYCODE'] = self::get_currency_code();
		$nvpData['COMPLETETYPE'] = $status;

		$nvpData = apply_filters( 'gb_paypal_ec_capture_nvp_data', $nvpData );

		//$nvpData = array_map('rawurlencode', $nvpData);
		return $nvpData;
	}

	private function get_currency_code() {
		return apply_filters( 'gb_paypal_ec_currency_code', self::$currency_code );
	}

	/**
	 * Create recurring payment profiles for any recurring deals in the purchase
	 *
	 * @param Group_Buying_Checkouts $checkout
	 * @param Group_Buying_Purchase $purchase
	 * @return void
	 */
	private function create_recurring_payment_profiles( Group_Buying_Checkouts $checkout, Group_Buying_Purchase $purchase ) {
		foreach ( $purchase->get_products() as $item ) {
			if ( isset( $item['payment_method'][$this->get_payment_method()] ) && isset( $item['data']['recurring'] ) && $item['data']['recurring'] ) {
				// make a separate recurring payment for each item,
				// so they can be cancelled separately if necessary
				$this->create_recurring_payment_profile( $item, $checkout, $purchase );
			}
		}
	}

	/**
	 * Create the recurring payment profile.
	 *
	 * Start on the second payment, as the first payment is included in the initial purchase
	 *
	 * @param array   $item
	 * @param Group_Buying_Checkouts $checkout
	 * @param Group_Buying_Purchase $purchase
	 * @return bool Whether we succeeded in creating a recurring payment profile
	 */
	private function create_recurring_payment_profile( $item, Group_Buying_Checkouts $checkout, Group_Buying_Purchase $purchase ) {
		echo "create_recurring_payment_profile";

		// see https://cms.paypal.com/us/cgi-bin/?cmd=_render-content&content_ID=developer/e_howto_api_nvp_r_CreateRecurringPayments
		$nvpData = $this->create_recurring_payment_nvp_data( $item, $checkout, $purchase );
		if ( !$nvpData ) {
			return FALSE; // paying for it some other way
		}

		if ( self::DEBUG ) {
			error_log( '----------Borgun EC Recurring Payment Request ----------' );
			error_log( print_r( $nvpData, TRUE ) );
		}

		$response = wp_remote_post( self::get_api_url(), array(
				'method' => 'POST',
				'body' => $nvpData,
				'timeout' => apply_filters( 'http_request_timeout', 15 ),
				'sslverify' => false
			) );

		if ( self::DEBUG ) {
			error_log( '----------Borgun EC Recurring Payment Response (Raw)----------' );
			error_log( print_r( $response, TRUE ) );
		}

		if ( is_wp_error( $response ) ) {
			return FALSE;
		}
		if ( $response['response']['code'] != '200' ) {
			return FALSE;
		}

		$response = wp_parse_args( wp_remote_retrieve_body( $response ) );

		if ( self::DEBUG ) {
			error_log( '----------Borgun EC Recurring Payment Response (Parsed)----------' );
			error_log( print_r( $response, TRUE ) );
		}

		if ( empty( $response['PROFILEID'] ) ) {
			do_action( 'gb_paypal_recurring_payment_profile_failed' );
			return FALSE;
		}

		// create a payment to store the API response
		$payment_id = Group_Buying_Payment::new_payment( array(
				'payment_method' => $this->get_payment_method(),
				'purchase' => $purchase->get_id(),
				'amount' => $item['data']['recurring']['price'],
				'data' => array(
					'api_response' => $response,
				),
			), Group_Buying_Payment::STATUS_RECURRING );

		// let the world know
		do_action( 'gb_paypal_recurring_payment_profile_created', $payment_id );
		return TRUE;
	}

	private function create_recurring_payment_nvp_data( $item, Group_Buying_Checkouts $checkout, Group_Buying_Purchase $purchase ) {
		$deal = Group_Buying_Deal::get_instance( $item['deal_id'] );
		$user = get_userdata( get_current_user_id() );
		$term = $item['data']['recurring']['term']; // day, week, month, or year
		$duration = (int)$item['data']['recurring']['duration'];
		$price = $item['data']['recurring']['price'];

		$terms = array(
			'day' => 'Day',
			'week' => 'Week',
			'month' => 'Month',
			'year' => 'Year',
		);
		if ( !isset( $terms[$term] ) ) {
			$term = 'day';
		}

		$starts = strtotime( date( 'Y-m-d' ).' +'.$duration.' '.$term );

		$nvp = array(
			'USER' => self::$api_username,
			'PWD' => self::$api_password,
			'SIGNATURE' => self::$api_signature,
			'MERCHANTID' => self::$api_merchantid,
			'PAGETYPE' => self::$api_pagetype,
			'PAYMENTGATEWAYID' => self::$api_paymentgatwayid,
			'MERCHANTLOGO' => self::$api_merchantlogo,


			'VERSION' => self::$version,
			'METHOD' => 'CreateRecurringPaymentsProfile',
			'TOKEN' => self::get_token(),
			'PROFILESTARTDATE' => date( 'Y-m-d', $starts ).'T00:00:00Z',
			'PROFILEREFERENCE' => $purchase->get_id(),
			'DESC' => $deal->get_title( $item['data'] ),
			'MAXFAILEDPAYMENTS' => 2,
			'AUTOBILLOUTAMT' => 'AddToNextBilling',
			'BILLINGPERIOD' => $terms[$term],
			'BILLINGFREQUENCY' => $duration,
			'TOTALBILLINGCYCLES' => 0,
			'AMT' => gb_get_number_format( $price ),
			'CURRENCYCODE' => self::get_currency_code(),
			'MerchantEMail' => $user->user_email,
			'L_PAYMENTREQUEST_0_ITEMCATEGORY0' => 'Digital',
			'L_PAYMENTREQUEST_0_NAME0' => $deal->get_title( $item['data'] ),
			'L_PAYMENTREQUEST_0_AMT0' => gb_get_number_format( $price ),
			'L_PAYMENTREQUEST_0_NUMBER0' => $deal->get_id(),
			'L_PAYMENTREQUEST_0_QTY0' => 1,
		);
		return $nvp;
	}

	/**
	 *
	 *
	 * @param Group_Buying_Payment $payment
	 * @return void
	 */
	public function verify_recurring_payment( Group_Buying_Payment $payment ) {
		// Check if the payment has a recurring profile ID (in $data['api_response'])
		$data = $payment->get_data();
		if ( empty( $data['api_response']['PROFILEID'] ) ) {
			return;
		}
		// Get the profile status
		//  - see https://cms.paypal.com/us/cgi-bin/?cmd=_render-content&content_ID=developer/e_howto_api_nvp_r_GetRecurringPaymentsProfileDetails
		$status = $this->get_recurring_payment_status( $data['api_response']['PROFILEID'] );
		if ( $status != 'Active' ) {
			$payment->set_status( Group_Buying_Payment::STATUS_CANCELLED );
		}
	}

	private function get_recurring_payment_status( $profile_id ) {
		$nvp = array(
			'USER' => self::$api_username,
			'PWD' => self::$api_password,
			'SIGNATURE' => self::$api_signature,
			'MERCHANTID' => self::$api_merchantid,
			'PAYMENTGATEWAYID' => self::$api_paymentgatwayid,

			'VERSION' => self::$version,
			'METHOD' => 'GetRecurringPaymentsProfileDetails',
			'PROFILEID' => $profile_id,
		);

		if ( self::DEBUG ) {
			error_log( '----------Borgun EC Recurring Payment Details Request ----------' );
			error_log( print_r( $nvp, TRUE ) );
		}

		$response = wp_remote_post( self::get_api_url(), array(
				'method' => 'POST',
				'body' => $nvp,
				'timeout' => apply_filters( 'http_request_timeout', 15 ),
				'sslverify' => false
			) );

		if ( self::DEBUG ) {
			error_log( '----------Borgun EC Recurring Payment Details Response (Raw)----------' );
			error_log( print_r( $response, TRUE ) );
		}

		if ( is_wp_error( $response ) ) {
			return FALSE;
		}
		if ( $response['response']['code'] != '200' ) {
			return FALSE;
		}

		$response = wp_parse_args( wp_remote_retrieve_body( $response ) );

		if ( self::DEBUG ) {
			error_log( '----------Borgun EC Recurring Payment Details Response (Parsed)----------' );
			error_log( print_r( $response, TRUE ) );
		}

		if ( empty( $response['STATUS'] ) ) {
			return FALSE;
		}

		return $response['STATUS'];
	}

	/**
	 *
	 *
	 * @param Group_Buying_Payment $payment
	 * @return void
	 */
	public function cancel_recurring_payment( Group_Buying_Payment $payment ) {
		// Check if the payment has a recurring profile ID (in $data['api_response'])
		$data = $payment->get_data();
		if ( empty( $data['api_response']['PROFILEID'] ) ) {
			return;
		}
		$profile_id = $data['api_response']['PROFILEID'];
		// Cancel the profile
		//  - see https://cms.paypal.com/us/cgi-bin/?cmd=_render-content&content_ID=developer/e_howto_api_nvp_r_ManageRecurringPaymentsProfileStatus

		$nvp = array(
			'USER' => self::$api_username,
			'PWD' => self::$api_password,
			'SIGNATURE' => self::$api_signature,
			'MERCHANTID' => self::$api_merchantid,
			'PAYMENTGATEWAYID' => self::$api_paymentgatwayid,

			'VERSION' => self::$version,
			'METHOD' => 'ManageRecurringPaymentsProfileStatus',
			'PROFILEID' => $profile_id,
			'ACTION' => 'Cancel',
			'NOTE' => apply_filters( 'gbs_paypal_recurring_payment_cancelled_note', '' ),
		);

		if ( self::DEBUG ) {
			error_log( '----------Borgun EC Cancel Recurring Payment Request ----------' );
			error_log( print_r( $nvp, TRUE ) );
		}

		$response = wp_remote_post( self::get_api_url(), array(
				'method' => 'POST',
				'body' => $nvp,
				'timeout' => apply_filters( 'http_request_timeout', 15 ),
				'sslverify' => false
			) );

		if ( self::DEBUG ) {
			error_log( '----------Borgun EC Cancel Recurring Payment Response (Raw)----------' );
			error_log( print_r( $response, TRUE ) );
		}
		// we don't really need to do anything with the response. It's either a success message
		// or the profile is already cancelled/suspended. Either way, we're good.
		parent::cancel_recurring_payment( $payment );
	}

	public function register_settings() {
		$page = Group_Buying_Payment_Processors::get_settings_page();
		$section = 'gb_paypal_settings';
		add_settings_section( $section, self::__( 'Borgun Payments Standard' ), array( $this, 'display_settings_section' ), $page );
		register_setting( $page, self::API_MODE_OPTION );
		register_setting( $page, self::API_USERNAME_OPTION );
		register_setting( $page, self::API_PASSWORD_OPTION );
		register_setting( $page, self::API_SIGNATURE_OPTION );
		register_setting( $page, self::CURRENCY_CODE_OPTION );
		register_setting( $page, self::API_MERCHANTID_OPTION );
		register_setting( $page, self::API_SECRETCODE_OPTION );
		register_setting( $page, self::API_PAGETYPE_OPTION );
		register_setting( $page, self::API_SKIPRECEIPTPAGE_OPTION );
		register_setting( $page, self::API_PAYMENTGATEWAYID_OPTION );
		register_setting( $page, self::API_MERCHANTLOGO_OPTION );


		//register_setting($page, self::RETURN_URL_OPTION);
		register_setting( $page, self::CANCEL_URL_OPTION );
		add_settings_field( self::API_MODE_OPTION, self::__( 'Mode' ), array( get_class(), 'display_api_mode_field' ), $page, $section );
		add_settings_field( self::API_USERNAME_OPTION, self::__( 'Borgun notendanafn' ), array( get_class(), 'display_api_username_field' ), $page, $section );
		add_settings_field( self::API_PASSWORD_OPTION, self::__( 'Borgun lykilor��' ), array( get_class(), 'display_api_password_field' ), $page, $section );
		add_settings_field( self::API_SIGNATURE_OPTION, self::__( 'API Signature' ), array( get_class(), 'display_api_signature_field' ), $page, $section );
		add_settings_field( self::CURRENCY_CODE_OPTION, self::__( 'Gjaldmi��ill (3 st. k����i)' ), array( get_class(), 'display_currency_code_field' ), $page, $section );
		add_settings_field( self::API_MERCHANTID_OPTION, self::__( 'Merchant id' ), array( get_class(), 'display_api_merchantid_field' ), $page, $section );
		add_settings_field( self::API_SECRETCODE_OPTION, self::__( 'Secrecode' ), array( get_class(), 'display_api_secretcode_field' ), $page, $section );
		add_settings_field( self::API_PAGETYPE_OPTION, self::__( 'Pagetype' ), array( get_class(), 'display_api_pagetype_field' ), $page, $section );
		add_settings_field( self::API_SKIPRECEIPTPAGE_OPTION, self::__( 'Skip Receipt page (yes=1 No=0)' ), array( get_class(), 'display_api_skipReceiptPage_field' ), $page, $section );
		add_settings_field( self::API_PAYMENTGATEWAYID_OPTION, self::__( 'Payment Gateway id' ), array( get_class(), 'display_api_paymentgatewayid_field' ), $page, $section );
		add_settings_field( self::API_MERCHANTLOGO_OPTION, self::__( 'Merchant logo' ), array( get_class(), 'display_api_merchantlogo_field' ), $page, $section );





		//add_settings_field(self::RETURN_URL_OPTION, self::__('Return URL'), array(get_class(), 'display_return_field'), $page, $section);
		add_settings_field( self::CANCEL_URL_OPTION, self::__( 'Cancel URL' ), array( get_class(), 'display_cancel_field' ), $page, $section );
		add_settings_section( 'gb_logs', self::__( 'Logs' ), array( $this, 'display_settings_logs' ), $page );

		echo 'register settings';
	}

	public function display_api_username_field() {
		echo '<input type="text" name="'.self::API_USERNAME_OPTION.'" value="'.self::$api_username.'" size="80" />';
	}

	public function display_api_password_field() {
		echo '<input type="text" name="'.self::API_PASSWORD_OPTION.'" value="'.self::$api_password.'" size="80" />';
	}

	public function display_api_signature_field() {
		echo '<input type="text" name="'.self::API_SIGNATURE_OPTION.'" value="'.self::$api_signature.'" size="80" />';
	}

	public function display_return_field() {
		echo '<input type="text" name="'.self::RETURN_URL_OPTION.'" value="'.self::$return_url.'" size="80" />';
	}

	public function display_cancel_field() {
		echo '<input type="text" name="'.self::CANCEL_URL_OPTION.'" value="'.self::$cancel_url.'" size="80" />';
	}

	public function display_api_mode_field() {
		echo '<label><input type="radio" name="'.self::API_MODE_OPTION.'" value="'.self::MODE_LIVE.'" '.checked( self::MODE_LIVE, self::$api_mode, FALSE ).'/> '.self::__( 'Live' ).'</label><br />';
		echo '<label><input type="radio" name="'.self::API_MODE_OPTION.'" value="'.self::MODE_TEST.'" '.checked( self::MODE_TEST, self::$api_mode, FALSE ).'/> '.self::__( 'Sandbox' ).'</label>';
	}

	public function display_currency_code_field() {
		echo '<input type="text" name="'.self::CURRENCY_CODE_OPTION.'" value="'.self::$currency_code.'" size="5" />';
	}

	public function display_api_merchantid_field() {
		echo '<input type="text" name="'.self::API_MERCHANTID_OPTION.'" value="'.self::$api_merchantid.'" size="80" />';
	}

	public function display_api_secretcode_field() {
		echo '<input type="text" name="'.self::API_SECRETCODE_OPTION.'" value="'.self::$api_secretcode.'" size="80" />';
	}

	public function display_api_pagetype_field() {
		echo '<input type="text" name="'.self::API_PAGETYPE_OPTION.'" value="'.self::$api_pagetype.'" size="80" />';
	}

	public function display_api_skipReceiptPage_field() {
		echo '<input type="text" name="'.self::API_SKIPRECEIPTPAGE_OPTION.'" value="'.self::$api_skipReceiptPage.'" size="80" />';
	}

	public function display_api_paymentgatewayid_field() {
		echo '<input type="text" name="'.self::API_PAYMENTGATEWAYID_OPTION.'" value="'.self::$api_paymentgatwayid.'" size="80" />';
	}

	public function display_api_merchantlogo_field() {
		echo '<input type="text" name="'.self::API_MERCHANTLOGO_OPTION.'" value="'.self::$api_merchantlogo.'" size="80" />';
	}

	public function display_settings_logs() {
		?>
		<script type="text/javascript">
	        jQuery(document).ready(function() {
                jQuery('#debug_wrap').hide();
                jQuery('#logs_link').click(function() {
                        jQuery('#debug_wrap').toggle();
                });
	        });
		</script>
		<?php
		//echo '<a id="logs_link" class="button">'.self::__( 'Logs' ).'</a>';
		//echo '<div id="debug_wrap"><pre>'.print_r( get_option( self::LOGS ), true ).'</pre></div>';
	}

	public function cart_controls( $controls ) {
		//print_r($controls);
		$controls['checkout'] = '<input type="submit" class="form-submit alignright checkout_next_step" value="Paypal" name="gb_cart_action-checkout" />';
		return $controls;
	}


	public function payment_controls( $controls, Group_Buying_Checkouts $checkout ) {
		if ( isset( $controls['review'] ) ) {
			$style = 'style="box-shadow: none;-moz-box-shadow: none;-webkit-box-shadow: none; display: block; width: 160px; height: 42px; background-color: transparent; background-image: url(https://www.borgun.is/library/Skrar/Vorumerki/borgun150px.png); background-repeat:no-repeat no-repeat; background-position: 0 0; padding: 42px 0 0 0; border: none; cursor: pointer; text-indent: -9000px; margin-top: 12px;"';
			$controls['review'] = str_replace( 'value="'.self::__( 'Review' ).'"', $style . ' value="'.self::__( 'Paypal' ).'"', $controls['review'] );
		}
		return $controls;
	}



	/**
	 * Grabs error messages from a PayPal response and displays them to the user
	 *
	 * @param array   $response
	 * @param bool    $display
	 * @return void
	 */
	private function set_error_messages( $message, $display = TRUE ) {
		if ( $display ) {
			self::set_message( $message, self::MESSAGE_STATUS_ERROR );
		} else {
			error_log( $message );
		}
	}
}
Group_Buying_Borgun_EC::register();