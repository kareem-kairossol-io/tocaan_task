<?php

namespace App\Contracts;

use App\Payments\PaymentResult;
use App\Models\Order;

interface PaymentGateway
{
    public function charge(Order $order): PaymentResult;
}
