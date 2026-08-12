<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\DailyAccrual;
use App\Models\FeeRule;
use App\Models\InvestmentAccount;
use App\Models\InvestmentLot;
use App\Models\InvestmentTerm;
use App\Models\Investor;
use App\Models\InvestorWithdrawalDetail;
use App\Models\User;
use App\Services\AvailableBalanceService;
use App\Services\DividendCapitalizationService;
use App\Services\WithdrawalRequestService;
use Carbon\Carbon;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DividendCapitalizationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-10 12:30:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_success_creates_completed_capitalization_lot_transaction_and_audit(): void
    {
        [$account, $investor, $oldLot, $user] = $this->context();

        $capitalization = $this->service()->capitalize($account, '100.00000000', $user);

        $this->assertSame('completed', $capitalization->status);
        $this->assertSame($investor->id, $capitalization->investor_id);
        $this->assertSame('100.00000000', $capitalization->requested_amount);
        $this->assertSame('100.00000000', $capitalization->capitalized_amount);
        $this->assertSame('USDT', $capitalization->currency);
        $this->assertSame($user->id, $capitalization->created_by);

        $lot = $capitalization->investmentLot;
        $this->assertSame('100.00000000', $lot->original_amount);
        $this->assertSame('100.00000000', $lot->remaining_amount);
        $this->assertSame('2.7500', $lot->monthly_rate);
        $this->assertSame(4, $lot->lock_months);
        $this->assertSame('2026-12-10', $lot->unlock_date->toDateString());
        $this->assertSame('2026-08-11', $lot->accrual_start_date->toDateString());
        $this->assertSame('active', $lot->status);

        $transaction = $capitalization->investmentTransaction;
        $this->assertSame('capitalization', $transaction->type);
        $this->assertSame('100.00000000', $transaction->amount);
        $this->assertSame('confirmed', $transaction->status);
        $this->assertSame($lot->id, $transaction->investment_lot_id);
        $this->assertStringContainsString((string) $capitalization->id, $transaction->comment);

        $this->assertSame('500.00000000', $oldLot->fresh()->original_amount);
        $this->assertSame('500.00000000', $oldLot->fresh()->remaining_amount);
        $audit = AuditLog::where('action', 'dividend_capitalization')->sole();
        $this->assertSame($capitalization->id, $audit->entity_id);
        $this->assertSame('100.00000000', $audit->new_values['requested_amount']);
        $this->assertSame($lot->id, $audit->new_values['investment_lot_id']);
        $this->assertSame($transaction->id, $audit->new_values['investment_transaction_id']);
    }

    public function test_available_dividends_decrease_by_requested_amount_without_double_subtraction(): void
    {
        [$account] = $this->context();
        $this->fee('10.00000000', 'investor');

        $capitalization = $this->service()->capitalize($account, '100.00000000');

        $this->assertSame('10.00000000', $capitalization->fee_amount);
        $this->assertSame('90.00000000', $capitalization->capitalized_amount);
        $this->assertSame('900.00000000', app(AvailableBalanceService::class)->availableDividendBalance($account));
    }

    public function test_investor_fee_reduces_lot_and_fee_snapshot_is_saved(): void
    {
        [$account] = $this->context();
        $rule = $this->fee('12.50000000', 'investor');

        $capitalization = $this->service()->capitalize($account, '100.00000000');

        $this->assertSame($rule->id, $capitalization->fee_rule_id);
        $this->assertSame('12.50000000', $capitalization->fee_amount);
        $this->assertSame('investor', $capitalization->fee_payer);
        $this->assertSame('87.50000000', $capitalization->capitalized_amount);
        $this->assertSame('87.50000000', $capitalization->investmentLot->original_amount);
    }

    public function test_company_fee_is_saved_but_does_not_reduce_lot(): void
    {
        [$account] = $this->context();
        $rule = $this->fee('12.50000000', 'company');

        $capitalization = $this->service()->capitalize($account, '100.00000000');

        $this->assertSame($rule->id, $capitalization->fee_rule_id);
        $this->assertSame('12.50000000', $capitalization->fee_amount);
        $this->assertSame('company', $capitalization->fee_payer);
        $this->assertSame('100.00000000', $capitalization->capitalized_amount);
        $this->assertSame('100.00000000', $capitalization->investmentLot->original_amount);
        $this->assertSame('900.00000000', app(AvailableBalanceService::class)->availableDividendBalance($account));
    }

    public function test_zero_negative_and_excessive_amounts_are_rejected(): void
    {
        [$account] = $this->context();

        foreach (['0', '-0.00000001', '1000.00000001'] as $amount) {
            try {
                $this->service()->capitalize($account, $amount);
                $this->fail("Amount {$amount} should have been rejected.");
            } catch (DomainException) {
                $this->assertTrue(true);
            }
        }

        $this->assertDatabaseCount('dividend_capitalizations', 0);
        $this->assertDatabaseCount('investment_lots', 1);
    }

    public function test_fee_that_consumes_entire_amount_is_rejected_atomically(): void
    {
        [$account] = $this->context();
        $this->fee('100.00000000', 'investor');

        $this->expectException(DomainException::class);
        $this->service()->capitalize($account, '100.00000000');
    }

    public function test_inactive_account_or_investor_user_is_rejected(): void
    {
        [$account, , , $user] = $this->context();
        $account->update(['status' => 'inactive']);

        try {
            $this->service()->capitalize($account, '10.00000000', $user);
            $this->fail('Inactive account should have been rejected.');
        } catch (DomainException) {
            $this->assertTrue(true);
        }

        $account->update(['status' => 'active']);
        $user->update(['is_active' => false]);

        $this->expectException(DomainException::class);
        $this->service()->capitalize($account, '10.00000000', $user);
    }

    public function test_foreign_investor_is_rejected_and_admin_is_allowed(): void
    {
        [$account] = $this->context();
        $foreign = User::factory()->create(['role' => 'investor', 'is_active' => true]);
        Investor::create(['user_id' => $foreign->id, 'status' => 'active']);

        try {
            $this->service()->capitalize($account, '10.00000000', $foreign);
            $this->fail('Foreign investor should have been rejected.');
        } catch (DomainException) {
            $this->assertTrue(true);
        }

        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $capitalization = $this->service()->capitalize($account, '10.00000000', $admin);

        $this->assertSame($admin->id, $capitalization->created_by);
        $this->assertDatabaseCount('dividend_capitalizations', 1);
    }

    public function test_missing_active_account_term_is_rejected(): void
    {
        [$account] = $this->context(withTerm: false);

        $this->expectException(DomainException::class);
        $this->service()->capitalize($account, '10.00000000');
    }

    public function test_repeat_full_balance_request_cannot_double_spend(): void
    {
        [$account] = $this->context(accrued: '100.00000000');

        $first = $this->service()->capitalize($account, '100.00000000');

        try {
            $this->service()->capitalize($account, '100.00000000');
            $this->fail('Second request should not spend the same dividend balance.');
        } catch (DomainException) {
            $this->assertTrue(true);
        }

        $this->assertDatabaseCount('dividend_capitalizations', 1);
        $this->assertDatabaseCount('investment_lots', 2);
        $this->assertSame('0.00000000', app(AvailableBalanceService::class)->availableDividendBalance($account));
        $this->assertNotNull($first->investment_lot_id);
    }

    public function test_capitalized_dividends_cannot_be_reserved_for_withdrawal(): void
    {
        [$account] = $this->context(accrued: '100.00000000');
        $this->service()->capitalize($account, '80.00000000');

        $this->expectException(DomainException::class);
        app(WithdrawalRequestService::class)->createDividendRequest($account, '20.00000001');
    }

    private function service(): DividendCapitalizationService
    {
        return app(DividendCapitalizationService::class);
    }

    private function context(bool $withTerm = true, string $accrued = '1000.00000000'): array
    {
        $user = User::factory()->create(['role' => 'investor', 'is_active' => true]);
        $investor = Investor::create(['user_id' => $user->id, 'status' => 'active']);
        $account = InvestmentAccount::create([
            'investor_id' => $investor->id, 'currency' => 'USDT', 'status' => 'active',
        ]);
        InvestorWithdrawalDetail::create([
            'investor_id' => $investor->id, 'currency' => 'USDT', 'network' => 'TRC20',
            'address' => 'TDividendTestWithdrawal', 'is_active' => true,
        ]);
        $lot = InvestmentLot::create([
            'investment_account_id' => $account->id,
            'original_amount' => '500.00000000', 'remaining_amount' => '500.00000000',
            'currency' => 'USDT', 'received_at' => '2026-01-01',
            'accrual_start_date' => '2026-01-02', 'lock_months' => 1,
            'unlock_date' => '2026-02-01', 'monthly_rate' => '1.5000', 'status' => 'active',
        ]);
        DailyAccrual::create([
            'investment_account_id' => $account->id, 'investment_lot_id' => $lot->id,
            'accrual_date' => '2026-08-10', 'principal_amount' => '500.00000000',
            'monthly_rate' => '1.5000', 'days_in_month' => 31,
            'calculated_amount' => $accrued, 'adjustment_amount' => '0.00000000',
            'final_amount' => $accrued, 'status' => 'calculated', 'calculated_at' => now(),
        ]);

        if ($withTerm) {
            InvestmentTerm::create([
                'investment_account_id' => $account->id, 'monthly_rate' => '2.7500',
                'lock_months' => 4, 'valid_from' => '2026-01-01',
            ]);
        }

        return [$account, $investor, $lot, $user];
    }

    private function fee(string $amount, string $payer): FeeRule
    {
        return FeeRule::create([
            'operation_type' => 'capitalization', 'scope' => 'global',
            'currency' => 'USDT', 'fee_type' => 'fixed', 'fixed_value' => $amount,
            'payer' => $payer, 'valid_from' => '2026-01-01', 'is_active' => true,
        ]);
    }
}
