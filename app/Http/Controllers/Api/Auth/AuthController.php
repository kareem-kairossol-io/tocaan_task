<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use PHPOpenSourceSaver\JWTAuth\JWTGuard;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]);

        $token = $this->guard()->login($user);

        return $this->successResponse(
            data: $this->tokenData($token, $user),
            message: 'Registration completed successfully.',
            statusCode: 201,
        );
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $token = $this->guard()->attempt(
            $request->validated()
        );

        if (! $token) {
            return $this->errorResponse(
                message: 'The provided credentials are incorrect.',
                statusCode: 401,
            );
        }

        /** @var User $user */
        $user = $this->guard()->user();

        return $this->successResponse(
            data: $this->tokenData($token, $user),
            message: 'Logged in successfully.',
        );
    }

    public function me(): JsonResponse
    {
        /** @var User $user */
        $user = $this->guard()->user();

        return $this->successResponse(
            data: new UserResource($user),
            message: 'Authenticated user retrieved successfully.',
        );
    }

    public function refresh(): JsonResponse
    {
        $token = $this->guard()->refresh();

        $this->guard()->setToken($token);

        /** @var User $user */
        $user = $this->guard()->user();

        return $this->successResponse(
            data: $this->tokenData($token, $user),
            message: 'Token refreshed successfully.',
        );
    }

    public function logout(): JsonResponse
    {
        $this->guard()->logout();

        return $this->successResponse(
            message: 'Logged out successfully.',
        );
    }

    private function tokenData(string $token, User $user): array
    {
        return [
            'user' => new UserResource($user),
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => $this->guard()->factory()->getTTL() * 60,
        ];
    }

    private function guard(): JWTGuard
    {
        /** @var JWTGuard $guard */
        $guard = Auth::guard('api');

        return $guard;
    }
}
