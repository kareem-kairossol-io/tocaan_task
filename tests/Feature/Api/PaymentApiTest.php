<?php

namespace Tests\Feature\Api;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use PHPOpenSourceSaver\JWTAuth\JWTGuard;
use Tests\TestCase;

class PaymentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_process_payment(): void
    {
        $user = User::factory()->create();

        $order = $this->createOrder(
            user: $user,
            status: OrderStatus::Confirmed,
        );

        $response = $this->postJson(
            "/api/orders/{$order->id}/payments",
            [
                'method' => 'credit_card',
            ]
        );

        $response->assertUnauthorized();
    }

    public function test_authenticated_user_can_get_available_payment_methods(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->withHeaders($this->authHeaders($user))
            ->getJson('/api/payment-methods');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath(
                'message',
                'Payment methods retrieved successfully.'
            )
            ->assertJsonPath(
                'data.methods',
                [
                    'credit_card',
                    'paypal',
                ]
            );
    }

    public function test_pending_order_cannot_be_paid(): void
    {
        $user = User::factory()->create();

        $order = $this->createOrder(
            user: $user,
            status: OrderStatus::Pending,
        );

        $response = $this
            ->withHeaders($this->authHeaders($user))
            ->postJson(
                "/api/orders/{$order->id}/payments",
                [
                    'method' => 'credit_card',
                ]
            );

        $response
            ->assertUnprocessable()
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_cancelled_order_cannot_be_paid(): void
    {
        $user = User::factory()->create();

        $order = $this->createOrder(
            user: $user,
            status: OrderStatus::Cancelled,
        );

        $response = $this
            ->withHeaders($this->authHeaders($user))
            ->postJson(
                "/api/orders/{$order->id}/payments",
                [
                    'method' => 'credit_card',
                ]
            );

        $response
            ->assertUnprocessable()
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_credit_card_gateway_creates_successful_payment(): void
    {
        $user = User::factory()->create();

        $order = $this->createOrder(
            user: $user,
            status: OrderStatus::Confirmed,
            total: 750.50,
        );

        $response = $this
            ->withHeaders($this->authHeaders($user))
            ->postJson(
                "/api/orders/{$order->id}/payments",
                [
                    'method' => 'credit_card',
                ]
            );

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.order_id', $order->id)
            ->assertJsonPath('data.method', 'credit_card')
            ->assertJsonPath('data.status', 'successful')
            ->assertJsonPath('data.amount', 750.50);

        $payment = Payment::query()->firstOrFail();

        $this->assertSame(
            PaymentStatus::Successful,
            $payment->status
        );

        $this->assertNotNull(
            $payment->transaction_reference
        );

        $this->assertStringStartsWith(
            'CC-',
            $payment->transaction_reference
        );
    }

    public function test_paypal_gateway_creates_failed_payment(): void
    {
        $user = User::factory()->create();

        $order = $this->createOrder(
            user: $user,
            status: OrderStatus::Confirmed,
            total: 400,
        );

        $response = $this
            ->withHeaders($this->authHeaders($user))
            ->postJson(
                "/api/orders/{$order->id}/payments",
                [
                    'method' => 'paypal',
                ]
            );

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.method', 'paypal')
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.amount', 400);

        $payment = Payment::query()->firstOrFail();

        $this->assertSame(
            PaymentStatus::Failed,
            $payment->status
        );

        $this->assertNull(
            $payment->transaction_reference
        );

        $this->assertSame(
            'declined',
            $payment->gateway_response['status']
        );
    }

    public function test_unsupported_method_returns_meaningful_error(): void
    {
        $user = User::factory()->create();

        $order = $this->createOrder(
            user: $user,
            status: OrderStatus::Confirmed,
        );

        $response = $this
            ->withHeaders($this->authHeaders($user))
            ->postJson(
                "/api/orders/{$order->id}/payments",
                [
                    'method' => 'bitcoin',
                ]
            );

        $response
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath(
                'message',
                'The payment method [bitcoin] is not supported.'
            );

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_payment_amount_always_equals_order_total(): void
    {
        $user = User::factory()->create();

        $order = $this->createOrder(
            user: $user,
            status: OrderStatus::Confirmed,
            total: 999.99,
        );

        $response = $this
            ->withHeaders($this->authHeaders($user))
            ->postJson(
                "/api/orders/{$order->id}/payments",
                [
                    'method' => 'credit_card',

                    // المفروض يتم تجاهله.
                    'amount' => 1,
                ]
            );

        $response
            ->assertCreated()
            ->assertJsonPath('data.amount', 999.99);

        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'amount' => 999.99,
        ]);
    }

    public function test_failed_payment_can_be_retried(): void
    {
        $user = User::factory()->create();

        $order = $this->createOrder(
            user: $user,
            status: OrderStatus::Confirmed,
        );

        // PayPal تفشل.
        $this
            ->withHeaders($this->authHeaders($user))
            ->postJson(
                "/api/orders/{$order->id}/payments",
                [
                    'method' => 'paypal',
                ]
            )
            ->assertCreated()
            ->assertJsonPath('data.status', 'failed');

        // إعادة المحاولة بالكريدت كارد تنجح.
        $this
            ->withHeaders($this->authHeaders($user))
            ->postJson(
                "/api/orders/{$order->id}/payments",
                [
                    'method' => 'credit_card',
                ]
            )
            ->assertCreated()
            ->assertJsonPath('data.status', 'successful');

        $this->assertDatabaseCount('payments', 2);

        $this->assertSame(
            1,
            Payment::query()
                ->where(
                    'status',
                    PaymentStatus::Failed->value
                )
                ->count()
        );

        $this->assertSame(
            1,
            Payment::query()
                ->where(
                    'status',
                    PaymentStatus::Successful->value
                )
                ->count()
        );
    }

    public function test_successfully_paid_order_cannot_be_paid_again(): void
    {
        $user = User::factory()->create();

        $order = $this->createOrder(
            user: $user,
            status: OrderStatus::Confirmed,
        );

        $this->createPayment(
            order: $order,
            status: PaymentStatus::Successful,
            method: 'credit_card',
        );

        $response = $this
            ->withHeaders($this->authHeaders($user))
            ->postJson(
                "/api/orders/{$order->id}/payments",
                [
                    'method' => 'paypal',
                ]
            );

        $response
            ->assertStatus(409)
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('payments', 1);
    }

    public function test_user_cannot_pay_another_users_order(): void
    {
        $user = User::factory()->create();
        $anotherUser = User::factory()->create();

        $order = $this->createOrder(
            user: $anotherUser,
            status: OrderStatus::Confirmed,
        );

        $response = $this
            ->withHeaders($this->authHeaders($user))
            ->postJson(
                "/api/orders/{$order->id}/payments",
                [
                    'method' => 'credit_card',
                ]
            );

        $response->assertNotFound();

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_payments_are_paginated_and_only_return_user_payments(): void
    {
        $user = User::factory()->create();
        $anotherUser = User::factory()->create();

        $userOrder = $this->createOrder(
            user: $user,
            status: OrderStatus::Confirmed,
        );

        $anotherOrder = $this->createOrder(
            user: $anotherUser,
            status: OrderStatus::Confirmed,
        );

        $this->createPayment(
            order: $userOrder,
            status: PaymentStatus::Failed,
            method: 'paypal',
        );

        $this->createPayment(
            order: $userOrder,
            status: PaymentStatus::Successful,
            method: 'credit_card',
        );

        $this->createPayment(
            order: $anotherOrder,
            status: PaymentStatus::Successful,
            method: 'credit_card',
        );

        $response = $this
            ->withHeaders($this->authHeaders($user))
            ->getJson('/api/payments?per_page=1');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data.payments')
            ->assertJsonPath('data.pagination.total', 2)
            ->assertJsonPath('data.pagination.per_page', 1)
            ->assertJsonPath('data.pagination.last_page', 2);
    }

    public function test_payments_can_be_filtered_by_status_and_method(): void
    {
        $user = User::factory()->create();

        $order = $this->createOrder(
            user: $user,
            status: OrderStatus::Confirmed,
        );

        $this->createPayment(
            order: $order,
            status: PaymentStatus::Failed,
            method: 'paypal',
        );

        $this->createPayment(
            order: $order,
            status: PaymentStatus::Successful,
            method: 'credit_card',
        );

        $response = $this
            ->withHeaders($this->authHeaders($user))
            ->getJson(
                '/api/payments?status=failed&method=paypal'
            );

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data.payments')
            ->assertJsonPath(
                'data.payments.0.status',
                'failed'
            )
            ->assertJsonPath(
                'data.payments.0.method',
                'paypal'
            );
    }

    public function test_payments_can_be_listed_for_a_specific_order(): void
    {
        $user = User::factory()->create();

        $firstOrder = $this->createOrder(
            user: $user,
            status: OrderStatus::Confirmed,
        );

        $secondOrder = $this->createOrder(
            user: $user,
            status: OrderStatus::Confirmed,
        );

        $this->createPayment(
            order: $firstOrder,
            status: PaymentStatus::Failed,
            method: 'paypal',
        );

        $this->createPayment(
            order: $firstOrder,
            status: PaymentStatus::Successful,
            method: 'credit_card',
        );

        $this->createPayment(
            order: $secondOrder,
            status: PaymentStatus::Failed,
            method: 'paypal',
        );

        $response = $this
            ->withHeaders($this->authHeaders($user))
            ->getJson(
                "/api/orders/{$firstOrder->id}/payments"
            );

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data.payments')
            ->assertJsonPath('data.pagination.total', 2);

        foreach ($response->json('data.payments') as $payment) {
            $this->assertSame(
                $firstOrder->id,
                $payment['order_id']
            );
        }
    }

    public function test_user_cannot_view_another_users_payment_history(): void
    {
        $user = User::factory()->create();
        $anotherUser = User::factory()->create();

        $order = $this->createOrder(
            user: $anotherUser,
            status: OrderStatus::Confirmed,
        );

        $this->createPayment(
            order: $order,
            status: PaymentStatus::Failed,
            method: 'paypal',
        );

        $response = $this
            ->withHeaders($this->authHeaders($user))
            ->getJson(
                "/api/orders/{$order->id}/payments"
            );

        $response->assertNotFound();
    }

    private function createOrder(
        User $user,
        OrderStatus $status,
        float $total = 500
    ): Order {
        $order = $user->orders()->create([
            'customer_name' => 'Test Customer',
            'customer_email' => fake()->unique()->safeEmail(),
            'customer_phone' => '01012345678',
            'status' => $status,
            'total' => $total,
        ]);

        $order->items()->create([
            'product_name' => 'Test Product',
            'quantity' => 1,
            'price' => $total,
        ]);

        return $order->refresh();
    }

    private function createPayment(
        Order $order,
        PaymentStatus $status,
        string $method
    ): Payment {
        return $order->payments()->create([
            'method' => $method,
            'status' => $status,
            'amount' => $order->total,
            'transaction_reference' =>
                $status === PaymentStatus::Successful
                    ? 'TEST-REFERENCE'
                    : null,
            'gateway_response' => [
                'simulated' => true,
            ],
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function authHeaders(User $user): array
    {
        /** @var JWTGuard $guard */
        $guard = Auth::guard('api');

        $token = $guard->login($user);

        return [
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ];
    }
}
