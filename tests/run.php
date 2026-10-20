<?php
/**
 * Offline tests for the WHMCS module: a stub WHMCS (invoices, tblaccounts, gateway log) on in-memory SQLite
 * and a fake SnapEX Pay API. Run: composer install && php tests/run.php (illuminate/database from vendor/;
 * inside the snapex monorepo its vendor/ is used).
 */

namespace {
    require is_file(__DIR__.'/../vendor/autoload.php') ? __DIR__.'/../vendor/autoload.php' : __DIR__.'/../../../vendor/autoload.php';
    require __DIR__.'/whmcs_stubs.php';
    whmcs_stub_boot();

    use SnapexPay\Whmcs\Api;
    use SnapexPay\Whmcs\Gateway;
    use SnapexPay\Whmcs\Store;

    // ---- fake SnapEX Pay API
    $GLOBALS['remote'] = [];
    $GLOBALS['keys'] = [];
    $GLOBALS['calls'] = [];
    Api::$transport = function ($method, $url, $headers, $body) {
        $GLOBALS['calls'][] = [$method, $url, $headers, $body];
        if (! in_array('Authorization: Bearer sk_test', $headers, true)) {
            return [401, json_encode(['error' => ['code' => 'unauthorized', 'message' => 'A valid merchant API key is required.']])];
        }
        if ($method === 'POST') {
            $data = json_decode($body, true);
            // Like the SnapEX API: a replayed Idempotency-Key returns the stored invoice if the request is the same,
            // an external_id may be used only once per merchant.
            $key = '';
            foreach ($headers as $h) {
                if (strpos($h, 'Idempotency-Key: ') === 0) {
                    $key = substr($h, 17);
                }
            }
            if (isset($GLOBALS['keys'][$key])) {
                $old = $GLOBALS['remote'][$GLOBALS['keys'][$key]];
                if ($old['external_id'] !== $data['external_id'] || $old['amount_usd'] !== $data['amount_usd'] || $old['environment'] !== $data['environment']) {
                    return [422, json_encode(['error' => ['code' => 'validation_error', 'message' => 'The request is invalid.', 'details' => ['amount_usd' => ['Idempotency-Key was already used with a different request.']]]])];
                }

                return [200, json_encode(['data' => $old])];
            }
            foreach ($GLOBALS['remote'] as $old) {
                if ($old['external_id'] === $data['external_id']) {
                    return [422, json_encode(['error' => ['code' => 'validation_error', 'message' => 'The request is invalid.', 'details' => ['amount_usd' => ['external_id has already been used by this merchant.']]]])];
                }
            }
            if ((float) $data['amount_usd'] < 1) {
                return [422, json_encode(['error' => ['code' => 'validation_error', 'message' => 'The request is invalid.', 'details' => ['amount_usd' => ['Minimum invoice is $1.']]]])];
            }
            $id = 'inv_'.(count($GLOBALS['remote']) + 1);
            $GLOBALS['keys'][$key] = $id;
            $GLOBALS['remote'][$id] = [
                'invoice_id' => $id, 'environment' => $data['environment'], 'amount_usd' => $data['amount_usd'],
                'status' => 'open', 'pay_url' => 'https://pay.snapex.pro/i/'.$id, 'external_id' => $data['external_id'],
                'expires_at' => gmdate('c', time() + $data['expires_in']), 'charge_usd' => $data['amount_usd'], 'credit_usd' => $data['amount_usd'],
                '_request' => $data,
            ];

            return [201, json_encode(['data' => $GLOBALS['remote'][$id]])];
        }
        $id = basename($url);

        return isset($GLOBALS['remote'][$id])
            ? [200, json_encode(['data' => $GLOBALS['remote'][$id]])]
            : [404, json_encode(['error' => ['code' => 'not_found', 'message' => 'The requested resource was not found.']])];
    };

