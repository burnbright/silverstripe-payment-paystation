<?php

Director::addRules(50, array(
	ImprovedPaystationHostedPayment_Handler::$URLSegment . '/$Action/$ID' => 'ImprovedPaystationHostedPayment_Handler'
));