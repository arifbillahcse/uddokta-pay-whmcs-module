# UddoktaPay Payment Gateway for WHMCS

A simple, self-contained WHMCS payment gateway module for [UddoktaPay](https://uddoktapay.com).
Supports bKash, Nagad, Rocket, and other MFS payments popular in Bangladesh.

---

## Requirements

| Item | Minimum Version |
|---|---|
| WHMCS | **7.4** or higher |
| PHP | **7.4** or higher |
| PHP cURL | Must be enabled on server |
| UddoktaPay | Self-hosted or cloud account |

> This module uses **no PHP 8-only syntax** (no `?->`, no `match`, no named arguments).
> It is safe to run on PHP 7.4, 7.4.x, 8.0, 8.1, and 8.2.

---

## File Structure

```
modules/
└── gateways/
    ├── uddoktapay.php          ← Main gateway (upload this)
    └── callback/
        └── uddoktapay.php      ← IPN / webhook handler (upload this)
```

> **Only two files to upload. No extra folders or dependencies.**

---

## Installation

### Step 1 — Upload the files

Upload **both files** to your WHMCS root directory:

```
<whmcs-root>/modules/gateways/uddoktapay.php
<whmcs-root>/modules/gateways/callback/uddoktapay.php
```

Use FTP, SFTP, or your hosting control panel's File Manager.

> Make sure `callback/uddoktapay.php` goes into the `callback/` subfolder — **not** the same folder as `uddoktapay.php`.

### Step 2 — Activate in WHMCS

1. Log in to **WHMCS Admin Panel**
2. Go to **Setup → Payment Gateways** (WHMCS 7.x)
   or **Configuration → System Settings → Payment Gateways** (WHMCS 8.x)
3. Click the **"All Payment Gateways"** tab
4. Find **UddoktaPay** in the list and click **Activate**

### Step 3 — Enter your credentials

On the **"Manage Existing Gateways"** tab, fill in:

| Field | What to enter |
|---|---|
| **API Key** | Your API key from the UddoktaPay dashboard |
| **API URL** | Base URL of your UddoktaPay installation |

**API URL format:**
```
https://pay.yourdomain.com        ✅ Correct
https://pay.yourdomain.com/       ❌ No trailing slash
https://pay.yourdomain.com/api/   ❌ Do not include /api/
```

Click **Save Changes**.

---

## How It Works

```
1. Customer opens WHMCS invoice and clicks "Pay Now via UddoktaPay"
        │
        ▼
2. WHMCS calls uddoktapay_link()
        │
        ▼
3. Module POSTs to UddoktaPay: POST /api/checkout-v2
   → Receives payment_url in response
        │
        ▼
4. Customer is redirected to UddoktaPay hosted checkout
        │
        ├──► On success: customer redirected back to WHMCS invoice
        │
        └──► UddoktaPay POSTs IPN to your callback URL
                    │
                    ▼
5. callback/uddoktapay.php verifies payment:
   POST /api/verify-payment  (server-side, not trusting IPN alone)
                    │
                    ▼
6. If status = COMPLETED → addInvoicePayment() → Invoice marked PAID ✅
```

---

## Webhook / IPN URL

The webhook URL is set automatically when a payment is created.
It points to:

```
https://yourwhmcs.com/modules/gateways/callback/uddoktapay.php
```

Make sure this URL is:
- Publicly accessible from the internet
- Not blocked by a firewall or `.htaccess` rule
- Not returning 403/404 when visited in a browser

---

## Troubleshooting

### Enable WHMCS module logging

**WHMCS 7.x:** Utilities → Logs → Module Log
**WHMCS 8.x:** Configuration → System Logs → Module Log

Turn logging **On**, reproduce the problem, then read the log for the raw error.

### Common errors

| Error | Cause | Fix |
|---|---|---|
| Module not in gateway list | File uploaded to wrong path | Re-upload to exact paths above |
| `UddoktaPay is not configured` | API Key/URL not saved | Re-enter credentials and save |
| `cURL error` | Wrong API URL or server can't reach UddoktaPay | Check API URL, test server connectivity |
| Invoice not marked paid | Webhook URL is blocked | Verify callback URL is publicly accessible |
| `Cannot resolve WHMCS invoice ID` | Metadata not returned by UddoktaPay | Ensure UddoktaPay returns metadata in webhook |
| PHP version error on activation | Server PHP < 7.4 | Upgrade PHP via hosting control panel |

---

## PHP Version Check

This module will refuse to load on PHP < 7.4 and display:

```
UddoktaPay gateway requires PHP 7.4 or higher. Current version: X.X.X
```

To check your PHP version in WHMCS:
**WHMCS Admin → Configuration → System Health Status → PHP Version**

---

## License

MIT License. See [LICENSE](LICENSE) for details.
