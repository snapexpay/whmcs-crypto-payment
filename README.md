# SnapEX Pay for WHMCS

Accept crypto on WHMCS invoices. The client presses **Pay Now**, pays on the SnapEX Pay page
(USDT on TRON, Ethereum, BSC and other networks, or other coins converted to USDT), and the
invoice is marked paid in WHMCS automatically.

- No wallets or nodes to run; funds arrive in your SnapEX Pay merchant balance.
- Three independent ways an invoice gets marked paid: the signed webhook, the client returning
  from the payment page, and a check on every WHMCS cron run. A lost webhook never leaves a paid
  invoice unpaid, and a payment is never added twice.
- Test mode: test invoices confirm by themselves without real money; only logged-in admins see
  the button while it is on.

Requirements: WHMCS 8.x or 9.x, PHP 7.4 or newer with cURL, a SnapEX Pay merchant account.

> **Connecting to SnapEX Pay?** Create a merchant account, check the pricing and see every ready-made module on **[pay.snapex.pro](https://pay.snapex.pro)**.

## Install

Download `snapexpay-whmcs-<version>.zip` from the [latest release](https://github.com/snapexpay/whmcs-crypto-payment/releases/latest)
or from [pay.snapex.pro/integrations/whmcs](https://pay.snapex.pro/integrations/whmcs).

1. Upload the contents of this archive to the WHMCS root folder, keeping the paths:
   ```
   modules/gateways/snapexpay.php
   modules/gateways/snapexpay/…
   modules/gateways/callback/snapexpay.php
   includes/hooks/snapexpay.php
   ```
2. WHMCS admin → **System Settings → Payment Gateways → All Payment Gateways** → **SnapEX Pay (Crypto)**.
3. In the SnapEX Pay merchant cabinet create an **API key** with the `invoices.read` and
   `invoices.write` scopes and paste it into **API key**.
4. Webhook (recommended): in the cabinet set the webhook URL to
   `https://<your WHMCS>/modules/gateways/callback/snapexpay.php` (the exact address is shown
   under the field in WHMCS) and paste the `whsec_…` secret into **Webhook secret**.
5. If your WHMCS currency is not USD, set **Convert To For Processing** to **USD**. Invoices are
   still recorded in the client's currency.
6. Turn on **Test mode**, open an unpaid invoice while logged in as admin, pay it on the
   SnapEX page with a test method and check it turns **Paid**. Then turn test mode off.

## Settings

| Setting | |
|---|---|
| API key | From the SnapEX Pay cabinet. |
| Webhook secret | `whsec_…` from the cabinet webhook settings. Without it the module still works through the return page and cron. |
| Network fee paid by | *Merchant*: the client pays exactly the invoice amount. *Client*: the fee is added on top. |
| Payment window, minutes | How long the payment page stays valid (1–1440, default 60). Pressing Pay Now again later opens a fresh one. |
| Test mode | Test invoices, admins only. Test payments are ignored once test mode is off. |
| Partner ID | Optional. The Partner ID of the integrator who set this up (SnapEX Pay referral program). Leave empty if you set it up yourself. |

## What lands in WHMCS

- **Paid in full** → the payment is added to the invoice in the invoice currency; the transaction
  ID is the SnapEX Pay invoice ID (`INV-…`).
- **Paid partly** (the client sent less and did not top up in time) → nothing is added
  automatically; the gateway log has an entry *Partial payment — review and apply manually* with the received amount.
- **Extra payment** after the invoice was closed → a gateway log entry; the money is in your SnapEX Pay balance.

Everything the module does is in **Billing → Gateway Log**.

## Support

support: https://snapex.pro
