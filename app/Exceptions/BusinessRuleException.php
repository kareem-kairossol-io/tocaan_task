<?php

namespace App\Exceptions;

use Exception;

abstract class BusinessRuleException extends Exception
{
    public function status(): int
    {
        return 422;
    }
}
