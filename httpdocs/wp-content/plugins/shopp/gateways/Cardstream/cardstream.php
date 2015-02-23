<?php
/**
 * Cardstream
 *
 * @author Cardstream
 * @copyright Cardstream
 * @package shopp
 * @version 1.3.5
 * @since 1.1
 **/

defined( 'WPINC' ) || header( 'HTTP/1.1 403' ) & exit; // Prevent direct access

class ShoppCardstream extends GatewayFramework implements GatewayModule {

	// Settings
	public $secure = false;
	public $saleonly = true;

	// Skinning constants
	const RESELLER_NAME = 'Cardstream';
	const RESELLER_FUNC = 'cardstream';
	const TEST_ACCOUNT 	= 100001;
	const TEST_SIG 		= 'Circle4Take40Idea';
	const LIVEURL 		= 'https://gateway.cardstream.com/hosted/';

	public function __construct () {
		parent::__construct();

		$this->setup('merchantID', 'verify', 'secret', 'testmode');

		add_filter('shopp_purchase_order_' . self::RESELLER_FUNC . '_processing', array($this, 'processing'));
		add_action('shopp_remote_payment', array($this, 'returned'));

	}

	public function actions () { /* Not implemented */ }

	public function processing () {
		return array($this, 'submit');
	}

	public function form ( ShoppPurchase $Purchase ) {

		$fields = array();

		$fields['merchantID'] 		 = str_true( $this->settings['testmode'] ) ? self::TEST_ACCOUNT : $this->settings['merchantID'];
		$fields['amount']            = $this->amount( 'total' ) * 100; // multiply by 100 to remove the floating point number
		$fields['transactionUnique'] = date( 'mdy' ) . '-' . date( 'His' ) . '-' .$Purchase->id; // this will stop a customer paying for the same order twice within 5 minutes
		$fields['action']            = 'SALE';
		$fields['type']              = 1; // type =1 for ecommerce, would need to be 2 for moto (staff/phone ordering)
		$fields['redirectURL']       = $this->settings['returnurl']; // the page the customer gets returned to
		$fields['orderRef'] 		 = $Purchase->id;
		$fields['customerAddress']   = $this->Order->Billing->address . "\n" . $this->Order->Billing->xaddress . "\n" . $this->Order->Billing->city . "\n" . $this->Order->Billing->state .
									   "\n" . $this->Order->Billing->country;
		$fields['customerName']      = $this->Order->Billing->name;
		$fields['customerPostcode']  = $this->Order->Billing->postcode;
		$fields['customerEmail']     = $this->Order->Customer->email;
		$fields['customerPhone']     = $this->Order->Customer->phone;

		ksort( $fields );

		$fields['signature'] = hash( 'SHA512', http_build_query( $fields, '', '&' ) . (str_true( $this->settings['testmode'] ) ? self::TEST_SIG : $this->settings['secret'])) . '|' . implode( ',', array_keys( $fields ) );

		return $this->format( $fields );

	}

	/**
	 * Builds a form to send the order to PayPal for processing
	 *
	 * @author Jonathan Davis
	 * @since 1.3
	 *
	 * @return string PayPal cart form
	 **/
	public function submit ( ShoppPurchase $Purchase ) {
		$id = sanitize_key( $this->module );
		$title = Shopp::__( 'Sending order to ' . self::RESELLER_NAME . '&hellip;' );
		$message = '<form id="' . $id . '" action="' . self::LIVEURL . '" method="POST">' .
					$this->form( $Purchase ) .
					'<h1>' . $title . '</h1>' .
					'<noscript>' .
					'<p>' . Shopp::__( 'Click the &quot;Submit Order to ' . self::RESELLER_NAME . '&quot; button below to submit your order to ' . self::RESELLER_NAME . ' for payment processing:' ) . '</p>' .
					'<p><input type="submit" name="submit" value="' . Shopp::__('Submit Order to ' . self::RESELLER_NAME . ''). '" id="' . $id . '" /></p>' .
					'</noscript>' .
					'</form>' .
					'<script type="text/javascript">document.getElementById("' . $id . '").submit();</script></body></html>';

		wp_die( $message, $title, array( 'response' => 200 ) );
	}


