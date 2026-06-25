<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\OrderHasPaymentsException;
use App\Exceptions\PaidOrderCannotBeModifiedException;
use App\Models\Order;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderService
{
    public function paginate(User $user, array $filters): LengthAwarePaginator
    {
        return $user->orders()
            ->with('items')
            ->when(
                $filters['status'] ?? null,
                fn ($query, string $status) =>
                $query->where('status', $status)
            )
            ->latest()
            ->paginate($filters['per_page'] ?? 15)
            ->withQueryString();
    }

    public function find(User $user, int $orderId): Order
    {
        return $user->orders()
            ->with('items')
            ->findOrFail($orderId);
    }

    public function create(User $user, array $data): Order
    {
        return DB::transaction(function () use ($user, $data): Order {
            $items = $data['items'];

            $order = $user->orders()->create([
                'customer_name' => $data['customer_name'],
                'customer_email' => $data['customer_email'],
                'customer_phone' => $data['customer_phone'] ?? null,
                'status' => OrderStatus::Pending,
                'total' => $this->calculateTotal($items),
            ]);

            $order->items()->createMany($items);

            return $order->load('items');
        });
    }

    public function update(Order $order, array $data): Order
    {
        $this->ensureOrderCanBeUpdated($order);
        $this->validateStatusTransition($order, $data);
        $this->validateItemsModification($order, $data);

        return DB::transaction(function () use ($order, $data): Order {
            $order->update(
                Arr::only($data, [
                    'customer_name',
                    'customer_email',
                    'customer_phone',
                    'status',
                ])
            );

            if (array_key_exists('items', $data)) {
                $this->replaceItems($order, $data['items']);
            }

            return $order->refresh()->load('items');
        });
    }

    public function delete(Order $order): void
    {
        if ($order->payments()->exists()) {
            throw new OrderHasPaymentsException();
        }

        $order->delete();
    }

    private function replaceItems(Order $order, array $items): void
    {
        $order->items()->delete();
        $order->items()->createMany($items);

        $order->update([
            'total' => $this->calculateTotal($items),
        ]);
    }

    private function calculateTotal(array $items): float
    {
        $total = collect($items)->sum(
            fn (array $item): float =>
                (int) $item['quantity'] * (float) $item['price']
        );

        return round($total, 2);
    }

    private function ensureOrderCanBeUpdated(Order $order): void
    {
        $hasSuccessfulPayment = $order->payments()
            ->where('status', PaymentStatus::Successful->value)
            ->exists();

        if ($hasSuccessfulPayment) {
            throw new PaidOrderCannotBeModifiedException();
        }
    }

    private function validateItemsModification(
        Order $order,
        array $data
    ): void {
        if (! array_key_exists('items', $data)) {
            return;
        }

        if ($order->status !== OrderStatus::Pending) {
            throw ValidationException::withMessages([
                'items' => [
                    'Confirmed or cancelled orders cannot have their items modified.',
                ],
            ]);
        }
    }

    private function validateStatusTransition(
        Order $order,
        array $data
    ): void {
        if (! array_key_exists('status', $data)) {
            return;
        }

        $newStatus = $data['status'] instanceof OrderStatus
            ? $data['status']
            : OrderStatus::from($data['status']);

        if ($newStatus === $order->status) {
            return;
        }

        $isAllowedTransition =
            $order->status === OrderStatus::Pending
            && in_array($newStatus, [
                OrderStatus::Confirmed,
                OrderStatus::Cancelled,
            ], true);

        if (! $isAllowedTransition) {
            throw ValidationException::withMessages([
                'status' => [
                    'Order status can only move from pending to confirmed or cancelled.',
                ],
            ]);
        }
    }
}
