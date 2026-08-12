<?php

namespace Tests\Feature;

use App\Models\CapitalWithdrawalAllocation;
use App\Models\InvestmentAccount;
use App\Models\InvestmentLot;
use App\Models\InvestmentTerm;
use App\Models\InvestmentTransaction;
use App\Models\Investor;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\AvailableBalanceService;
use App\Services\CapitalWithdrawalService;
use Carbon\Carbon;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CapitalWithdrawalPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_fifo_allocates_across_lots_closes_first_and_is_idempotent(): void
    {
        [$account, $investor] = $this->context();
        $first = $this->lot($account, '2026-01-01', '60.00000000');
        $second = $this->lot($account, '2026-02-01', '100.00000000');
        $request = $this->request($account, $investor, '100.00000000', '7.00000000');
        $service = app(CapitalWithdrawalService::class);

        $service->pay($request, txid: 'capital-tx');
        $service->pay($request, txid: 'ignored-second-call');

        $this->assertSame('0.00000000', $first->fresh()->remaining_amount);
        $this->assertSame('closed', $first->fresh()->status);
        $this->assertSame('60.00000000', $second->fresh()->remaining_amount);
        $this->assertSame('60.00000000', CapitalWithdrawalAllocation::where('investment_lot_id', $first->id)->value('amount'));
        $this->assertSame('40.00000000', CapitalWithdrawalAllocation::where('investment_lot_id', $second->id)->value('amount'));
        $this->assertDatabaseCount('capital_withdrawal_allocations', 2);
        $this->assertDatabaseCount('investment_transactions', 1);
        $this->assertSame('100.00000000', InvestmentTransaction::first()->amount);
        $this->assertSame('paid', $request->fresh()->status);
        $this->assertSame('capital-tx', $request->fresh()->txid);
        $this->assertDatabaseHas('audit_logs', ['action' => 'withdrawal.capital_paid', 'entity_id' => $request->id]);
        $this->assertSame('60.00000000', app(AvailableBalanceService::class)
            ->availableCapitalForWithdrawal($account, Carbon::today()));
    }

    public function test_partial_withdrawal_only_reduces_first_fifo_lot(): void
    {
        [$account, $investor] = $this->context();
        $first = $this->lot($account, '2026-01-01', '100.00000000');
        $second = $this->lot($account, '2026-02-01', '100.00000000');

        app(CapitalWithdrawalService::class)->pay($this->request($account, $investor, '40.00000000'));

        $this->assertSame('60.00000000', $first->fresh()->remaining_amount);
        $this->assertSame('100.00000000', $second->fresh()->remaining_amount);
        $this->assertDatabaseCount('capital_withdrawal_allocations', 1);
    }

    public function test_insufficient_capital_rolls_back_everything(): void
    {
        [$account, $investor] = $this->context();
        $lot = $this->lot($account, '2026-01-01', '50.00000000');
        $request = $this->request($account, $investor, '60.00000000');

        try {
            app(CapitalWithdrawalService::class)->pay($request);
            $this->fail('Expected insufficient capital exception.');
        } catch (DomainException) {
            $this->assertSame('50.00000000', $lot->fresh()->remaining_amount);
            $this->assertSame('approved', $request->fresh()->status);
            $this->assertDatabaseCount('capital_withdrawal_allocations', 0);
            $this->assertDatabaseCount('investment_transactions', 0);
        }
    }

    public function test_locked_lot_cannot_be_used_for_payment(): void
    {
        [$account, $investor] = $this->context();
        $this->lot($account, '2026-01-01', '100.00000000', Carbon::today()->addDay()->toDateString());

        $this->expectException(DomainException::class);
        app(CapitalWithdrawalService::class)->pay($this->request($account, $investor, '50.00000000'));
    }

    public function test_minimum_balance_is_rechecked_at_payment_time(): void
    {
        [$account, $investor] = $this->context();
        $lot = $this->lot($account, '2026-01-01', '100.00000000');
        $request = $this->request($account, $investor, '80.00000000');
        InvestmentTerm::create([
            'investment_account_id' => $account->id,
            'monthly_rate' => '3.0000',
            'minimum_balance' => '25.00000000',
            'valid_from' => '2020-01-01',
        ]);

        try {
            app(CapitalWithdrawalService::class)->pay($request);
            $this->fail('Expected minimum balance exception.');
        } catch (DomainException) {
            $this->assertSame('100.00000000', $lot->fresh()->remaining_amount);
            $this->assertSame('approved', $request->fresh()->status);
            $this->assertDatabaseCount('capital_withdrawal_allocations', 0);
        }
    }

    private function context(): array
    {
        $investor = Investor::create(['user_id' => User::factory()->create()->id, 'status' => 'active']);
        $account = InvestmentAccount::create(['investor_id' => $investor->id, 'currency' => 'USDT']);

        return [$account, $investor];
    }

    private function lot(InvestmentAccount $account, string $receivedAt, string $amount, ?string $unlockDate = null): InvestmentLot
    {
        return InvestmentLot::create([
            'investment_account_id' => $account->id,
            'original_amount' => $amount, 'remaining_amount' => $amount, 'currency' => 'USDT',
            'received_at' => $receivedAt, 'accrual_start_date' => '2026-01-01',
            'unlock_date' => $unlockDate, 'monthly_rate' => '3.0000', 'status' => 'active',
        ]);
    }

    private function request(InvestmentAccount $account, Investor $investor, string $amount, string $fee = '0'): WithdrawalRequest
    {
        return WithdrawalRequest::create([
            'investor_id' => $investor->id, 'investment_account_id' => $account->id,
            'type' => 'capital', 'requested_amount' => $amount, 'reserved_amount' => $amount,
            'fee_amount' => $fee, 'net_amount' => '1.00000000', 'currency' => 'USDT',
            'status' => 'approved', 'requested_at' => now(), 'approved_at' => now(),
        ]);
    }
}
