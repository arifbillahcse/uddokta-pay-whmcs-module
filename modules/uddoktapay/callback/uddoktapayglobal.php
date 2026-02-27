<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

use WHMCS\Module\Gateway\UddoktaPay\Enums\GatewayType;
use WHMCS\Module\Gateway\UddoktaPay\Handler\BasePaymentHandler;

final class UddoktaPayGlobalHandler extends BasePaymentHandler
{
    protected function getGatewayType(): GatewayType
    {
        return GatewayType::GLOBAL;
    }

    public static function init(): self
    {
        return new self();
    }
}

$handler = UddoktaPayGlobalHandler::init();
$handler->run();
