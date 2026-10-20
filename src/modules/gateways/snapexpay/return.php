<?php
/** SnapEX Pay sends the client back here after paying; settle at once if the money is already in. */

require_once __DIR__.'/../../../init.php';

App::load_function('gateway');
App::load_function('invoice');

require_once __DIR__.'/lib/bootstrap.php';

use SnapexPay\Whmcs\Gateway;

$params = getGatewayVariables(Gateway::MODULE);
$invoiceId = isset($_GET['invoiceid']) ? (int) $_GET['invoiceid'] : 0;
$paid = false;
if (! empty($params['type']) && $invoiceId > 0) {
    $paid = Gateway::returned($params, $invoiceId);
}

header('Location: '.Gateway::systemUrl().'viewinvoice.php?id='.$invoiceId.($paid ? '&paymentsuccess=true' : ''), true, 303);
exit;