    $failures = 0;
    function check($cond, $label)
    {
        global $failures;
        echo ($cond ? '  ok   ' : '  FAIL ').$label."\n";
        if (! $cond) {
            $failures++;
        }
    }
    function throwsMsg(callable $fn)
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            return $e->getMessage();
        }

        return null;
    }
    function form(array $p, $id, $amount)
    {
        return ['invoiceid' => $id, 'amount' => $amount, 'currency' => 'USD', 'sig' => Gateway::sign($p, $id, $amount, 'USD')];
    }
    function signed($secret, $payload, $ts = null)
    {
        $ts = $ts === null ? (string) time() : (string) $ts;
        $body = json_encode($payload);

        return [$body, ['Webhook-Timestamp' => $ts, 'Webhook-Signature' => 'v1='.hash_hmac('sha256', $ts.'.'.$body, $secret)]];
    }

    $params = ['name' => 'SnapEX Pay', 'type' => 'Invoices', 'apiKey' => 'sk_test', 'webhookSecret' => 'whsec_x', 'feeOn' => 'merchant', 'expiresMinutes' => '30', 'testMode' => '', 'apiUrl' => ''];
    $GLOBALS['invoices'][101] = ['invoiceid' => 101, 'status' => 'Unpaid', 'balance' => '45.00'];
    $GLOBALS['invoices'][102] = ['invoiceid' => 102, 'status' => 'Paid', 'balance' => '0.00'];

    echo "link\n";
    $html = Gateway::link($params + ['invoiceid' => 101, 'amount' => '49.9', 'currency' => 'USD', 'langpaynow' => 'Pay Now']);
    check(strpos($html, 'name="amount" value="49.90"') !== false, 'button carries normalized converted amount');
    check(strpos(Gateway::link($params + ['invoiceid' => 101, 'amount' => '45', 'currency' => 'EUR']), 'Convert To For Processing') !== false, 'non-USD asks for conversion');
    check(strpos(Gateway::link(['testMode' => 'on'] + $params + ['invoiceid' => 101, 'amount' => '45', 'currency' => 'USD']), '<form') === false, 'test mode hides button from clients');
    $_SESSION['adminid'] = 1;
    check(strpos(Gateway::link(['testMode' => 'on'] + $params + ['invoiceid' => 101, 'amount' => '45', 'currency' => 'USD']), '<form') !== false, 'test mode shows button to admins');
    $_SESSION = [];

    echo "start\n";
    check(throwsMsg(function () use ($params) { Gateway::start($params, ['sig' => 'x'] + form($params, 101, '49.90')); }) !== null, 'forged signature rejected');
    check(throwsMsg(function () use ($params) { Gateway::start($params, form($params, 102, '10.00')); }) === 'This invoice is not awaiting payment.', 'paid invoice rejected');
    $url = Gateway::start($params, form($params, 101, '49.90'));
    check($url === 'https://pay.snapex.pro/i/inv_1', 'redirects to the SnapEX payment page');
    $req = $GLOBALS['remote']['inv_1']['_request'];
    $site = substr(sha1('https://billing.host.example/'), 0, 12);
    check(preg_match('/^whmcs-'.$site.'-101-0-49\.90-live-\d+$/', $req['external_id']) && $req['environment'] === 'live' && $req['expires_in'] === 1800 && $req['fee_on'] === 'merchant', 'invoice request fields');
    check(strpos($req['success_url'], 'modules/gateways/snapexpay/return.php?invoiceid=101') !== false, 'success url points to return.php');
    check(! array_key_exists('partner_ref', $req), 'no Partner ID → no partner_ref');
    check(end($GLOBALS['calls'])[2][3] === 'Idempotency-Key: '.$req['external_id'], 'Idempotency-Key is the external_id');
    check(Gateway::start($params, form($params, 101, '49.90')) === $url && count($GLOBALS['remote']) === 1, 'second click reuses the open invoice');
    $row = Store::find('inv_1');
    check($row && (float) $row->whmcs_amount === 45.0 && $row->amount_usd === '49.90', 'stores WHMCS-currency balance and USD amount');
    $msg = throwsMsg(function () use ($params) { Gateway::start($params, form($params, 101, '0.50')); });
    check($msg !== null && strpos($msg, 'Minimum invoice is $1.') !== false, 'API validation message reaches the client');
    check(throwsMsg(function () use ($params) { Gateway::start(['testMode' => 'on'] + $params, form($params, 101, '49.90')); }) !== null, 'test mode: clients cannot start');

    echo "webhook\n";
    list($body, $h) = signed('whsec_x', ['type' => 'invoice.paid', 'data' => ['invoice_id' => 'inv_1']]);
    check(Gateway::webhook($params, $body, ['Webhook-Timestamp' => $h['Webhook-Timestamp'], 'Webhook-Signature' => 'v1=bad'])[0] === 401, 'bad signature 401');
    list($oldBody, $oldH) = signed('whsec_x', ['type' => 'invoice.paid', 'data' => ['invoice_id' => 'inv_1']], time() - 3600);
    check(Gateway::webhook($params, $oldBody, $oldH)[0] === 400, 'stale timestamp rejected');
    check(Gateway::webhook(['webhookSecret' => ''] + $params, $body, $h)[0] === 503, 'no secret configured: 503');
    // webhook says paid, API still says open: nothing applied
    check(Gateway::webhook($params, $body, $h) === [200, 'ok'] && $GLOBALS['invoices'][101]['status'] === 'Unpaid', 'body alone never marks paid');
    $GLOBALS['remote']['inv_1']['status'] = 'paid';
    $GLOBALS['remote']['inv_1']['paid_at'] = gmdate('c');
    check(Gateway::webhook($params, $body, $h) === [200, 'ok'], 'paid webhook 200');
    check($GLOBALS['invoices'][101]['status'] === 'Paid', 'WHMCS invoice marked paid');
    $acc = \WHMCS\Database\Capsule::table('tblaccounts')->get();
    check(count($acc) === 1 && $acc[0]->transid === 'inv_1' && (float) $acc[0]->amountin === 45.0, 'payment in invoice currency with SnapEX id as transaction');
    Gateway::webhook($params, $body, $h);
    Gateway::returned($params, 101);
    Gateway::cron($params);
    check(\WHMCS\Database\Capsule::table('tblaccounts')->count() === 1, 'retries / return / cron do not double-pay');
    list($b2, $h2) = signed('whsec_x', ['type' => 'invoice.paid', 'data' => ['invoice_id' => 'inv_999']]);
    check(Gateway::webhook($params, $b2, $h2)[1] === 'not a WHMCS invoice', 'foreign invoice ignored');

    echo "return + cron without webhook\n";
    $GLOBALS['invoices'][103] = ['invoiceid' => 103, 'status' => 'Unpaid', 'balance' => '20.00'];
    Gateway::start($params, form($params, 103, '20.00'));
    check(Gateway::returned($params, 103) === false, 'return before payment: not paid');
    $GLOBALS['remote']['inv_2']['status'] = 'paid';
    check(Gateway::returned($params, 103) === true && $GLOBALS['invoices'][103]['status'] === 'Paid', 'return after payment settles at once');

    $GLOBALS['invoices'][104] = ['invoiceid' => 104, 'status' => 'Unpaid', 'balance' => '15.00'];
    Gateway::start($params, form($params, 104, '15.00'));
    $GLOBALS['remote']['inv_3']['status'] = 'paid';
    check(Gateway::cron($params) >= 1 && $GLOBALS['invoices'][104]['status'] === 'Paid', 'cron settles a payment with no webhook and no return');

    echo "edge cases\n";
    $GLOBALS['invoices'][105] = ['invoiceid' => 105, 'status' => 'Unpaid', 'balance' => '30.00'];
    Gateway::start($params, form($params, 105, '30.00'));
    $GLOBALS['remote']['inv_4']['status'] = 'partially_paid';
    $GLOBALS['log'] = [];
    Gateway::returned($params, 105);
    check($GLOBALS['invoices'][105]['status'] === 'Unpaid' && in_array('Partial payment — review and apply manually', $GLOBALS['log'], true), 'partial payment logged, not applied');

    $GLOBALS['invoices'][106] = ['invoiceid' => 106, 'status' => 'Unpaid', 'balance' => '30.00'];
    Gateway::start($params, form($params, 106, '30.00'));
    $GLOBALS['remote']['inv_5']['status'] = 'paid';
    $GLOBALS['remote']['inv_5']['amount_usd'] = '3.00';
    Gateway::returned($params, 106);
    check($GLOBALS['invoices'][106]['status'] === 'Unpaid', 'amount mismatch not applied');

    $GLOBALS['invoices'][107] = ['invoiceid' => 107, 'status' => 'Unpaid', 'balance' => '30.00'];
    $_SESSION['adminid'] = 1;
    Gateway::start(['testMode' => 'on'] + $params, form($params, 107, '30.00'));
    $_SESSION = [];
    check($GLOBALS['remote']['inv_6']['environment'] === 'test', 'test mode creates test invoices');
    $GLOBALS['remote']['inv_6']['status'] = 'paid';
    Gateway::returned($params, 107);
    check($GLOBALS['invoices'][107]['status'] === 'Unpaid', 'test payment ignored once test mode is off');

    $GLOBALS['invoices'][108] = ['invoiceid' => 108, 'status' => 'Unpaid', 'balance' => '30.00'];
    Gateway::start($params, form($params, 108, '30.00'));
    $GLOBALS['remote']['inv_7']['status'] = 'expired';
    Gateway::returned($params, 108);
    check(Store::find('inv_7')->status === 'expired', 'expired attempt recorded');
    check(Gateway::start($params, form($params, 108, '30.00')) === 'https://pay.snapex.pro/i/inv_8', 'after expiry a new invoice is created');

    check(Gateway::start($params, form($params, 108, '30.00')) === 'https://pay.snapex.pro/i/inv_8' && count($GLOBALS['remote']) === 8, 'second click after the retry reuses it');
    check(Store::find('inv_8') && $GLOBALS['remote']['inv_8']['external_id'] !== $GLOBALS['remote']['inv_7']['external_id'], 'retry after expiry has a new external_id (no "already used" error)');

    echo "external_id\n";
    // double click racing in parallel: the second request does not see the first one's row yet
    $GLOBALS['invoices'][109] = ['invoiceid' => 109, 'status' => 'Unpaid', 'balance' => '30.00'];
    $url = Gateway::start($params, form($params, 109, '30.00'));
    $first = Store::find(basename($url));
    \WHMCS\Database\Capsule::table(Store::TABLE)->where('id', $first->id)->delete();
    check(Gateway::start($params, form($params, 109, '30.00')) === $url && count($GLOBALS['remote']) === 9, 'parallel double click: same external_id, one SnapEX invoice');
    $now = time();
    check(Gateway::externalId(109, 1, '30.00', 'live', $now) === Gateway::externalId(109, 1, '30.00', 'live', $now - $now % 60 + 59), 'same within the minute');
    check(Gateway::externalId(109, 1, '30.00', 'live', $now) !== Gateway::externalId(109, 1, '30.00', 'live', $now + 60), 'differs a minute later');
    check(Gateway::externalId(109, 1, '30.00', 'live', $now) !== Gateway::externalId(109, 1, '31.00', 'live', $now)
        && Gateway::externalId(109, 1, '30.00', 'live', $now) !== Gateway::externalId(109, 1, '30.00', 'test', $now)
        && Gateway::externalId(109, 1, '30.00', 'live', $now) !== Gateway::externalId(109, 2, '30.00', 'live', $now), 'differs with amount, mode and attempt');
    check(Gateway::parseExternalId(Gateway::externalId(109, 1, '30.00', 'live', $now)) === ['invoice' => 109, 'amount' => '30.00', 'environment' => 'live'], 'new format parsed');
    check(Gateway::parseExternalId('whmcs-109') === ['invoice' => 109, 'amount' => null, 'environment' => null], 'legacy format parsed');
    check(Gateway::parseExternalId('wc-109') === null && Gateway::parseExternalId('whmcs-109x') === null, 'other formats rejected');

    // invoice created by module 1.0.0 (fixed external_id) is still settled by the webhook
    $GLOBALS['invoices'][110] = ['invoiceid' => 110, 'status' => 'Unpaid', 'balance' => '12.00'];
    $GLOBALS['remote']['inv_legacy'] = ['invoice_id' => 'inv_legacy', 'environment' => 'live', 'amount_usd' => '12.00', 'status' => 'paid', 'external_id' => 'whmcs-110'];
    Store::insert(['whmcs_invoice_id' => 110, 'snapex_invoice_id' => 'inv_legacy', 'environment' => 'live', 'amount_usd' => '12.00', 'whmcs_amount' => '12.00', 'pay_url' => 'x', 'status' => 'open']);
    list($b, $h) = signed('whsec_x', ['type' => 'invoice.paid', 'data' => ['invoice_id' => 'inv_legacy']]);
    check(Gateway::webhook($params, $b, $h) === [200, 'ok'] && $GLOBALS['invoices'][110]['status'] === 'Paid', 'webhook settles a legacy whmcs-<id> invoice');

    // new-format invoice: paid via webhook
    $GLOBALS['invoices'][111] = ['invoiceid' => 111, 'status' => 'Unpaid', 'balance' => '12.00'];
    $id = basename(Gateway::start($params, form($params, 111, '12.00')));
    $GLOBALS['remote'][$id]['status'] = 'paid';
    list($b, $h) = signed('whsec_x', ['type' => 'invoice.paid', 'data' => ['invoice_id' => $id]]);
    check(Gateway::webhook($params, $b, $h) === [200, 'ok'] && $GLOBALS['invoices'][111]['status'] === 'Paid', 'webhook settles a new-format invoice');

    // external_id pointing at another WHMCS invoice, or carrying another amount: never applied
    $GLOBALS['invoices'][112] = ['invoiceid' => 112, 'status' => 'Unpaid', 'balance' => '12.00'];
    $id = basename(Gateway::start($params, form($params, 112, '12.00')));
    $GLOBALS['remote'][$id]['status'] = 'paid';
    $GLOBALS['remote'][$id]['external_id'] = Gateway::externalId(113, 0, '12.00', 'live', time());
    Gateway::returned($params, 112);
    check($GLOBALS['invoices'][112]['status'] === 'Unpaid' && end($GLOBALS['log']) === 'Mismatch — ignored', 'external_id of another invoice ignored');
    $GLOBALS['remote'][$id]['external_id'] = Gateway::externalId(112, 0, '1.00', 'live', time());
    $GLOBALS['remote'][$id]['amount_usd'] = '1.00';
    Gateway::returned($params, 112);
    check($GLOBALS['invoices'][112]['status'] === 'Unpaid', 'paid amount below the invoice amount rejected');

    check(throwsMsg(function () use ($params) { Gateway::start(['apiKey' => 'wrong'] + $params, form(['apiKey' => 'wrong'] + $params, 108, '31.00')); }) === 'Could not start the crypto payment: A valid merchant API key is required.', 'bad API key explained');

    echo "partner id\n";
    $GLOBALS['invoices'][120] = ['invoiceid' => 120, 'status' => 'Unpaid', 'balance' => '20.00'];
    $pid = basename(Gateway::start(['partnerId' => ' int-42 '] + $params, form(['partnerId' => ' int-42 '] + $params, 120, '20.00')));
    check(($GLOBALS['remote'][$pid]['_request']['partner_ref'] ?? null) === 'int-42', 'Partner ID → partner_ref');

    echo $failures === 0 ? "\nALL PASSED\n" : "\n{$failures} FAILED\n";
    exit($failures === 0 ? 0 : 1);
}
