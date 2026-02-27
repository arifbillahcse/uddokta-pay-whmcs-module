<?php

declare(strict_types=1);

if (!defined('WHMCS')) {
    exit('This file cannot be accessed directly');
}

use WHMCS\Module\Gateway\UddoktaPay\Enums\GatewayType;
use WHMCS\Module\Gateway\UddoktaPay\Handler\GatewayHelper;

function uddoktapaymfs_config(): array
{
    return GatewayHelper::getBaseConfig(GatewayType::MFS);
}

function uddoktapaymfs_link(array $params): string
{
    return GatewayHelper::handleLink($params, GatewayType::MFS);
}
