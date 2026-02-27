<?php

declare(strict_types=1);

namespace WHMCS\Module\Gateway\UddoktaPay\Http;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\RequestOptions;
use WHMCS\Module\Gateway\UddoktaPay\Exception\UddoktaPayException;

/**
 * UddoktaPay Payment Gateway API Client
 */
final class UddoktaPayAPI
{
    private const API_HEADER_KEY = 'RT-UDDOKTAPAY-API-KEY';
    private const DEFAULT_TIMEOUT = 30;
    private const CHECKOUT_V2 = 'checkout-v2';
    private const VERIFY_ENDPOINT = 'verify-payment';

    private readonly string $apiKey;
    private readonly string $apiBaseURL;
    private readonly Client $client;

    private function __construct(
        string $apiKey,
        string $apiBaseURL,
        int $timeout = self::DEFAULT_TIMEOUT,
        bool $verifySsl = true
    ) {
        $trimmedApiKey = trim($apiKey);

        if ($trimmedApiKey === '') {
            throw UddoktaPayException::make('API Key cannot be empty');
        }

        $this->apiKey = $trimmedApiKey;
        $this->apiBaseURL = $this->normalizeBaseURL($apiBaseURL);

        $this->client = new Client([
            'base_uri' => $this->apiBaseURL . '/',
            'timeout' => max(1, $timeout),
            'verify' => $verifySsl,
            'headers' => [
                self::API_HEADER_KEY => $this->apiKey,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    public static function make(
        string $apiKey,
        string $apiBaseURL,
        int $timeout = self::DEFAULT_TIMEOUT,
        bool $verifySsl = true
    ): self {
        return new self($apiKey, $apiBaseURL, $timeout, $verifySsl);
    }

    public function initPayment(array $requestData, string $apiType = self::CHECKOUT_V2): string
    {
        $this->validatePaymentData($requestData);

        $response = $this->sendRequest('POST', $apiType, $requestData);

        return $response['payment_url']
            ?? throw UddoktaPayException::make($response['message'] ?? 'Payment initialization failed');
    }

    public function verifyPayment(string $invoiceId): array
    {
        if (trim($invoiceId) === '') {
            throw UddoktaPayException::make('Invoice ID cannot be empty');
        }

        return $this->sendRequest('POST', self::VERIFY_ENDPOINT, ['invoice_id' => $invoiceId]);
    }

    public function executePayment(): array
    {
        $headerKey = 'HTTP_' . str_replace('-', '_', self::API_HEADER_KEY);
        $headerApi = $_SERVER[$headerKey] ?? null;

        if ($headerApi === null) {
            throw UddoktaPayException::make('Missing API key in request header');
        }

        if ($headerApi !== $this->apiKey) {
            throw UddoktaPayException::make('Invalid API key - Unauthorized');
        }

        $rawInput = trim(file_get_contents('php://input') ?: '');

        if ($rawInput === '') {
            throw UddoktaPayException::make('Empty IPN response body');
        }

        $data = json_decode($rawInput, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw UddoktaPayException::make('Invalid JSON in IPN response: ' . json_last_error_msg());
        }

        $invoiceId = $data['invoice_id'] ?? throw UddoktaPayException::make('Invoice ID missing in IPN data');

        return $this->verifyPayment($invoiceId);
    }

    private function normalizeBaseURL(string $apiBaseURL): string
    {
        if ($apiBaseURL === '') {
            throw UddoktaPayException::make('API Base URL cannot be empty');
        }

        $baseURL = rtrim($apiBaseURL, '/');
        $apiSegmentPosition = strpos($baseURL, '/api');

        if ($apiSegmentPosition !== false) {
            $baseURL = substr($baseURL, 0, $apiSegmentPosition + 4);
        }

        return $baseURL;
    }

    private function sendRequest(string $method, string $endpoint, array $data): array
    {
        try {
            $response = $this->client->request($method, $endpoint, [
                RequestOptions::JSON => $data,
            ]);

            $body = $response->getBody()->getContents();
            $decodedResponse = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw UddoktaPayException::make('Invalid JSON response: ' . json_last_error_msg());
            }

            return $decodedResponse;
        } catch (RequestException $e) {
            throw UddoktaPayException::make($this->extractErrorMessage($e));
        } catch (GuzzleException $e) {
            throw UddoktaPayException::make('Request failed: ' . $e->getMessage());
        }
    }

    private function validatePaymentData(array $data): void
    {
        $requiredFields = ['full_name', 'email', 'amount', 'metadata'];

        foreach ($requiredFields as $field) {
            if (!isset($data[$field])) {
                throw UddoktaPayException::make("Required field missing: {$field}");
            }

            if (is_string($data[$field]) && trim($data[$field]) === '') {
                throw UddoktaPayException::make("Required field cannot be empty: {$field}");
            }
        }

        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            throw UddoktaPayException::make('Invalid email address format');
        }

        if (!is_numeric($data['amount'])) {
            throw UddoktaPayException::make('Amount must be a number');
        }

        if ($data['amount'] <= 0) {
            throw UddoktaPayException::make('Amount must be greater than zero');
        }

        if (!is_array($data['metadata'])) {
            throw UddoktaPayException::make('Metadata must be an array');
        }

        $this->validateOptionalUrls($data);
    }

    private function validateOptionalUrls(array $data): void
    {
        $urlFields = ['redirect_url', 'cancel_url', 'webhook_url'];

        foreach ($urlFields as $field) {
            if (isset($data[$field]) && !filter_var($data[$field], FILTER_VALIDATE_URL)) {
                $fieldLabel = ucwords(str_replace('_', ' ', $field));
                throw UddoktaPayException::make("Invalid {$fieldLabel} format");
            }
        }
    }

    private function extractErrorMessage(RequestException $e): string
    {
        $response = $e->getResponse();

        if ($response === null) {
            return 'Connection failed: ' . $e->getMessage();
        }

        $body = (string) $response->getBody();
        $data = json_decode($body, true);

        if (json_last_error() === JSON_ERROR_NONE && isset($data['message'])) {
            return $data['message'];
        }

        return 'Request failed with status ' . $response->getStatusCode();
    }
}
