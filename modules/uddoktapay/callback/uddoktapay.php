<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

use WHMCS\Module\Gateway\UddoktaPay\Enums\GatewayType;
use WHMCS\Module\Gateway\UddoktaPay\Handler\BasePaymentHandler;

final class UddoktaPayHandler extends BasePaymentHandler
{
    protected function getGatewayType(): GatewayType
    {
        return GatewayType::DEFAULT;
    }

    public static function init(): self
    {
        return new self();
    }
}

$handler = UddoktaPayHandler::init();
$handler->run();
