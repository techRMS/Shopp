<?php
	/**
	 * CharityClear payment module
	 *
	 * @class      CharityClear
	 *
	 * @author     Paul Lashbrook
	 * @version    1.1.5
	 * @copyright  CharityClear Limited
	 * @package    shopp
	 * @since      1.1
	 * @subpackage CharityClear
	 *
	 * $Id$
	 **/

	class Cardstream extends GatewayFramework implements GatewayModule {

		// Settings
		public $secure = false;
		public $saleonly = true;
		public $precision = 2;
		public $decimals = '.';
		public $thousands = '';

		public $captures = true; // merchant initiated capture supported
		public $refunds = true; // refunds supported


		// URLs
		public $hosted = 'https://gateway.cardstream.com/hosted/'; // for sales
		public $direct = 'https://gateway.cardstream.com/direct/'; // for refunds

		function __construct() {
			parent::__construct();

			$this->setup( 'merchantID', 'verify', 'secret', 'returnurl', 'testmode' );

			$this->settings['returnurl'] = add_query_arg( 'rmtpay', 'process', shoppurl( false, 'thanks', false ) );

			// add_action('shopp_txn_update',array(&$this,'notifications'));
			add_action( 'shopp_cardstream_sale', array(
														$this,
														'sale'
												   ) );
			// add refund event handler
			add_action( 'shopp_cardstream_refund', array(
														  $this,
														  'refund'
													 ) );


		}

		function actions() {
			add_action( 'shopp_process_checkout', array(
													   &$this,
													   'checkout'
												  ), 9 );
			add_action( 'shopp_init_checkout', array(
													&$this,
													'init'
											   ) );

			add_action( 'shopp_init_confirmation', array(
														&$this,
														'confirmation'
												   ) );
			add_action( 'shopp_remote_payment', array(
													 &$this,
													 'returned'
												) );
		}

		function confirmation() {
			add_filter( 'shopp_confirm_url', array(
												  &$this,
												  'url'
											 ) );
			add_filter( 'shopp_confirm_form', array(
												   &$this,
												   'form'
											  ) );
		}

		function checkout() {
			$this->Order->confirm = true;
		}

		function url( $url ) {
			return $this->hosted;
		}

		/**
		 * creates the payment form
		 *
		 * @param $form
		 *
		 * @return string
		 */
		function form( $form ) {

			// get the next order ID...
			$purchasetable = DatabaseObject::tablename( Purchase::$table );
			$next          = DB::query( "SELECT IF ((MAX(id)) > 0,(MAX(id)+1),1) AS id FROM $purchasetable LIMIT 1" );

			$fields = array();

			$fields['merchantID']        = str_true( $this->settings['testmode'] ) ? 100001 : $this->settings['merchantID'];
			$fields['amount']            = $this->amount( 'total' ) * 100; // multiply by 100 to remove the floating point number
			$fields['transactionUnique'] = date( 'mdy' ) . '-' . date( 'His' ) . '-' . $next->id; // this will stop a customer paying for the same order twice within 5 minutes
			$fields['action']            = 'SALE'; // sale as action type as we wants all of dems monies
			$fields['type']              = 1; // type =1 for ecommerce, would need to be 2 for moto (staff/phone ordering)
			$fields['redirectURL']       = $this->settings['returnurl']; // the page the customer gets returned to
			$fields['customerAddress']   = $this->Order->Billing->address . "\n" . $this->Order->Billing->xaddress . "\n" . $this->Order->Billing->city . "\n" . $this->Order->Billing->state .
										   "\n" . $this->Order->Billing->country;
			$fields['customerName']      = $this->Order->Billing->name;
			$fields['customerPostcode']  = $this->Order->Billing->postcode;
			$fields['customerEmail']     = $this->Order->Customer->email;
			$fields['customerPhone']     = $this->Order->Customer->phone;

			ksort( $fields );

			$fields['signature'] = hash( 'SHA512', http_build_query( $fields, '', '&' ) . $this->settings['secret'] ) . '|' . implode( ',', array_keys( $fields ) );

			return $form . $this->format( $fields );
		}

		/**
		 * this function handles the return checking
		 */
		function returned() {

			if ( empty( $_POST['transactionUnique'] ) ) {
				new ShoppError( __( 'The order submitted by Cardstream did not specify a transaction ID.', 'Shopp' ), 'cc_validation_error', SHOPP_TRXN_ERR );
				shopp_redirect( shoppurl( false, 'checkout' ) );
			}

			// check the order values match
			if ( (float)$_POST['amount'] !== $this->amount( 'total' ) * 100 ) {
				new ShoppError( __( 'The order submitted by Cardstream did not match the order value of our order, please contact support.', 'Shopp' ), 'cc_validation_error', SHOPP_TRXN_ERR );
				shopp_redirect( shoppurl( false, 'checkout' ) );
			}

			if ( isset( $_POST['signature'] ) ) {
				// do a signature check
				ksort( $_POST );
				$signature = $_POST['signature'];
				unset( $_POST['signature'] );
				$check = preg_replace( '/%0D%0A|%0A%0D|%0A|%0D/i', '%0A', http_build_query( $_POST, '', '&' ) . $this->settings['secret'] );

				if ( $signature !== hash( 'SHA512', $check ) ) {
					new ShoppError( __( 'The calculated signature of the payment return did not match, for security this order cant complete automatically please contact support.', 'Shopp' ), 'cc_validation_error', SHOPP_TRXN_ERR );
					shopp_redirect( shoppurl( false, 'checkout' ) );

				}
			}

			// do a check to make sure it was actually a good payment
			if ( (int)$_POST['responseCode'] !== 0 ) {
				new ShoppError( __( 'There was a issue with that card, no payment has been taken, please retry', 'Shopp' ), 'cc_validation_error', SHOPP_TRXN_ERR );
				shopp_redirect( shoppurl( false, 'checkout' ) );
			}

			// Create the order and begin processing it
			shopp_add_order_event( false, 'purchase', array(
														   'gateway' => $this->module,
														   'txnid'   => $_POST['xref']
													  ) );

			ShoppOrder()->purchase = ShoppPurchase()->id;
			shopp_redirect( shoppurl( false, 'thanks', false ) );

		}

		/**
		 * @param $Event
		 */
		function sale( $Event ) {

			$Paymethod = $this->Order->paymethod();
			$Billing   = $this->Order->Billing;

			shopp_add_order_event( $Event->order, 'authed', array(
																 'txnid'     => $_POST['xref'],
																 // Transaction ID
																 'amount'    => $_POST['amount'] / 100,
																 // Gross amount authorized
																 'gateway'   => $this->module,
																 // Gateway handler name (module name from @subpackage)
																 'paymethod' => $Paymethod->label,
																 // Payment method (payment method label from payment settings)
																 'paytype'   => $Billing->cardtype,
																 // Type of payment (check, MasterCard, etc)
																 'payid'     => $Billing->card,
																 // Payment ID (last 4 of card or check number)
																 'capture'   => true
																 // Capture flag
															) );

		}

		/**
		 * performs a refund of a transaction
		 *
		 * @param RefundOrderEvent $Event
		 */
		function refund( RefundOrderEvent $Event ) {

			$Purchase = new Purchase( $Event->order );

			$fields = array(
				'merchantID' => $this->settings['merchantID'],
				'orderRef'   => $Event->reason,
				'action'     => 'REFUND',
				'type'       => 2,
				'xref'       => $Event->txnid,
				'amount'     => $Event->amount * 100
			);

			$ret = $this->makeApiCall( $fields );

			if ( $ret['responseCode'] == 0 ) {
				// Initiate shopp refunded event
				shopp_add_order_event( $Purchase->id, 'refunded', array(
																	   'txnid'   => $ret['xref'],
																	   // Transaction ID for the REFUND event
																	   'amount'  => $ret['amount'] / 100,
																	   // Amount refunded
																	   'gateway' => $this->module
																	   // Gateway handler name (module name from @subpackage)
																  ) );
			}

		}

		/**
		 * makes a request to the Cardstream Direct API
		 *
		 * @param $params
		 *
		 * @return array|bool
		 */
		function makeApiCall( $params ) {
			$header = array(
				'http' => array(
					'method'        => 'POST',
					'ignore_errors' => true
				)
			);
			if ( $params !== null && !empty( $params ) ) {
				// check if signature has been provided if not, make it
				if ( !isset( $params['signature'] ) ) {
					$params['signature'] = $this->signRequest( $params );
				}

				$params = http_build_query( $params, '', '&' );

				$header["http"]['header']  = 'Content-Type: application/x-www-form-urlencoded';
				$header['http']['content'] = $params;

			}

			$context = stream_context_create( $header );
			$fp      = fopen( $this->direct, 'rb', false, $context );
			if ( !$fp ) {
				$res = false;
			} else {
				$res = stream_get_contents( $fp );
				parse_str( $res, $res );
			}

			if ( $res === false ) {
				return false;
			}

			return $res;

		}

		/**
		 * @param      $sig_fields
		 * @param null $secret
		 *
		 * @return string
		 */
		function signRequest( $sig_fields, $secret = null ) {

			if ( is_array( $sig_fields ) ) {
				ksort( $sig_fields );
				$sig_fields = http_build_query( $sig_fields, '', '&' ) . ( $secret === null ? $this->settings['secret'] : $secret );
			} else {
				$sig_fields .= ( $secret === null ? $this->settings['secret'] : $secret );
			}

			return hash( 'SHA512', $sig_fields );

		}


		function settings() {

			$this->ui->text( 0, array(
									 'name'  => 'merchantID',
									 'size'  => 10,
									 'value' => $this->settings['merchantID'],
									 'label' => __( 'Your Cardstream merchant account number.', 'Shopp' )
								) );

			$this->ui->text( 0, array(
									 'name'     => 'returnurl',
									 'size'     => 40,
									 'value'    => $this->settings['returnurl'],
									 'readonly' => 'readonly',
									 'classes'  => 'selectall',
									 'label'    => __( 'This for most cases wont need to be changed, added as a config value so the merchant can change just in case', 'Shopp' )
								) );

			$this->ui->checkbox( 1, array(
										 'name'    => 'testmode',
										 'checked' => $this->settings['testmode'],
										 'label'   => __( 'Enable test mode, will default the merchantID to 100001 ignoring the config value', 'Shopp' )
									) );

			$this->ui->text( 1, array(
									 'name'  => 'secret',
									 'size'  => 10,
									 'value' => $this->settings['secret'],
									 'label' => __( 'Your Cardstream secret word for order signatures.', 'Shopp' )
								) );

			$this->ui->p( 1, array(
								  'name'     => 'returnurl',
								  'size'     => 40,
								  'value'    => $this->settings['returnurl'],
								  'readonly' => 'readonly',
								  'classes'  => 'selectall',
								  'content'  => '<span style="width: 300px;">&nbsp;</span>'
							 ) );

		}

	}

?>