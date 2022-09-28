<?php
/**
 * Plugin Name: Payment Gateway For EDD - SecurionPay
 * Author: Sajjad Hossain Sagor
 * Description: Integrate SecurionPay payment gateway to your Easy Digital Downloads (EDD) store.
 * Version: 1.0.1
 * Author URI: http://sajjadhsagor.com
 * Text Domain: edd-securionpay
 * Domain Path: languages
 */

if ( ! defined( 'ABSPATH' ) )
{
	exit;
}

// Define Gateway Name (Used as ID)

define( 'EDD_SECURIONPAY_GATEWAY', 'edd_securionpay_gateway' );

/**
 * Load plugin textdomain.
 */
function edd_securionpay_load_textdomain()
{	
	load_plugin_textdomain( 'edd-securionpay', false, basename( dirname( __FILE__ ) ) . '/languages' );
}

add_action( 'init', 'edd_securionpay_load_textdomain' );

/**
 * Checking if Easy Digital Downloads is either installed or active
 */

register_activation_hook( __FILE__, 'edd_securionpay_check_plugin_activation_status' );

add_action( 'admin_init', 'edd_securionpay_check_plugin_activation_status' );

function edd_securionpay_check_plugin_activation_status()
{
	if ( ! in_array( 'easy-digital-downloads/easy-digital-downloads.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) )
	{
		// Deactivate the plugin
		deactivate_plugins(__FILE__);

		// Throw an error in the wordpress admin console
		$error_message = __( 'SecurionPay for Easy Digital Downloads requires <a href="https://wordpress.org/plugins/easy-digital-downloads/">Easy Digital Downloads</a> plugin to be active! <a href="javascript:history.back()"> Go back & activate Easy Digital Downloads Plugin First.</a>', 'edd-securionpay' );

		wp_die( $error_message, "Easy Digital Downloads Plugin Not Found" );
	}
}

// registers the gateway
add_filter( 'edd_payment_gateways', 'edd_securionpay_register_gateway' );

function edd_securionpay_register_gateway( $gateways )
{	
	$gateways[ EDD_SECURIONPAY_GATEWAY ] = array(
		'admin_label'    => __( 'Securionpay', 'edd-securionpay' ),
		'checkout_label' => __( 'Securionpay Payment Gateway', 'edd-securionpay' ),
	);

	return $gateways;
}

/**
 * Adds the Securionpay gateway settings to the Payment Gateways section.
 *
 * @param $settings
 *
 * @return array
 */
function edd_securionpay_add_settings( $settings )
{
	$edd_securionpay_gateway_settings = array(
		array(
			'id'   => 'edd_securionpay_gateway_heading',
			'type' => 'header',
			'name' => __( 'Securionpay Payment Gateway', 'edd-securionpay' )
		),
		array(
			'id'   => 'edd_securionpay_api_key',
			'type' => 'text',
			'name' => 'API Secret Key',
			'size' => 'regular',
			'desc' => __( 'You can find you Secret Key in <a href="https://securionpay.com/account-settings#api-keys">your account settings</a> once you login to your account.', 'edd-securionpay' )
		)
	);

	return array_merge( $settings, $edd_securionpay_gateway_settings );
}

/**
 * Hooks a function into the filter 'edd_settings_gateways' which is defined by
 * the EDD's core plugin.
 */
add_filter( 'edd_settings_gateways', 'edd_securionpay_add_settings' );

/**
 * Creates a payment on the gateway.
 * See https://securionpay.com/docs/api#charge-create for more information.
 *
 * @param $purchase_data
 *  The argument which will be passed to
 *  the hook edd_gateway_{payment gateway ID}
 *
 * @return bool
 */

use SecurionPay\SecurionPayGateway;
use SecurionPay\Exception\SecurionPayException;

function edd_securionpay_process_purchase( $purchase_data )
{
	// check for nonce to skip security vulnerability
	if( ! wp_verify_nonce( $purchase_data['gateway_nonce'], 'edd-gateway' ) )
	{	
		wp_die( __( 'Nonce verification has failed', 'easy-digital-downloads' ), __( 'Error', 'edd-securionpay' ), array( 'response' => 403 ) );
	}

	global $edd_options;

	// load the securionpay library [https://github.com/securionpay/securionpay-php]
	require dirname(__FILE__) . "/includes/vendor/autoload.php";

	// make up the data to add a pending payment order first
	$payment_data = array(
		'price'        => $purchase_data['price'],
		'date'         => $purchase_data['date'],
		'user_email'   => $purchase_data['user_email'],
		'purchase_key' => $purchase_data['purchase_key'],
		'currency'     => edd_get_currency(),
		'downloads'    => $purchase_data['downloads'],
		'user_info'    => $purchase_data['user_info'],
		'cart_details' => $purchase_data['cart_details'],
		'status'       => 'pending',
	);

	// record the pending payment
	$payment_id = edd_insert_payment( $payment_data );

	// if couldn't process exit and get back to checkout again
	if ( empty( $payment_id ) )
	{	
		edd_send_back_to_checkout( '?payment-mode=' . $purchase_data['post_data']['edd-gateway'] );
	}

	// if not API key set then no going forward
	if ( empty( $edd_options['edd_securionpay_api_key'] ) )
	{	
		// send user to payment failed page
		wp_redirect( get_permalink( $edd_options['failure_page'] ) );
	}

	// SecurionPay API key
	$api_key  = $edd_options['edd_securionpay_api_key'];

	// amount to charge
	$amount   = edd_securionpay_get_amount( $purchase_data['price'], edd_get_currency() );
	
	// success page to redirect after payment processed successfully
	$callback = get_permalink( $edd_options['success_page'] );

	// initiate the SecurionPay gateway library class
	$gateway = new SecurionPayGateway( $api_key );

	// make up the data to send to Gateway API
	$request = array(
	    'amount' => $amount,
	    'currency' => edd_get_currency(),
	    'card' => array(
	        'cardholderName' => $purchase_data['card_info']['card_name'],
	        'number' 		 => $purchase_data['card_info']['card_number'],
	        'cvc' 		 	 => $purchase_data['card_info']['card_cvc'],
	        'expMonth' 		 => $purchase_data['card_info']['card_exp_month'],
	        'expYear' 		 => $purchase_data['card_info']['card_exp_year'],
	        'addressLine1'   => $purchase_data['card_info']['card_address'],
	        'addressCity'    => $purchase_data['card_info']['card_city'],
	        'addressState'   => $purchase_data['card_info']['card_state'],
	        'addressZip'     => $purchase_data['card_info']['card_zip'],
	        'addressCountry' => $purchase_data['card_info']['card_country']
	    )
	);

	// go for it... charge the amount and see if it has any chance...
	try
	{    
	    // the charge object after successfully charging a card
	    // do something with charge object - see https://securionpay.com/docs/api#charge-object
	    $charge = $gateway->createCharge( $request );

	    // charge id will be used as TransactionID for reference
	    $chargeId = $charge->getId();

	    // Saves transaction id
	    edd_insert_payment_note( $payment_id, '_edd_securionpay_transaction_id', $chargeId );
		edd_insert_payment_note( $payment_id, __( 'Transaction ID : ', 'edd-securionpay' ) . $chargeId );
		
		// now we better empty the cart
		edd_empty_cart();
		
		// we change the payment status as completed
		edd_update_payment_status( $payment_id, 'publish' );
		
		edd_send_to_success_page();
	}
	catch ( SecurionPayException $e )
	{
		//something went wrong buddy!
	    
	    // handle error response - see https://securionpay.com/docs/api#error-object
	    $errorType = $e->getType();
	    $errorCode = $e->getCode();
	    $errorMessage = $e->getMessage();

	    // add the error message to the order
		edd_insert_payment_note( $payment_id, $errorMessage );

		// change the order as failed
		edd_update_payment_status( $payment_id, 'failed' );

		// redirect 
		wp_redirect( get_permalink( $edd_options['failure_page'] ) );
	}
}

/**
 * Hooks into edd_gateway_{payment gateway ID} which is defined in the
 * EDD's core plugin.
 */
add_action( 'edd_gateway_' . EDD_SECURIONPAY_GATEWAY, 'edd_securionpay_process_purchase' );

/**
 * Shows checkbox to automatically refund payments made in Securionpay.
 *
 * @since  1.0.0
 *
 * @param int $payment_id The current payment ID.
 * @return void
 */
function edd_securionpay_refund_admin_js( $payment_id = 0 )
{
	global $edd_options;

	// If not the proper gateway, return early.
	if ( EDD_SECURIONPAY_GATEWAY !== edd_get_payment_gateway( $payment_id ) )
	{
		return;
	}

	// If our credentials (Secret key) are not set, return early.
	if ( empty( $edd_options['edd_securionpay_api_key'] ) )
	{
		return;
	}

	// Localize the refund checkbox label.
	$label = __( 'Refund Payment in Securionpay', 'edd-securionpay' );

	?>
	<script type="text/javascript">
		jQuery( document ).ready( function( $ )
		{
			$( 'select[name=edd-payment-status]' ).change( function()
			{
				if ( 'refunded' == $( this ).val() )
				{
					$( this ).parent().parent().append( '<input type="checkbox" id="edd-securionpay-refund" name="edd-securionpay-refund" value="1" style="margin-top:0">' );
					$( this ).parent().parent().append( '<label for="edd-securionpay-refund"><?php echo $label; ?></label>' );
				}
				else
				{
					$( '#edd-securionpay-refund' ).remove();
					
					$( 'label[for="edd-securionpay-refund"]' ).remove();
				}
			});
		});
	</script>
	<?php
}

add_action( 'edd_view_order_details_before', 'edd_securionpay_refund_admin_js', 100 );

/**
 * Possibly refunds a payment made with Securionpay Gateway.
 *
 * @since  1.0.0
 *
 * @param int $payment_id The current payment ID.
 * @return void
 */
function edd_maybe_refund_securionpay_purchase( EDD_Payment $payment )
{
	// check if current user can actually have the permission to process refund
	if( ! current_user_can( 'edit_shop_payments', $payment->ID ) )
	{
		return;
	}

	// is it a refund request
	if( empty( $_POST['edd-securionpay-refund'] ) )
	{
		return;
	}

	// If the payment has already been refunded in the past, return early.
	$processed = $payment->get_meta( '_edd_securionpay_refunded', true );
	
	if ( $processed )
	{
		return;
	}

	// If not Securionpay, return early.
	if ( EDD_SECURIONPAY_GATEWAY !== $payment->gateway )
	{
		return;
	}

	// Process the refund in Securionpay.
	edd_refund_securionpay_purchase( $payment );
}

add_action( 'edd_pre_refund_payment', 'edd_maybe_refund_securionpay_purchase', 999 );

/**
 * Refunds a purchase made via Securionpay.
 *
 * @since  1.0.0
 *
 * @param object|int $payment The payment ID or object to refund.
 * @return void
 */
function edd_refund_securionpay_purchase( $payment )
{
	global $edd_options;

	// If our credentials (Secret key) are not set, return early.
	$api_key  = empty( $edd_options['edd_securionpay_api_key'] ) ? '' : $edd_options['edd_securionpay_api_key'];

	if ( empty( $api_key ) )
	{
		return ;
	}

	// if not $payment is an intance of EDD_Payment class then create one quick
	if( ! $payment instanceof EDD_Payment && is_numeric( $payment ) ) {
		
		$payment = new EDD_Payment( $payment );
	}

	// the transaction_id we saved while checkout
	$chargeID = $payment->get_meta( '_edd_securionpay_transaction_id', true );

	// if not set then no refund can happen buddy!
	if ( empty( $chargeID ) )
	{
		return;
	}

	// load the securionpay library [https://github.com/securionpay/securionpay-php]
	require dirname(__FILE__) . "/includes/vendor/autoload.php";

	// initiate the SecurionPay gateway library class
	$gateway = new SecurionPayGateway( $api_key );

	// make up the data to send to Gateway API
	$request = array(
	    'chargeId' => $chargeID
	);

	// try to refund the amount
	try
	{    
	    $refund = $gateway->refundCharge( $request );

	    // do something with charge object - see https://securionpay.com/docs/api#charge-object
	    $chargeId = $refund->getId();
	    $Amount   = $refund->getAmount();
	    $Currency = $refund->getCurrency();

	    // Prevents the Securionpay gateway from trying to process the refund multiple times...
		$payment->update_meta( '_edd_securionpay_refunded', true );

		$payment->add_note( sprintf( __( 'Securionpay successfully refunded %s %s', 'edd-securionpay' ), $Amount, $Currency ) );
	}
	catch ( SecurionPayException $e )
	{    
	    // handle error response - see https://securionpay.com/docs/api#error-object
	    $errorMessage = $e->getMessage();

	    $payment->add_note( sprintf( __( 'Securionpay refund failed : %s', 'edd-securionpay' ), $errorMessage ) );

		return false;
	}

	// Run hook letting people know the payment has been refunded successfully.
	do_action( 'edd_securionpay_refund_purchase', $payment );
}

/**
 * Helper function to convert amount to minor unit
 *
 * Charge amount in minor units of given currency.
 * For example 10€ is represented as "1000" and 10¥ is represented as "10".
 * @param $amount
 * @param $currency
 *
 * @return float|int
 */
function edd_securionpay_get_amount( $amount, $currency )
{
	// if it's Chinese yuan (¥) or japanese yen then no amount conversion
	switch ( strtolower( $currency ) )
	{	
		case strtolower( 'JPY' ):
		case strtolower( 'BIF' ):
		case strtolower( 'CLP' ):
		case strtolower( 'DJF' ):
		case strtolower( 'GNF' ):
		case strtolower( 'ISK' ):
		case strtolower( 'KMF' ):
		case strtolower( 'KRW' ):
		case strtolower( 'PYG' ):
		case strtolower( 'RWF' ):
		case strtolower( 'UGX' ):
		case strtolower( 'UYI' ):
		case strtolower( 'XAF' ):
			return $amount;
		break;
		
		default:
			return $amount * 100;
		break;
	}
}
