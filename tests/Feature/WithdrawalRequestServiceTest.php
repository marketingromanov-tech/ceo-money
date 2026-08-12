<?php

namespace Tests\Feature;

use App\Models\DailyAccrual;
use App\Models\InvestmentAccount;
use App\Models\InvestmentLot;
use App\Models\InvestmentTerm;
use App\Models\Investor;
use App\Models\InvestorWithdrawalDetail;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\AvailableBalanceService;
use App\Services\WithdrawalRequestService;
use Carbon\Carbon;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WithdrawalRequestServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_dividend_cannot_exceed_available_balance(): void
    {
        [$account] = $this->context();
        $this->accrual($account, '100.00000000');

        $this->expectException(DomainException::class);
        app(WithdrawalRequestService::class)->createDividendRequest($account, '100.00000001');
    }

    public function test_active_request_reserves_dividends_and_cancelled_request_releases_them(): void
    {
        [$account] = $this->context();
        $this->accrual($account, '100.00000000');
        $request = app(WithdrawalRequestService::class)->createDividendRequest($account, '40.00000000');
        $balances = app(AvailableBalanceService::class);

        $this->assertSame('60.00000000', $balances->availableDividendBalance($account));
        $request->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        $this->assertSame('100.00000000', $balances->availableDividendBalance($account));
    }

    public function test_paid_dividend_is_counted_as_paid_not_reserved(): void
    {
        [$account] = $this->context();
        $this->accrual($account, '100.00000000');
        $request = app(WithdrawalRequestService::class)->createDividendRequest($account, '40.00000000');
        $request->update(['status' => 'paid', 'paid_at' => now()]);

        $this->assertSame('60.00000000', app(AvailableBalanceService::class)->availableDividendBalance($account));
    }

    public function test_review_and_approved_reserve_while_rejected_does_not(): void
    {
        [$account, $investor] = $this->context();
        $this->accrual($account, '100.00000000');

        foreach (['review', 'approved', 'rejected'] as $status) {
            WithdrawalRequest::create([
                'investor_id' => $investor->id,
                'investment_account_id' => $account->id,
                'type' => 'dividend',
                'requested_amount' => '10.00000000',
                'reserved_amount' => '10.00000000',
                'currency' => 'USDT',
                'status' => $status,
                'requested_at' => now(),
            ]);
        }

        $this->assertSame('80.00000000', app(AvailableBalanceService::class)->availableDividendBalance($account));
    }

    public function test_minimum_dividend_withdrawal_is_enforced(): void
    {
        [$account] = $this->context();
        $this->accrual($account, '100.00000000');
        $this->term($account, ['minimum_dividend_withdrawal' => '25.00000000']);

        $this->expectException(DomainException::class);
        app(WithdrawalRequestService::class)->createDividendRequest($account, '20.00000000');
    }

    public function test_locked_lot_is_unavailable_and_unlocked_lot_is_available(): void
    {
        [$account] = $this->context();
        $this->lot($account, ['remaining_amount' => '100.00000000', 'unlock_date' => '2026-08-11']);
        $balances = app(AvailableBalanceService::class);

        $this->assertSame('0.00000000', $balances->availableCapitalForWithdrawal($account, Carbon::parse('2026-08-10')));
        $this->assertSame('100.00000000', $balances->availableCapitalForWithdrawal($account, Carbon::parse('2026-08-11')));
    }

    public function test_active_capital_request_reduces_available_capital_without_changing_lot(): void
    {
        [$account] = $this->context();
        $lot = $this->lot($account, ['remaining_amount' => '100.00000000', 'unlock_date' => null]);

        app(WithdrawalRequestService::class)->createCapitalRequest(
            $account, '40.00000000', date: Carbon::parse('2026-08-10'),
        );

        $this->assertSame('60.00000000', app(AvailableBalanceService::class)
            ->availableCapitalForWithdrawal($account, Carbon::parse('2026-08-10')));
        $this->assertSame('100.00000000', $lot->fresh()->remaining_amount);
    }

    public function test_minimum_balance_is_enforced(): void
    {
        [$account] = $this->context();
        $this->lot($account, ['remaining_amount' => '100.00000000', 'unlock_date' => null]);
        $this->term($account, ['minimum_balance' => '25.00000000']);

        $this->expectException(DomainException::class);
        app(WithdrawalRequestService::class)->createCapitalRequest(
            $account, '80.00000000', date: Carbon::parse('2026-08-10'),
        );
    }

    public function test_partial_withdrawal_can_be_disallowed(): void
    {
        [$account] = $this->context();
        $this->lot($account, ['remaining_amount' => '100.00000000', 'unlock_date' => null]);
        $this->term($account, ['partial_withdrawal_allowed' => false]);

        $this->expectException(DomainException::class);
        app(WithdrawalRequestService::class)->createCapitalRequest(
            $account, '50.00000000', date: Carbon::parse('2026-08-10'),
        );
    }

    private function context(): array
    {
        $investor = Investor::create([
            'user_id' => User::factory()->create(['role' => 'investor'])->id,
            'status' => 'active',
        ]);
        $account = InvestmentAccount::create([
            'investor_id' => $investor->id, 'currency' => 'USDT', 'status' => 'active',
        ]);
        InvestorWithdrawalDetail::create([
            'investor_id' => $investor->id, 'currency' => 'USDT', 'network' => 'TRC20',
            'address' => 'TServiceWithdrawalAddress', 'is_active' => true,
        ]);

        return [$account, $investor];
    }

    private function lot(InvestmentAccount $account, array $attributes = []): InvestmentLot
    {
        return InvestmentLot::create(array_merge([
            'investment_account_id' => $account->id,
            'original_amount' => '100.00000000',
            'remaining_amount' => '100.00000000',
            'currency' => 'USDT',
            'received_at' => '2026-01-01 00:00:00',
            'accrual_start_date' => '2026-01-01',
            'monthly_rate' => '3.0000',
            'status' => 'active',
        ], $attributes));
    }

    private function accrual(InvestmentAccount $account, string $amount): DailyAccrual
    {
        $lot = $this->lot($account);

        return DailyAccrual::create([
            'investment_account_id' => $account->id,
            'investment_lot_id' => $lot->id,
            'accrual_date' => '2026-08-01',
            'principal_amount' => '100.00000000',
            'monthly_rate' => '3.0000',
            'days_in_month' => 31,
            'calculated_amount' => $amount,
            'final_amount' => $amount,
        ]);
    }

    private function term(InvestmentAccount $account, array $attributes): InvestmentTerm
    {
        return InvestmentTerm::create(array_merge([
            'investment_account_id' => $account->id,
            'monthly_rate' => '3.0000',
            'valid_from' => '2020-01-01',
        ], $attributes));
    }
}
