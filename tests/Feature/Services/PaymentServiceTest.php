<?php

namespace Tests\Feature\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\OrderAlreadyPaidException;
use App\Exceptions\OrderNotConfirmedException;
use App\Exceptions\UnsupportedPaymentMethodException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    private PaymentService $paymentService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->paymentService = app(PaymentService::class);
    }

    public function test_it_returns_available_payment_methods(): void
    {
        $methods = $this->paymentService->availableMethods();

        $this->assertSame([
            'credit_card',
            'paypal',
        ], $methods);
    }

    public function test_credit_card_creates_successful_payment(): void
    {
        $user = User::factory()->create();

        $order = $this->createOrder(
            user: $user,
            status: OrderStatus::Confirmed,
            total: 750.50,
        );

        $payment = $this->paymentService->process(
            user: $user,
            orderId: $order->id,
            method: 'credit_card',
        );

        $this->assertSame($order->id, $payment->order_id);
        $this->assertSame('credit_card', $payment->method);

        $this->assertSame(
            PaymentStatus::Successful,
            $payment->status
        );

        $this->assertEquals(
            750.50,
            (float) $payment->amount
        );

        $this->assertNotNull(
            $payment->transaction_reference
        );

        $this->assertStringStartsWith(
            'CC-',
            $payment->transaction_reference
        );

        $this->assertSame(
            'approved',
            $payment->gateway_response['status']
        );

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'order_id' => $order->id,
            'method' => 'credit_card',
            'status' => PaymentStatus::Successful->value,
            'amount' => 750.50,
        ]);
    }

    public function test_paypal_creates_failed_payment(): void
    {
        $user = User::factory()->create();

        $order = $this->createOrder(
            user: $user,
            status: OrderStatus::Confirmed,
            total: 400,
        );

        $payment = $this->paymentService->process(
            user: $user,
            orderId: $order->id,
            method: 'paypal',
        );

        $this->assertSame('paypal', $payment->method);

        $this->assertSame(
            PaymentStatus::Failed,
            $payment->status
        );

        $this->assertEquals(
            400,
            (float) $payment->amount
        );

        $this->assertNull(
            $payment->transaction_reference
        );

        $this->assertSame(
            'declined',
            $payment->gateway_response['status']
        );
    }

    public function test_payment_amount_is_taken_from_order_total(): void
    {
        $user = User::factory()->create();

        $order = $this->createOrder(
            user: $user,
            status: OrderStatus::Confirmed,
            total: 999.99,
        );

        $payment = $this->paymentService->process(
            user: $user,
            orderId: $order->id,
            method: 'credit_card',
        );

        $this->assertEquals(
            999.99,
            (float) $payment->amount
        );
    }

    public function test_pending_order_cannot_be_paid(): void
    {
        $user = User::factory()->create();

        $order = $this->createOrder(
            user: $user,
            status: OrderStatus::Pending,
        );

        try {
            $this->paymentService->process(
                user: $user,
                orderId: $order->id,
                method: 'credit_card',
            );

            $this->fail(
                'Expected OrderNotConfirmedException was not thrown.'
            );
        } catch (OrderNotConfirmedException) {
            $this->assertDatabaseCount('payments', 0);
        }
    }

    public function test_cancelled_order_cannot_be_paid(): void
    {
        $user = User::factory()->create();

        $order = $this->createOrder(
            user: $user,
            status: OrderStatus::Cancelled,
        );

        try {
            $this->paymentService->process(
                user: $user,
                orderId: $order->id,
                method: 'credit_card',
            );

            $this->fail(
                'Expected OrderNotConfirmedException was not thrown.'
            );
        } catch (OrderNotConfirmedException) {
            $this->assertDatabaseCount('payments', 0);
        }
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

        try {
            $this->paymentService->process(
                user: $user,
                orderId: $order->id,
                method: 'paypal',
            );

            $this->fail(
                'Expected OrderAlreadyPaidException was not thrown.'
            );
        } catch (OrderAlreadyPaidException) {
            $this->assertDatabaseCount('payments', 1);
        }
    }

    public function test_failed_payment_can_be_retried(): void
    {
        $user = User::factory()->create();

        $order = $this->createOrder(
            user: $user,
            status: OrderStatus::Confirmed,
        );

        $failedPayment = $this->paymentService->process(
            user: $user,
            orderId: $order->id,
            method: 'paypal',
        );

        $successfulPayment = $this->paymentService->process(
            user: $user,
            orderId: $order->id,
            method: 'credit_card',
        );

        $this->assertSame(
            PaymentStatus::Failed,
            $failedPayment->status
        );

        $this->assertSame(
            PaymentStatus::Successful,
            $successfulPayment->status
        );

        $this->assertDatabaseCount('payments', 2);
    }

    public function test_unsupported_payment_method_is_rejected(): void
    {
        $user = User::factory()->create();

        $order = $this->createOrder(
            user: $user,
            status: OrderStatus::Confirmed,
        );

        try {
            $this->paymentService->process(
                user: $user,
                orderId: $order->id,
                method: 'bitcoin',
            );

            $this->fail(
                'Expected UnsupportedPaymentMethodException was not thrown.'
            );
        } catch (UnsupportedPaymentMethodException) {
            $this->assertDatabaseCount('payments', 0);
        }
    }

    public function test_user_cannot_pay_another_users_order(): void
    {
        $user = User::factory()->create();
        $anotherUser = User::factory()->create();

        $order = $this->createOrder(
            user: $anotherUser,
            status: OrderStatus::Confirmed,
        );

        $this->expectException(
            ModelNotFoundException::class
        );

        $this->paymentService->process(
            user: $user,
            orderId: $order->id,
            method: 'credit_card',
        );
    }

    public function test_paginate_returns_only_user_payments(): void
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

        $payments = $this->paymentService->paginate(
            user: $user,
            filters: [],
        );

        $this->assertSame(2, $payments->total());

        foreach ($payments->items() as $payment) {
            $this->assertSame(
                $user->id,
                $payment->order->user_id
            );
        }
    }

    public function test_payments_can_be_filtered_and_paginated(): void
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

        $this->createPayment(
            order: $order,
            status: PaymentStatus::Failed,
            method: 'paypal',
        );

        $payments = $this->paymentService->paginate(
            user: $user,
            filters: [
                'status' => PaymentStatus::Failed->value,
                'method' => 'paypal',
                'per_page' => 1,
            ],
        );

        $this->assertSame(2, $payments->total());
        $this->assertSame(1, $payments->perPage());
        $this->assertSame(2, $payments->lastPage());
        $this->assertCount(1, $payments->items());

        $payment = $payments->items()[0];

        $this->assertSame(
            PaymentStatus::Failed,
            $payment->status
        );

        $this->assertSame(
            'paypal',
            $payment->method
        );
    }

    public function test_history_returns_only_payments_for_selected_order(): void
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

        $payments = $this->paymentService->history(
            user: $user,
            orderId: $firstOrder->id,
            filters: [],
        );

        $this->assertSame(2, $payments->total());

        foreach ($payments->items() as $payment) {
            $this->assertSame(
                $firstOrder->id,
                $payment->order_id
            );
        }
    }

    public function test_history_can_be_filtered(): void
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

        $payments = $this->paymentService->history(
            user: $user,
            orderId: $order->id,
            filters: [
                'status' => PaymentStatus::Failed->value,
                'method' => 'paypal',
            ],
        );

        $this->assertSame(1, $payments->total());

        $payment = $payments->items()[0];

        $this->assertSame(
            PaymentStatus::Failed,
            $payment->status
        );

        $this->assertSame(
            'paypal',
            $payment->method
        );
    }

    public function test_user_cannot_view_another_users_payment_history(): void
    {
        $user = User::factory()->create();
        $anotherUser = User::factory()->create();

        $order = $this->createOrder(
            user: $anotherUser,
            status: OrderStatus::Confirmed,
        );

        $this->expectException(
            ModelNotFoundException::class
        );

        $this->paymentService->history(
            user: $user,
            orderId: $order->id,
            filters: [],
        );
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
}
