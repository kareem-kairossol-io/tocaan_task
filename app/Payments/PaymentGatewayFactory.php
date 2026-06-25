<?php

namespace App\Payments;

use App\Contracts\PaymentGateway;
use App\Exceptions\UnsupportedPaymentMethodException;
use LogicException;

class PaymentGatewayFactory
{
    public function make(string $method): PaymentGateway
    {
        /** @var array<string, mixed>|null $gatewayConfig */
        $gatewayConfig = config("payments.gateways.{$method}");

        if ($gatewayConfig === null) {
            throw new UnsupportedPaymentMethodException($method);
        }

        $gatewayClass = $gatewayConfig['driver'] ?? null;

        if (
            ! is_string($gatewayClass)
            || ! is_subclass_of($gatewayClass, PaymentGateway::class)
        ) {
            throw new LogicException(
                "Invalid payment gateway driver configured for [{$method}]."
            );
        }

        unset($gatewayConfig['driver']);

        /** @var PaymentGateway $gateway */
        $gateway = app()->makeWith(
            $gatewayClass,
            ['config' => $gatewayConfig]
        );

        return $gateway;
    }

    /**
     * @return array<int, string>
     */
    public function availableMethods(): array
    {
        return array_keys(config('payments.gateways', []));
    }
}