	public function returned () {

		if ( $this->id() != $_GET['rmtpay'] ) return; // Not our offsite payment


		if ( isset( $_POST['signature'] ) ) {
			// do a signature check
			ksort( $_POST );
			$signature = $_POST['signature'];
			unset( $_POST['signature'] );
			$check = preg_replace( '/%0D%0A|%0A%0D|%0A|%0D/i', '%0A', http_build_query( $_POST, '', '&' ) . (str_true( $this->settings['testmode'] ) ? self::TEST_SIG : $this->settings['secret']));

			if ( $signature !== hash( 'SHA512', $check ) ) {
				shopp_add_error(Shopp::__( 'The calculated signature of the payment return did not match, for security this order cant complete automatically please contact support.', 'Shopp' ), 'cc_validation_error', SHOPP_TRXN_ERR );
				shopp::redirect( shopp::url( false, 'checkout' ) );

			}
		}

		// do a check to make sure it was actually a good payment
		if ( (int)$_POST['responseCode'] !== 0 ) {
			shopp_add_error(Shopp::__( 'There was a issue with that card, no payment has been taken, please retry', 'Shopp' ), 'cc_validation_error', SHOPP_TRXN_ERR );
			shopp::redirect( shopp::url( false, 'checkout' ) );
		}

		if ( empty($_POST['orderRef']) ) {
			shopp_add_error(Shopp::__('The order submitted by ' . self::RESELLER_NAME . ' did not specify a transaction ID.'), SHOPP_TRXN_ERR);
			Shopp::redirect(Shopp::url(false, 'checkout'));
		}

		$Purchase = ShoppPurchase(new ShoppPurchase((int)$_POST['orderRef']));
		if ( ! $Purchase->exists() ) {
			shopp_add_error(Shopp::__('The order submitted by ' . self::RESELLER_NAME . ' did not match any submitted orders.'), SHOPP_TRXN_ERR);
			Shopp::redirect(Shopp::url(false, 'checkout'));
		}


		add_action( 'shopp_authed_order_event', array( ShoppOrder(), 'notify' ) );
		add_action( 'shopp_authed_order_event', array( ShoppOrder(), 'accounts' ) );
		add_action( 'shopp_authed_order_event', array( ShoppOrder(), 'success' ) );

		shopp_add_order_event($Purchase->id, 'authed', array(
			'txnid' 	=> $_POST['xref'],   			// Transaction ID
			'amount' 	=> (float)$_POST['amount']/100, // Gross amount authorized
			'fees' 		=> false,            			// Fees associated with transaction
			'gateway' 	=> $this->module, 				// The gateway module name
			'paymethod' => self::RESELLER_NAME, 			// Payment method (payment method label from payment settings)
			'paytype' 	=> $pay_method,   				// Type of payment (check, MasterCard, etc)
			'payid' 	=> $invoice_id,     			// Payment ID (last 4 of card or check number or other payment id)
			'capture' 	=> true           				// Capture flag
		));

		ShoppOrder()->purchase = ShoppPurchase()->id;
		Shopp::redirect( Shopp::url(false, 'thanks', false) );

	}

	public function authed ( ShoppPurchase $Order ) {

		$Paymethod = $this->Order->paymethod();
		$Billing = $this->Order->Billing;

		shopp_add_order_event($Order->id, 'authed', array(
			'txnid' => $_POST['xref'],						// Transaction ID
			'amount' => $_POST['ammount']/100,							// Gross amount authorized
			'gateway' => $this->module,								// Gateway handler name (module name from @subpackage)
			'paymethod' => $Paymethod->label,						// Payment method (payment method label from payment settings)
			'paytype' => $Billing->cardtype,						// Type of payment (check, MasterCard, etc)
			'payid' => $Billing->card,								// Payment ID (last 4 of card or check number)
			'capture' => true										// Capture flag
		));

	}

	protected function verify ( $key ) {
		if ( Shopp::str_true($this->settings['testmode']) ) return true;
		$order = $_GET['order_number'];
		$total = $_GET['total'];

		$verification = strtoupper(md5($this->settings['secret'] .
							$this->settings['sid'] .
							$order .
							$total
						));

		return ( $verification == $key );
	}

	protected function returnurl () {
		return add_query_arg('rmtpay', $this->id(), Shopp::url(false, 'thanks'));
	}

	protected function itemname ( $Item ) {
		$name = $Item->name . ( empty($Item->option->label) ? '' : ' ' . $Item->option->label );
		$name = str_replace(array('<', '>'), '', $name);
		return substr($name, 0, 128);
	}

	public function settings () {

		$this->ui->text(0,array(
			'name' => 'merchantID',
			'size' => 10,
			'value' => $this->settings['merchantID'],
			'label' => __('Your ' . self::RESELLER_NAME . ' merchant ID.','Shopp')
		));


		$this->ui->checkbox(0,array(
			'name' => 'verify',
			'checked' => $this->settings['verify'],
			'label' => __('Enable order verification','Shopp')
		));

		$this->ui->text(0,array(
			'name' => 'secret',
			'size' => 10,
			'value' => $this->settings['secret'],
			'label' => __('Your ' . self::RESELLER_NAME . ' signature key.','Shopp')
		));

		$this->ui->checkbox(0,array(
			'name' => 'testmode',
			'checked' => $this->settings['testmode'],
			'label' => __('Enable test mode','Shopp')
		));

		$this->ui->text(1, array(
			'name' => 'returnurl',
			'size' => 64,
			'value' => $this->returnurl(),
			'readonly' => 'readonly',
			'class' => 'selectall',
			'label' => __('','Shopp')
		));

		$script = "var tc ='Shopp" . self::RESELLER_FUNC . "';jQuery(document).bind(tc+'Settings',function(){var $=jqnc(),p='#'+tc+'-',v=$(p+'verify'),t=$(p+'secret');v.change(function(){v.prop('checked')?t.parent().fadeIn('fast'):t.parent().hide();}).change();});";
		$this->ui->behaviors( $script );
	}

}
