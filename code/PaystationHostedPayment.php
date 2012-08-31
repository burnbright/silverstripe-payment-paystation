<?php
/**
 * PaystationHostedPayment - www.paystation.co.nz
 * Contains improvements
 * 
 * Test cards:
 * Type - number - expiry - security code
 * VISA - 4987654321098769 - 0513 - 100
 * MASTERCARD - 5123456789012346 - 0513 - 100
 * 
 * How to get different responses (by changing transaction cents value):
 * cents - response - response code
 * .00 - approved - 0
 * .51 - Insufficient Funds -5 
 * .57 - Invalid transaction - 8
 * .54 - Expired card - 4
 * .91 - Error communicating with bank - 6
 * 
 * URL paramaters:
 * 
 * paystation (REQUIRED)
 * pstn_pi = paystation ID (REQUIRED) - This is an initiator flag for the payment engine and can be nothing, or if your environment requires to assign a value please send ‘_empty’
 * pstn_gi = Gateway ID (REQUIRED) - The Gateway ID that the payments will be made against
 * pstn_ms = Merchant Session (REQUIRED) - a unique identification code for each financial transaction request. Used to identify the transaction when tracing transactions. Must be unique for each attempt at every transaction.
 * pstn_am = Ammount (REQUIRED) - the amount of the transaction, in cents.
 * pstn_cu = Currency - the three letter currency identifier. If not sent the default currency for the gateway is used.
 * pstn_tm = Test Mode - sets the Paystation server into Test Mode (for the single transaction only). It uses the merchants TEST account on the VPS server, and marks the transaction as a Test in the Paystation server. This allows the merchant to run test transactions without incurring any costs or running live card transactions.
 * pstn_mr = Merchant Reference Code - a non-unique reference code which is stored against the transaction. This is recommended because it can be used to tie the transaction to a merchants customers account, or to tie groups of transactions to a particular ledger at the merchant. This will be seen from Paystation Admin. pstn_mr can be empty or omitted.
 * pstn_ct = Card Type - the type of card used. When used, the card selection screen is skipped and the first screen displayed from the bank systems is the card details entry screen. Your merchant account must be enabled for External Payment Selection (EPS), you may have to ask your bank to enable this - check with us if you have problems. CT cannot be empty, but may be omitted.
 * pstn_af = Ammount Format - Tells Paystation what format the Amount is in. If omitted, it will be assumed the amount is in cents
 * 
*/
class PaystationHostedPayment extends Payment {
	
	static $db = array(
		'MerchantSession' => 'Varchar',
		'TransactionID' => 'Varchar'
	);
	
	protected static $privacy_link = 'http://paystation.co.nz/privacy-policy';
	protected static $logo = 'payment/images/payments/paystation.jpg';
	protected static $url = 'https://www.paystation.co.nz/direct/paystation.dll';
	protected static $test_mode = false;
	protected static $paystation_id;
	protected static $gateway_id;
	protected static $merchant_ref;
	
	protected static $returnurl = null;
	
	static function set_test_mode() {
		self::$test_mode = true;
	}
	
	static function set_return_url($url){
		self::$returnurl = $url;
	}

	static function set_paystation_id($paystation_id) {
		self::$paystation_id = $paystation_id;
	}

	static function set_gateway_id($gateway_id) {
		self::$gateway_id = $gateway_id;
	}
	
	static function set_merchant_ref($merchant_ref) {
		self::$merchant_ref = $merchant_ref;
	}
	
	static function get_paystation_id(){
		return self::$paystation_id;
	}
	
	function getPaymentFormFields() {
		$logo = '<img src="' . self::$logo . '" title="Credit card payments powered by Paystation"/>';
		$privacyLink = '<a href="' . self::$privacy_link . '" target="_blank" title="Read Paystation\'s privacy policy">' . $logo . '</a><br/>';
		return new FieldSet(
			new LiteralField('PaystationInfo', $privacyLink),
			new LiteralField(
				'PaystationPaymentsList',
				'<img src="payment/images/payments/methods/visa.jpg" title="Visa"/>' .
				'<img src="payment/images/payments/methods/mastercard.jpg" title="MasterCard"/>' 
			)
		);
	}
	
