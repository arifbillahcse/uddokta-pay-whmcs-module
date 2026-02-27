<?php

declare(strict_types=1);

namespace WHMCS\Module\Gateway\UddoktaPay\Exception;

use Exception;
use Throwable;

class UddoktaPayException extends Exception
{
    public static function make(string $message, int $code = 0, ?Throwable $previous = null): self
    {
        return new self($message, $code, $previous);
    }
}
