<?php

namespace Tests\Feature\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\OrderHasPaymentsException;
use App\Exceptions\PaidOrderCannotBeModifiedException;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OrderServiceTest extends TestCase
{
    use RefreshDatabase;

    private OrderService $orderService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orderService = app(OrderService::class);
    }

    public function test_it_creates_an_order_with_items_and_calculates_total(): void
    {
        $user = User::factory()->create();

        $order = $this->orderService->create($user, [
            'customer_name' => 'Ahmed Ali',
            'customer_email' => 'ahmed@example.com',
            'customer_phone' => '01012345678',

            // يجب تجاهلها وعدم الاعتماد عليها.
            'total' => 1,

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
        ]);

        $this->assertSame($user->id, $order->user_id);
        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertEquals(1250.50, (float) $order->total);
        $this->assertCount(2, $order->items);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'user_id' => $user->id,
            'customer_name' => 'Ahmed Ali',
            'status' => OrderStatus::Pending->value,
        ]);

        $this->assertDatabaseCount('order_items', 2);
    }

    public function test_paginate_returns_only_the_authenticated_user_orders(): void
    {
        $user = User::factory()->create();
        $anotherUser = User::factory()->create();

        $this->createOrder($user);
        $this->createOrder($user);
        $this->createOrder($anotherUser);

        $orders = $this->orderService->paginate($user, []);

        $this->assertSame(2, $orders->total());
        $this->assertCount(2, $orders->items());

        foreach ($orders->items() as $order) {
            $this->assertSame($user->id, $order->user_id);
        }
    }

    public function test_orders_can_be_filtered_by_status(): void
    {
        $user = User::factory()->create();

        $pendingOrder = $this->createOrder(
            $user,
            OrderStatus::Pending
        );

        $confirmedOrder = $this->createOrder(
            $user,
            OrderStatus::Confirmed
        );

        $orders = $this->orderService->paginate($user, [
            'status' => OrderStatus::Confirmed->value,
        ]);

        $this->assertSame(1, $orders->total());
        $this->assertSame(
            $confirmedOrder->id,
            $orders->items()[0]->id
        );

        $this->assertNotSame(
            $pendingOrder->id,
            $orders->items()[0]->id
        );
    }

    public function test_orders_are_paginated_using_requested_page_size(): void
    {
        $user = User::factory()->create();

        $this->createOrder($user);
        $this->createOrder($user);
        $this->createOrder($user);

        $orders = $this->orderService->paginate($user, [
            'per_page' => 2,
        ]);

        $this->assertSame(3, $orders->total());
        $this->assertSame(2, $orders->perPage());
        $this->assertCount(2, $orders->items());
        $this->assertSame(2, $orders->lastPage());
    }

    public function test_user_can_find_their_order(): void
    {
        $user = User::factory()->create();

        $order = $this->createOrder($user);

        $foundOrder = $this->orderService->find(
            $user,
            $order->id
        );

        $this->assertSame($order->id, $foundOrder->id);
        $this->assertTrue($foundOrder->relationLoaded('items'));
    }

    public function test_user_cannot_find_another_users_order(): void
    {
        $user = User::factory()->create();
        $anotherUser = User::factory()->create();

        $order = $this->createOrder($anotherUser);

        $this->expectException(ModelNotFoundException::class);

        $this->orderService->find($user, $order->id);
    }

    public function test_pending_order_customer_data_can_be_updated(): void
    {
        $user = User::factory()->create();

        $order = $this->createOrder($user);

        $updatedOrder = $this->orderService->update($order, [
            'customer_name' => 'Updated Name',
        ]);

        $this->assertSame(
            'Updated Name',
            $updatedOrder->customer_name
        );

        $this->assertSame(
            $order->customer_email,
            $updatedOrder->customer_email
        );
    }

    public function test_nullable_customer_phone_can_be_cleared(): void
    {
        $user = User::factory()->create();

        $order = $this->createOrder($user);

        $this->assertNotNull($order->customer_phone);

        $updatedOrder = $this->orderService->update($order, [
            'customer_phone' => null,
        ]);

        $this->assertNull($updatedOrder->customer_phone);
    }

    public function test_pending_order_items_can_be_replaced_and_total_recalculated(): void
    {
        $user = User::factory()->create();

        $order = $this->createOrder($user);

        $updatedOrder = $this->orderService->update($order, [
            'items' => [
                [
                    'product_name' => 'Monitor',
                    'quantity' => 2,
                    'price' => 1500.25,
                ],
            ],
        ]);

        $this->assertCount(1, $updatedOrder->items);
        $this->assertSame(
            'Monitor',
            $updatedOrder->items->first()->product_name
        );

        $this->assertEquals(
            3000.50,
            (float) $updatedOrder->total
        );

        $this->assertDatabaseCount('order_items', 1);
    }

    public function test_pending_order_can_be_confirmed(): void
    {
        $user = User::factory()->create();

        $order = $this->createOrder(
            $user,
            OrderStatus::Pending
        );

        $updatedOrder = $this->orderService->update($order, [
            'status' => OrderStatus::Confirmed->value,
        ]);

        $this->assertSame(
            OrderStatus::Confirmed,
            $updatedOrder->status
        );
    }

    public function test_pending_order_can_be_cancelled(): void
    {
        $user = User::factory()->create();

        $order = $this->createOrder(
            $user,
            OrderStatus::Pending
        );

        $updatedOrder = $this->orderService->update($order, [
            'status' => OrderStatus::Cancelled->value,
        ]);

        $this->assertSame(
            OrderStatus::Cancelled,
            $updatedOrder->status
        );
    }

    public function test_order_status_cannot_move_after_confirmation(): void
    {
        $user = User::factory()->create();

        $order = $this->createOrder(
            $user,
            OrderStatus::Confirmed
        );

        try {
            $this->orderService->update($order, [
                'status' => OrderStatus::Cancelled->value,
            ]);

            $this->fail(
                'Expected ValidationException was not thrown.'
            );
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'status',
                $exception->errors()
            );
        }

        $this->assertSame(
            OrderStatus::Confirmed,
            $order->fresh()->status
        );
    }

    public function test_cancelled_order_cannot_return_to_pending(): void
    {
        $user = User::factory()->create();

        $order = $this->createOrder(
            $user,
            OrderStatus::Cancelled
        );

        try {
            $this->orderService->update($order, [
                'status' => OrderStatus::Pending->value,
            ]);

            $this->fail(
                'Expected ValidationException was not thrown.'
            );
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'status',
                $exception->errors()
            );
        }
    }

    public function test_confirmed_order_items_cannot_be_modified(): void
    {
        $user = User::factory()->create();

        $order = $this->createOrder(
            $user,
            OrderStatus::Confirmed
        );

        try {
            $this->orderService->update($order, [
                'items' => [
                    [
                        'product_name' => 'Updated Product',
                        'quantity' => 1,
                        'price' => 200,
                    ],
                ],
            ]);

            $this->fail(
                'Expected ValidationException was not thrown.'
            );
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'items',
                $exception->errors()
            );
        }
    }

    public function test_cancelled_order_items_cannot_be_modified(): void
    {
        $user = User::factory()->create();

        $order = $this->createOrder(
            $user,
            OrderStatus::Cancelled
        );

        try {
            $this->orderService->update($order, [
                'items' => [
                    [
                        'product_name' => 'Updated Product',
                        'quantity' => 1,
                        'price' => 200,
                    ],
                ],
            ]);

            $this->fail(
                'Expected ValidationException was not thrown.'
            );
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'items',
                $exception->errors()
            );
        }
    }

    public function test_successfully_paid_order_cannot_be_modified(): void
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

        $this->expectException(
            PaidOrderCannotBeModifiedException::class
        );

        $this->orderService->update($order, [
            'customer_name' => 'New Name',
        ]);
    }

    public function test_failed_payment_does_not_prevent_pending_order_update(): void
    {
        $user = User::factory()->create();

        $order = $this->createOrder(
            $user,
            OrderStatus::Pending
        );

        $this->createPayment(
            $order,
            PaymentStatus::Failed
        );

        $updatedOrder = $this->orderService->update($order, [
            'customer_name' => 'Updated Name',
        ]);

        $this->assertSame(
            'Updated Name',
            $updatedOrder->customer_name
        );
    }

    public function test_order_without_payments_can_be_deleted(): void
    {
        $user = User::factory()->create();

        $order = $this->createOrder($user);

        $this->orderService->delete($order);

        $this->assertModelMissing($order);
    }

    public function test_order_with_any_payment_cannot_be_deleted(): void
    {
        $user = User::factory()->create();

        $order = $this->createOrder($user);

        // حتى المحاولة الفاشلة تعتبر associated payment.
        $this->createPayment(
            $order,
            PaymentStatus::Failed
        );

        $this->expectException(
            OrderHasPaymentsException::class
        );

        $this->orderService->delete($order);
    }

    private function createOrder(
        User $user,
        OrderStatus $status = OrderStatus::Pending,
        array $items = []
    ): Order {
        if ($items === []) {
            $items = [
                [
                    'product_name' => 'Default Product',
                    'quantity' => 2,
                    'price' => 100,
                ],
            ];
        }

        $total = collect($items)->sum(
            fn (array $item): float =>
                (int) $item['quantity']
                * (float) $item['price']
        );

        $order = $user->orders()->create([
            'customer_name' => 'Test Customer',
            'customer_email' => fake()->unique()->safeEmail(),
            'customer_phone' => '01012345678',
            'status' => $status,
            'total' => round($total, 2),
        ]);

        $order->items()->createMany($items);

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
}