	function getPaymentFormRequirements() {
		return null;
	}
	
	function processPayment($data, $form) {
		//check for correct set up info
		if(!self::$paystation_id)
			user_error('No paystation id specified. Use PaystationHostedPayment::set_paystation_id() in your _config.php file.', E_USER_ERROR);
		if(!self::$gateway_id)
			user_error('No gateway id specified. Use PaystationHostedPayment::set_gateway_id() in your _config.php file.', E_USER_ERROR);
		//merchant Session: built from (php session id)-(payment id) to ensure uniqueness
		$this->MerchantSession = session_id()."-".$this->ID;
		//set up required parameters
		$data = array(
			'paystation' => '_empty',
			'pstn_pi' => self::$paystation_id, //paystation ID
			'pstn_gi' => self::$gateway_id, //gateway ID
			'pstn_ms' => $this->MerchantSession, 
			'pstn_am' => $this->Amount->Amount * 100 //ammount in cents
		);
		//add optional parameters
		//$data['pstn_cu'] = //currency
		if(self::$test_mode) $data['pstn_tm'] = 't'; //test mode
		if(self::$merchant_ref) $data['pstn_mr'] = self::$merchant_ref; //merchant refernece
		//$data['pstn_ct'] = //card type
		//$data['pstn_af'] = //ammount format
		//Make POST request to Paystation via RESTful service
		$paystation = new RestfulService(self::$url,0); //REST connection that will expire immediately
		$paystation->httpHeader('Accept: application/xml');
		$paystation->httpHeader('Content-Type: application/x-www-form-urlencoded');
		$data = http_build_query($data);
		$response = $paystation->request('','POST',$data);		
		$sxml = $response->simpleXML();
		
		if($paymenturl = $sxml->DigitalOrder){
			$this->Status = 'Pending';
			$this->write();
			Director::redirect($paymenturl); //redirect to payment gateway
			return new Payment_Processing();
		}
		if(isset($sxml->PaystationErrorCode) && $sxml->PaystationErrorCode > 0){
			//provide useful feedback on failure
			$error = $sxml->PaystationErrorCode." ".$sxml->PaystationErrorMessage;
			$this->Message = $error;
			$this->Status = 'Failure';
			$this->write();
			//user_error('Paystation error: $error');
			return new Payment_Failure($sxml->PaystationErrorMessage);
		}
		//else recieved bad xml or transaction falied for an unknown reason
		//what should happen here?
		return new Payment_Failure("Unknown error");
	}
	
	function ProcessError($errorcode){
		//if errorcode = 4,5,7?
		//then user has failed in some way
		//if error code = 10,11,12,13,22,23,25,26,101,102,104
		// then this payment code has failed in some way
		//else system failed somehow
	}

	function RedirectJavascript($url) {
		$url = Convert::raw2xml($url);
		return<<<HTML
			<script type="text/javascript">
				jQuery(document).ready(function() {
					location = "$url";
				});
			</script>
HTML;
	}
	
	function redirectToReturnURL(){
		if(self::$returnurl){
			Director::redirect(self::$returnurl.'/'.$this->ID);
			return;
		}
		if($url = $this->ReturnURL){
			Director::redirect($url);
			return;
		}	
		Director::redirect(Director::baseURL());
	}
}

/**
 * Handler for responses from the PayPal site
 */
class PaystationHostedPayment_Controller extends Controller {
	
	protected static $usequicklookup = true;
	protected static $quicklookupurl = 'https://www.paystation.co.nz/lookup/quick/';

	static $URLSegment = 'paystation';
	
	static function complete_link() {
		return self::$URLSegment . '/complete';
	}
	
