<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payments\IndexPaymentRequest;
use App\Http\Requests\Payments\ProcessPaymentRequest;
use App\Http\Resources\Payments\PaymentResource;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use PHPOpenSourceSaver\JWTAuth\JWTGuard;

class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $paymentService,
    ) {
    }

    public function index(
        IndexPaymentRequest $request
    ): JsonResponse {
        $payments = $this->paymentService->paginate(
            user: $this->user(),
            filters: $request->validated(),
        );

        return $this->paginatedResponse(
            payments: $payments,
            message: 'Payments retrieved successfully.',
        );
    }

    public function history(
        IndexPaymentRequest $request,
        int $order
    ): JsonResponse {
        $payments = $this->paymentService->history(
            user: $this->user(),
            orderId: $order,
            filters: $request->validated(),
        );

        return $this->paginatedResponse(
            payments: $payments,
            message: 'Order payment history retrieved successfully.',
        );
    }

    public function store(
        ProcessPaymentRequest $request,
        int $order
    ): JsonResponse {
        $payment = $this->paymentService->process(
            user: $this->user(),
            orderId: $order,
            method: $request->validated('method'),
        );

        return $this->successResponse(
            data: new PaymentResource($payment),
            message: 'Payment processed successfully.',
            statusCode: 201,
        );
    }

    public function methods(): JsonResponse
    {
        return $this->successResponse(
            data: [
                'methods' =>
                    $this->paymentService->availableMethods(),
            ],
            message: 'Payment methods retrieved successfully.',
        );
    }

    private function paginatedResponse(
        mixed $payments,
        string $message
    ): JsonResponse {
        return $this->successResponse(
            data: [
                'payments' => PaymentResource::collection(
                    $payments->getCollection()
                ),
                'pagination' => [
                    'current_page' => $payments->currentPage(),
                    'last_page' => $payments->lastPage(),
                    'per_page' => $payments->perPage(),
                    'total' => $payments->total(),
                    'from' => $payments->firstItem(),
                    'to' => $payments->lastItem(),
                ],
            ],
            message: $message,
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
