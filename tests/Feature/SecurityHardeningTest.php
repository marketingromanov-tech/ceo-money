<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureUserIsActive;
use App\Models\InvestmentAccount;
use App\Models\Investor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_investor_session_is_revoked_after_user_is_deactivated(): void
    {
        [$user] = $this->investorContext();

        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $user->update(['is_active' => false]);

        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_admin_session_is_revoked_after_user_is_deactivated(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();
        $admin->update(['is_active' => false]);

        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_livewire_financial_requests_use_active_user_enforcement(): void
    {
        [$user] = $this->investorContext();
        $this->assertContains(
            EnsureUserIsActive::class,
            app(PersistentMiddleware::class)->getPersistentMiddleware(),
        );

        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $user->update(['is_active' => false]);
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_failed_logins_are_throttled_by_normalized_email_and_ip(): void
    {
        $email = 'throttle@example.com';
        RateLimiter::clear(mb_strtolower($email).'|127.0.0.1');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('login.store'), ['email' => strtoupper($email), 'password' => 'wrong'])
                ->assertSessionHasErrors('email');
        }

        $this->post(route('login.store'), ['email' => strtoupper($email), 'password' => 'wrong'])
            ->assertStatus(429);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post(route('login.store'), ['email' => $email, 'password' => 'wrong'])
            ->assertSessionHasErrors('email');
    }

    public function test_valid_login_before_threshold_still_works(): void
    {
        $user = User::factory()->create([
            'email' => 'before-threshold@example.com',
            'password' => 'password',
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong'])
            ->assertSessionHasErrors('email');
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    private function investorContext(): array
    {
        $user = User::factory()->create(['role' => 'investor', 'is_active' => true]);
        $investor = Investor::create(['user_id' => $user->id, 'status' => 'active']);
        $account = InvestmentAccount::create([
            'investor_id' => $investor->id,
            'currency' => 'USDT',
            'status' => 'active',
        ]);

        return [$user, $investor, $account];
    }
}