	function complete() {
		//TODO: check that request came from paystation.co.nz
		if(isset($_REQUEST['ec'])) {
			if(isset($_REQUEST['ms'])) {
				$payid = (int)substr($_REQUEST['ms'],strpos($_REQUEST['ms'],'-')+1);//extract PaystationPayment ID off the end
				if($payment = DataObject::get_by_id('PaystationHostedPayment', $payid)) {
					if($payment->Status == "Success"){
						$payment->redirectToReturnURL();
					}
					$payment->Status = $_REQUEST['ec'] == '0' ? 'Success' : 'Failure';
					if($_REQUEST['ti']) $payment->TransactionID = $_REQUEST['ti'];
					if($_REQUEST['em']) $payment->Message = $_REQUEST['em'];
					
					//Quick Lookup
					if(self::$usequicklookup){
						$paystation = new RestfulService(self::$quicklookupurl,0); //REST connection that will expire immediately
						$paystation->httpHeader('Accept: application/xml');
						$paystation->httpHeader('Content-Type: application/x-www-form-urlencoded');
						$data = array(
							'pi' => PaystationHostedPayment::get_paystation_id(),
							//'ti' => $payment->TransactionID
							'ms' => $_REQUEST['ms']
						);
						$paystation->setQueryString($data);
						$response = $paystation->request(null,'GET');
						$sxml = $response->simpleXML();
						if(!$sxml){
							//falied connection?
							$payment->Status = "Failure";
							$payment->Message .= "Paystation quick lookup failed.";
						}elseif($sxml->LookupStatus && $sxml->LookupStatus->LookupCode == "00"){
							$payment->Status = "Success";
						}elseif($s = $sxml->LookupResponse){
							//check transaction ID matches
							if($payment->TransactionID != (string)$s->PaystationTransactionID){
								$payment->Status = "Failure";
								$payment->Message .= "The transaction ID didn't match.";
							}
							//check amount matches
							if($payment->Amount*100 != (int)$s->PurchaseAmount){
								$payment->Status = "Failure";
								$payment->Message .= "The purchase amount was inconsistent.";
							}
							//check session ID matches
							if(session_id() != substr($_REQUEST['ms'],0,strpos($_REQUEST['ms'],'-'))){
								$payment->Status = "Failure";
								$payment->Message .= "Session id didn't match.";
							}
							//TODO: extra - check IP address against $payment->IP??
						}elseif($sxml && $s = $sxml->LookupStatus){
							$payment->Status = "Failure";
							$payment->Message .= $s->LookupMessage;
						}
					}
					$payment->write();
					$payment->redirectToReturnURL();
					return;
				}
				else user_error('There is no any Paystation payment which ID is #' . $payid, E_USER_ERROR);
			}
			else user_error('There is no any Paystation hosted payment ID specified', E_USER_ERROR);
		}
		else user_error('There is no any Paystation hosted payment error code specified', E_USER_ERROR);
		//TODO: sawp errors for payment failures??
	}
	
	/** Quick Lookup Refernce:
 	 * 
	 * AcquirerName - Merchants Acquirer Name
	 * AcquirerMerchantID - The acquirer’s merchant ID used for the transaction
	 * PaystationUserID - Paystation username
	 * PaystationTransactionID - A string containing the unique transaction ID assigned to the	transaction attempt by the Paystation server.
	 * PurchaseAmount - The amount of the transaction, in cents.
	 * MerchantSession - A string containing the unique reference assigned to the transaction by the merchants system
	 * ReturnReceiptNumber - The RRN number is a virtual terminal counter for the transaction and is not unique.
	 * ShoppingTransactionNumber - Unique bank reference assigned to the transaction
	 * AcquirerResponseCode - Acquirer’s response code. The result code’s vary from acquirer to acquirer and is included for debugging purposes. Please process	transaction result from the PaystationErrorCode.
	 * QSIResponseCode - Payment Server Response code – the actual raw result from the payment server. Please process transaction result from the PaystationErrorCode.
	 * PaystationErrorCode - The result of the transaction
	 * BatchNumber - The Batch number on the Payment Server that this transaction will be added to in order to be processed by the acquiring institution.
	 * Cardtype - The card type used
	 */
	
}