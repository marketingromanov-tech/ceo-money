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
}
