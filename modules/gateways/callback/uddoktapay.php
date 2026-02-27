<?php
/**
 * UddoktaPay IPN / Webhook Callback Handler
 *
 * UddoktaPay POSTs payment data to this URL after a transaction completes.
 * This script verifies the payment server-side via the UddoktaPay API,
 * then marks the corresponding WHMCS invoice as paid.
 *
 * Webhook URL (auto-configured in payment request):
 *   https://yourwhmcs.com/modules/gateways/callback/uddoktapay.php
 *
 * @requires  PHP    7.4 or higher
 * @requires  WHMCS  7.4 or higher
 */

// Bootstrap WHMCS — paths go 3 levels up from /modules/gateways/callback/
require_once dirname(dirname(dirname(__DIR__))) . '/init.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/gatewayfunctions.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/invoicefunctions.php';

// Gateway module identifier — must match the filename: uddoktapay.php
$gatewayModuleName = 'uddoktapay';

// Load this gateway's saved configuration from the WHMCS database
$gatewayParams = getGatewayVariables($gatewayModuleName);

// Abort if the module is not activated in WHMCS
if (empty($gatewayParams['type'])) {
    die('UddoktaPay: Module is not activated in WHMCS Payment Gateways.');
}

$apiKey = isset($gatewayParams['apiKey']) ? trim($gatewayParams['apiKey']) : '';
$apiUrl = isset($gatewayParams['apiUrl']) ? rtrim(trim($gatewayParams['apiUrl']), '/') : '';

if (empty($apiKey) || empty($apiUrl)) {
    die('UddoktaPay: API Key or API URL is not configured.');
}

// ---------------------------------------------------------------------------
// STEP 1 — Read the IPN payload sent by UddoktaPay
//
// UddoktaPay sends a JSON POST body. Some server configurations parse it into
// $_POST instead, so we check both sources.
// ---------------------------------------------------------------------------
$rawInput = file_get_contents('php://input');
$ipnData  = json_decode($rawInput, true);

if (empty($ipnData) && !empty($_POST)) {
    $ipnData = $_POST;
}

// Ensure we received at least an empty array
if (!is_array($ipnData)) {
    $ipnData = array();
}

// The UddoktaPay payment reference (their invoice ID)
$uddoktaInvoiceId = isset($ipnData['invoice_id']) ? trim($ipnData['invoice_id']) : '';

if ($uddoktaInvoiceId === '') {
    logTransaction($gatewayModuleName, $ipnData, 'Failed: invoice_id missing from IPN');
    http_response_code(400);
    die('Bad request: invoice_id is required.');
}

// ---------------------------------------------------------------------------
// STEP 2 — Verify the payment server-side via UddoktaPay API
//
// NEVER trust the IPN payload alone. Always re-confirm with the API.
// ---------------------------------------------------------------------------
$verifyPayload = json_encode(array('invoice_id' => $uddoktaInvoiceId));

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL,            $apiUrl . '/api/verify-payment');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST,           true);
curl_setopt($ch, CURLOPT_POSTFIELDS,     $verifyPayload);
curl_setopt($ch, CURLOPT_HTTPHEADER,     array(
    'RT-UDDOKTAPAY-API-KEY: ' . $apiKey,
    'Content-Type: application/json',
    'Accept: application/json',
));
curl_setopt($ch, CURLOPT_TIMEOUT,        30);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

$verifyResponse = curl_exec($ch);
$httpCode       = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError      = curl_error($ch);
curl_close($ch);

// Handle cURL-level failure (DNS, timeout, SSL, etc.)
if ($curlError) {
    logTransaction($gatewayModuleName, $ipnData, 'Failed: cURL error during verification — ' . $curlError);
    http_response_code(500);
    die('UddoktaPay: Could not reach verification API.');
}

$verifyResult = json_decode($verifyResponse, true);

if (!is_array($verifyResult)) {
    $verifyResult = array();
}

// Payment status must be COMPLETED
$paymentStatus = isset($verifyResult['status']) ? strtoupper(trim($verifyResult['status'])) : '';

if ($httpCode !== 200 || $paymentStatus !== 'COMPLETED') {
    $errorMsg = isset($verifyResult['message']) ? $verifyResult['message'] : 'Verification failed (HTTP ' . $httpCode . ')';
    logTransaction($gatewayModuleName, $verifyResult, 'Failed: ' . $errorMsg);
    http_response_code(400);
    die('UddoktaPay: Payment not verified — ' . $errorMsg);
}

// ---------------------------------------------------------------------------
// STEP 3 — Recover the WHMCS invoice ID from the response metadata
//
// We embedded whmcs_invoice_id in the metadata when initiating the payment.
// ---------------------------------------------------------------------------
$metadata       = isset($verifyResult['metadata']) ? $verifyResult['metadata'] : array();
$whmcsInvoiceId = isset($metadata['whmcs_invoice_id']) ? (int) $metadata['whmcs_invoice_id'] : 0;

if ($whmcsInvoiceId <= 0) {
    logTransaction($gatewayModuleName, $verifyResult, 'Failed: whmcs_invoice_id not found in metadata');
    http_response_code(400);
    die('UddoktaPay: Cannot resolve WHMCS invoice ID.');
}

// Paid amount from verified API response
$paymentAmount = isset($verifyResult['amount']) ? floatval($verifyResult['amount']) : 0.00;

// Use the UddoktaPay invoice ID as the unique transaction reference in WHMCS
$transactionId = $uddoktaInvoiceId;

// ---------------------------------------------------------------------------
// STEP 4 — Record payment in WHMCS
//
// WHMCS built-in helpers prevent duplicate payments and update the invoice.
// ---------------------------------------------------------------------------

// Confirm the WHMCS invoice exists (throws error if not found)
$whmcsInvoiceId = checkCbInvoiceID($whmcsInvoiceId, $gatewayModuleName);

// Block duplicate transaction IDs from being recorded twice
checkCbTransID($transactionId);

// Mark the invoice as paid
addInvoicePayment(
    $whmcsInvoiceId,    // WHMCS invoice ID (integer)
    $transactionId,     // Unique transaction reference string
    $paymentAmount,     // Amount paid (float)
    0,                  // Gateway fee — 0 unless UddoktaPay provides it
    $gatewayModuleName  // Must match module filename
);

logTransaction($gatewayModuleName, $verifyResult, 'Successful');

http_response_code(200);
echo 'OK';
