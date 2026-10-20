<?php

namespace SnapexPay\Whmcs;

if (! defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

class Gateway
{
    const MODULE = 'snapexpay';

    const VERSION = '1.1.0';

    const DEFAULT_API_URL = 'https://snapex.pro/api/v1/merchant';

    /** Webhook timestamps older than this are rejected (replay protection). */
    const WEBHOOK_TOLERANCE = 300;

    public static function systemUrl()
    {
        $url = '';
        if (class_exists('\WHMCS\Config\Setting')) {
            $url = (string) \WHMCS\Config\Setting::getValue('SystemURL');
        }

        return rtrim($url, '/').'/';
    }

    public static function callbackUrl()
    {
        return self::systemUrl().'modules/gateways/callback/'.self::MODULE.'.php';
    }

    public static function api(array $params)
    {
        $url = trim((string) (isset($params['apiUrl']) ? $params['apiUrl'] : ''));

        return new Api($url !== '' ? $url : self::DEFAULT_API_URL, isset($params['apiKey']) ? $params['apiKey'] : '');
    }

    public static function testMode(array $params)
    {
        return ! empty($params['testMode']) && $params['testMode'] !== 'off';
    }

    public static function environment(array $params)
    {
        return self::testMode($params) ? 'test' : 'live';
    }

    /** While test mode is on, only an admin (logged in to the admin area) may start payments. */
    public static function allowedToPay(array $params)
    {
        return ! self::testMode($params) || ! empty($_SESSION['adminid']);
    }

    /** "12.5" → "12.50": the same amount always gives the same string (signature, reuse). */
    public static function money($amount)
    {
        return number_format(round((float) $amount, 2), 2, '.', '');
    }

    public static function sign(array $params, $invoiceId, $amount, $currency)
    {
        return hash_hmac('sha256', self::MODULE.'|'.(int) $invoiceId.'|'.$amount.'|'.$currency, (string) $params['apiKey']);
    }

    // ------------------------------------------------------------------ invoice page

    public static function link(array $params)
    {
        $label = isset($params['langpaynow']) && $params['langpaynow'] !== '' ? $params['langpaynow'] : 'Pay Now';
        if (strtoupper((string) $params['currency']) !== 'USD') {
            return '<p class="text-danger">SnapEX Pay: set "Convert To For Processing" to USD in the gateway settings.</p>';
        }
        if (! self::allowedToPay($params)) {
            return '<p class="text-muted">SnapEX Pay is in test mode.</p>';
        }
        $amount = self::money($params['amount']);
        $invoiceId = (int) $params['invoiceid'];
        $action = self::systemUrl().'modules/gateways/'.self::MODULE.'/pay.php';
        $fields = [
            'invoiceid' => $invoiceId,
            'amount' => $amount,
            'currency' => 'USD',
            'sig' => self::sign($params, $invoiceId, $amount, 'USD'),
        ];
        $html = '<form method="post" action="'.htmlspecialchars($action, ENT_QUOTES).'">';
        foreach ($fields as $name => $value) {
            $html .= '<input type="hidden" name="'.$name.'" value="'.htmlspecialchars((string) $value, ENT_QUOTES).'">';
        }
        $html .= '<button type="submit" class="btn btn-success">'.htmlspecialchars($label, ENT_QUOTES).'</button>';
        if (self::testMode($params)) {
            $html .= ' <small class="text-muted">(test mode)</small>';
        }

        return $html.'</form>';
    }

    // ------------------------------------------------------------------ pay.php

    /**
     * Creates (or reuses) a SnapEX Pay invoice for an unpaid WHMCS invoice and returns its payment page URL.
     *
     * @throws \RuntimeException with a message safe to show the client
     */
    public static function start(array $params, array $input)
    {
        $invoiceId = isset($input['invoiceid']) ? (int) $input['invoiceid'] : 0;
        $amount = isset($input['amount']) ? (string) $input['amount'] : '';
        $currency = isset($input['currency']) ? (string) $input['currency'] : '';
        $sig = isset($input['sig']) ? (string) $input['sig'] : '';
        if ($invoiceId <= 0 || $currency !== 'USD' || ! preg_match('/^\d+\.\d{2}$/', $amount)
            || ! hash_equals(self::sign($params, $invoiceId, $amount, $currency), $sig)) {
            throw new \RuntimeException('This payment link is not valid. Open the invoice again and press the button once more.');
        }
        if (! self::allowedToPay($params)) {
            throw new \RuntimeException('Crypto payments are being set up. Please choose another payment method for now.');
        }

        $invoice = localAPI('GetInvoice', ['invoiceid' => $invoiceId]);
        if (! is_array($invoice) || (isset($invoice['result']) && $invoice['result'] !== 'success')) {
            throw new \RuntimeException('Invoice not found.');
        }
        if ($invoice['status'] !== 'Unpaid') {
            throw new \RuntimeException('This invoice is not awaiting payment.');
        }
        $balance = (float) $invoice['balance'];
        if ($balance <= 0) {
            throw new \RuntimeException('Nothing to pay on this invoice.');
        }
        if ((float) $amount <= 0) {
            throw new \RuntimeException('Nothing to pay on this invoice.');
        }

        Store::ensureTable();
        $environment = self::environment($params);
        $existing = Store::reusable($invoiceId, $amount, $environment);
        if ($existing) {
            return $existing->pay_url;
        }

        $minutes = (int) (isset($params['expiresMinutes']) ? $params['expiresMinutes'] : 60);
        $minutes = max(1, min(1440, $minutes ?: 60));
        $system = self::systemUrl();
        // external_id doubles as the Idempotency-Key. SnapEX rejects a reused external_id, so it changes with the
        // attempt number, amount, mode and minute: double clicks collapse into one SnapEX invoice, while a retry
        // after expiry or after the amount changed gets a fresh one.
        $key = self::externalId($invoiceId, Store::countFor($invoiceId), $amount, $environment, time());
        $data = [
            'amount_usd' => $amount,
            'fee_on' => (isset($params['feeOn']) && $params['feeOn'] === 'customer') ? 'customer' : 'merchant',
            'environment' => $environment,
            'external_id' => $key,
            'description' => self::description($params, $invoiceId),
            'success_url' => $system.'modules/gateways/'.self::MODULE.'/return.php?invoiceid='.$invoiceId,
            'cancel_url' => $system.'viewinvoice.php?id='.$invoiceId,
            'expires_in' => $minutes * 60,
        ];
        $partnerId = isset($params['partnerId']) ? trim((string) $params['partnerId']) : '';
        if ($partnerId !== '') {
            $data['partner_ref'] = $partnerId;
        }

        try {
            $created = self::api($params)->createInvoice($data, $key);
        } catch (ApiException $e) {
            logTransaction($params['name'], ['invoiceid' => $invoiceId, 'request' => $data, 'error' => $e->getMessage()], 'Error creating payment');
            throw new \RuntimeException('Could not start the crypto payment: '.$e->getMessage());
        }
        if (empty($created['invoice_id']) || empty($created['pay_url'])) {
            throw new \RuntimeException('Could not start the crypto payment. Please try again.');
        }

        if (! Store::find($created['invoice_id'])) {
            Store::insert([
                'whmcs_invoice_id' => $invoiceId,
                'snapex_invoice_id' => (string) $created['invoice_id'],
                'environment' => $environment,
                'amount_usd' => $amount,
                'whmcs_amount' => self::money($balance),
                'pay_url' => (string) $created['pay_url'],
                'status' => (string) $created['status'],
                'expires_at' => self::dbTime(isset($created['expires_at']) ? $created['expires_at'] : null),
            ]);
        }

        return (string) $created['pay_url'];
    }

    /** whmcs-{site}-{invoice}-{attempt}-{amount}-{environment}-{minute}; site: a short hash of the WHMCS System URL. */
    public static function externalId($invoiceId, $attempt, $amount, $environment, $time)
    {
        return 'whmcs-'.substr(sha1(self::systemUrl()), 0, 12).'-'.(int) $invoiceId.'-'.(int) $attempt.'-'.$amount
            .'-'.$environment.'-'.intdiv((int) $time, 60);
    }

    /**
     * WHMCS invoice an external_id belongs to: the current format above, or "whmcs-{invoice}" from module 1.0.0.
     *
     * @return array|null ['invoice' => int, 'amount' => ?string, 'environment' => ?string]
     */
    public static function parseExternalId($externalId)
    {
        if (preg_match('/^whmcs-[0-9a-f]{12}-(\d+)-\d+-(\d+\.\d{2})-(live|test)-\d+$/', (string) $externalId, $m)) {
            return ['invoice' => (int) $m[1], 'amount' => $m[2], 'environment' => $m[3]];
        }
        if (preg_match('/^whmcs-(\d+)$/', (string) $externalId, $m)) {
            return ['invoice' => (int) $m[1], 'amount' => null, 'environment' => null];
        }

        return null;
    }

    private static function description(array $params, $invoiceId)
    {
        $company = class_exists('\WHMCS\Config\Setting') ? (string) \WHMCS\Config\Setting::getValue('CompanyName') : '';
        $text = trim(($company !== '' ? $company.' ' : '').'invoice #'.$invoiceId);

        return function_exists('mb_substr') ? mb_substr($text, 0, 255) : substr($text, 0, 255);
    }

    // ------------------------------------------------------------------ settlement

    /**
     * Brings one attempt in line with SnapEX Pay. The invoice data always comes from the API
     * (never from the webhook body alone). Returns the SnapEX status.
     */
    public static function sync(array $params, $row, array $remote = null)
    {
        if ($remote === null) {
            $remote = self::api($params)->invoice($row->snapex_invoice_id);
        }
        $status = isset($remote['status']) ? (string) $remote['status'] : '';
        $external = self::parseExternalId(isset($remote['external_id']) ? $remote['external_id'] : '');
        if ((string) $remote['invoice_id'] !== (string) $row->snapex_invoice_id
            || ! $external || $external['invoice'] !== (int) $row->whmcs_invoice_id
            || ($external['amount'] !== null && ($external['amount'] !== (string) $row->amount_usd || $external['environment'] !== (string) $row->environment))) {
            logTransaction($params['name'], ['row' => (array) $row, 'remote' => $remote], 'Mismatch — ignored');

            return $status;
        }

        $previous = (string) $row->status;
        Store::update($row->id, ['status' => $status, 'checked_at' => Store::now()]);

        if ($status === 'paid') {
            try {
                self::addPayment($params, $row, $remote);
            } catch (\Throwable $e) {
                Store::update($row->id, ['status' => $previous]); // let the cron try again
                throw $e;
            }
        } elseif ($status === 'partially_paid' && $previous !== 'partially_paid') {
            logTransaction($params['name'], self::logData($row, $remote), 'Partial payment — review and apply manually');
        } elseif ($status === 'expired' && $previous !== 'expired') {
            logTransaction($params['name'], self::logData($row, $remote), 'Expired');
        }

        return $status;
    }

    private static function addPayment(array $params, $row, array $remote)
    {
        if (abs((float) $remote['amount_usd'] - (float) $row->amount_usd) > 0.005) {
            logTransaction($params['name'], self::logData($row, $remote), 'Amount mismatch — not applied');

            return;
        }
        if ($row->environment === 'test' && ! self::testMode($params)) {
            logTransaction($params['name'], self::logData($row, $remote), 'Test payment while test mode is off — not applied');

            return;
        }
        if (! Store::claim($row->id)) {
            return; // webhook, return page and cron can race; only one adds the payment
        }
        $transId = (string) $row->snapex_invoice_id;
        $exists = Capsule::table('tblaccounts')->where('gateway', self::MODULE)->where('transid', $transId)->exists();
        if ($exists) {
            return;
        }
        try {
            $ok = addInvoicePayment((int) $row->whmcs_invoice_id, $transId, (float) $row->whmcs_amount, 0, self::MODULE);
        } catch (\Throwable $e) {
            Store::release($row->id);
            throw $e;
        }
        logTransaction($params['name'], self::logData($row, $remote), $ok === false ? 'Payment not applied' : 'Successful');
    }

    private static function logData($row, array $remote)
    {
        $payment = isset($remote['payment']) && is_array($remote['payment']) ? $remote['payment'] : [];

        return [
            'whmcs_invoice' => (int) $row->whmcs_invoice_id,
            'snapex_invoice' => $row->snapex_invoice_id,
            'environment' => $row->environment,
            'status' => isset($remote['status']) ? $remote['status'] : null,
            'amount_usd' => isset($remote['amount_usd']) ? $remote['amount_usd'] : null,
            'charge_usd' => isset($remote['charge_usd']) ? $remote['charge_usd'] : null,
            'credit_usd' => isset($remote['credit_usd']) ? $remote['credit_usd'] : null,
            'paid_with' => isset($payment['symbol']) ? $payment['symbol'].' '.(isset($payment['network']) ? $payment['network'] : '') : null,
            'received' => isset($payment['received_amount']) ? $payment['received_amount'] : null,
            'paid_at' => isset($remote['paid_at']) ? $remote['paid_at'] : null,
        ];
    }

    // ------------------------------------------------------------------ return.php

    /** Client is back from the payment page: settle right away if SnapEX already has the money. */
    public static function returned(array $params, $invoiceId)
    {
        Store::ensureTable();
        $paid = false;
        foreach (Store::pendingFor($invoiceId) as $row) {
            try {
                $paid = self::sync($params, $row) === 'paid' || $paid;
            } catch (ApiException $e) {
                // the webhook or cron will settle it later
            }
        }

        return $paid;
    }

    // ------------------------------------------------------------------ webhook

    /**
     * @return array{0:int,1:string} HTTP status and body for SnapEX Pay
     */
    public static function webhook(array $params, $body, array $headers)
    {
        $headers = array_change_key_case($headers, CASE_LOWER);
        $secret = isset($params['webhookSecret']) ? trim((string) $params['webhookSecret']) : '';
        if ($secret === '') {
            return [503, 'webhook secret is not configured'];
        }
        $timestamp = isset($headers['webhook-timestamp']) ? (string) $headers['webhook-timestamp'] : '';
        $signature = isset($headers['webhook-signature']) ? (string) $headers['webhook-signature'] : '';
        if (! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > self::WEBHOOK_TOLERANCE) {
            return [400, 'stale or missing timestamp'];
        }
        $expected = 'v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
        if (! hash_equals($expected, $signature)) {
            return [401, 'bad signature'];
        }

        $event = json_decode((string) $body, true);
        $type = is_array($event) && isset($event['type']) ? (string) $event['type'] : '';
        $snapexId = is_array($event) && isset($event['data']['invoice_id']) ? (string) $event['data']['invoice_id'] : '';
        if (strpos($type, 'invoice.') !== 0 || $snapexId === '') {
            return [200, 'ignored'];
        }

        Store::ensureTable();
        $row = Store::find($snapexId);
        if (! $row) {
            return [200, 'not a WHMCS invoice']; // same merchant account may serve other shops
        }
        if ($type === 'invoice.extra_payment') {
            logTransaction($params['name'], ['event' => $type, 'data' => $event['data']], 'Extra payment received — check the SnapEX Pay cabinet');
        }
        try {
            self::sync($params, $row);
        } catch (ApiException $e) {
            return [502, 'could not confirm with the API: '.$e->getMessage()]; // SnapEX retries
        }

        return [200, 'ok'];
    }

    // ------------------------------------------------------------------ cron

    public static function cron(array $params)
    {
        Store::ensureTable();
        $checked = 0;
        foreach (Store::dueForCheck() as $row) {
            try {
                self::sync($params, $row);
            } catch (ApiException $e) {
                Store::update($row->id, ['checked_at' => Store::now()]);
            }
            $checked++;
        }

        return $checked;
    }

    private static function dbTime($iso)
    {
        if (! is_string($iso) || $iso === '') {
            return null;
        }
        $ts = strtotime($iso);

        return $ts === false ? null : date('Y-m-d H:i:s', $ts);
    }
}
