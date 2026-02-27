<?php

declare(strict_types=1);

namespace WHMCS\Module\Gateway\UddoktaPay\Handler;

use Carbon\Carbon;
use Symfony\Component\HttpFoundation\Request;
use WHMCS\Config\Setting;
use WHMCS\Database\Capsule;
use WHMCS\Module\Gateway\UddoktaPay\Enums\ErrorCode;
use WHMCS\Module\Gateway\UddoktaPay\Enums\GatewayType;
use WHMCS\Module\Gateway\UddoktaPay\Enums\PaymentAction;
use WHMCS\Module\Gateway\UddoktaPay\Enums\PaymentStatus;
use WHMCS\Module\Gateway\UddoktaPay\Exception\UddoktaPayException;
use WHMCS\Module\Gateway\UddoktaPay\Http\UddoktaPayAPI;

abstract class BasePaymentHandler
{
    protected readonly string $gatewayModuleName;
    protected readonly array $gatewayParams;
    protected readonly array $invoice;
    protected readonly object $clientDetails;
    protected readonly array $customerCurrency;
    protected readonly float $due;
    protected readonly float $fee;
    protected readonly float $total;
    protected readonly UddoktaPayAPI $api;

    public readonly bool $isActive;
    public readonly Request $request;

    abstract protected function getGatewayType(): GatewayType;

    protected function __construct()
    {
        $this->request = Request::createFromGlobals();
        $this->gatewayModuleName = $this->getGatewayType()->moduleName();
        $this->gatewayParams = getGatewayVariables($this->gatewayModuleName);
        $this->isActive = !empty($this->gatewayParams['type']);

        $this->api = UddoktaPayAPI::make(
            $this->gatewayParams['api_key'],
            $this->gatewayParams['api_url']
        );

        $this->invoice = $this->fetchInvoice();
        $this->customerCurrency = $this->fetchCurrency();
        $this->clientDetails = $this->fetchClient();
        $this->due = (float) $this->invoice['balance'];
        $this->fee = 0.0;
        $this->total = $this->due + $this->fee;
    }

    public function run(): never
    {
        if (!$this->isActive) {
            exit('The gateway is unavailable.');
        }

        $action = PaymentAction::tryFrom($this->request->get('action') ?? '');
        $invoiceId = (int) $this->request->get('id');

        match ($action) {
            PaymentAction::INIT => $this->handleInit($invoiceId),
            PaymentAction::VERIFY => $this->handleVerify($invoiceId),
            PaymentAction::IPN => $this->handleIpn(),
            default => $this->redirectWithError($invoiceId, ErrorCode::SOMETHING_WRONG->value),
        };
    }

    protected function handleInit(int $invoiceId): never
    {
        $response = $this->createPayment();

        if ($response['status'] === 'success') {
            header('Location: ' . $response['payment_url']);
            exit;
        }

        $this->redirectWithError($invoiceId, (string) $response['errorCode']);
    }

    protected function handleVerify(int $invoiceId): never
    {
        $paymentInvoiceId = $this->request->get('invoice_id') ?? '';
        $response = $this->processPayment($paymentInvoiceId);

        if ($response['status'] === 'success') {
            redirSystemURL("id={$invoiceId}", 'viewinvoice.php');
            exit;
        }

        $this->redirectWithError($invoiceId, (string) $response['errorCode']);
    }

    protected function handleIpn(): never
    {
        $invoiceId = $this->request->get('invoice_id') ?? '';
        $this->processPayment($invoiceId, isIpn: true);
        exit;
    }

    protected function redirectWithError(int $invoiceId, string $error): never
    {
        redirSystemURL("id={$invoiceId}&error={$error}", 'viewinvoice.php');
        exit;
    }

    public function createPayment(): array
    {
        $systemUrl = Setting::getValue('SystemURL');
        $invoiceId = $this->invoice['invoiceid'];
        $baseCallbackUrl = "{$systemUrl}/modules/gateways/callback/{$this->gatewayModuleName}.php";

        $fields = [
            'full_name' => trim($this->clientDetails->firstname . ' ' . $this->clientDetails->lastname),
            'email' => $this->clientDetails->email,
            'phone' => $this->formatPhoneNumber($this->clientDetails->phonenumber),
            'amount' => $this->total,
            'currency' => $this->customerCurrency['code'],
            'metadata' => ['invoice_id' => $invoiceId],
            'redirect_url' => "{$baseCallbackUrl}?id={$invoiceId}&action=verify",
            'return_type' => 'GET',
            'cancel_url' => "{$systemUrl}/viewinvoice.php?error=cancelled&id={$invoiceId}",
            'webhook_url' => "{$baseCallbackUrl}?id={$invoiceId}&action=ipn",
        ];

        try {
            return [
                'status' => 'success',
                'payment_url' => $this->api->initPayment($fields, $this->getGatewayType()->value),
            ];
        } catch (UddoktaPayException $e) {
            return $this->errorResponse(ErrorCode::INVALID_RESPONSE, $e->getMessage());
        } catch (\Exception) {
            return $this->errorResponse(ErrorCode::SOMETHING_WRONG);
        }
    }

