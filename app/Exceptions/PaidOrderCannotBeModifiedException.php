<?php

namespace App\Exceptions;

class PaidOrderCannotBeModifiedException extends BusinessRuleException
{
    public function __construct()
    {
        parent::__construct(
            'A paid order cannot be modified.'
        );
    }

    public function status(): int
    {
        return 409;
    }
}
