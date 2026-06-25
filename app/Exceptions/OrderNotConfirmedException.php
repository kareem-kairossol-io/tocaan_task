<?php

namespace App\Exceptions;

class OrderNotConfirmedException extends BusinessRuleException
{
    public function __construct()
    {
        parent::__construct(
            'Payments can only be processed for confirmed orders.'
        );
    }
}
