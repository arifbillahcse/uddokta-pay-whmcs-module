<?php

declare(strict_types=1);

namespace WHMCS\Module\Gateway\UddoktaPay\Enums;

enum PaymentAction: string
{
    case INIT = 'init';
    case VERIFY = 'verify';
    case IPN = 'ipn';
}
