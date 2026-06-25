<?php

namespace App\Payments\Gateways;

use App\Contracts\PaymentGateway;
use App\Models\Order;
use App\Payments\PaymentResult;
use Illuminate\Support\Str;
use RuntimeException;

class CreditCardGateway implements PaymentGateway
{
    /**
     * @param array{
     *     api_key?: string|null,
     *     secret?: string|null,
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
            successful: true,
            transactionReference: 'CC-'.Str::uuid(),
            gatewayResponse: [
                'gateway' => 'credit_card',
                'status' => 'approved',
                'amount' => (float) $order->total,
                'sandbox' => $this->config['sandbox'] ?? true,
                'simulated' => true,
            ],
        );
    }

    private function ensureConfigured(): void
    {
        if (
            empty($this->config['api_key'])
            || empty($this->config['secret'])
        ) {
            throw new RuntimeException(
                'Credit card gateway credentials are not configured.'
            );
        }
    }
}
