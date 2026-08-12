<?php

namespace Tests\Feature;

use App\Livewire\Investor\Profile;
use App\Models\Investor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class InvestorProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_route_is_protected_by_auth_and_investor_role(): void
    {
        $this->get('/profile')->assertRedirect(route('login'));

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('/profile')->assertForbidden();

        [$user] = $this->investorContext();
        $this->actingAs($user)->get('/profile')->assertOk()->assertSee('Личные данные и безопасность аккаунта');
    }

    public function test_profile_shows_only_own_read_only_contract_and_account_data(): void
    {
        [$user, $investor] = $this->investorContext('owner@example.com', 'Владелец');
        [, $foreignInvestor] = $this->investorContext('foreign-profile@example.com', 'Чужой пользователь');
        $foreignInvestor->update(['code' => 'PRIVATE-CODE', 'contract_number' => 'PRIVATE-CONTRACT']);
        $this->actingAs($user);

        Livewire::test(Profile::class)
            ->assertSee('Владелец')->assertSee('owner@example.com')->assertSee('INV-PROFILE')
            ->assertSee('DOG-100')->assertSee('10.03.2026')->assertSee('Дата регистрации')
            ->assertDontSee('PRIVATE-CODE')->assertDontSee('PRIVATE-CONTRACT')->assertDontSee('Чужой пользователь')
            ->assertSee('readonly', false)->assertSee('aria-readonly="true"', false)
            ->assertSee('lg:grid-cols-[1.15fr_.85fr]', false);
    }

    public function test_investor_can_update_only_own_name_and_phone(): void
    {
        [$user, $investor] = $this->investorContext();
        [$foreignUser, $foreignInvestor] = $this->investorContext('foreign-update@example.com', 'Не менять');
        $this->actingAs($user);

        Livewire::test(Profile::class)
            ->set('name', 'Новое имя')->set('phone', '+7 900 123-45-67')
            ->call('saveProfile')->assertHasNoErrors()->assertSee('Профиль обновлён.');

        $this->assertSame('Новое имя', $user->fresh()->name);
        $this->assertSame('+7 900 123-45-67', $investor->fresh()->phone);
        $this->assertSame('Не менять', $foreignUser->fresh()->name);
        $this->assertNull($foreignInvestor->fresh()->phone);
        $this->assertSame('investor@example.com', $user->fresh()->email);
        $this->assertSame('INV-PROFILE', $investor->fresh()->code);
        $this->assertSame('DOG-100', $investor->fresh()->contract_number);
    }

    public function test_password_change_rejects_wrong_current_password_confirmation_and_same_password(): void
    {
        [$user] = $this->investorContext(); $this->actingAs($user);

        Livewire::test(Profile::class)
            ->set('currentPassword', 'wrong-password')->set('newPassword', 'new-password-123')->set('newPasswordConfirmation', 'new-password-123')
            ->call('changePassword')->assertHasErrors('currentPassword')->assertSee('Текущий пароль указан неверно.');

        Livewire::test(Profile::class)
            ->set('currentPassword', 'password')->set('newPassword', 'new-password-123')->set('newPasswordConfirmation', 'different-password')
            ->call('changePassword')->assertHasErrors('newPasswordConfirmation');

        Livewire::test(Profile::class)
            ->set('currentPassword', 'password')->set('newPassword', 'password')->set('newPasswordConfirmation', 'password')
            ->call('changePassword')->assertHasErrors('newPassword')->assertSee('Новый пароль должен отличаться от текущего.');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_valid_password_change_updates_hash_clears_fields_and_keeps_session(): void
    {
        [$user] = $this->investorContext(); $this->actingAs($user);

        Livewire::test(Profile::class)
            ->set('currentPassword', 'password')->set('newPassword', 'secure-new-password')->set('newPasswordConfirmation', 'secure-new-password')
            ->call('changePassword')->assertHasNoErrors()->assertSee('Пароль изменён.')
            ->assertSet('currentPassword', '')->assertSet('newPassword', '')->assertSet('newPasswordConfirmation', '');

        $this->assertTrue(Hash::check('secure-new-password', $user->fresh()->password));
        $this->assertAuthenticatedAs($user);
    }

    public function test_investor_layout_has_accessible_local_dropdown_and_post_logout(): void
    {
        [$user] = $this->investorContext(); $this->actingAs($user);

        $this->get('/profile')->assertOk()
            ->assertSee('x-on:click.outside="open = false"', false)
            ->assertSee('x-on:keydown.escape.window="open = false"', false)
            ->assertSee('x-bind:aria-expanded="open.toString()"', false)
            ->assertSee('role="menu"', false)->assertSee('Профиль')->assertSee('Безопасность')
            ->assertSee('method="POST"', false)->assertSee('action="'.route('logout').'"', false);
    }

    private function investorContext(string $email = 'investor@example.com', string $name = 'Инвестор'): array
    {
        $user = User::factory()->create([
            'name' => $name, 'email' => $email, 'password' => 'password',
            'role' => 'investor', 'is_active' => true, 'created_at' => '2026-03-10 10:00:00',
        ]);
        $investor = Investor::create([
            'user_id' => $user->id, 'code' => in_array($email, ['investor@example.com', 'owner@example.com'], true) ? 'INV-PROFILE' : 'INV-'.strtoupper(substr(md5($email), 0, 8)), 'phone' => null,
            'contract_number' => 'DOG-100', 'contract_date' => '2026-03-10', 'status' => 'active',
        ]);

        return [$user, $investor];
    }
}
