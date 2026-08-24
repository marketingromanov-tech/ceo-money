<?php

namespace Tests\Feature;

use App\Livewire\Admin\Administrators\Index;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AdminManagementService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class AdminManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_investor_login_never_requires_two_factor_challenge(): void
    {
        $investor = User::factory()->create(['role' => 'investor', 'is_active' => true, 'password' => 'password', 'mfa_secret' => 'unused-secret', 'mfa_enabled_at' => now()]);

        $this->post('/login', ['email' => $investor->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($investor);
        $this->assertFalse(session()->has('mfa_login_user_id'));
    }

    public function test_admin_can_create_activate_deactivate_and_reset_admin_password_with_safe_audit(): void
    {
        $actor = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        Livewire::actingAs($actor)->test(Index::class)
            ->set('name', 'Security Admin')
            ->set('email', 'security.admin@example.com')
            ->set('password', 'StrongPassword-2026')
            ->set('password_confirmation', 'StrongPassword-2026')
            ->call('create')->assertHasNoErrors();

        $created = User::where('email', 'security.admin@example.com')->sole();
        $this->assertSame('admin', $created->role);
        $this->assertTrue($created->is_active);
        $this->assertNull($created->mfa_enabled_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.created', 'entity_id' => $created->id, 'user_id' => $actor->id]);

        Livewire::actingAs($actor)->test(Index::class)->call('toggle', $created->id)->assertHasNoErrors();
        $this->assertFalse($created->fresh()->is_active);
        Livewire::actingAs($actor)->test(Index::class)->call('toggle', $created->id)->call('openPasswordReset', $created->id)
            ->set('resetPassword', 'Replacement-Password-2026')->set('resetPassword_confirmation', 'Replacement-Password-2026')
            ->call('resetAdminPassword')->assertHasNoErrors();
        $this->assertTrue(Hash::check('Replacement-Password-2026', $created->fresh()->password));
        foreach (['admin.activated', 'admin.deactivated', 'admin.password_reset'] as $action) $this->assertDatabaseHas('audit_logs', ['action' => $action, 'entity_id' => $created->id]);
        $auditPayload = AuditLog::where('entity_id', $created->id)->get()->toJson();
        $this->assertStringNotContainsString('StrongPassword-2026', $auditPayload);
        $this->assertStringNotContainsString('Replacement-Password-2026', $auditPayload);
        $this->assertStringNotContainsString('unused-secret', $auditPayload);
    }

    public function test_investor_cannot_access_or_create_administrators(): void
    {
        $investor = User::factory()->create(['role' => 'investor', 'is_active' => true]);
        $this->actingAs($investor)->get(route('admin.administrators.index'))->assertForbidden();

        $this->expectException(DomainException::class);
        app(AdminManagementService::class)->create($investor, 'Forbidden', 'forbidden@example.com', 'StrongPassword-2026');
    }

    public function test_last_active_administrator_and_current_administrator_are_protected(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        Livewire::actingAs($admin)->test(Index::class)->call('toggle', $admin->id)
            ->assertHasErrors(['management' => 'Нельзя деактивировать самого себя.']);
        $this->assertTrue($admin->fresh()->is_active);
        $this->assertSame(1, User::where('role', 'admin')->where('is_active', true)->count());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'admin.deactivated', 'entity_id' => $admin->id]);
    }
}
