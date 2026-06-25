<?php

namespace App\Exceptions;

class OrderAlreadyPaidException extends BusinessRuleException
{
    public function __construct()
    {
        parent::__construct(
            'This order has already been paid.'
        );
    }

    public function status(): int
    {
        return 409;
    }
}
