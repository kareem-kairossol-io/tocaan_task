<?php

namespace Tests\Unit\Payments;

use App\Contracts\PaymentGateway;
use App\Exceptions\UnsupportedPaymentMethodException;
use App\Models\Order;
use App\Payments\Gateways\CreditCardGateway;
use App\Payments\Gateways\PayPalGateway;
use App\Payments\PaymentGatewayFactory;
use App\Payments\PaymentResult;
use Tests\TestCase;

class PaymentGatewayTest extends TestCase
{
    public function test_credit_card_gateway_returns_successful_payment_result(): void
    {
        $order = $this->makeOrder(total: 250.50);

        $result = (new CreditCardGateway())->charge($order);

        $this->assertInstanceOf(PaymentResult::class, $result);
        $this->assertTrue($result->successful);
        $this->assertNotNull($result->transactionReference);
        $this->assertStringStartsWith(
            'CC-',
            $result->transactionReference
        );

        $this->assertSame(
            'credit_card',
            $result->gatewayResponse['gateway']
        );

        $this->assertSame(
            'approved',
            $result->gatewayResponse['status']
        );

        $this->assertSame(
            250.50,
            $result->gatewayResponse['amount']
        );
    }

    public function test_paypal_gateway_returns_failed_payment_result(): void
    {
        $order = $this->makeOrder(total: 300);

        $result = (new PayPalGateway())->charge($order);

        $this->assertInstanceOf(PaymentResult::class, $result);
        $this->assertFalse($result->successful);
        $this->assertNull($result->transactionReference);

        $this->assertSame(
            'paypal',
            $result->gatewayResponse['gateway']
        );

        $this->assertSame(
            'declined',
            $result->gatewayResponse['status']
        );

        $this->assertSame(
            300.0,
            $result->gatewayResponse['amount']
        );
    }

    public function test_factory_resolves_the_correct_gateway(): void
    {
        $factory = app(PaymentGatewayFactory::class);

        $this->assertInstanceOf(
            CreditCardGateway::class,
            $factory->make('credit_card')
        );

        $this->assertInstanceOf(
            PayPalGateway::class,
            $factory->make('paypal')
        );
    }

    public function test_factory_returns_available_payment_methods(): void
    {
        $factory = app(PaymentGatewayFactory::class);

        $this->assertSame([
            'credit_card',
            'paypal',
        ], $factory->availableMethods());
    }

    public function test_factory_rejects_unsupported_payment_method(): void
    {
        $factory = app(PaymentGatewayFactory::class);

        $this->expectException(
            UnsupportedPaymentMethodException::class
        );

        $factory->make('bitcoin');
    }

    public function test_factory_always_returns_payment_gateway_contract(): void
    {
        $factory = app(PaymentGatewayFactory::class);

        foreach ($factory->availableMethods() as $method) {
            $this->assertInstanceOf(
                PaymentGateway::class,
                $factory->make($method)
            );
        }
    }

    private function makeOrder(float $total): Order
    {
        $order = new Order();

        $order->setAttribute('total', $total);

        return $order;
    }
}
