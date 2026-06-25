<?php

namespace App\Payments\Gateways;

use App\Contracts\PaymentGateway;
use App\Models\Order;
use App\Payments\PaymentResult;
use Illuminate\Support\Str;

class CreditCardGateway implements PaymentGateway
{
    public function charge(Order $order): PaymentResult
    {
        return new PaymentResult(
            successful: true,
            transactionReference: 'CC-'.Str::uuid(),
            gatewayResponse: [
                'gateway' => 'credit_card',
                'status' => 'approved',
                'amount' => (float) $order->total,
                'simulated' => true,
            ],
        );
    }
}
