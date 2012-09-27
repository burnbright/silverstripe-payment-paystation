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
	protected static $quicklookupurl = 'https://www.paystation.co.nz/lookup/quick/';
	
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
	
	/**
	 * Get message for error code
	 */
	function errorForCode($errorcode = 1){
		$codes = array(
			0  => "Transaction succesful",
			1  => "Unknown error",
			2  => "Bank declined transaction",
			3  => "No reply from bank",
			4  => "Expired card",
			5  => "Insufficient funds",
			6  => "Error cummunicating with bank",
			7  => "Payment server system error",
			8  => "Transaction type not supported",
			9  => "Transaction failed",
			10 => "Purchase amount less or greater than merchant values",
			11 => "Paystation couldnt create order based on inputs",
			12 => "Paystation couldnt find merchant based on merchant ID",
			13 => "Transaction already in progress"
		);
	
		if(isset($codes[$errorcode])){
			return $codes[$errorcode];
		}
		return $codes[1]; //unknown
	}
	
	function statusForCode($code = -1){
		if($code == 0){
			return "Success";
		}
		return "Failure";
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
		if(self::$test_mode){
			$data['pstn_tm'] = 't'; //test mode
		}
		if(isset($data['Reference'])){
			$data['pstn_mr'] = $data['Reference'];
		}elseif($this->Reference){
			$data['pstn_mr'] = $this->Reference;
		}elseif(self::$merchant_ref){
			$data['pstn_mr'] = self::$merchant_ref; //merchant refernece
		}
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
			return new Payment_Failure($sxml->PaystationErrorMessage);
		}
		//else recieved bad xml or transaction falied for an unknown reason
		//what should happen here?
		return new Payment_Failure("Unknown error");
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
	
	function updateFromCode($code){
		$this->Message = $this->errorForCode($code);
		$this->Status = $this->statusForCode($code);
	}
	
	/**
	 * Look up payment transaction on the paystation server, and update payment status/message.
	 * @see http://www.paystation.co.nz/cms_show_download.php?id=38
	 * @return boolean - quick lookup was successful or failure
	 */
	function doQuickLookup(){
		$paystation = new RestfulService(self::$quicklookupurl,0); //REST connection that will expire immediately
		$paystation->httpHeader('Accept: application/xml');
		$paystation->httpHeader('Content-Type: application/x-www-form-urlencoded');
		$data = array(
			'pi' => self::$paystation_id,
			'ti' => $this->TransactionID
		);
		$paystation->setQueryString($data);
		$response = $paystation->request(null,'GET');
		$sxml = $response->simpleXML();
		//TODO: don't allow a quick lookup  to overwrite successful/failed payments if lookup doesn't work.
			//eg a connection failure for lookup shouldn't imply failed payment.
		if(!$sxml){
			//falied connection?
			//$this->Status = "Failure";
			//$this->Message = "Paystation quick lookup failed.";
			return false;
		}elseif($sxml->LookupStatus && $sxml->LookupResponse && $sxml->LookupStatus->LookupCode == "00"){ //lookup was successful
			$r = $sxml->LookupResponse;			
			$this->updateFromCode((int)$r->PaystationErrorCode);
			//check transaction ID matches
			if($this->TransactionID != (string)$r->PaystationTransactionID){
				$this->Status = "Failure";
				$this->Message = "The transaction ID didn't match";
			}
			//check amount matches
			if($this->AmountAmount * 100 != (int)$r->PurchaseAmount){
				$this->Status = "Incomplete";
				$this->Message = "The purchase amount was inconsistent";
			}
			$this->write();
			return true;
		}
		//something went wrong reading xml response
		return false;
	}
	
}

/**
 * Handler for responses from the PayPal site
 */
class PaystationHostedPayment_Controller extends Controller {
	
	protected static $usequicklookup = true;

	static $URLSegment = 'paystation';
	
	static function complete_link() {
		return Controller::join_links(self::$URLSegment , 'complete');
	}
	
	/**
	 * Post-payment action for returning to silverstripe website from paystation.
	 */
	function complete() {
		//TODO: check that request came from paystation.co.nz
		if(!isset($_REQUEST['ec'])){
			user_error('There is no any Paystation hosted payment error code specified', E_USER_ERROR);
		}
		if(!isset($_REQUEST['ms'])){
			user_error('There is no any Paystation hosted payment ID specified', E_USER_ERROR);
		}
		$ec = (int)$_REQUEST['ec']; //error code
		$ms = $_REQUEST['ms']; //merchant session
		$payid = (int)substr($ms,strpos($ms,'-') + 1); //extract PaystationPayment ID off the end
		if($payment = DataObject::get_by_id('PaystationHostedPayment', $payid)) {
			if($payment->Status != "Pending"){ //payment already processed
				$payment->redirectToReturnURL();
			}
			$payment->updateFromCode($ec);
			if(isset($_REQUEST['ti'])){
				$payment->TransactionID = $_REQUEST['ti'];
			}
			$payment->write();
			if(self::$usequicklookup){ //contact the server to check
				$payment->doQuickLookup();
			}
			$payment->redirectToReturnURL();
			return;
		}
		user_error('There is no any Paystation payment which ID is #' . $payid, E_USER_ERROR);
	}
	
}