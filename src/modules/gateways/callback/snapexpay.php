<?php
/** SnapEX Pay webhook: signed invoice events. The status is always re-read from the API before applying. */

require_once __DIR__.'/../../../init.php';

App::load_function('gateway');
App::load_function('invoice');

require_once __DIR__.'/../snapexpay/lib/bootstrap.php';

use SnapexPay\Whmcs\Gateway;

$params = getGatewayVariables(Gateway::MODULE);
if (empty($params['type'])) {
    http_response_code(503);
    die('Module not activated');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die('SnapEX Pay webhook endpoint');
}

$headers = [];
foreach ($_SERVER as $name => $value) {
    if (strpos($name, 'HTTP_') === 0) {
        $headers[str_replace('_', '-', substr($name, 5))] = $value;
    }
}

list($status, $body) = Gateway::webhook($params, (string) file_get_contents('php://input'), $headers);
http_response_code($status);
header('Content-Type: text/plain; charset=utf-8');
echo $body;
