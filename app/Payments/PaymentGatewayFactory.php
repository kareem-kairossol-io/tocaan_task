<?php

namespace App\Payments;

use App\Contracts\PaymentGateway;
use App\Exceptions\UnsupportedPaymentMethodException;
use App\Payments\Gateways\CreditCardGateway;
use App\Payments\Gateways\PayPalGateway;

class PaymentGatewayFactory
{
    /**
     * @var array<string, class-string<PaymentGateway>>
     */
    private const GATEWAYS = [
        'credit_card' => CreditCardGateway::class,
        'paypal' => PayPalGateway::class,
    ];

    public function make(string $method): PaymentGateway
    {
        $gatewayClass = self::GATEWAYS[$method]
            ?? throw new UnsupportedPaymentMethodException($method);

        /** @var PaymentGateway $gateway */
        $gateway = app($gatewayClass);

        return $gateway;
    }

    /**
     * @return array<int, string>
     */
    public function availableMethods(): array
    {
        return array_keys(self::GATEWAYS);
    }
}
