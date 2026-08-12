<?php

namespace Tests\Feature;

use App\Models\DailyAccrual;
use App\Models\DividendPayment;
use App\Models\InvestmentAccount;
use App\Models\InvestmentLot;
use App\Models\Investor;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\AvailableBalanceService;
use App\Services\DividendWithdrawalService;
use App\Services\WithdrawalVerificationService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DividendWithdrawalPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_uses_snapshots_does_not_change_lot_and_is_idempotent(): void
    {
        [$account, $investor, $lot] = $this->context();
        $request = WithdrawalRequest::create([
            'investor_id' => $investor->id, 'investment_account_id' => $account->id,
            'type' => 'dividend', 'requested_amount' => '40.00000000',
            'reserved_amount' => '40.00000000', 'fee_amount' => '5.00000000',
            'fee_payer' => 'investor', 'net_amount' => '35.00000000', 'currency' => 'USDT',
            'wallet_address_snapshot' => 'wallet-1', 'network_snapshot' => 'TRC20',
            'status' => 'approved', 'requested_at' => now(), 'approved_at' => now(),
        ]);
        $service = app(DividendWithdrawalService::class);
        $this->completeVerification($request);

        $first = $service->pay($request, txid: 'dividend-tx');
        $second = $service->pay($request);

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('dividend_payments', 1);
        $this->assertSame('40.00000000', $first->gross_amount);
        $this->assertSame('5.00000000', $first->fee_amount);
        $this->assertSame('35.00000000', $first->net_amount);
        $this->assertSame('wallet-1', $first->wallet_address);
        $this->assertSame('TRC20', $first->network);
        $this->assertSame('100.00000000', $lot->fresh()->remaining_amount);
        $this->assertSame('paid', $request->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'withdrawal.dividend_paid', 'entity_id' => $request->id]);
        $this->assertSame('60.00000000', app(AvailableBalanceService::class)->availableDividendBalance($account));
        $this->assertDatabaseCount('daily_accruals', 1);
    }

    public function test_payment_rejects_non_admin_and_inactive_admin_actors(): void
    {
        [$account, $investor] = $this->context();
        $request = WithdrawalRequest::create([
            'investor_id' => $investor->id, 'investment_account_id' => $account->id,
            'type' => 'dividend', 'requested_amount' => '40.00000000', 'reserved_amount' => '40.00000000',
            'fee_amount' => '0.00000000', 'net_amount' => '40.00000000', 'currency' => 'USDT',
            'wallet_address_snapshot' => 'wallet-1', 'network_snapshot' => 'TRC20',
            'status' => 'approved', 'requested_at' => now(), 'approved_at' => now(),
        ]);
        $this->completeVerification($request);

        foreach ([
            User::factory()->create(['role' => 'investor', 'is_active' => true]),
            User::factory()->create(['role' => 'admin', 'is_active' => false]),
        ] as $actor) {
            try {
                app(DividendWithdrawalService::class)->pay($request, $actor);
                $this->fail('Invalid actor must not pay a dividend withdrawal.');
            } catch (DomainException) {
                $this->assertSame('approved', $request->fresh()->status);
            }
        }

        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        app(DividendWithdrawalService::class)->pay($request, $admin);
        $this->assertSame('paid', $request->fresh()->status);
    }

    public function test_incomplete_verification_blocks_payment_without_financial_effects(): void
    {
        [$account, $investor] = $this->context();
        $request = WithdrawalRequest::create([
            'investor_id' => $investor->id, 'investment_account_id' => $account->id,
            'type' => 'dividend', 'requested_amount' => '40.00000000', 'reserved_amount' => '40.00000000',
            'fee_amount' => '0.00000000', 'net_amount' => '40.00000000', 'currency' => 'USDT',
            'wallet_address_snapshot' => 'wallet-1', 'network_snapshot' => 'TRC20',
            'status' => 'approved', 'requested_at' => now(), 'approved_at' => now(),
        ]);

        $this->expectException(DomainException::class);
        try {
            app(DividendWithdrawalService::class)->pay($request, txid: 'unverified-tx');
        } finally {
            $this->assertDatabaseCount('dividend_payments', 0);
            $this->assertSame('approved', $request->fresh()->status);
        }
    }

    public function test_txid_is_normalized_and_duplicate_is_rejected(): void
    {
        [$account, $investor] = $this->context();
        $makeRequest = fn () => WithdrawalRequest::create([
            'investor_id' => $investor->id, 'investment_account_id' => $account->id,
            'type' => 'dividend', 'requested_amount' => '20.00000000', 'reserved_amount' => '20.00000000',
            'fee_amount' => '0.00000000', 'net_amount' => '20.00000000', 'currency' => 'USDT',
            'wallet_address_snapshot' => 'wallet-1', 'network_snapshot' => 'TRC20',
            'status' => 'approved', 'requested_at' => now(), 'approved_at' => now(),
        ]);
        $first = $makeRequest();
        $this->completeVerification($first);
        app(DividendWithdrawalService::class)->pay($first, txid: '  AbC-123  ');
        $this->assertSame('abc-123', $first->fresh()->txid);

        $second = $makeRequest();
        $this->completeVerification($second);
        try {
            app(DividendWithdrawalService::class)->pay($second, txid: 'ABC-123');
            $this->fail('Duplicate withdrawal TXID must be rejected.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('TXID', $exception->getMessage());
            $this->assertSame('approved', $second->fresh()->status);
            $this->assertDatabaseCount('dividend_payments', 1);
        }
    }

    private function context(): array
    {
        $investor = Investor::create(['user_id' => User::factory()->create()->id, 'status' => 'active']);
        $account = InvestmentAccount::create(['investor_id' => $investor->id, 'currency' => 'USDT']);
        $lot = InvestmentLot::create([
            'investment_account_id' => $account->id,
            'original_amount' => '100.00000000', 'remaining_amount' => '100.00000000',
            'received_at' => '2026-01-01', 'accrual_start_date' => '2026-01-01',
            'monthly_rate' => '3.0000', 'status' => 'active',
        ]);
        DailyAccrual::create([
            'investment_account_id' => $account->id, 'investment_lot_id' => $lot->id,
            'accrual_date' => '2026-08-01', 'principal_amount' => '100.00000000',
            'monthly_rate' => '3.0000', 'days_in_month' => 31,
            'calculated_amount' => '100.00000000', 'final_amount' => '100.00000000',
        ]);

        return [$account, $investor, $lot];
    }

    private function completeVerification(WithdrawalRequest $request): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $verification = app(WithdrawalVerificationService::class);
        $verification->initializeForRequest($request);
        foreach (WithdrawalVerificationService::MANUAL_KEYS as $key) {
            $verification->markPassed($request, $key, $admin);
        }
    }
}
