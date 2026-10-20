<?php
/**
 * The bits of WHMCS the module touches, for tests: Capsule on in-memory SQLite, tblaccounts,
 * invoices held in $GLOBALS['invoices'], gateway log in $GLOBALS['log'].
 */

if (! defined('WHMCS')) {
    define('WHMCS', true);
}
if (! class_exists('WHMCS\Database\Capsule')) {
    class_alias(\Illuminate\Database\Capsule\Manager::class, 'WHMCS\Database\Capsule');
}

function whmcs_stub_boot()
{
    $capsule = new \WHMCS\Database\Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    \WHMCS\Database\Capsule::schema()->create('tblaccounts', function ($t) {
        $t->increments('id');
        $t->integer('invoiceid');
        $t->string('gateway');
        $t->string('transid');
        $t->decimal('amountin', 16, 2);
    });
    $GLOBALS['invoices'] = [];
    $GLOBALS['log'] = [];
    $_SESSION = [];

    require_once __DIR__.'/../src/modules/gateways/snapexpay/lib/bootstrap.php';
}

if (! function_exists('localAPI')) {
    function localAPI($cmd, $args)
    {
        $inv = isset($GLOBALS['invoices'][$args['invoiceid']]) ? $GLOBALS['invoices'][$args['invoiceid']] : null;

        return $inv ? ['result' => 'success'] + $inv : ['result' => 'error', 'message' => 'Invoice ID Not Found'];
    }

    function logTransaction($gateway, $data, $status)
    {
        $GLOBALS['log'][] = $status;
    }

    function addInvoicePayment($invoiceId, $transId, $amount, $fee, $gateway)
    {
        \WHMCS\Database\Capsule::table('tblaccounts')->insert(['invoiceid' => $invoiceId, 'gateway' => $gateway, 'transid' => $transId, 'amountin' => $amount]);
        $GLOBALS['invoices'][$invoiceId]['status'] = 'Paid';
        $GLOBALS['invoices'][$invoiceId]['balance'] = '0.00';

        return true;
    }
}

if (! class_exists('WHMCS\Config\Setting')) {
    eval('namespace WHMCS\Config; class Setting { public static $values = ["SystemURL" => "https://billing.host.example/", "CompanyName" => "Example Host"]; public static function getValue($k) { return isset(self::$values[$k]) ? self::$values[$k] : ""; } }');
}
