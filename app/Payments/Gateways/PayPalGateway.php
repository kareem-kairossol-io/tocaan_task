<?php

namespace App\Payments\Gateways;

use App\Contracts\PaymentGateway;
use App\Models\Order;
use App\Payments\PaymentResult;

class PayPalGateway implements PaymentGateway
{
    public function charge(Order $order): PaymentResult
    {
        return new PaymentResult(
            successful: false,
            transactionReference: null,
            gatewayResponse: [
                'gateway' => 'paypal',
                'status' => 'declined',
                'amount' => (float) $order->total,
                'error' => 'Simulated PayPal payment failure.',
                'simulated' => true,
            ],
        );
    }
}
