# UddoktaPay Payment Gateway for WHMCS

A lightweight, third-party WHMCS payment gateway module for [UddoktaPay](https://uddoktapay.com) — popular in Bangladesh for bKash, Nagad, Rocket, and other MFS payments.

> **PHP 7.4+ compatible.** No complex namespacing or PHP 8-only syntax.

---

## Requirements

| Item | Requirement |
|---|---|
| WHMCS | 7.x or 8.x |
| PHP | 7.4 or higher |
| cURL | Enabled on server |
| UddoktaPay | Self-hosted or cloud account |

---

## File Structure

```
modules/
└── gateways/
    ├── uddoktapay.php          ← Main gateway module
    └── callback/
        └── uddoktapay.php      ← IPN / webhook handler
```

---

## Installation

### 1. Upload Files

Upload both files to your WHMCS root, preserving the directory structure:

```
<whmcs-root>/modules/gateways/uddoktapay.php
<whmcs-root>/modules/gateways/callback/uddoktapay.php
```

### 2. Activate the Gateway

1. Log in to your **WHMCS Admin Panel**
2. Go to **Configuration → System Settings → Payment Gateways**
3. Click the **"All Payment Gateways"** tab
4. Find **UddoktaPay** and click **Activate**

### 3. Configure Credentials

On the **"Manage Existing Gateways"** tab, fill in:

| Field | Value |
|---|---|
| **API Key** | Your UddoktaPay API key from the dashboard |
| **API URL** | Your UddoktaPay base URL, e.g. `https://pay.yourdomain.com` |

> **Note:** The API URL must not have a trailing slash and must point to the root of your UddoktaPay installation.

---

## How It Works

```
Customer clicks "Pay Now"
        │
        ▼
WHMCS calls uddoktapay_link()
        │
        ▼
POST /api/checkout-v2  ──► UddoktaPay returns { payment_url }
        │
        ▼
Customer redirected to UddoktaPay checkout
        │
        ├──► redirect_url ──► Customer lands back on WHMCS invoice
        │
        └──► webhook_url  ──► callback/uddoktapay.php receives IPN
                    │
                    ▼
            POST /api/verify-payment  (server-side verification)
                    │
                    ▼
            addInvoicePayment()  ──► Invoice marked as PAID
```

---

## Webhook / IPN URL

UddoktaPay will automatically POST payment notifications to:

```
https://yourwhmcs.com/modules/gateways/callback/uddoktapay.php
```

This URL is passed to UddoktaPay when the payment is created — no manual configuration needed.

---

## Troubleshooting

| Error | Cause | Fix |
|---|---|---|
| Module not in gateway list | File in wrong path | Re-upload to exact path above |
| `cURL error` | Wrong API URL | Check API URL, remove trailing slash |
| Invoice not marked paid | IPN URL blocked | Ensure the callback URL is publicly accessible |
| Payment not verified | Wrong API Key | Double-check the API key in gateway settings |

**Enable debug logging:**
WHMCS Admin → Configuration → System Logs → Module Log → Turn On

---

## License

MIT License. See [LICENSE](LICENSE) for details.