    public function processPayment(string $invoiceId, bool $isIpn = false): array
    {
        try {
            $payment = $isIpn
                ? $this->api->executePayment()
                : $this->api->verifyPayment($invoiceId);

            return $this->handlePaymentResult($payment);
        } catch (UddoktaPayException $e) {
            return $this->errorResponse(ErrorCode::INVALID_RESPONSE, $e->getMessage());
        } catch (\Exception) {
            return $this->errorResponse(ErrorCode::SOMETHING_WRONG);
        }
    }

    private function handlePaymentResult(array $payment): array
    {
        $status = PaymentStatus::tryFrom($payment['status'] ?? '');

        return match ($status) {
            PaymentStatus::COMPLETED => $this->handleCompletedPayment($payment),
            PaymentStatus::PENDING => $this->handlePendingPayment(),
            default => $this->errorResponse(ErrorCode::INVALID_RESPONSE),
        };
    }

    private function handleCompletedPayment(array $payment): array
    {
        $transactionId = $payment['transaction_id'];

        if ($this->transactionExists($transactionId)) {
            return [
                'status' => 'success',
                'message' => ErrorCode::TRANSACTION_USED->message(),
                'errorCode' => ErrorCode::TRANSACTION_USED->value,
            ];
        }

        if ($payment['amount'] < $this->total) {
            return $this->errorResponse(ErrorCode::LESS_AMOUNT);
        }

        $this->logTransaction($payment);
        $result = $this->addTransaction($transactionId);

        if ($result['result'] !== 'success') {
            return $this->errorResponse(ErrorCode::SOMETHING_WRONG);
        }

        return [
            'status' => 'success',
            'message' => 'The payment has been successfully verified.',
        ];
    }

    private function handlePendingPayment(): array
    {
        return $this->errorResponse(ErrorCode::PENDING_VERIFICATION);
    }

    protected function errorResponse(ErrorCode $code, ?string $customMessage = null): array
    {
        return [
            'status' => 'error',
            'message' => $customMessage ?? $code->message(),
            'errorCode' => $customMessage ?? $code->value,
        ];
    }

    private function formatPhoneNumber(string $phoneNumber): string
    {
        $phoneNumber = trim($phoneNumber);

        // Handle WHMCS default format: +CountryCode.SubscriberNumber
        if (strpos($phoneNumber, '.') !== false && strpos($phoneNumber, '+') === 0) {
            [$country, $subscriber] = explode('.', $phoneNumber, 2);
            $lastDigit = substr($country, -1);
            $subscriber = preg_replace('/\D/', '', $subscriber);

            return $lastDigit . $subscriber;
        }

        return preg_replace('/\D/', '', $phoneNumber);
    }

    private function fetchInvoice(): array
    {
        return localAPI('GetInvoice', ['invoiceid' => $this->request->get('id')]);
    }

    private function fetchCurrency(): array
    {
        $currencyId = Capsule::table('tblclients')
            ->where('id', '=', $this->invoice['userid'])
            ->value('currency');

        return (array) Capsule::table('tblcurrencies')
            ->where('id', '=', $currencyId)
            ->first();
    }

    private function fetchClient(): object
    {
        return Capsule::table('tblclients')
            ->where('id', '=', $this->invoice['userid'])
            ->first();
    }

    private function transactionExists(string $transactionId): bool
    {
        $result = localAPI('GetTransactions', ['transid' => $transactionId]);

        return ($result['totalresults'] ?? 0) > 0;
    }

    private function logTransaction(array $payload): void
    {
        logTransaction(
            $this->gatewayParams['name'],
            [
                $this->gatewayModuleName => $payload,
                'request_data' => $this->request->request->all(),
            ],
            $payload['status']
        );
    }

    private function addTransaction(string $transactionId): array
    {
        $fields = [
            'invoiceid' => $this->invoice['invoiceid'],
            'transid' => $transactionId,
            'gateway' => $this->gatewayModuleName,
            'date' => Carbon::now()->toDateTimeString(),
            'amount' => $this->due,
            'fees' => $this->fee,
        ];

        return array_merge(localAPI('AddInvoicePayment', $fields), $fields);
    }
}
