<?php

Director::addRules(50, array(
	PaystationHostedPayment_Controller::$URLSegment . '/$Action/$ID' => 'PaystationHostedPayment_Controller'
));