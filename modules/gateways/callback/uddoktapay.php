<?php
/**
 * UddoktaPay IPN / Webhook Callback Handler
 *
 * UddoktaPay POSTs payment data to this URL after a transaction.
 * This script verifies the payment via the UddoktaPay API and
 * marks the corresponding WHMCS invoice as paid.
 *
 * Callback URL (set as webhook_url in payment request):
 *   https://yourwhmcs.com/modules/gateways/callback/uddoktapay.php
 *
 * Compatible with PHP 7.4+ and WHMCS 7.x / 8.x
 */

// Boot WHMCS
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

// Identify this gateway module
$gatewayModuleName = 'uddoktapay';

// Load gateway configuration (API Key, API URL)
$gatewayParams = getGatewayVariables($gatewayModuleName);

if (!$gatewayParams['type']) {
    die('UddoktaPay module is not activated.');
}

$apiKey = $gatewayParams['apiKey'];
$apiUrl = rtrim($gatewayParams['apiUrl'], '/');

// -----------------------------------------------------------------------
// Step 1 — Read incoming IPN data from UddoktaPay
// UddoktaPay sends a JSON POST body to this URL.
// -----------------------------------------------------------------------
$rawInput = file_get_contents('php://input');
$ipnData  = json_decode($rawInput, true);

// Fallback: some servers may parse it into $_POST
if (empty($ipnData) && !empty($_POST)) {
    $ipnData = $_POST;
}

// The UddoktaPay invoice ID comes in the IPN body
$uddoktaInvoiceId = isset($ipnData['invoice_id']) ? trim($ipnData['invoice_id']) : '';

if (empty($uddoktaInvoiceId)) {
    logTransaction($gatewayModuleName, $ipnData, 'Failed: Missing invoice_id in IPN payload');
    http_response_code(400);
    die('Invalid IPN: invoice_id is missing.');
}

// -----------------------------------------------------------------------
// Step 2 — Verify the payment with UddoktaPay API
// Never trust the IPN payload alone — always re-verify server-side.
// -----------------------------------------------------------------------
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $apiUrl . '/api/verify-payment',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode(['invoice_id' => $uddoktaInvoiceId]),
    CURLOPT_HTTPHEADER     => [
        'RT-UDDOKTAPAY-API-KEY: ' . $apiKey,
        'Content-Type: application/json',
        'Accept: application/json',
    ],
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_SSL_VERIFYPEER => true,
]);

$verifyResponse = curl_exec($ch);
$httpCode       = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError      = curl_error($ch);
curl_close($ch);

if ($curlError) {
    logTransaction($gatewayModuleName, $ipnData, 'Failed: cURL error — ' . $curlError);
    http_response_code(500);
    die('Verification request failed: ' . $curlError);
}

$verifyResult = json_decode($verifyResponse, true);

// Payment must be COMPLETED to proceed
$paymentStatus = isset($verifyResult['status']) ? strtoupper($verifyResult['status']) : '';

if ($httpCode !== 200 || $paymentStatus !== 'COMPLETED') {
    $message = isset($verifyResult['message']) ? $verifyResult['message'] : 'Verification failed (HTTP ' . $httpCode . ')';
    logTransaction($gatewayModuleName, $verifyResult, 'Failed: ' . $message);
    http_response_code(400);
    die('Payment verification failed: ' . $message);
}

// -----------------------------------------------------------------------
// Step 3 — Extract WHMCS invoice ID from metadata
// We stored whmcs_invoice_id in the metadata when creating the payment.
// -----------------------------------------------------------------------
$metadata       = isset($verifyResult['metadata']) ? $verifyResult['metadata'] : [];
$whmcsInvoiceId = isset($metadata['whmcs_invoice_id']) ? (int) $metadata['whmcs_invoice_id'] : 0;

if ($whmcsInvoiceId <= 0) {
    logTransaction($gatewayModuleName, $verifyResult, 'Failed: whmcs_invoice_id missing from metadata');
    http_response_code(400);
    die('Cannot determine WHMCS invoice ID.');
}

$paymentAmount = isset($verifyResult['amount']) ? $verifyResult['amount'] : 0;
$transactionId = $uddoktaInvoiceId; // Use UddoktaPay invoice ID as the transaction reference

// -----------------------------------------------------------------------
// Step 4 — Record payment in WHMCS
// WHMCS helper functions handle duplicate checks and invoice updates.
// -----------------------------------------------------------------------

// Validate the invoice exists and belongs to this gateway
$whmcsInvoiceId = checkCbInvoiceID($whmcsInvoiceId, $gatewayModuleName);

// Prevent duplicate transaction recording
checkCbTransID($transactionId);

// Mark invoice as paid
addInvoicePayment(
    $whmcsInvoiceId,   // WHMCS invoice ID
    $transactionId,    // Unique transaction reference
    $paymentAmount,    // Amount paid
    0,                 // Transaction fee (0 if not provided)
    $gatewayModuleName // Gateway module name
);

logTransaction($gatewayModuleName, $verifyResult, 'Successful');

http_response_code(200);
echo 'OK';
