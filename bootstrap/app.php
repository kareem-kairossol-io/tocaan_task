<?php

use App\Exceptions\BusinessRuleException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenExpiredException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenInvalidException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(
            fn (Request $request): ?string =>
            $request->is('api/*')
                ? null
                : route('login')
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(
            function (BusinessRuleException $exception, Request $request) {
                return response()->json([
                    'success' => false,
                    'message' => $exception->getMessage(),
                    'data' => null,
                ], $exception->status());
            }
        );
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $exception): bool =>
                $request->is('api/*') || $request->expectsJson()
        );
        $exceptions->render(
            function (
                ThrottleRequestsException $exception,
                Request $request
            ) {
                if (! $request->is('api/*')) {
                    return null;
                }

                $headers = $exception->getHeaders();

                return response()->json([
                    'success' => false,
                    'message' => 'Too many requests. Please try again later.',
                    'retry_after' => (int) ($headers['Retry-After'] ?? 60),
                ], 429, $headers);
            }
        );
        $exceptions->render(
            function (
                ValidationException $exception,
                Request $request
            ) {
                if (! $request->is('api/*')) {
                    return null;
                }

                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed.',
                    'errors' => $exception->errors(),
                ], 422);
            }
        );

        $exceptions->render(
            function (
                AuthenticationException $exception,
                Request $request
            ) {
                if (! $request->is('api/*')) {
                    return null;
                }

                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated.',
                ], 401);
            }
        );

        $exceptions->render(
            function (
                TokenExpiredException $exception,
                Request $request
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token has expired.',
                ], 401);
            }
        );

        $exceptions->render(
            function (
                TokenInvalidException $exception,
                Request $request
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token is invalid.',
                ], 401);
            }
        );

        $exceptions->render(
            function (
                JWTException $exception,
                Request $request
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token is missing or could not be parsed.',
                ], 401);
            }
        );
    })->create();
