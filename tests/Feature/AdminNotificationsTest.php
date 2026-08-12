<?php

namespace Tests\Feature;

use App\Livewire\Admin\Notifications\Dropdown;
use App\Models\DepositRequest;
use App\Models\InvestmentAccount;
use App\Models\Investor;
use App\Models\InvestorWallet;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\AdminNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_receives_actionable_notifications_with_correct_targets_and_unread_count(): void
    {
        [$admin, , $investor, $account] = $this->context();
        $service = app(AdminNotificationService::class);
        $deposit = DepositRequest::create([
            'investor_id' => $investor->id, 'investment_account_id' => $account->id,
            'requested_amount' => '4300.00000000', 'currency' => 'USDT',
            'status' => 'pending', 'requested_at' => now(),
        ]);
        $withdrawal = WithdrawalRequest::create([
            'investor_id' => $investor->id, 'investment_account_id' => $account->id,
            'type' => 'dividend', 'requested_amount' => '1200.00000000',
            'reserved_amount' => '1200.00000000', 'currency' => 'USDT',
            'status' => 'new', 'requested_at' => now(),
        ]);
        $wallet = InvestorWallet::create([
            'investor_id' => $investor->id, 'currency' => 'USDT', 'network' => 'TRC20',
            'address' => 'TNotificationWallet', 'status' => 'pending',
        ]);

        $service->depositCreated($deposit);
        $service->depositPaymentSubmitted($deposit);
        $service->withdrawalCreated($withdrawal);
        $service->walletCreated($wallet);

        $this->assertSame(4, $admin->unreadNotifications()->count());
        $this->assertSame(route('admin.deposits.index').'?deposit='.$deposit->id,
            $admin->notifications()->where('data->event', 'deposit.created')->firstOrFail()->data['target']);
        $this->assertSame(route('admin.withdrawals.index').'?withdrawal='.$withdrawal->id,
            $admin->notifications()->where('data->event', 'withdrawal.created')->firstOrFail()->data['target']);

        Livewire::actingAs($admin)->test(Dropdown::class)
            ->assertSee('Новая заявка на пополнение')
            ->assertSee('Инвестор сообщил об оплате')
            ->assertSee('Новая заявка на вывод')
            ->assertSee('Новый кошелёк ожидает проверки')
            ->assertSee('4 300.00 USDT')
            ->assertSee('Алексей Смирнов');
    }

    public function test_admin_marks_one_or_all_notifications_read(): void
    {
        [$admin, , $investor, $account] = $this->context();
        $deposit = DepositRequest::create([
            'investor_id' => $investor->id, 'investment_account_id' => $account->id,
            'requested_amount' => '100.00000000', 'currency' => 'USDT',
            'status' => 'pending', 'requested_at' => now(),
        ]);
        $service = app(AdminNotificationService::class);
        $service->depositCreated($deposit);
        $service->depositPaymentSubmitted($deposit);
        $notification = $admin->notifications()->where('data->event', 'deposit.created')->firstOrFail();

        Livewire::actingAs($admin)->test(Dropdown::class)
            ->call('open', $notification->id)
            ->assertRedirect(route('admin.deposits.index').'?deposit='.$deposit->id);
        $this->assertNotNull($notification->fresh()->read_at);
        $this->assertSame(1, $admin->unreadNotifications()->count());

        Livewire::actingAs($admin)->test(Dropdown::class)->call('markAllRead');
        $this->assertSame(0, $admin->unreadNotifications()->count());
    }

    public function test_notifications_and_settings_are_admin_only_and_settings_icon_has_route(): void
    {
        [$admin, $investorUser] = $this->context();

        $this->actingAs($admin)->get(route('admin.notifications.index'))->assertOk()->assertSee('Уведомления');
        $this->actingAs($admin)->get(route('admin.settings.index'))->assertOk()
            ->assertSee('Настройки')->assertSee('Общие')->assertSee('Финансы')->assertSee('Уведомления')->assertSee('Система')
            ->assertSee('href="'.route('admin.settings.index').'"', false);
        $this->actingAs($investorUser)->get(route('admin.notifications.index'))->assertForbidden();
        $this->actingAs($investorUser)->get(route('admin.settings.index'))->assertForbidden();
        Livewire::actingAs($investorUser)->test(Dropdown::class)->assertForbidden();
    }

    private function context(): array
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $investorUser = User::factory()->create(['role' => 'investor', 'is_active' => true]);
        $investor = Investor::create([
            'user_id' => $investorUser->id, 'code' => 'INV-002', 'status' => 'active',
        ]);
        $investorUser->update(['name' => 'Алексей Смирнов']);
        $account = InvestmentAccount::create([
            'investor_id' => $investor->id, 'currency' => 'USDT', 'status' => 'active',
        ]);

        return [$admin, $investorUser, $investor, $account];
    }
}
