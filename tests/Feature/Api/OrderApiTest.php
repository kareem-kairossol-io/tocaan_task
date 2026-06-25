<?php

namespace Tests\Feature\Api;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use PHPOpenSourceSaver\JWTAuth\JWTGuard;
use Tests\TestCase;

class OrderApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_create_an_order(): void
    {
        $response = $this->postJson(
            '/api/orders',
            $this->validPayload()
        );

        $response->assertUnauthorized();
    }

    public function test_authenticated_user_can_create_an_order(): void
    {
        $user = User::factory()->create();

        $payload = $this->validPayload();

        // محاولة التحكم في الإجمالي.
        $payload['total'] = 1;

        $response = $this
            ->withHeaders($this->authHeaders($user))
            ->postJson('/api/orders', $payload);

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath(
                'message',
                'Order created successfully.'
            )
            ->assertJsonPath(
                'data.customer.name',
                'Ahmed Ali'
            )
            ->assertJsonPath(
                'data.status',
                OrderStatus::Pending->value
            )
            ->assertJsonCount(2, 'data.items');

        $order = Order::query()->findOrFail(
            $response->json('data.id')
        );

        $this->assertSame($user->id, $order->user_id);

        // 2 × 500 + 1 × 250.5
        $this->assertEquals(
            1250.50,
            (float) $order->total
        );
    }

    public function test_order_must_contain_at_least_one_item(): void
    {
        $user = User::factory()->create();

        $payload = $this->validPayload();
        $payload['items'] = [];

        $response = $this
            ->withHeaders($this->authHeaders($user))
            ->postJson('/api/orders', $payload);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items']);
    }

    public function test_order_item_fields_are_validated(): void
    {
        $user = User::factory()->create();

        $payload = $this->validPayload();

        $payload['items'] = [
            [
                'product_name' => '',
                'quantity' => 0,
                'price' => 0,
            ],
        ];

        $response = $this
            ->withHeaders($this->authHeaders($user))
            ->postJson('/api/orders', $payload);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'items.0.product_name',
                'items.0.quantity',
                'items.0.price',
            ]);
    }

    public function test_user_can_view_their_order(): void
    {
        $user = User::factory()->create();

        $order = $this->createOrder($user);

        $response = $this
            ->withHeaders($this->authHeaders($user))
            ->getJson("/api/orders/{$order->id}");

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $order->id)
            ->assertJsonCount(1, 'data.items');
    }

    public function test_user_cannot_view_another_users_order(): void
    {
        $user = User::factory()->create();
        $anotherUser = User::factory()->create();

        $order = $this->createOrder($anotherUser);

        $response = $this
            ->withHeaders($this->authHeaders($user))
            ->getJson("/api/orders/{$order->id}");

        $response->assertNotFound();
    }

    public function test_index_returns_only_authenticated_user_orders(): void
    {
        $user = User::factory()->create();
        $anotherUser = User::factory()->create();

        $this->createOrder($user);
        $this->createOrder($user);
        $this->createOrder($anotherUser);

        $response = $this
            ->withHeaders($this->authHeaders($user))
            ->getJson('/api/orders');

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data.orders')
            ->assertJsonPath('data.pagination.total', 2);
    }

    public function test_orders_can_be_filtered_and_paginated(): void
    {
        $user = User::factory()->create();
        $anotherUser = User::factory()->create();

        $this->createOrder(
            $user,
            OrderStatus::Pending
        );

        $this->createOrder(
            $user,
            OrderStatus::Confirmed
        );

        $this->createOrder(
            $user,
            OrderStatus::Confirmed
        );

        $this->createOrder(
            $anotherUser,
            OrderStatus::Confirmed
        );

        $response = $this
            ->withHeaders($this->authHeaders($user))
            ->getJson(
                '/api/orders?status=confirmed&per_page=1'
            );

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data.orders')
            ->assertJsonPath(
                'data.orders.0.status',
                OrderStatus::Confirmed->value
            )
            ->assertJsonPath(
                'data.pagination.total',
                2
            )
            ->assertJsonPath(
                'data.pagination.per_page',
                1
            )
            ->assertJsonPath(
                'data.pagination.last_page',
                2
            );
    }

    public function test_pending_order_can_be_updated(): void
    {
        $user = User::factory()->create();

        $order = $this->createOrder(
            $user,
            OrderStatus::Pending
        );

        $response = $this
            ->withHeaders($this->authHeaders($user))
            ->patchJson("/api/orders/{$order->id}", [
                'customer_name' => 'Updated Customer',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath(
                'data.customer.name',
                'Updated Customer'
            );

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'customer_name' => 'Updated Customer',
        ]);
    }

    public function test_invalid_status_transition_returns_validation_error(): void
    {
        $user = User::factory()->create();

        $order = $this->createOrder(
            $user,
            OrderStatus::Confirmed
        );

        $response = $this
            ->withHeaders($this->authHeaders($user))
            ->patchJson("/api/orders/{$order->id}", [
                'status' => OrderStatus::Cancelled->value,
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    public function test_confirmed_order_items_cannot_be_modified(): void
    {
        $user = User::factory()->create();

        $order = $this->createOrder(
            $user,
            OrderStatus::Confirmed
        );

        $response = $this
            ->withHeaders($this->authHeaders($user))
            ->patchJson("/api/orders/{$order->id}", [
                'items' => [
                    [
                        'product_name' => 'New Product',
                        'quantity' => 1,
                        'price' => 500,
                    ],
                ],
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items']);
    }

    public function test_successfully_paid_order_cannot_be_updated(): void
    {
        $user = User::factory()->create();

        $order = $this->createOrder(
            $user,
            OrderStatus::Confirmed
        );

        $this->createPayment(
            $order,
            PaymentStatus::Successful
        );

        $response = $this
            ->withHeaders($this->authHeaders($user))
            ->patchJson("/api/orders/{$order->id}", [
                'customer_name' => 'Updated Name',
            ]);

        $response
            ->assertStatus(409)
            ->assertJsonPath('success', false);
    }

    public function test_order_without_payments_can_be_deleted(): void
    {
        $user = User::factory()->create();

        $order = $this->createOrder($user);

        $response = $this
            ->withHeaders($this->authHeaders($user))
            ->deleteJson("/api/orders/{$order->id}");

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath(
                'message',
                'Order deleted successfully.'
            );

        $this->assertModelMissing($order);
    }

    public function test_order_with_payments_cannot_be_deleted(): void
    {
        $user = User::factory()->create();

        $order = $this->createOrder($user);

        // أي associated payment يمنع الحذف.
        $this->createPayment(
            $order,
            PaymentStatus::Failed
        );

        $response = $this
            ->withHeaders($this->authHeaders($user))
            ->deleteJson("/api/orders/{$order->id}");

        $response
            ->assertStatus(409)
            ->assertJsonPath('success', false);

        $this->assertModelExists($order);
    }

    private function validPayload(): array
    {
        return [
            'customer_name' => 'Ahmed Ali',
            'customer_email' => 'ahmed@example.com',
            'customer_phone' => '01012345678',
            'items' => [
                [
                    'product_name' => 'Keyboard',
                    'quantity' => 2,
                    'price' => 500,
                ],
                [
                    'product_name' => 'Mouse',
                    'quantity' => 1,
                    'price' => 250.50,
                ],
            ],
        ];
    }

    private function createOrder(
        User $user,
        OrderStatus $status = OrderStatus::Pending
    ): Order {
        $order = $user->orders()->create([
            'customer_name' => 'Test Customer',
            'customer_email' => fake()->unique()->safeEmail(),
            'customer_phone' => '01012345678',
            'status' => $status,
            'total' => 200,
        ]);

        $order->items()->create([
            'product_name' => 'Test Product',
            'quantity' => 2,
            'price' => 100,
        ]);

        return $order->refresh()->load('items');
    }

    private function createPayment(
        Order $order,
        PaymentStatus $status
    ): void {
        $order->payments()->create([
            'method' => 'credit_card',
            'status' => $status,
            'amount' => $order->total,
            'transaction_reference' => null,
            'gateway_response' => null,
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
