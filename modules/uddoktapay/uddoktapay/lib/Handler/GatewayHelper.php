<?php

declare(strict_types=1);

namespace WHMCS\Module\Gateway\UddoktaPay\Handler;

use WHMCS\Module\Gateway\UddoktaPay\Enums\ErrorCode;
use WHMCS\Module\Gateway\UddoktaPay\Enums\GatewayType;

final class GatewayHelper
{
    public static function renderAlert(string $message, string $type): string
    {
        return sprintf(
            '<div class="alert alert-%s" style="margin-top: 10px;" role="alert">%s</div>',
            htmlspecialchars($type, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
        );
    }

    public static function renderPaymentForm(string $actionUrl, int $invoiceId, string $buttonText): string
    {
        $escapedUrl = htmlspecialchars($actionUrl, ENT_QUOTES, 'UTF-8');
        $escapedButtonText = htmlspecialchars($buttonText, ENT_QUOTES, 'UTF-8');

        return <<<HTML
        <form method="GET" action="{$escapedUrl}">
            <input type="hidden" name="action" value="init" />
            <input type="hidden" name="id" value="{$invoiceId}" />
            <input class="btn btn-primary" type="submit" value="{$escapedButtonText}" />
        </form>
        HTML;
    }

    public static function getBaseConfig(GatewayType $type): array
    {
        return [
            'FriendlyName' => [
                'Type' => 'System',
                'Value' => $type->displayName(),
            ],
            'api_key' => [
                'FriendlyName' => 'API KEY',
                'Type' => 'text',
                'Size' => '40',
            ],
            'api_url' => [
                'FriendlyName' => 'API URL',
                'Type' => 'text',
                'Size' => '50',
            ],
        ];
    }

    public static function handleLink(array $params, GatewayType $type): string
    {
        $invoiceId = (int) $params['invoiceid'];

        $errorCode = $_REQUEST['error'] ?? '';
        $paymentForm = self::renderPaymentForm(
            $params['systemurl'] . '/modules/gateways/callback/' . $type->moduleName() . '.php',
            $invoiceId,
            $params['langpaynow']
        );

        if ($errorCode === '') {
            return $paymentForm;
        }

        $isPending = $errorCode === ErrorCode::PENDING_VERIFICATION->value;
        $alert = self::renderAlert(ErrorCode::getMessage($errorCode), $isPending ? 'warning' : 'danger');

        return $isPending ? $alert : $alert . $paymentForm;
    }
}
