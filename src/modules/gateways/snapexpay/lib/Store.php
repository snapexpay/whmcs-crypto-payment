<?php

namespace SnapexPay\Whmcs;

if (! defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

/** Links WHMCS invoices to SnapEX Pay invoices. One WHMCS invoice may get several attempts (expired, amount changed). */
class Store
{
    const TABLE = 'mod_snapexpay_invoices';

    /** Statuses after which nothing can change on the SnapEX side. */
    const FINAL_STATUSES = ['paid', 'partially_paid', 'expired'];

    public static function ensureTable()
    {
        $schema = Capsule::schema();
        if ($schema->hasTable(self::TABLE)) {
            return;
        }
        $schema->create(self::TABLE, function ($table) {
            $table->increments('id');
            $table->unsignedInteger('whmcs_invoice_id');
            $table->string('snapex_invoice_id', 64)->unique();
            $table->string('environment', 8);
            $table->string('amount_usd', 32);
            $table->decimal('whmcs_amount', 16, 2);
            $table->text('pay_url');
            $table->string('status', 24);
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('checked_at')->nullable();
            $table->dateTime('applied_at')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->index(['whmcs_invoice_id', 'status']);
        });
    }

    public static function insert(array $row)
    {
        $now = self::now();
        $row += ['created_at' => $now, 'updated_at' => $now];

        return (int) Capsule::table(self::TABLE)->insertGetId($row);
    }

    public static function find($snapexInvoiceId)
    {
        return Capsule::table(self::TABLE)->where('snapex_invoice_id', (string) $snapexInvoiceId)->first();
    }

    /** An attempt the client can still pay: same amount and mode, not expiring within a few minutes. */
    public static function reusable($whmcsInvoiceId, $amountUsd, $environment)
    {
        return Capsule::table(self::TABLE)
            ->where('whmcs_invoice_id', (int) $whmcsInvoiceId)
            ->where('amount_usd', (string) $amountUsd)
            ->where('environment', (string) $environment)
            ->whereIn('status', ['open', 'awaiting'])
            ->where('expires_at', '>', date('Y-m-d H:i:s', time() + 300))
            ->orderBy('id', 'desc')
            ->first();
    }

    /** How many SnapEX invoices this WHMCS invoice already has (part of the next external_id). */
    public static function countFor($whmcsInvoiceId)
    {
        return (int) Capsule::table(self::TABLE)->where('whmcs_invoice_id', (int) $whmcsInvoiceId)->count();
    }

    /** Unsettled attempts for one WHMCS invoice (client came back from the payment page). */
    public static function pendingFor($whmcsInvoiceId)
    {
        return Capsule::table(self::TABLE)
            ->where('whmcs_invoice_id', (int) $whmcsInvoiceId)
            ->whereNotIn('status', self::FINAL_STATUSES)
            ->whereNull('applied_at')
            ->orderBy('id', 'desc')
            ->limit(5)
            ->get();
    }

    /** Attempts the cron should look at: recent, unsettled, not checked in the last 10 minutes. */
    public static function dueForCheck($limit = 25)
    {
        $now = time();

        return Capsule::table(self::TABLE)
            ->whereNotIn('status', self::FINAL_STATUSES)
            ->whereNull('applied_at')
            ->where('created_at', '>', date('Y-m-d H:i:s', $now - 5 * 86400))
            ->where(function ($q) use ($now) {
                $q->whereNull('checked_at')->orWhere('checked_at', '<', date('Y-m-d H:i:s', $now - 600));
            })
            ->orderBy('id')
            ->limit((int) $limit)
            ->get();
    }

    public static function update($id, array $values)
    {
        $values['updated_at'] = self::now();
        Capsule::table(self::TABLE)->where('id', (int) $id)->update($values);
    }

    /** Atomically claims the right to add the payment; false if another request already did. */
    public static function claim($id)
    {
        $now = self::now();

        return Capsule::table(self::TABLE)
            ->where('id', (int) $id)
            ->whereNull('applied_at')
            ->update(['applied_at' => $now, 'updated_at' => $now]) === 1;
    }

    public static function release($id)
    {
        Capsule::table(self::TABLE)->where('id', (int) $id)->update(['applied_at' => null, 'updated_at' => self::now()]);
    }

    public static function now()
    {
        return date('Y-m-d H:i:s');
    }
}
