<?php

namespace Tests\Feature;

use App\Models\InvestmentAccount;
use App\Models\InvestmentLot;
use App\Models\Investor;
use App\Models\InvestorWallet;
use App\Models\User;
use App\Services\WalletService;
use App\Services\WithdrawalRequestService;
use Carbon\Carbon;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvestorWalletWithdrawalTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_blocked_and_archived_wallets_cannot_be_used(): void
    {
        [$account, $investor, $admin] = $this->context();
        $wallet = $this->wallet($investor);
        $walletService = app(WalletService::class);

        foreach (['pending', 'blocked', 'archived'] as $status) {
            if ($status === 'blocked') {
                $walletService->blockInvestorWallet($wallet, $admin);
            } elseif ($status === 'archived') {
                $walletService->archiveInvestorWallet($wallet, $admin);
            }

            try {
                $this->createCapitalRequest($account, $wallet);
                $this->fail("Expected {$status} wallet to be rejected.");
            } catch (DomainException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_approved_wallet_can_be_used_and_snapshot_does_not_change(): void
    {
        [$account, $investor, $admin] = $this->context();
        $wallet = app(WalletService::class)->approveInvestorWallet($this->wallet($investor), $admin);

        $request = $this->createCapitalRequest($account, $wallet);
        $wallet->update(['address' => 'changed-wallet', 'network' => 'ERC20']);

        $request->refresh();
        $this->assertSame($wallet->id, $request->investor_wallet_id);
        $this->assertSame('wallet-address', $request->wallet_address_snapshot);
        $this->assertSame('TRC20', $request->network_snapshot);
    }

    public function test_foreign_wallet_cannot_be_used(): void
    {
        [$account, , $admin] = $this->context();
        [, $otherInvestor] = $this->context();
        $wallet = app(WalletService::class)->approveInvestorWallet($this->wallet($otherInvestor), $admin);

        $this->expectException(DomainException::class);
        $this->createCapitalRequest($account, $wallet);
    }

    public function test_wallet_currency_and_network_must_match(): void
    {
        [$account, $investor, $admin] = $this->context();
        $service = app(WalletService::class);
        $wrongCurrency = $service->approveInvestorWallet($this->wallet($investor, [
            'currency' => 'BTC', 'address' => 'btc-wallet',
        ]), $admin);

        try {
            $this->createCapitalRequest($account, $wrongCurrency);
            $this->fail('Expected currency mismatch.');
        } catch (DomainException) {
            $this->assertTrue(true);
        }

        $wrongNetwork = $service->approveInvestorWallet($this->wallet($investor, [
            'network' => 'ERC20', 'address' => 'erc-wallet',
        ]), $admin);

        $this->expectException(DomainException::class);
        app(WithdrawalRequestService::class)->createCapitalRequest(
            $account, '10.00000000', network: 'TRC20', date: Carbon::today(), investorWallet: $wrongNetwork,
        );
    }

    private function createCapitalRequest(InvestmentAccount $account, InvestorWallet $wallet)
    {
        return app(WithdrawalRequestService::class)->createCapitalRequest(
            $account, '10.00000000', date: Carbon::today(), investorWallet: $wallet,
        );
    }

    private function context(): array
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $investor = Investor::create(['user_id' => User::factory()->create()->id, 'status' => 'active']);
        $account = InvestmentAccount::create(['investor_id' => $investor->id, 'currency' => 'USDT']);
        InvestmentLot::create([
            'investment_account_id' => $account->id,
            'original_amount' => '100.00000000', 'remaining_amount' => '100.00000000',
            'currency' => 'USDT', 'received_at' => '2026-01-01',
            'accrual_start_date' => '2026-01-01', 'monthly_rate' => '3.0000', 'status' => 'active',
        ]);

        return [$account, $investor, $admin];
    }

    private function wallet(Investor $investor, array $attributes = []): InvestorWallet
    {
        return InvestorWallet::create(array_merge([
            'investor_id' => $investor->id, 'currency' => 'USDT',
            'network' => 'TRC20', 'address' => 'wallet-address', 'status' => 'pending',
        ], $attributes));
    }
}
