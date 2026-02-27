<?php
/**
 * UddoktaPay Payment Gateway for WHMCS
 *
 * Redirects customers to the UddoktaPay hosted checkout page
 * and verifies payment via IPN/webhook callback.
 *
 * @requires  PHP        7.4 or higher
 * @requires  WHMCS      7.4 or higher
 * @requires  PHP cURL   extension enabled
 *
 * @link https://uddoktapay.com
 */

// Block direct file access
if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

// Minimum PHP version guard
if (version_compare(PHP_VERSION, '7.4.0', '<')) {
    die('UddoktaPay gateway requires PHP 7.4 or higher. Current version: ' . PHP_VERSION);
}

/**
 * Module metadata.
 * Supported from WHMCS 7.0+
 */
function uddoktapay_MetaData()
{
    return array(
        'DisplayName' => 'UddoktaPay',
        'APIVersion'  => '1.1',
    );
}

/**
 * Gateway configuration fields.
 * Displayed in WHMCS Admin > Setup > Payment Gateways.
 */
function uddoktapay_config()
{
    return array(
        'FriendlyName' => array(
            'Type'  => 'System',
            'Value' => 'UddoktaPay',
        ),
        'apiKey' => array(
            'FriendlyName' => 'API Key',
            'Type'         => 'text',
            'Size'         => '80',
            'Default'      => '',
            'Description'  => 'Your UddoktaPay API Key — find it in UddoktaPay Dashboard > Settings',
        ),
        'apiUrl' => array(
            'FriendlyName' => 'API URL',
            'Type'         => 'text',
            'Size'         => '80',
            'Default'      => '',
            'Description'  => 'Base URL of your UddoktaPay installation. Example: https://pay.yourdomain.com',
        ),
    );
}

/**
 * Payment button shown on the WHMCS invoice page.
 *
 * Calls the UddoktaPay checkout API to create a payment session,
 * then renders a button that redirects the customer to the hosted
 * UddoktaPay checkout page.
 *
 * Available $params keys (WHMCS 7.4):
 *   invoiceid, amount, currency, description, returnurl, systemurl,
 *   clientdetails (array: firstname, lastname, email, address1, city, etc.)
 *
 * @param  array  $params  Gateway parameter array supplied by WHMCS
 * @return string          HTML button markup or error message
 */
function uddoktapay_link($params)
{
    // --- Gateway credentials ---
    $apiKey = isset($params['apiKey']) ? trim($params['apiKey']) : '';
    $apiUrl = isset($params['apiUrl']) ? rtrim(trim($params['apiUrl']), '/') : '';

    // Bail early if not configured
    if (empty($apiKey) || empty($apiUrl)) {
        return '<p style="color:red;font-weight:bold;">UddoktaPay is not configured. Please set your API Key and API URL in Payment Gateways.</p>';
    }

    // --- Invoice details ---
    $invoiceId = isset($params['invoiceid']) ? (int) $params['invoiceid'] : 0;
    // floatval ensures correct numeric type in JSON (no quoted string)
    $amount    = isset($params['amount']) ? floatval($params['amount']) : 0.00;

    // --- Customer details ---
    $clientDetails = isset($params['clientdetails']) ? $params['clientdetails'] : array();
    $firstName     = isset($clientDetails['firstname']) ? $clientDetails['firstname'] : '';
    $lastName      = isset($clientDetails['lastname'])  ? $clientDetails['lastname']  : '';
    $email         = isset($clientDetails['email'])     ? $clientDetails['email']     : '';
    $fullName      = trim($firstName . ' ' . $lastName);

    // --- URLs ---
    $systemUrl   = isset($params['systemurl']) ? $params['systemurl'] : '';
    $returnUrl   = isset($params['returnurl']) ? $params['returnurl'] : $systemUrl;
    $callbackUrl = $systemUrl . 'modules/gateways/callback/uddoktapay.php';

    // --- Build API payload ---
    $payload = json_encode(array(
        'full_name'    => $fullName,
        'email'        => $email,
        'amount'       => $amount,
        'metadata'     => array(
            'whmcs_invoice_id' => $invoiceId,
        ),
        'redirect_url' => $returnUrl,
        'return_type'  => 'GET',
        'cancel_url'   => $returnUrl,
        'webhook_url'  => $callbackUrl,
    ));

    // --- Call UddoktaPay /api/checkout-v2 ---
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL,            $apiUrl . '/api/checkout-v2');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST,           true);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER,     array(
        'RT-UDDOKTAPAY-API-KEY: ' . $apiKey,
        'Content-Type: application/json',
        'Accept: application/json',
    ));
    curl_setopt($ch, CURLOPT_TIMEOUT,        30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

    $response  = curl_exec($ch);
    $httpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    // --- cURL connection failure ---
    if ($curlError) {
        logActivity('UddoktaPay | Invoice #' . $invoiceId . ' | cURL error: ' . $curlError);
        return '<p style="color:red;font-weight:bold;">UddoktaPay: Connection error. Please contact support.</p>';
    }

    $result = json_decode($response, true);

    // --- API error (non-200 or missing payment_url) ---
    if ($httpCode !== 200 || empty($result['payment_url'])) {
        $errorMsg = (!empty($result['message'])) ? $result['message'] : 'Unexpected error (HTTP ' . $httpCode . ')';
        logActivity('UddoktaPay | Invoice #' . $invoiceId . ' | API error: ' . $errorMsg);
        return '<p style="color:red;font-weight:bold;">UddoktaPay Error: ' . htmlspecialchars($errorMsg, ENT_QUOTES, 'UTF-8') . '</p>';
    }

    // --- Render payment button ---
    $paymentUrl = htmlspecialchars($result['payment_url'], ENT_QUOTES, 'UTF-8');

    return '<a href="' . $paymentUrl . '" class="btn btn-success btn-lg" style="display:inline-block;padding:10px 24px;">'
         . 'Pay Now via UddoktaPay'
         . '</a>';
}
