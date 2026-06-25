<?php

namespace App\Exceptions;

class UnsupportedPaymentMethodException extends BusinessRuleException
{
    public function __construct(string $method)
    {
        parent::__construct(
            "The payment method [{$method}] is not supported."
        );
    }
}
