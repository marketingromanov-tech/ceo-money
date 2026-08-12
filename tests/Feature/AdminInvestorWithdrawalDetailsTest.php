<?php

namespace Tests\Feature;

use App\Livewire\Admin\Investors\Show;
use App\Models\InvestmentAccount;
use App\Models\Investor;
use App\Models\InvestorPaymentDetail;
use App\Models\InvestorWallet;
use App\Models\InvestorWithdrawalDetail;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\InvestorWithdrawalDetailService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminInvestorWithdrawalDetailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_see_add_edit_and_deactivate_investor_withdrawal_details(): void
    {
        [$admin, $investorUser, $investor] = $this->context();

        $component = Livewire::actingAs($admin)->test(Show::class, ['investor' => $investor])
            ->call('selectTab', 'wallets')
            ->assertSee('Платёжные реквизиты для пополнения')
            ->assertSee('Платёжные реквизиты для вывода')
            ->call('openWithdrawalDetailForm')
            ->set('withdrawalDetailCurrency', 'usdt')
            ->set('withdrawalDetailNetwork', 'TRC20')
            ->set('withdrawalDetailAddress', 'TWithdrawalAddress001')
            ->set('withdrawalDetailMemo', 'INV-001')
            ->set('withdrawalDetailIsActive', true)
            ->call('saveWithdrawalDetail')
            ->assertHasNoErrors()
            ->assertSee('TWithdrawalAddress001');

        $detail = InvestorWithdrawalDetail::where('address', 'TWithdrawalAddress001')->sole();
        $this->assertSame($investor->id, $detail->investor_id);
        $this->assertSame('USDT', $detail->currency);
        $this->assertTrue($detail->is_active);

        $component->call('openWithdrawalDetailForm', $detail->id)
            ->set('withdrawalDetailNetwork', 'ERC20')
            ->set('withdrawalDetailAddress', '0xWithdrawalAddressEdited')
            ->call('saveWithdrawalDetail')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('investor_withdrawal_details', [
            'id' => $detail->id,
            'investor_id' => $investor->id,
            'network' => 'ERC20',
            'address' => '0xWithdrawalAddressEdited',
        ]);

        $component->call('toggleWithdrawalDetail', $detail->id);
        $this->assertFalse($detail->fresh()->is_active);
        $this->actingAs($investorUser)->get(route('admin.investors.show', $investor))->assertForbidden();
    }

    public function test_deposit_and_withdrawal_details_are_independent_and_scoped_to_investor(): void
    {
        [$admin, , $investor] = $this->context();
        [, , $otherInvestor] = $this->context('other');
        $deposit = InvestorPaymentDetail::create([
            'investor_id' => $investor->id,
            'currency' => 'USDT',
            'network' => 'TRC20',
            'address' => 'TDepositAddress',
            'is_active' => true,
        ]);
        $withdrawal = InvestorWithdrawalDetail::create([
            'investor_id' => $investor->id,
            'currency' => 'USDT',
            'network' => 'TRC20',
            'address' => 'TWithdrawalAddress',
            'is_active' => true,
        ]);
        InvestorWithdrawalDetail::create([
            'investor_id' => $otherInvestor->id,
            'currency' => 'USDT',
            'network' => 'TRC20',
            'address' => 'TOtherInvestorAddress',
            'is_active' => true,
        ]);

        Livewire::actingAs($admin)->test(Show::class, ['investor' => $investor])
            ->call('selectTab', 'wallets')
            ->assertSee('TWithdrawalAddress')
            ->assertDontSee('TOtherInvestorAddress')
            ->call('toggleWithdrawalDetail', $withdrawal->id);

        $this->assertTrue($deposit->fresh()->is_active);
        $this->assertSame('TDepositAddress', $deposit->fresh()->address);
        $this->assertFalse($withdrawal->fresh()->is_active);
    }

    public function test_selector_returns_latest_active_detail_matching_account_currency_and_optional_network(): void
    {
        [, , $investor, $account] = $this->context();
        $investor->withdrawalDetails()->create(['currency' => 'USDT', 'network' => 'TRC20', 'address' => 'TOld', 'is_active' => true]);
        $investor->withdrawalDetails()->create(['currency' => 'USDT', 'network' => 'TRC20', 'address' => 'TInactive', 'is_active' => false]);
        $erc20 = $investor->withdrawalDetails()->create(['currency' => 'USDT', 'network' => 'ERC20', 'address' => '0xLatest', 'is_active' => true]);
        $investor->withdrawalDetails()->create(['currency' => 'USDC', 'network' => 'TRC20', 'address' => 'TWrongCurrency', 'is_active' => true]);

        $service = app(InvestorWithdrawalDetailService::class);

        $this->assertTrue($erc20->is($service->latestActiveFor($account)));
        $this->assertSame('TOld', $service->latestActiveFor($account, 'TRC20')?->address);
        $this->assertNull($service->latestActiveFor($account, 'BEP20'));
    }

    public function test_admin_sees_only_current_investor_wallets_and_converts_only_approved_wallet(): void
    {
        [$admin, $investorUser, $investor, $account] = $this->context();
        [, , $otherInvestor] = $this->context('wallet-other');
        $approved = InvestorWallet::create([
            'investor_id' => $investor->id, 'currency' => 'USDT', 'network' => 'TRC20',
            'address' => 'TApprovedForConversion', 'label' => 'Основной кошелёк',
            'status' => 'approved', 'approved_by' => $admin->id, 'approved_at' => now(),
        ]);
        $pending = InvestorWallet::create([
            'investor_id' => $investor->id, 'currency' => 'USDT', 'network' => 'ERC20',
            'address' => '0xPendingWallet', 'label' => 'Новый кошелёк', 'status' => 'pending',
        ]);
        InvestorWallet::create([
            'investor_id' => $otherInvestor->id, 'currency' => 'USDT', 'network' => 'TRC20',
            'address' => 'TForeignWalletNotVisible', 'status' => 'approved',
        ]);
        $legacy = WithdrawalRequest::create([
            'investor_id' => $investor->id, 'investment_account_id' => $account->id,
            'investor_wallet_id' => $approved->id, 'type' => 'capital',
            'requested_amount' => '10.00000000', 'reserved_amount' => '10.00000000',
            'currency' => 'USDT', 'wallet_address_snapshot' => 'TLegacyFrozenSnapshot',
            'network_snapshot' => 'TRC20', 'status' => 'new', 'requested_at' => now(),
        ]);
        $walletBefore = $approved->fresh()->getAttributes();
        $legacyBefore = $legacy->fresh()->getAttributes();

        Livewire::actingAs($admin)->test(Show::class, ['investor' => $investor])
            ->call('selectTab', 'wallets')
            ->assertSee('Действующие кошельки инвестора')
            ->assertSee('Кошельки, добавленные инвестором и прошедшие проверку')
            ->assertSee('Основной кошелёк')->assertSee('TApprovedForConversion')
            ->assertSee('Новый кошелёк')->assertSee('Доступно после одобрения')
            ->assertDontSee('TForeignWalletNotVisible')
            ->assertSee('useWalletForWithdrawal('.$approved->id.')', false)
            ->assertDontSee('useWalletForWithdrawal('.$pending->id.')', false)
            ->call('useWalletForWithdrawal', $approved->id)
            ->assertHasNoErrors()
            ->assertSee('TApprovedForConversion');

        $this->assertDatabaseHas('investor_withdrawal_details', [
            'investor_id' => $investor->id, 'currency' => 'USDT', 'network' => 'TRC20',
            'address' => 'TApprovedForConversion', 'memo' => null, 'is_active' => true,
        ]);
        $this->assertSame($walletBefore, $approved->fresh()->getAttributes());
        $this->assertSame($legacyBefore, $legacy->fresh()->getAttributes());

        Livewire::actingAs($admin)->test(Show::class, ['investor' => $investor])
            ->call('useWalletForWithdrawal', $pending->id)
            ->assertHasErrors('investorWallet');
        $this->assertDatabaseMissing('investor_withdrawal_details', ['address' => '0xPendingWallet']);

        try {
            app(InvestorWithdrawalDetailService::class)->useApprovedWallet($approved, $investor, $investorUser);
            $this->fail('Investor must not convert wallets into permanent details.');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_duplicate_active_is_not_created_and_matching_inactive_detail_is_reactivated(): void
    {
        [$admin, , $investor] = $this->context();
        $wallet = InvestorWallet::create([
            'investor_id' => $investor->id, 'currency' => 'USDT', 'network' => 'TRC20',
            'address' => 'TDuplicateWallet', 'status' => 'approved', 'approved_at' => now(),
        ]);
        $existing = $investor->withdrawalDetails()->create([
            'currency' => 'USDT', 'network' => 'TRC20', 'address' => 'TDuplicateWallet',
            'memo' => 'Сохранить memo', 'is_active' => true,
        ]);

        $component = Livewire::actingAs($admin)->test(Show::class, ['investor' => $investor])
            ->call('selectTab', 'wallets')
            ->call('useWalletForWithdrawal', $wallet->id)
            ->assertSee('Эти реквизиты уже используются для вывода');
        $this->assertSame(1, InvestorWithdrawalDetail::where('investor_id', $investor->id)->count());
        $this->assertSame('Сохранить memo', $existing->fresh()->memo);

        $existing->update(['is_active' => false]);
        $component->call('useWalletForWithdrawal', $wallet->id);

        $this->assertSame(1, InvestorWithdrawalDetail::where('investor_id', $investor->id)->count());
        $this->assertTrue($existing->fresh()->is_active);
        $this->assertSame('Сохранить memo', $existing->fresh()->memo);
    }

    private function context(string $suffix = ''): array
    {
        $admin = User::factory()->create(['role' => 'admin', 'email' => "admin{$suffix}@example.com"]);
        $investorUser = User::factory()->create(['role' => 'investor', 'email' => "investor{$suffix}@example.com"]);
        $investor = Investor::create(['user_id' => $investorUser->id, 'status' => 'active']);
        $account = InvestmentAccount::create(['investor_id' => $investor->id, 'currency' => 'USDT', 'status' => 'active']);

        return [$admin, $investorUser, $investor, $account];
    }
}
