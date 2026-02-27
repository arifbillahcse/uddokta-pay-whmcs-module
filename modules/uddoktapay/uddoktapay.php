<?php

declare(strict_types=1);

if (!defined('WHMCS')) {
    exit('This file cannot be accessed directly');
}

use WHMCS\Module\Gateway\UddoktaPay\Enums\GatewayType;
use WHMCS\Module\Gateway\UddoktaPay\Handler\GatewayHelper;

function uddoktapay_config(): array
{
    return GatewayHelper::getBaseConfig(GatewayType::DEFAULT);
}

function uddoktapay_link(array $params): string
{
    return GatewayHelper::handleLink($params, GatewayType::DEFAULT);
}
