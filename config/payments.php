<?php

use App\Payments\Gateways\CreditCardGateway;
use App\Payments\Gateways\PayPalGateway;

return [
    'gateways' => [
        'credit_card' => [
            'driver' => CreditCardGateway::class,
            'api_key' => env('CREDIT_CARD_API_KEY'),
            'secret' => env('CREDIT_CARD_SECRET'),
            'sandbox' => env('CREDIT_CARD_SANDBOX', true),
        ],
        'paypal' => [
            'driver' => PayPalGateway::class,
            'client_id' => env('PAYPAL_CLIENT_ID'),
            'client_secret' => env('PAYPAL_CLIENT_SECRET'),
            'sandbox' => env('PAYPAL_SANDBOX', true),
        ],
    ],
];
