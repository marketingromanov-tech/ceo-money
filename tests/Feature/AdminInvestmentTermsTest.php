<?php

namespace Tests\Feature;

use App\Livewire\Admin\Investors\Show;
use App\Models\AuditLog;
use App\Models\DailyAccrual;
use App\Models\DepositRequest;
use App\Models\InvestmentAccount;
use App\Models\InvestmentLot;
use App\Models\InvestmentTerm;
use App\Models\Investor;
use App\Models\User;
use App\Services\DailyAccrualService;
use App\Services\DepositRequestService;
use App\Services\DepositVerificationService;
use App\Services\DividendCapitalizationService;
use App\Services\InvestmentTermService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminInvestmentTermsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-10 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_only_admin_can_open_and_manage_investor_terms(): void
    {
        [$admin, $investorUser, $investor] = $this->context();

        $this->actingAs($admin)->get(route('admin.investors.show', $investor))->assertOk();
        $this->actingAs($investorUser)->get(route('admin.investors.show', $investor))->assertForbidden();
    }

    public function test_terms_tab_shows_current_history_and_russian_presentation(): void
    {
        [$admin, , $investor, $account] = $this->context();
        $term = $this->term($account, ['created_by' => $admin->id]);

        Livewire::actingAs($admin)->test(Show::class, ['investor' => $investor])
            ->call('selectTab', 'terms')
            ->assertSee('Текущие условия')
            ->assertSee('История условий')
            ->assertSee('Капитал доступен к выводу через')
            ->assertDontSee('Срок блокировки новых инвестиций')
            ->assertDontSee('Условия новых инвестиций')
            ->assertSee('2.50%')
            ->assertSee('3 месяца')
            ->assertSee('Разрешён')
            ->assertSee('Действует')
            ->assertSee($term->valid_from->format('d.m.Y'));
    }

    public function test_admin_can_create_first_term_with_all_conditions_and_audit(): void
    {
        [$admin, , $investor, $account] = $this->context();

        Livewire::actingAs($admin)->test(Show::class, ['investor' => $investor])
            ->call('openTermsModal')
            ->set('termMonthlyRate', '3.1250')
            ->set('termLockMonths', 6)
            ->set('termMinimumBalance', '100.00000000')
            ->set('termPartialWithdrawalAllowed', false)
            ->set('termMinimumDividendWithdrawal', '50.00000000')
            ->set('termValidFrom', '2026-08-15')
            ->call('saveTerms')
            ->assertHasNoErrors();

        $term = $account->investmentTerms()->sole();
        $this->assertSame('3.1250', $term->monthly_rate);
        $this->assertSame(6, $term->lock_months);
        $this->assertSame('100.00000000', $term->minimum_balance);
        $this->assertFalse($term->partial_withdrawal_allowed);
        $this->assertSame('50.00000000', $term->minimum_dividend_withdrawal);
        $this->assertSame($admin->id, $term->created_by);
        $audit = AuditLog::where('action', 'investment_term_created')->sole();
        $this->assertSame($admin->id, $audit->user_id);
        $this->assertSame($account->investor_id, $audit->new_values['investor_id']);
    }

    public function test_new_term_closes_previous_day_and_future_term_is_scheduled(): void
    {
        [$admin, , , $account] = $this->context();
        $old = $this->term($account, ['valid_from' => '2026-03-01']);

        $new = app(InvestmentTermService::class)->createAccountTerm($account, $this->conditions([
            'monthly_rate' => '3.0000', 'lock_months' => 6, 'valid_from' => '2026-08-15',
        ]), $admin);

        $this->assertSame('2026-08-14', $old->fresh()->valid_to->toDateString());
        $this->assertSame('2026-08-15', $new->valid_from->toDateString());
        $this->assertNull($new->valid_to);
        $this->assertTrue($new->valid_from->isFuture());
    }

    public function test_validation_rejects_negative_or_non_decimal_conditions(): void
    {
        [$admin, , $investor] = $this->context();

        Livewire::actingAs($admin)->test(Show::class, ['investor' => $investor])
            ->call('openTermsModal')
            ->set('termMonthlyRate', '-1')
            ->set('termLockMonths', -1)
            ->set('termMinimumBalance', '-0.1')
            ->set('termMinimumDividendWithdrawal', 'not-a-decimal')
            ->set('termValidFrom', '')
            ->call('saveTerms')
            ->assertHasErrors([
                'termMonthlyRate', 'termLockMonths', 'termMinimumBalance',
                'termMinimumDividendWithdrawal', 'termValidFrom',
            ]);
    }

    public function test_old_lot_keeps_rate_and_unlock_date_after_account_term_changes(): void
    {
        [$admin, , , $account] = $this->context();
        $this->term($account, ['monthly_rate' => '2.5000', 'lock_months' => 3, 'valid_from' => '2026-08-01']);
        $oldLot = $this->lot($account, [
            'monthly_rate' => '2.5000', 'lock_months' => 3, 'unlock_date' => '2026-11-01',
        ]);

        app(InvestmentTermService::class)->createAccountTerm($account, $this->conditions([
            'monthly_rate' => '3.0000', 'lock_months' => 6, 'valid_from' => '2026-08-15',
        ]), $admin);
        $accrual = app(DailyAccrualService::class)->calculateForLot($oldLot, Carbon::parse('2026-08-20'));

        $this->assertSame('2.5000', $oldLot->fresh()->monthly_rate);
        $this->assertSame(3, $oldLot->fresh()->lock_months);
        $this->assertSame('2026-11-01', $oldLot->fresh()->unlock_date->toDateString());
        $this->assertSame('2.5000', $accrual->monthly_rate);
    }

    public function test_deposit_after_new_valid_from_snapshots_new_rate_lock_and_unlock(): void
    {
        [$admin, , , $account] = $this->context();
        $this->term($account, ['monthly_rate' => '2.5000', 'lock_months' => 3, 'valid_from' => '2026-01-01']);
        app(InvestmentTermService::class)->createAccountTerm($account, $this->conditions([
            'monthly_rate' => '3.0000', 'lock_months' => 6, 'valid_from' => '2026-08-15',
        ]), $admin);
        Carbon::setTestNow('2026-08-20 12:00:00');
        $request = DepositRequest::create([
            'investor_id' => $account->investor_id, 'investment_account_id' => $account->id,
            'requested_amount' => '1000.00000000', 'currency' => 'USDT', 'status' => 'submitted',
            'received_amount' => '1000.00000000',
            'requested_at' => now(),
        ]);

        $fee=app(DepositRequestService::class)->preview($request,'1000.00000000');$request->update(['txid'=>'terms-deposit-'.$request->id,'fee_amount'=>$fee['fee_amount'],'fee_payer'=>$fee['payer'],'net_investment_amount'=>$fee['net_amount']]);
        $verification=app(DepositVerificationService::class);$verification->initializeForRequest($request);foreach(DepositVerificationService::MANUAL_KEYS as $key)$verification->markPassed($request,$key,$admin);
        app(DepositRequestService::class)->confirm($request, $admin);
        $lot = $account->investmentLots()->sole();
        $this->assertSame('3.0000', $lot->monthly_rate);
        $this->assertSame(6, $lot->lock_months);
        $this->assertSame('2027-02-20', $lot->unlock_date->toDateString());
    }

    public function test_capitalization_after_new_valid_from_snapshots_new_conditions(): void
    {
        [$admin, $investorUser, , $account] = $this->context();
        $this->term($account, ['monthly_rate' => '2.5000', 'lock_months' => 3, 'valid_from' => '2026-01-01']);
        app(InvestmentTermService::class)->createAccountTerm($account, $this->conditions([
            'monthly_rate' => '3.0000', 'lock_months' => 6, 'valid_from' => '2026-08-15',
        ]), $admin);
        $oldLot = $this->lot($account);
        DailyAccrual::create([
            'investment_account_id' => $account->id, 'investment_lot_id' => $oldLot->id,
            'accrual_date' => '2026-08-19', 'principal_amount' => '10000.00000000',
            'monthly_rate' => '2.5000', 'days_in_month' => 31, 'calculated_amount' => '100.00000000',
            'adjustment_amount' => '0.00000000', 'final_amount' => '100.00000000', 'status' => 'calculated',
        ]);
        Carbon::setTestNow('2026-08-20 12:00:00');

        $capitalization = app(DividendCapitalizationService::class)->capitalize($account, '100.00000000', $investorUser);
        $lot = $capitalization->investmentLot;
        $this->assertSame('3.0000', $lot->monthly_rate);
        $this->assertSame(6, $lot->lock_months);
        $this->assertSame('2027-02-20', $lot->unlock_date->toDateString());
        $this->assertSame('2.5000', $oldLot->fresh()->monthly_rate);
    }

    private function context(): array
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $investorUser = User::factory()->create(['role' => 'investor', 'is_active' => true]);
        $investor = Investor::create(['user_id' => $investorUser->id, 'status' => 'active']);
        $account = InvestmentAccount::create(['investor_id' => $investor->id, 'currency' => 'USDT', 'status' => 'active']);

        return [$admin, $investorUser, $investor, $account];
    }

    private function term(InvestmentAccount $account, array $attributes = []): InvestmentTerm
    {
        return $account->investmentTerms()->create(array_merge($this->conditions(), $attributes));
    }

    private function conditions(array $attributes = []): array
    {
        return array_merge([
            'monthly_rate' => '2.5000', 'lock_months' => 3,
            'minimum_balance' => '0.00000000', 'partial_withdrawal_allowed' => true,
            'minimum_dividend_withdrawal' => '50.00000000', 'valid_from' => '2026-01-01',
        ], $attributes);
    }

    private function lot(InvestmentAccount $account, array $attributes = []): InvestmentLot
    {
        return $account->investmentLots()->create(array_merge([
            'original_amount' => '10000.00000000', 'remaining_amount' => '10000.00000000',
            'currency' => 'USDT', 'received_at' => '2026-08-01', 'accrual_start_date' => '2026-08-01',
            'lock_months' => 3, 'unlock_date' => '2026-11-01', 'monthly_rate' => '2.5000', 'status' => 'active',
        ], $attributes));
    }
}
