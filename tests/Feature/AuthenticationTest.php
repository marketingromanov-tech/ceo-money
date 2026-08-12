<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_opens(): void
    {
        $this->get('/login')->assertOk()->assertSee('Добро пожаловать')->assertSee('Запомнить меня');
    }

    public function test_login_with_a_session_csrf_token_works(): void
    {
        $user = User::factory()->create(['email' => 'csrf@example.com', 'password' => 'password', 'role' => 'investor']);

        $this->withSession(['_token' => 'valid-test-token'])
            ->post('/login', ['_token' => 'valid-test-token', 'email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_admin_login_redirects_to_admin_dashboard(): void
    {
        User::factory()->create(['email' => 'admin@example.com', 'password' => 'password', 'role' => 'admin']);

        $this->post('/login', ['email' => 'admin@example.com', 'password' => 'password'])
            ->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticated();
    }

    public function test_investor_login_redirects_to_investor_dashboard_and_logout_works(): void
    {
        $user = User::factory()->create(['email' => 'investor@example.com', 'password' => 'password', 'role' => 'investor']);

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard'));
        $this->post('/logout')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_inactive_user_cannot_login(): void
    {
        User::factory()->create(['email' => 'inactive@example.com', 'password' => 'password', 'is_active' => false]);

        $this->post('/login', ['email' => 'inactive@example.com', 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_invalid_credentials_show_an_error(): void
    {
        $this->post('/login', ['email' => 'missing@example.com', 'password' => 'wrong-password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_remember_field_does_not_break_authentication(): void
    {
        $user = User::factory()->create(['email' => 'remember@example.com', 'password' => 'password', 'role' => 'investor']);

        $this->post('/login', ['email' => $user->email, 'password' => 'password', 'remember' => '1'])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_page_expired_view_is_branded_and_links_to_login(): void
    {
        $this->view('errors.419')
            ->assertSee('Сессия истекла')
            ->assertSee('Вернуться ко входу')
            ->assertSee(route('login'), false)
            ->assertDontSee('TokenMismatchException');
    }
}
