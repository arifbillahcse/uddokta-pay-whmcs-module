<?php

declare(strict_types=1);

namespace WHMCS\Module\Gateway\UddoktaPay\Enums;

enum ErrorCode: string
{
    case CANCELLED = 'cancelled';
    case INVALID_RESPONSE = 'irs';
    case TRANSACTION_USED = 'tau';
    case LESS_AMOUNT = 'lpa';
    case PENDING_VERIFICATION = 'pfv';
    case SOMETHING_WRONG = 'sww';

    public function message(): string
    {
        return match ($this) {
            self::CANCELLED => 'Payment has been cancelled.',
            self::INVALID_RESPONSE => 'Invalid response from UddoktaPay API.',
            self::TRANSACTION_USED => 'This transaction has already been processed.',
            self::LESS_AMOUNT => 'The paid amount is less than the required amount.',
            self::PENDING_VERIFICATION => 'Your payment is pending verification.',
            self::SOMETHING_WRONG => 'Something went wrong. Please try again.',
        };
    }

    public static function getMessage(string $code): string
    {
        $errorCode = self::tryFrom($code);

        return $errorCode?->message() ?? $code;
    }
}
