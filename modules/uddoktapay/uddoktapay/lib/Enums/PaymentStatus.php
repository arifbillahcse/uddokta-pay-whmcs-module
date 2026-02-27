<?php

declare(strict_types=1);

namespace WHMCS\Module\Gateway\UddoktaPay\Enums;

enum PaymentStatus: string
{
    case COMPLETED = 'COMPLETED';
    case PENDING = 'PENDING';
}
