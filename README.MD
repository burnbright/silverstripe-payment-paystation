# Paystation Hosted Payment

Provides the connection for the Paystation Hosted payment gateway.
    
## Maintainer Contact   

 * Jeremy Shipman (Jedateach, <jeremy@burnbright.net>)
 * Nicolaas Francken (Nicolaas Francken [at] sunnysideup.co.nz)

## Requirements

 * SilverStripe 2.4+
 * Payments

## Installation Instructions

Assuming you have set up a paystation account already, and recieved the config details:

 1. Copy into your SilverStripe site directory
 2. Run [yourwebsite]/db/build?flush=all in your browser
 3. Set your _config.php configuration as per the details provided by Paystation:
 
 	ImprovedPaystationHostedPayment::set_paystation_id('[PAYSTATION_ID]');
	ImprovedPaystationHostedPayment::set_gateway_id('[GATEWAY_ID]');

 4. Optionally put the payment into test mode:	

	ImprovedPaystationHostedPayment::set_test_mode();