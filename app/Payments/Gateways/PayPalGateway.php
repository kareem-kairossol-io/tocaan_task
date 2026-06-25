<?php

namespace App\Payments\Gateways;

use App\Contracts\PaymentGateway;
use App\Models\Order;
use App\Payments\PaymentResult;
use RuntimeException;

class PayPalGateway implements PaymentGateway
{
    /**
     * @param array{
     *     client_id?: string|null,
     *     client_secret?: string|null,
     *     sandbox?: bool
     * } $config
     */
    public function __construct(
        private readonly array $config,
    ) {
    }

    public function charge(Order $order): PaymentResult
    {
        $this->ensureConfigured();

        return new PaymentResult(
            successful: false,
            transactionReference: null,
            gatewayResponse: [
                'gateway' => 'paypal',
                'status' => 'declined',
                'amount' => (float) $order->total,
                'sandbox' => $this->config['sandbox'] ?? true,
                'error' => 'Simulated PayPal payment failure.',
                'simulated' => true,
            ],
        );
    }

    private function ensureConfigured(): void
    {
        if (
            empty($this->config['client_id'])
            || empty($this->config['client_secret'])
        ) {
            throw new RuntimeException(
                'PayPal gateway credentials are not configured.'
            );
        }
    }
}
