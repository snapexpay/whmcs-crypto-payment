<?php
/**
 * SnapEX Pay — crypto payments for WHMCS.
 *
 * Third-party gateway: the client pays on the SnapEX Pay page (USDT on several networks or other coins
 * swapped to USDT), the invoice is marked paid from the signed webhook, on the client's return,
 * or by the cron check — whichever comes first.
 *
 * @see https://snapex.pro
 */

if (! defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__.'/snapexpay/lib/bootstrap.php';

use SnapexPay\Whmcs\Gateway;

function snapexpay_MetaData()
{
    return [
        'DisplayName' => 'SnapEX Pay (Crypto)',
        'APIVersion' => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
    ];
}

function snapexpay_config()
{
    return [
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => 'SnapEX Pay (Crypto)',
        ],
        'apiKey' => [
            'FriendlyName' => 'API key',
            'Type' => 'password',
            'Size' => '80',
            'Description' => 'SnapEX Pay merchant cabinet → API keys. Scopes: invoices.read and invoices.write.',
        ],
        'webhookSecret' => [
            'FriendlyName' => 'Webhook secret',
            'Type' => 'password',
            'Size' => '80',
            'Description' => 'Optional but recommended. In the cabinet set the webhook URL to <code>'
                .htmlspecialchars(Gateway::callbackUrl(), ENT_QUOTES)
                .'</code> and paste the whsec_… secret here.',
        ],
        'feeOn' => [
            'FriendlyName' => 'Network fee paid by',
            'Type' => 'dropdown',
            'Options' => [
                'merchant' => 'Merchant (client pays the invoice amount)',
                'customer' => 'Client (added on top of the invoice amount)',
            ],
            'Default' => 'merchant',
        ],
        'expiresMinutes' => [
            'FriendlyName' => 'Payment window, minutes',
            'Type' => 'text',
            'Size' => '6',
            'Default' => '60',
            'Description' => 'How long the client has to pay once the payment page is opened (1–1440).',
        ],
        'testMode' => [
            'FriendlyName' => 'Test mode',
            'Type' => 'yesno',
            'Description' => 'Test invoices confirm by themselves without real money. Only logged-in admins see the button while this is on.',
        ],
        'partnerId' => [
            'FriendlyName' => 'Partner ID',
            'Type' => 'text',
            'Size' => '32',
            'Description' => 'Optional. The Partner ID of the integrator who set this up (SnapEX Pay referral program). Leave empty if you set it up yourself.',
        ],
        'apiUrl' => [
            'FriendlyName' => 'API URL',
            'Type' => 'text',
            'Size' => '60',
            'Default' => Gateway::DEFAULT_API_URL,
            'Description' => 'Leave as is.',
        ],
    ];
}

/** Invoice page: a button that starts the payment. Nothing is created at SnapEX Pay until it is clicked. */
function snapexpay_link($params)
{
    return Gateway::link($params);
}
