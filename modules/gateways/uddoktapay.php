<?php
/**
 * UddoktaPay Payment Gateway for WHMCS
 *
 * A third-party gateway module that redirects customers to the
 * UddoktaPay hosted checkout page and verifies payment via IPN/webhook.
 *
 * Compatible with PHP 7.4+ and WHMCS 7.x / 8.x
 *
 * @author      UddoktaPay Module
 * @link        https://uddoktapay.com
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

/**
 * Module metadata.
 */
function uddoktapay_MetaData()
{
    return [
        'DisplayName' => 'UddoktaPay',
        'APIVersion'  => '1.1',
    ];
}

/**
 * Gateway configuration fields shown in WHMCS Admin → Payment Gateways.
 */
function uddoktapay_config()
{
    return [
        'FriendlyName' => [
            'Type'  => 'System',
            'Value' => 'UddoktaPay',
        ],
        'apiKey' => [
            'FriendlyName' => 'API Key',
            'Type'         => 'text',
            'Size'         => '80',
            'Default'      => '',
            'Description'  => 'Your UddoktaPay API Key (found in UddoktaPay Dashboard → Settings)',
        ],
        'apiUrl' => [
            'FriendlyName' => 'API URL',
            'Type'         => 'text',
            'Size'         => '80',
            'Default'      => '',
            'Description'  => 'Base URL of your UddoktaPay installation, e.g. https://pay.yourdomain.com',
        ],
    ];
}

/**
 * Payment link / button shown on the invoice page.
 *
 * Calls UddoktaPay API to create a payment session, then renders
 * a button that redirects the customer to the hosted checkout.
 *
 * @param array $params WHMCS gateway parameters
 * @return string HTML button or error message
 */
function uddoktapay_link($params)
{
    $apiKey   = $params['apiKey'];
    $apiUrl   = rtrim($params['apiUrl'], '/');

    // Invoice details
    $invoiceId = $params['invoiceid'];
    $amount    = $params['amount'];

    // Customer details
    $firstName = $params['clientdetails']['firstname'];
    $lastName  = $params['clientdetails']['lastname'];
    $email     = $params['clientdetails']['email'];

    // URLs
    $systemUrl   = $params['systemurl'];
    $returnUrl   = $params['returnurl'];
    $callbackUrl = $systemUrl . 'modules/gateways/callback/uddoktapay.php';

    // Build payment request payload
    $payload = json_encode([
        'full_name'    => trim($firstName . ' ' . $lastName),
        'email'        => $email,
        'amount'       => $amount,
        'metadata'     => [
            'whmcs_invoice_id' => $invoiceId,
        ],
        'redirect_url' => $returnUrl,
        'return_type'  => 'GET',
        'cancel_url'   => $returnUrl,
        'webhook_url'  => $callbackUrl,
    ]);

    // Call UddoktaPay checkout API
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $apiUrl . '/api/checkout-v2',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'RT-UDDOKTAPAY-API-KEY: ' . $apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    // Handle connection errors
    if ($curlError) {
        logActivity('UddoktaPay: cURL error for invoice #' . $invoiceId . ' — ' . $curlError);
        return '<p style="color:red;font-weight:bold;">UddoktaPay connection error. Please contact support.</p>';
    }

    $result = json_decode($response, true);

    // Handle API errors
    if ($httpCode !== 200 || empty($result['payment_url'])) {
        $message = !empty($result['message']) ? $result['message'] : 'Unexpected error (HTTP ' . $httpCode . ')';
        logActivity('UddoktaPay: API error for invoice #' . $invoiceId . ' — ' . $message);
        return '<p style="color:red;font-weight:bold;">UddoktaPay Error: ' . htmlspecialchars($message) . '</p>';
    }

    $paymentUrl = htmlspecialchars($result['payment_url'], ENT_QUOTES, 'UTF-8');

    return '<a href="' . $paymentUrl . '" class="btn btn-success btn-lg">
                Pay Now via UddoktaPay
            </a>';
}
