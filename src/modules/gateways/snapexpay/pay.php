<?php
/** "Pay Now" on the invoice page posts here: create the SnapEX Pay invoice and send the client to it. */

require_once __DIR__.'/../../../init.php';

App::load_function('gateway');
App::load_function('invoice');

require_once __DIR__.'/lib/bootstrap.php';

use SnapexPay\Whmcs\Gateway;

$params = getGatewayVariables(Gateway::MODULE);
if (empty($params['type'])) {
    http_response_code(404);
    die('Module not activated');
}

$invoiceId = isset($_POST['invoiceid']) ? (int) $_POST['invoiceid'] : 0;
try {
    $url = Gateway::start($params, $_POST);
    header('Location: '.$url, true, 303);
    exit;
} catch (\RuntimeException $e) {
    $back = Gateway::systemUrl().'viewinvoice.php?id='.$invoiceId;
    http_response_code(422);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        .'<title>Payment</title><div style="font-family:sans-serif;max-width:32rem;margin:4rem auto;padding:0 1rem">'
        .'<p>'.htmlspecialchars($e->getMessage(), ENT_QUOTES).'</p>'
        .'<p><a href="'.htmlspecialchars($back, ENT_QUOTES).'">&larr; Back to the invoice</a></p></div>';
}
