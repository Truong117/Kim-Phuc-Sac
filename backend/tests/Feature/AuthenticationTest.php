<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function spaHeaders(): array
    {
        return [
            'Origin' => 'http://localhost:5173',
            'Referer' => 'http://localhost:5173/login',
        ];
    }

    public function test_spa_can_request_a_csrf_cookie(): void
    {
        $response = $this
            ->withHeaders($this->spaHeaders())
            ->get('/sanctum/csrf-cookie');

        $response
            ->assertNoContent()
            ->assertCookie('XSRF-TOKEN');
    }

    public function test_user_can_log_in_with_valid_credentials(): void
    {
        $user = User::factory()->create([
            'name' => 'KPS User',
            'email' => 'user@example.com',
            'password' => 'correct-password',
        ]);

        $this->withSession(['login-marker' => 'present']);
        $sessionIdBeforeLogin = session()->getId();

        $response = $this
            ->withHeaders($this->spaHeaders())
            ->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'correct-password',
            ]);

        $response
            ->assertOk()
            ->assertExactJson([
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ]);

        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($sessionIdBeforeLogin, session()->getId());
    }

    public function test_login_rejects_invalid_credentials_without_revealing_account_details(): void
    {
        User::factory()->create([
            'email' => 'user@example.com',
            'password' => 'correct-password',
        ]);

        $response = $this
            ->withHeaders($this->spaHeaders())
            ->postJson('/api/auth/login', [
                'email' => 'user@example.com',
                'password' => 'incorrect-password',
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email'])
            ->assertJsonPath('errors.email.0', 'Email hoặc mật khẩu không chính xác.');

        $this->assertGuest('web');
    }

    public function test_login_validates_email_and_password(): void
    {
        $this
            ->withHeaders($this->spaHeaders())
            ->postJson('/api/auth/login', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_current_user_endpoint_rejects_unauthenticated_requests(): void
    {
        $this
            ->withHeaders($this->spaHeaders())
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    }

    public function test_current_user_endpoint_returns_only_safe_user_fields(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->withHeaders($this->spaHeaders())
            ->getJson('/api/auth/me');

        $response
            ->assertOk()
            ->assertExactJson([
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ]);
    }

    public function test_authenticated_user_can_log_out_and_session_data_is_invalidated(): void
    {
        $user = User::factory()->create([
            'email' => 'user@example.com',
            'password' => 'correct-password',
        ]);

        $this
            ->withHeaders($this->spaHeaders())
            ->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'correct-password',
            ])
            ->assertOk();

        $response = $this
            ->withSession(['auth-marker' => 'present'])
            ->withHeaders($this->spaHeaders())
            ->postJson('/api/auth/logout');

        $response
            ->assertNoContent()
            ->assertSessionMissing('auth-marker');

        $this->assertGuest('web');

        // Simulate the fresh authentication guard used by the next HTTP request.
        $this->app['auth']->forgetGuards();

        $this
            ->withHeaders($this->spaHeaders())
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    }
}
