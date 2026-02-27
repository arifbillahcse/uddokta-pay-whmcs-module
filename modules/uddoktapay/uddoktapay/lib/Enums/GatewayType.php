<?php

declare(strict_types=1);

namespace WHMCS\Module\Gateway\UddoktaPay\Enums;

enum GatewayType: string
{
    case DEFAULT = 'checkout-v2';
    case MFS = 'checkout-v2/mfs';
    case BANK = 'checkout-v2/bank';
    case GLOBAL = 'checkout-v2/global';

    public function displayName(): string
    {
        return match ($this) {
            self::DEFAULT => 'UddoktaPay',
            self::MFS => 'UddoktaPay MFS',
            self::BANK => 'UddoktaPay Bank',
            self::GLOBAL => 'UddoktaPay Global',
        };
    }

    public function moduleName(): string
    {
        return match ($this) {
            self::DEFAULT => 'uddoktapay',
            self::MFS => 'uddoktapaymfs',
            self::BANK => 'uddoktapaybank',
            self::GLOBAL => 'uddoktapayglobal',
        };
    }
}
