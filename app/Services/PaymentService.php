<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\OrderAlreadyPaidException;
use App\Exceptions\OrderNotConfirmedException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Payments\PaymentGatewayFactory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class PaymentService
{
    public function __construct(
        private readonly PaymentGatewayFactory $gatewayFactory,
    ) {
    }

    public function paginate(
        User $user,
        array $filters
    ): LengthAwarePaginator {
        $query = Payment::query()
            ->whereHas(
                'order',
                fn (Builder $query) =>
                $query->where('user_id', $user->id)
            );

        return $this->applyFilters($query, $filters)
            ->latest()
            ->paginate($filters['per_page'] ?? 15)
            ->withQueryString();
    }

    public function history(
        User $user,
        int $orderId,
        array $filters
    ): LengthAwarePaginator {
        $order = $this->findOrder($user, $orderId);

        return $this->applyFilters(
            $order->payments()->getQuery(),
            $filters
        )
            ->latest()
            ->paginate($filters['per_page'] ?? 15)
            ->withQueryString();
    }

    /**
     * @return array<int, string>
     */
    public function availableMethods(): array
    {
        return $this->gatewayFactory->availableMethods();
    }

    public function process(
        User $user,
        int $orderId,
        string $method
    ): Payment {
        $order = $this->findOrder($user, $orderId);

        $this->ensureOrderCanBePaid($order);

        $gateway = $this->gatewayFactory->make($method);

        $payment = $order->payments()->create([
            'method' => $method,
            'status' => PaymentStatus::Pending,
            'amount' => $order->total,
        ]);

        $result = $gateway->charge($order);

        $payment->update([
            'status' => $result->successful
                ? PaymentStatus::Successful
                : PaymentStatus::Failed,

            'transaction_reference' =>
                $result->transactionReference,

            'gateway_response' =>
                $result->gatewayResponse,
        ]);

        return $payment->refresh();
    }

    private function findOrder(User $user, int $orderId): Order
    {
        return $user->orders()->findOrFail($orderId);
    }

    private function ensureOrderCanBePaid(Order $order): void
    {
        if ($order->status !== OrderStatus::Confirmed) {
            throw new OrderNotConfirmedException();
        }

        $hasSuccessfulPayment = $order->payments()
            ->where(
                'status',
                PaymentStatus::Successful->value
            )
            ->exists();

        if ($hasSuccessfulPayment) {
            throw new OrderAlreadyPaidException();
        }
    }

    private function applyFilters(
        Builder $query,
        array $filters
    ): Builder {
        return $query
            ->when(
                $filters['status'] ?? null,
                fn (Builder $query, string $status) =>
                $query->where('status', $status)
            )
            ->when(
                $filters['method'] ?? null,
                fn (Builder $query, string $method) =>
                $query->where('method', $method)
            );
    }
}
