<?php
/**
 * SnapEX Pay: on every WHMCS cron run, re-check recent unpaid crypto payments.
 * Settles invoices even if a webhook was lost or never configured.
 */

if (! defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

add_hook('AfterCronJob', 1, function () {
    $module = ROOTDIR.'/modules/gateways/snapexpay/lib/bootstrap.php';
    if (! is_file($module)) {
        return;
    }
    if (! function_exists('getGatewayVariables')) {
        App::load_function('gateway');
    }
    if (! function_exists('addInvoicePayment')) {
        App::load_function('invoice');
    }
    require_once $module;

    $params = getGatewayVariables(\SnapexPay\Whmcs\Gateway::MODULE);
    if (empty($params['type'])) {
        return;
    }
    try {
        \SnapexPay\Whmcs\Gateway::cron($params);
    } catch (\Throwable $e) {
        logActivity('SnapEX Pay cron check failed: '.$e->getMessage());
    }
});
