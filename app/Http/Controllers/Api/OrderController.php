<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Orders\IndexOrderRequest;
use App\Http\Requests\Orders\StoreOrderRequest;
use App\Http\Requests\Orders\UpdateOrderRequest;
use App\Http\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use PHPOpenSourceSaver\JWTAuth\JWTGuard;

class OrderController extends Controller
{
    public function __construct(
        private readonly OrderService $orderService,
    ) {
    }

    public function index(IndexOrderRequest $request): JsonResponse
    {
        $orders = $this->orderService->paginate(
            user: $this->user(),
            filters: $request->validated(),
        );

        return $this->successResponse(
            data: [
                'orders' => OrderResource::collection(
                    $orders->getCollection()
                ),
                'pagination' => [
                    'current_page' => $orders->currentPage(),
                    'last_page' => $orders->lastPage(),
                    'per_page' => $orders->perPage(),
                    'total' => $orders->total(),
                    'from' => $orders->firstItem(),
                    'to' => $orders->lastItem(),
                ],
            ],
            message: 'Orders retrieved successfully.',
        );
    }

    public function store(StoreOrderRequest $request): JsonResponse
    {
        $order = $this->orderService->create(
            user: $this->user(),
            data: $request->validated(),
        );

        return $this->successResponse(
            data: new OrderResource($order),
            message: 'Order created successfully.',
            statusCode: 201,
        );
    }

    public function show(int $order): JsonResponse
    {
        $order = $this->orderService->find(
            user: $this->user(),
            orderId: $order,
        );

        return $this->successResponse(
            data: new OrderResource($order),
            message: 'Order retrieved successfully.',
        );
    }

    public function update(
        UpdateOrderRequest $request,
        int $order
    ): JsonResponse {
        $order = $this->orderService->find(
            user: $this->user(),
            orderId: $order,
        );

        $order = $this->orderService->update(
            order: $order,
            data: $request->validated(),
        );

        return $this->successResponse(
            data: new OrderResource($order),
            message: 'Order updated successfully.',
        );
    }

    public function destroy(int $order): JsonResponse
    {
        $order = $this->orderService->find(
            user: $this->user(),
            orderId: $order,
        );

        $this->orderService->delete($order);

        return $this->successResponse(
            message: 'Order deleted successfully.',
        );
    }

    private function user(): User
    {
        /** @var User $user */
        $user = $this->guard()->user();

        return $user;
    }

    private function guard(): JWTGuard
    {
        /** @var JWTGuard $guard */
        $guard = Auth::guard('api');

        return $guard;
    }
}
