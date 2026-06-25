<?php

namespace Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use PHPOpenSourceSaver\JWTAuth\JWTGuard;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Kareem',
            'email' => 'kareem@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath(
                'message',
                'Registration completed successfully.'
            )
            ->assertJsonPath(
                'data.user.email',
                'kareem@example.com'
            )
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'user' => [
                        'id',
                        'name',
                        'email',
                        'created_at',
                    ],
                    'access_token',
                    'token_type',
                    'expires_in',
                ],
            ]);

        $this->assertDatabaseHas('users', [
            'email' => 'kareem@example.com',
        ]);
    }

    public function test_user_can_login(): void
    {
        User::factory()->create([
            'email' => 'kareem@example.com',
            'password' => 'password123',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'kareem@example.com',
            'password' => 'password123',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath(
                'data.user.email',
                'kareem@example.com'
            )
            ->assertJsonStructure([
                'data' => [
                    'access_token',
                    'token_type',
                    'expires_in',
                ],
            ]);
    }

    public function test_invalid_credentials_return_401(): void
    {
        User::factory()->create([
            'email' => 'kareem@example.com',
            'password' => 'password123',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'kareem@example.com',
            'password' => 'wrong-password',
        ]);

        $response
            ->assertUnauthorized()
            ->assertJson([
                'success' => false,
                'message' => 'The provided credentials are incorrect.',
            ]);
    }

    public function test_authenticated_user_can_access_me(): void
    {
        $user = User::factory()->create();
        $token = $this->guard()->login($user);

        $response = $this
            ->withToken($token)
            ->getJson('/api/auth/me');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', $user->email);
    }

    public function test_guest_cannot_access_protected_endpoint(): void
    {
        $response = $this->getJson('/api/auth/me');

        $response
            ->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_user_can_refresh_token(): void
    {
        $user = User::factory()->create();
        $oldToken = $this->guard()->login($user);

        $response = $this
            ->withToken($oldToken)
            ->postJson('/api/auth/refresh');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'access_token',
                    'token_type',
                    'expires_in',
                ],
            ]);

        $newToken = $response->json('data.access_token');

        $this->assertNotSame($oldToken, $newToken);
    }

    public function test_user_can_logout(): void
    {
        $user = User::factory()->create();
        $token = $this->guard()->login($user);

        $response = $this
            ->withToken($token)
            ->postJson('/api/auth/logout');

        $response
            ->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Logged out successfully.',
            ]);

        $this
            ->withToken($token)
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    }

    private function guard(): JWTGuard
    {
        /** @var JWTGuard $guard */
        $guard = Auth::guard('api');

        return $guard;
    }
}
