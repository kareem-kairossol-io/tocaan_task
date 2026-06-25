<?php

namespace App\Payments;

final readonly class PaymentResult
{
    /**
     * @param array<string, mixed>|null $gatewayResponse
     */
    public function __construct(
        public bool $successful,
        public ?string $transactionReference = null,
        public ?array $gatewayResponse = null,
    ) {
    }
}
