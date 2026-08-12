<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AdminMfaService;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Livewire\Livewire;
use App\Livewire\Admin\Fees\Index as AdminFees;

class AdminMfaTest extends TestCase
{
    use RefreshDatabase;

    public function test_investor_login_remains_without_mfa_and_admin_without_setup_is_forced_to_setup(): void
    {
        $investor = User::factory()->create(['role' => 'investor', 'password' => 'password']);
        $this->post('/login', ['email' => $investor->email, 'password' => 'password'])->assertRedirect(route('dashboard'));
        $this->post('/logout');
        $admin = User::factory()->create(['role' => 'admin', 'password' => 'password']);
        $this->post('/login', ['email' => $admin->email, 'password' => 'password'])->assertRedirect(route('admin.dashboard'));
        $this->withSession(['mfa_enforcement_test' => true])->get('/admin')->assertRedirect(route('admin.security.setup'));
    }

    public function test_admin_login_requires_and_accepts_totp_and_rejects_invalid_code(): void
    {
        [$admin, $secret] = $this->enabledAdmin();
        $this->post('/login', ['email' => $admin->email, 'password' => 'password'])->assertRedirect(route('mfa.challenge'));
        $this->post(route('mfa.verify'), ['code' => '000000'])->assertSessionHasErrors('code');
        $this->post(route('mfa.verify'), ['code' => app(TotpService::class)->currentCode($secret)])->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($admin);
        $this->assertSame($admin->id, session('admin_mfa_user_id'));
    }

    public function test_setup_encrypts_secret_and_hashes_single_use_recovery_codes(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'password' => 'password']);
        $secret = app(TotpService::class)->generateSecret();
        $response = $this->actingAs($admin)->withSession(['mfa_setup_secret' => $secret])
            ->post(route('admin.security.enable'), ['password' => 'password', 'code' => app(TotpService::class)->currentCode($secret)]);
        $response->assertRedirect(route('admin.security.setup'));
        $admin->refresh();
        $this->assertNotSame($secret, DB::table('users')->where('id', $admin->id)->value('mfa_secret'));
        $plain = session('new_recovery_codes');
        $this->assertCount(8, $plain);
        $this->assertFalse(in_array($plain[0], $admin->mfa_recovery_codes, true));
        $this->assertTrue(Hash::check($plain[0], $admin->mfa_recovery_codes[0]));
        $this->assertTrue(app(AdminMfaService::class)->verify($admin, $plain[0]));
        $this->assertFalse(app(AdminMfaService::class)->verify($admin->fresh(), $plain[0]));
    }

    public function test_recent_auth_requires_password_and_mfa_and_expires(): void
    {
        [$admin, $secret] = $this->enabledAdmin();
        $session = ['admin_mfa_user_id' => $admin->id, 'mfa_enforcement_test' => true];
        $this->actingAs($admin)->withSession($session)->post(route('admin.recent-auth.verify'), ['password' => 'wrong', 'code' => app(TotpService::class)->currentCode($secret)])->assertSessionHasErrors('code');
        $this->actingAs($admin)->withSession($session)->post(route('admin.recent-auth.verify'), ['password' => 'password', 'code' => app(TotpService::class)->currentCode($secret)])->assertRedirect(route('admin.dashboard'));
        $this->assertGreaterThanOrEqual(now()->subMinute()->timestamp, session('recent_auth_at'));
    }

    public function test_invalid_setup_code_does_not_enable_mfa_and_recovery_regeneration_invalidates_old_codes(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'password' => 'password']);
        $secret = app(TotpService::class)->generateSecret();
        $this->actingAs($admin)->withSession(['mfa_setup_secret' => $secret])
            ->post(route('admin.security.enable'), ['password' => 'password', 'code' => '000000'])->assertSessionHasErrors('code');
        $this->assertNull($admin->fresh()->mfa_enabled_at);

        [$admin, $secret] = $this->enabledAdmin();
        $old = app(AdminMfaService::class)->recoveryCodes($admin)[0];
        $this->actingAs($admin)->withSession(['admin_mfa_user_id' => $admin->id, 'recent_auth_at' => now()->timestamp])
            ->post(route('admin.security.recovery'))->assertRedirect();
        $this->assertFalse(app(AdminMfaService::class)->verify($admin->fresh(), $old));
    }

    public function test_disable_requires_recent_auth_and_mfa_failures_are_throttled(): void
    {
        [$admin] = $this->enabledAdmin();
        $this->actingAs($admin)->withSession(['admin_mfa_user_id' => $admin->id])->delete(route('admin.security.disable'))->assertForbidden();
        $this->assertNotNull($admin->fresh()->mfa_enabled_at);

        auth()->logout();
        $this->post('/login', ['email' => $admin->email, 'password' => 'password']);
        for ($i = 0; $i < 5; $i++) $this->post(route('mfa.verify'), ['code' => 'invalid']);
        $this->post(route('mfa.verify'), ['code' => 'invalid'])->assertSessionHasErrors(['code' => 'Слишком много попыток. Повторите позже.']);
    }

    public function test_inactive_admin_and_direct_admin_url_cannot_bypass_mfa(): void
    {
        [$admin] = $this->enabledAdmin();
        $this->actingAs($admin)->withSession(['mfa_enforcement_test' => true])->get('/admin')->assertRedirect(route('login'));
        $admin->update(['is_active' => false]);
        $this->actingAs($admin)->withSession(['mfa_enforcement_test' => true, 'admin_mfa_user_id' => $admin->id])->get('/admin')->assertRedirect(route('login'));
    }

    public function test_sensitive_livewire_action_requires_unexpired_recent_auth(): void
    {
        [$admin] = $this->enabledAdmin();
        $this->actingAs($admin)->withSession(['recent_auth_enforcement_test' => true, 'admin_mfa_user_id' => $admin->id]);
        Livewire::test(AdminFees::class)->call('save')->assertRedirect(route('admin.recent-auth'));

        $this->withSession(['recent_auth_enforcement_test' => true, 'recent_auth_at' => now()->subMinutes(11)->timestamp]);
        Livewire::test(AdminFees::class)->call('save')->assertRedirect(route('admin.recent-auth'));
    }

    private function enabledAdmin(): array
    {
        $secret = app(TotpService::class)->generateSecret();
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'password' => 'password', 'mfa_secret' => $secret, 'mfa_enabled_at' => now(), 'mfa_recovery_codes' => []]);
        return [$admin, $secret];
    }
}
