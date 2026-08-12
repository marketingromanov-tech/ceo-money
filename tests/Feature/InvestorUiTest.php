<?php

namespace Tests\Feature;

use App\Livewire\Investor\Accruals;
use App\Livewire\Investor\Dashboard;
use App\Livewire\Investor\Finance;
use App\Livewire\Investor\Wallets;
use App\Livewire\Investor\Withdrawals;
use App\Models\DailyAccrual;
use App\Models\DividendCapitalization;
use App\Models\FeeRule;
use App\Models\InvestmentAccount;
use App\Models\InvestmentLot;
use App\Models\InvestmentTerm;
use App\Models\InvestmentTransaction;
use App\Models\Investor;
use App\Models\InvestorPaymentDetail;
use App\Models\InvestorWithdrawalDetail;
use App\Models\InvestorWallet;
use App\Models\User;
use App\Models\WithdrawalRequest;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class InvestorUiTest extends TestCase
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

    public function test_investor_routes_render_only_investor_navigation(): void
    {
        [$user] = $this->context(); $this->actingAs($user);

        foreach (['/dashboard', '/accruals', '/finance', '/withdrawals', '/wallets'] as $uri) {
            $this->get($uri)->assertOk()->assertSee('CEO Money')->assertDontSee('Admin panel');
        }
        $this->get('/admin')->assertForbidden();
    }

    public function test_dashboard_period_modes_and_inclusive_custom_range_are_exact(): void
    {
        [$user, $account, $lot] = $this->context();
        $this->accrual($account, $lot, '2026-07-31', '1.00000000');
        $this->accrual($account, $lot, '2026-08-01', '2.00000000');
        $this->accrual($account, $lot, '2026-08-10', '3.00000000');
        $this->actingAs($user);

        Livewire::test(Dashboard::class)
            ->call('selectPeriod', 'today')->assertViewHas('summary', fn ($v) => $v['period'] === '3.00000000')->assertDontSee('За период 10.08.2026')
            ->call('selectPeriod', 'month')->assertViewHas('summary', fn ($v) => $v['period'] === '5.00000000')->assertDontSee('За период 01.08.2026')
            ->call('selectPeriod', 'all')->assertViewHas('summary', fn ($v) => $v['period'] === '6.00000000')
            ->call('selectPeriod', 'custom')->set('periodFrom', '2026-07-31')->set('periodTo', '2026-08-01')
            ->call('applyPeriod')
            ->assertViewHas('summary', fn ($v) => $v['period'] === '3.00000000')
            ->assertSee('За период 31.07.2026 — 01.08.2026')
            ->call('resetPeriod')->assertSet('periodMode', 'month')->assertDontSee('За период 31.07.2026');
    }

    public function test_custom_range_sums_multiple_lots_on_same_date_and_empty_range_is_zero(): void
    {
        [$user, $account, $lot] = $this->context();
        $second = $this->lot($account, '250.00000000', '2.0000');
        $this->accrual($account, $lot, '2026-08-05', '1.25000000');
        $this->accrual($account, $second, '2026-08-05', '2.75000000');
        $this->actingAs($user);

        Livewire::test(Dashboard::class)->call('selectPeriod', 'custom')
            ->set('periodFrom', '2026-08-05')->set('periodTo', '2026-08-05')
            ->assertViewHas('summary', fn ($v) => $v['period'] === '4.00000000')
            ->set('periodFrom', '2026-08-06')->set('periodTo', '2026-08-07')
            ->assertViewHas('summary', fn ($v) => $v['period'] === '0');
    }

    public function test_custom_period_rejects_reversed_and_future_ranges(): void
    {
        [$user] = $this->context(); $this->actingAs($user);

        Livewire::test(Dashboard::class)->call('selectPeriod', 'custom')
            ->set('periodFrom', '2026-08-09')->set('periodTo', '2026-08-08')
            ->assertHasErrors('periodFrom');
        Livewire::test(Dashboard::class)->call('selectPeriod', 'custom')
            ->set('periodFrom', '2026-08-10')->set('periodTo', '2026-08-11')
            ->assertHasErrors('periodTo');
    }

    public function test_accruals_page_uses_russian_month_and_exact_month_summary(): void
    {
        [$user, $account, $lot] = $this->context();
        $this->accrual($account, $lot, '2026-08-01', '1.25000000');
        $this->accrual($account, $lot, '2026-08-02', '2.75000000');
        $this->actingAs($user);

        Livewire::test(Accruals::class)
            ->assertSee('Август 2026')
            ->assertViewHas('monthSummary', fn ($v) => $v['count'] === 2 && $v['total'] === '4.00000000')
            ->assertSee('Записей:')->assertSee('Итого:')
            ->call('previousMonth')->assertSet('selectedMonth', '2026-07')->assertSee('Июль 2026')
            ->call('nextMonth')->assertSet('selectedMonth', '2026-08')->assertSee('Август 2026');
    }

    public function test_accrual_chart_summary_handles_equal_values_and_one_day_exactly(): void
    {
        [$user, $account, $lot] = $this->context();
        $this->accrual($account, $lot, '2026-08-08', '10.00000000');
        $this->accrual($account, $lot, '2026-08-09', '10.00000000');
        $this->actingAs($user);

        Livewire::test(Accruals::class)->call('selectPeriod', 'custom')
            ->set('periodFrom', '2026-08-08')->set('periodTo', '2026-08-09')
            ->assertViewHas('chartSummary', fn ($v) => $v === ['total'=>'20.00000000','average'=>'10.00000000','days'=>2])
            ->assertViewHas('chartPoints', fn ($v) => $v === '10,110 890,110')
            ->set('periodFrom', '2026-08-08')->set('periodTo', '2026-08-08')
            ->assertViewHas('chartSummary', fn ($v) => $v === ['total'=>'10.00000000','average'=>'10.00000000','days'=>1])
            ->assertViewHas('chartPoints', fn ($v) => $v === '10,110');
    }

    public function test_multiple_lots_stay_separate_in_table_and_sum_into_daily_chart_point(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');
        [$user, $account, $mainLot] = $this->context();
        $mainLot->update(['original_amount'=>'25000.00000000','remaining_amount'=>'25000.00000000','monthly_rate'=>'2.5000']);
        [, $foreignAccount] = $this->context('accrual-number-gap@example.com');
        $this->lot($foreignAccount, '100.00000000', '1.0000');
        $capitalizedLot = $this->lot($account, '400.00000000', '2.5000');
        $capitalizedLot->update(['accrual_start_date'=>'2026-08-11']);
        $this->accrual($account, $mainLot, '2026-08-10', '20.16129032');
        $this->accrual($account, $mainLot, '2026-08-11', '20.16129032');
        $this->accrual($account, $capitalizedLot, '2026-08-11', '0.32258065');
        $this->actingAs($user);

        Livewire::test(Accruals::class)->call('selectPeriod', 'custom')
            ->set('periodFrom', '2026-08-10')->set('periodTo', '2026-08-11')
            ->assertViewHas('monthSummary', fn ($v) => $v['count'] === 3 && $v['total'] === '40.64516129')
            ->assertViewHas('series', function ($series) {
                return collect($series)->firstWhere('date', '2026-08-10')['amount'] === '20.16129032'
                    && collect($series)->firstWhere('date', '2026-08-11')['amount'] === '20.48387097';
            })
            ->assertViewHas('investmentNumbers', fn ($numbers) => $numbers[$mainLot->id] === 1 && $numbers[$capitalizedLot->id] === 2)
            ->assertSee('Инвестиция')->assertSee('Сумма инвестиции')->assertDontSee('Транш')->assertDontSee('База')
            ->assertSee('Инвестиция №1')->assertSee('Инвестиция №2')
            ->assertDontSee('Инвестиция №'.$capitalizedLot->id);
    }

    public function test_finance_summary_numbering_sources_balances_and_lot_states_are_exact(): void
    {
        [$user, $account, $firstLot, $investor] = $this->context();
        [, $gapAccount] = $this->context('number-gap@example.com');
        $this->lot($gapAccount, '1.00000000', '1.0000');
        $this->lot($gapAccount, '2.00000000', '1.0000');
        $firstLot->update(['original_amount'=>'500.00000000','remaining_amount'=>'500.00000000','received_at'=>'2026-01-01','unlock_date'=>'2026-12-01']);
        $secondLot = $this->lot($account, '300.00000000', '3.0000');
        $secondLot->update(['remaining_amount'=>'200.00000000','received_at'=>'2026-02-01','unlock_date'=>'2026-08-01']);
        $closedLot = $this->lot($account, '100.00000000', '3.0000');
        $closedLot->update(['remaining_amount'=>'0.00000000','received_at'=>'2026-03-01','status'=>'closed','unlock_date'=>'2026-04-01']);
        DividendCapitalization::create([
            'investor_id'=>$investor->id,'investment_account_id'=>$account->id,
            'requested_amount'=>'300.00000000','capitalized_amount'=>'300.00000000',
            'currency'=>'USDT','status'=>'completed','investment_lot_id'=>$secondLot->id,'capitalized_at'=>'2026-02-01',
        ]);
        $this->actingAs($user);

        Livewire::test(Finance::class)
            ->assertViewHas('financeSummary', fn ($v) => $v === [
                'capital'=>'700.00000000','activeLots'=>2,
                'availableCapital'=>'200.00000000','lockedCapital'=>'500.00000000',
            ])
            ->assertSee('Мои инвестиции')->assertSee('Инвестиция №1')->assertSee('Инвестиция №2')->assertDontSee('Инвестиция №3')
            ->assertDontSee('Инвестиция №'.$secondLot->id)
            ->assertSee('Пополнение')->assertSee('Капитализация дивидендов')
            ->assertSee('Первоначальная сумма')->assertSee('300.00 USDT')->assertSee('200.00')
            ->assertSee('Заблокирована до 01.12.2026')->assertSee('Доступна к выводу')
            ->assertSee('Мои инвестиции')->assertSee('Отдельные вложения, их доходность и сроки доступности')
            ->assertSee('Активные 2')->assertSee('Завершённые 1')->assertSee('Все 3')
            ->assertSee('x-data="{ expanded: false }', false)->assertSee('aria-controls=', false)
            ->assertSee('lg:hidden', false)->assertSee('Первоначальная сумма')->assertSee('Дата разблокировки')
            ->call('setInvestmentFilter', 'closed')->assertSet('investmentFilter', 'closed')
            ->assertSee('Инвестиция №3')->assertDontSee('Инвестиция №1')->assertSee('Завершена')
            ->call('setInvestmentFilter', 'all')->assertSet('investmentFilter', 'all')
            ->assertViewHas('visibleLots', fn ($visibleLots) => $visibleLots->pluck('display_number')->all() === [1, 2, 3])
            ->assertSee('Инвестиция №1')->assertSee('Инвестиция №2')->assertSee('Инвестиция №3')
            ->assertDontSee('Транш');
    }

    public function test_finance_compact_investment_rows_scale_to_ten_with_stable_numbers(): void
    {
        [$user, $account, $firstLot] = $this->context();
        $firstLot->update(['received_at' => '2026-01-01']);

        for ($number = 2; $number <= 10; $number++) {
            $lot = $this->lot($account, $number.'00.00000000', '2.5000');
            $lot->update(['received_at' => sprintf('2026-%02d-01', $number)]);
        }

        $this->actingAs($user);

        $component = Livewire::test(Finance::class)
            ->assertViewHas('investmentCounts', fn ($counts) => $counts === ['active' => 10, 'closed' => 0, 'all' => 10])
            ->assertSee('Активные 10')->assertSee('Завершённые 0')->assertSee('Все 10');

        for ($number = 1; $number <= 10; $number++) {
            $component->assertSee('Инвестиция №'.$number);
        }

        $component->assertSee('grid-cols-[minmax(180px,1.35fr)', false)
            ->assertSee('x-bind:aria-expanded="expanded.toString()"', false);
    }

    public function test_finance_payment_details_waiting_and_snapshot_states(): void
    {
        [$user, $account, , $investor] = $this->context(); $this->actingAs($user);
        Livewire::test(Finance::class)->assertSee('Администратор ещё не назначил реквизиты для пополнения');

        InvestorPaymentDetail::create(['investor_id'=>$investor->id,'currency'=>'USDT','network'=>'TRC20','address'=>'TAlexeyFinancePaymentAddress001','memo'=>'CEO-17','is_active'=>true]);
        Livewire::test(Finance::class)->assertSee('USDT · TRC20')->assertSee('TAlexeyFinancePaymentAddress001')->assertSee('Memo/Tag:')->assertSee('CEO-17')->assertSee('Копировать');
    }

    public function test_finance_history_contains_deposit_capitalization_and_withdrawal_in_newest_first_order(): void
    {
        [$user, $account, $lot, $investor] = $this->context();
        InvestmentTransaction::create(['investment_account_id'=>$account->id,'investment_lot_id'=>$lot->id,'type'=>'deposit','amount'=>'500.00000000','currency'=>'USDT','effective_date'=>'2026-08-01','status'=>'confirmed']);
        InvestmentTransaction::create(['investment_account_id'=>$account->id,'investment_lot_id'=>$lot->id,'type'=>'capitalization','amount'=>'50.00000000','currency'=>'USDT','effective_date'=>'2026-08-02','status'=>'confirmed']);
        WithdrawalRequest::create(['investor_id'=>$investor->id,'investment_account_id'=>$account->id,'type'=>'capital','requested_amount'=>'25.00000000','reserved_amount'=>'25.00000000','currency'=>'USDT','status'=>'new','requested_at'=>'2026-08-03']);
        $this->actingAs($user);

        Livewire::test(Finance::class)
            ->assertSeeInOrder(['Вывод капитала','Капитализация','Пополнение'])
            ->assertSee('Дивиденды → капитал')->assertSee('Подтверждено')->assertSee('Новая');
    }

    public function test_finance_summary_does_not_include_foreign_investor_lots(): void
    {
        [$user, $account, $lot] = $this->context();
        [, $foreignAccount] = $this->context('finance-foreign@example.com');
        $this->lot($foreignAccount, '9999.00000000', '9.0000');
        $lot->update(['unlock_date'=>'2026-08-01']);
        $this->actingAs($user);

        Livewire::test(Finance::class)
            ->assertViewHas('financeSummary', fn ($v) => $v['capital']==='500.00000000' && $v['availableCapital']==='500.00000000')
            ->assertDontSee('9 999.00');
    }

    public function test_capitalization_preview_all_amount_and_success_update_dashboard_and_finance(): void
    {
        [$user, $account, $lot] = $this->context();
        $this->accrual($account, $lot, '2026-08-10', '100.00000000');
        $this->actingAs($user);

        Livewire::test(Dashboard::class)->call('openDialog', 'capitalization')->call('useAllDividends')
            ->assertSet('capitalizationAmount', '100.00000000')
            ->assertSee('100.00')
            ->assertViewHas('operationChanges', fn ($v) => $v['capitalAfter'] === '600.00000000' && $v['dividendsAfterCapitalization'] === '0.00000000')
            ->call('continueOperation')->assertSet('dialogStep', 'confirm')->assertSee('Подтвердите операцию')
            ->call('capitalize')->assertHasNoErrors()->assertSee('успешно переведены');

        $this->assertDatabaseHas('dividend_capitalizations', ['investment_account_id' => $account->id, 'requested_amount' => '100.00000000']);
        Livewire::test(Finance::class)->assertSee('Капитализация')->assertSee('100.00');
    }

    public function test_financial_modal_has_instant_local_close_and_reopens_clean(): void
    {
        [$user] = $this->context(); $this->actingAs($user);

        Livewire::test(Dashboard::class)->call('openDialog', 'capitalization')
            ->assertSee('x-show="modalOpen"', false)
            ->assertSee('x-on:keydown.escape.window="closeModal()"', false)
            ->assertSee('cursor-pointer', false)
            ->set('capitalizationAmount', '0')->call('continueOperation')->assertHasErrors('capitalizationAmount')
            ->call('closeDialog')->assertSet('dialog', null)->assertSet('dialogStep', 'form')->assertSet('dialogError', null)
            ->call('openDialog', 'capitalization')->assertSet('dialogStep', 'form')->assertSet('dialogError', null)
            ->assertHasNoErrors();
    }

    public function test_capitalization_preview_respects_investor_and_company_fee_payers(): void
    {
        [$user, $account, $lot] = $this->context(); $this->accrual($account, $lot, '2026-08-10', '200.00000000'); $this->actingAs($user);
        $investorRule = $this->fee('capitalization', '10.00000000', 'investor');

        Livewire::test(Dashboard::class)->call('openDialog', 'capitalization')->set('capitalizationAmount', '100.00000000')
            ->assertViewHas('capitalizationPreview', fn ($v) => $v['fee_amount'] === '10.00000000' && $v['net_amount'] === '90.00000000');
        $investorRule->delete(); $this->fee('capitalization', '10.00000000', 'company');
        Livewire::test(Dashboard::class)->call('openDialog', 'capitalization')->set('capitalizationAmount', '100.00000000')
            ->assertViewHas('capitalizationPreview', fn ($v) => $v['fee_amount'] === '10.00000000' && $v['net_amount'] === '100.00000000');
    }

    public function test_capitalization_cannot_exceed_available_balance(): void
    {
        [$user, $account, $lot] = $this->context(); $this->accrual($account, $lot, '2026-08-10', '10.00000000'); $this->actingAs($user);
        Livewire::test(Dashboard::class)->call('openDialog', 'capitalization')->set('capitalizationAmount', '10.00000001')
            ->call('continueOperation')->assertHasErrors('capitalizationAmount')->assertSet('dialogStep', 'form');
    }

    public function test_dividend_withdrawal_uses_approved_owned_wallet_and_reserves_balance(): void
    {
        [$user, $account, $lot, $investor] = $this->context(); $this->accrual($account, $lot, '2026-08-10', '100.00000000');
        $this->actingAs($user);

        Livewire::test(Dashboard::class)->call('openDialog', 'dividend')->set('withdrawalAmount', '40.00000000')
            ->assertViewHas('operationChanges', fn ($v) => $v['dividendsAfterWithdrawal'] === '60.00000000' && $v['dividendReservedAfter'] === '40.00000000')
            ->call('continueOperation')->assertSet('dialogStep', 'confirm')
            ->call('requestDividendWithdrawal')->assertHasNoErrors()->assertSee('Заявка на вывод дивидендов создана');
        $this->assertDatabaseHas('withdrawal_requests', ['investment_account_id' => $account->id, 'type' => 'dividend', 'reserved_amount' => '40.00000000']);
        Livewire::test(Dashboard::class)->assertViewHas('summary', fn ($v) => $v['availableDividends'] === '60.00000000');
    }

    public function test_missing_withdrawal_details_are_explained_and_creation_is_blocked(): void
    {
        [$user, $account, $lot, $investor] = $this->context(); $this->accrual($account, $lot, '2026-08-10', '100.00000000');
        $investor->withdrawalDetails()->delete(); $this->actingAs($user);

        Livewire::test(Dashboard::class)->call('openDialog', 'dividend')->assertSee('Администратор ещё не назначил реквизиты для вывода средств.')
            ->set('withdrawalAmount', '10.00000000')
            ->call('requestDividendWithdrawal')->assertSet('dialog', 'dividend')
            ->assertSee('Администратор ещё не назначил реквизиты для вывода средств.');
        $this->assertDatabaseCount('withdrawal_requests', 0);
    }

    public function test_capital_withdrawal_and_locked_capital_states(): void
    {
        [$user, $account, $lot, $investor] = $this->context(); $lot->update(['unlock_date' => '2026-08-01']);
        $this->actingAs($user);
        Livewire::test(Dashboard::class)->call('openDialog', 'capital')->set('withdrawalAmount', '50.00000000')
            ->assertViewHas('operationChanges', fn ($v) => $v['availableCapitalAfter'] === '450.00000000' && $v['capitalReservedAfter'] === '50.00000000')
            ->call('continueOperation')->assertSet('dialogStep', 'confirm')
            ->call('requestCapitalWithdrawal')->assertHasNoErrors()->assertSee('Заявка на вывод капитала создана');
        $this->assertDatabaseHas('withdrawal_requests', ['type' => 'capital', 'requested_amount' => '50.00000000']);
        $this->assertSame('500.00000000', $lot->fresh()->remaining_amount);

        $lot->update(['unlock_date' => '2026-12-01']);
        Livewire::test(Dashboard::class)->assertViewHas('summary', fn ($v) => $v['availableCapital'] === '0.00000000')->assertSee('Ближайшая разблокировка');
    }

    public function test_permanent_withdrawal_details_are_read_only_and_full_snapshot_is_saved(): void
    {
        [$user, $account, $lot, $investor] = $this->context();
        $this->accrual($account, $lot, '2026-08-10', '100.00000000');
        $detail = $investor->withdrawalDetails()->latest('id')->firstOrFail(); $this->actingAs($user);

        Livewire::test(Dashboard::class)->call('openDialog', 'dividend')
            ->assertSee($detail->address)->assertSee('Реквизиты назначены администратором и доступны только для просмотра.')
            ->set('withdrawalAmount', '25.00000000')
            ->call('continueOperation')->assertSet('dialogStep', 'confirm')
            ->call('requestDividendWithdrawal')->assertSet('dialog', null);

        $this->assertDatabaseHas('withdrawal_requests', [
            'investment_account_id' => $account->id,
            'wallet_address_snapshot' => $detail->address,
            'network_snapshot' => 'TRC20',
            'withdrawal_memo_snapshot' => 'INVESTOR-MEMO',
        ]);
    }

    public function test_service_domain_errors_remain_inside_open_modal(): void
    {
        [$user, $account, $lot] = $this->context();
        $this->accrual($account, $lot, '2026-08-10', '100.00000000'); $this->actingAs($user);
        $component = Livewire::test(Dashboard::class)->call('openDialog', 'capitalization')
            ->set('capitalizationAmount', '20.00000000')->call('continueOperation')->assertSet('dialogStep', 'confirm');
        $account->investmentTerms()->delete();

        $component->call('capitalize')->assertSet('dialog', 'capitalization')->assertSet('dialogStep', 'form')
            ->assertSee('Условия инвестирования изменились. Проверьте расчёт ещё раз.');
        $this->assertDatabaseCount('dividend_capitalizations', 0);
    }

    public function test_withdrawal_details_becoming_unavailable_after_confirmation_keeps_modal_open(): void
    {
        [$user, $account, $lot, $investor] = $this->context();
        $this->accrual($account, $lot, '2026-08-10', '100.00000000');
        $detail = $investor->withdrawalDetails()->latest('id')->firstOrFail(); $this->actingAs($user);
        $component = Livewire::test(Dashboard::class)->call('openDialog', 'dividend')
            ->set('withdrawalAmount', '20.00000000')
            ->call('continueOperation')->assertSet('dialogStep', 'confirm');
        $detail->update(['is_active' => false]);

        $component->call('requestDividendWithdrawal')->assertSet('dialog', 'dividend')->assertSet('dialogStep', 'form')
            ->assertSee('Администратор ещё не назначил реквизиты для вывода средств.');
        $this->assertDatabaseCount('withdrawal_requests', 0);
    }

    public function test_capital_modal_explains_account_term_restrictions(): void
    {
        [$user, $account, $lot] = $this->context(); $lot->update(['unlock_date' => '2026-08-01']);
        $account->investmentTerms()->update([
            'partial_withdrawal_allowed' => false,
            'minimum_balance' => '100.00000000',
        ]);
        $this->actingAs($user);

        Livewire::test(Dashboard::class)->call('openDialog', 'capital')
            ->assertSee('Частичный вывод капитала запрещён')
            ->assertSee('должно остаться не менее 100.00 USDT');
    }

    public function test_investor_cannot_read_other_investor_financial_data(): void
    {
        [$user] = $this->context(); [, $otherAccount, $otherLot, $otherInvestor] = $this->context('private@example.com');
        $this->accrual($otherAccount, $otherLot, '2026-08-10', '987.65432100');
        WithdrawalRequest::create(['investor_id'=>$otherInvestor->id,'investment_account_id'=>$otherAccount->id,'type'=>'dividend','requested_amount'=>'876.54321000','reserved_amount'=>'876.54321000','currency'=>'USDT','status'=>'new','requested_at'=>now()]);
        $this->actingAs($user);

        Livewire::test(Accruals::class)->assertDontSee('987.65');
        Livewire::test(Withdrawals::class)->assertDontSee('876.54');
        Livewire::test(Finance::class)->assertDontSee((string) $otherLot->id.' private');
    }

    public function test_withdrawal_rows_summary_filters_details_and_ownership_are_exact(): void
    {
        [$user, $account, , $investor] = $this->context();
        $wallet = $this->wallet($investor);
        $records = [
            ['dividend', 'new', '100.00000000', '1.00000000', '99.00000000', '2026-08-01'],
            ['capital', 'review', '200.00000000', '2.00000000', '198.00000000', '2026-08-02'],
            ['capital', 'approved', '300.00000000', '3.00000000', '297.00000000', '2026-08-03'],
            ['dividend', 'paid', '400.00000000', '4.00000000', '396.00000000', '2026-08-04'],
            ['dividend', 'cancelled', '500.00000000', '5.00000000', '495.00000000', '2026-08-05'],
            ['capital', 'rejected', '600.00000000', '6.00000000', '594.00000000', '2026-08-06'],
        ];

        foreach ($records as $index => [$type, $status, $requested, $fee, $net, $date]) {
            WithdrawalRequest::create([
                'investor_id'=>$investor->id, 'investment_account_id'=>$account->id, 'investor_wallet_id'=>$wallet->id,
                'type'=>$type, 'requested_amount'=>$requested, 'reserved_amount'=>'0.00000000', 'fee_amount'=>$fee,
                'net_amount'=>$net, 'currency'=>'USDT', 'network_snapshot'=>'TRC20',
                'wallet_address_snapshot'=>'TFullWithdrawalSnapshotAddress001', 'status'=>$status,
                'txid'=>$status === 'paid' ? 'demo-payment-txid-001' : null, 'requested_at'=>$date.' 10:00:00',
                'approved_at'=>in_array($status, ['approved', 'paid'], true) ? $date.' 11:00:00' : null,
                'paid_at'=>$status === 'paid' ? $date.' 12:00:00' : null,
                'cancelled_at'=>$status === 'cancelled' ? $date.' 12:00:00' : null,
                'rejected_at'=>$status === 'rejected' ? $date.' 12:00:00' : null,
                'rejected_reason'=>$status === 'rejected' ? 'Данные кошелька требуют уточнения' : null,
            ]);
        }
        [, $foreignAccount, , $foreignInvestor] = $this->context('withdrawals-private@example.com');
        WithdrawalRequest::create(['investor_id'=>$foreignInvestor->id,'investment_account_id'=>$foreignAccount->id,'type'=>'dividend','requested_amount'=>'9999.00000000','reserved_amount'=>'0.00000000','fee_amount'=>'0.00000000','net_amount'=>'9999.00000000','currency'=>'USDT','status'=>'new','requested_at'=>'2026-08-10']);
        $this->actingAs($user);

        Livewire::test(Withdrawals::class)
            ->assertViewHas('withdrawalSummary', fn ($summary) => $summary === ['total'=>6, 'active'=>3, 'paid'=>1, 'declined'=>2])
            ->assertSee('Вывод дивидендов')->assertSee('Вывод капитала')
            ->assertSee('Новая')->assertSee('На проверке')->assertSee('Одобрена')->assertSee('Выплачена')->assertSee('Отменена')->assertSee('Отклонена')
            ->assertSee('TRC20 · TFullW…s001')->assertSee('TFullWithdrawalSnapshotAddress001')
            ->assertSee('demo-payment-txid-001')->assertSee('navigator.clipboard.writeText', false)
            ->assertSee('x-bind:aria-expanded="expanded.toString()"', false)->assertSee('lg:hidden', false)
            ->assertSee('Дата одобрения')->assertSee('Дата выплаты')->assertSee('Дата отмены')->assertSee('Причина отказа')
            ->assertSeeInOrder(['06.08.2026', '05.08.2026', '04.08.2026'])
            ->assertDontSee('9 999.00')
            ->set('typeFilter', 'dividend')->assertSet('typeFilter', 'dividend')->assertSee('Вывод дивидендов')->assertDontSee('Вывод капитала')
            ->set('typeFilter', '')->set('statusFilter', 'paid')
            ->assertViewHas('withdrawals', fn ($items) => $items->count() === 1 && $items->first()->status === 'paid');
    }

    public function test_withdrawal_pagination_scales_to_twenty_five_and_preserves_filters(): void
    {
        [$user, $account, , $investor] = $this->context();
        for ($number = 1; $number <= 25; $number++) {
            WithdrawalRequest::create([
                'investor_id'=>$investor->id, 'investment_account_id'=>$account->id, 'type'=>'dividend',
                'requested_amount'=>$number.'00.00000000', 'reserved_amount'=>'0.00000000',
                'fee_amount'=>'0.00000000', 'net_amount'=>$number.'00.00000000', 'currency'=>'USDT',
                'status'=>'new', 'requested_at'=>Carbon::parse('2026-01-01')->addDays($number),
            ]);
        }
        $this->actingAs($user);

        Livewire::test(Withdrawals::class)
            ->set('typeFilter', 'dividend')->assertViewHas('withdrawals', fn ($items) => $items->count() === 20 && $items->total() === 25 && $items->first()->requested_amount === '2500.00000000' && $items->last()->requested_amount === '600.00000000')
            ->call('nextPage')->assertSet('typeFilter', 'dividend')
            ->assertViewHas('withdrawals', fn ($items) => $items->currentPage() === 2 && $items->count() === 5 && $items->first()->requested_amount === '500.00000000' && $items->last()->requested_amount === '100.00000000');
    }

    public function test_withdrawals_empty_state_links_to_dashboard(): void
    {
        [$user] = $this->context(); $this->actingAs($user);

        Livewire::test(Withdrawals::class)
            ->assertSee('У вас пока нет заявок на вывод.')
            ->assertSee('Создать заявку можно на странице Обзор.')
            ->assertSee(route('dashboard'));
    }

    public function test_wallet_creation_is_pending_and_only_owned_pending_wallet_can_be_archived(): void
    {
        [$user, , , $investor] = $this->context(); $this->actingAs($user);
        Livewire::test(Wallets::class)->set('address', 'TNewInvestorWallet')->set('label', 'Новый')->call('add')->assertHasNoErrors()
            ->assertDispatched('wallet-added')->assertSee('Кошелёк добавлен и отправлен на проверку.');
        $wallet = InvestorWallet::where('investor_id', $investor->id)->sole(); $this->assertSame('pending', $wallet->status);
        Livewire::test(Wallets::class)->call('archive', $wallet->id)->assertHasNoErrors();
        $this->assertSame('archived', $wallet->fresh()->status);
    }

    public function test_wallet_manager_rows_summary_details_modal_and_ownership_are_exact(): void
    {
        [$user, , , $investor] = $this->context();
        $approved = InvestorWallet::create(['investor_id'=>$investor->id,'currency'=>'USDT','network'=>'TRC20','address'=>'TApprovedFullWalletAddress001','label'=>'Основной кошелёк','status'=>'approved','approved_at'=>now()]);
        InvestorWallet::create(['investor_id'=>$investor->id,'currency'=>'USDT','network'=>'TRC20','address'=>'TPendingFullWalletAddress002','label'=>'Резервный кошелёк','status'=>'pending']);
        InvestorWallet::create(['investor_id'=>$investor->id,'currency'=>'USDT','network'=>'BEP20','address'=>'TArchivedFullWalletAddress003','label'=>'Старый кошелёк','status'=>'archived','archived_at'=>now()]);
        [, , , $foreignInvestor] = $this->context('wallet-manager-private@example.com');
        InvestorWallet::create(['investor_id'=>$foreignInvestor->id,'currency'=>'USDT','network'=>'TRC20','address'=>'TForeignPrivateWallet999','status'=>'pending']);
        $this->actingAs($user);

        Livewire::test(Wallets::class)
            ->assertViewHas('walletSummary', fn ($summary) => $summary === ['total'=>3, 'approved'=>1, 'pending'=>1])
            ->assertSee('Основной кошелёк')->assertSee('Резервный кошелёк')->assertSee('Старый кошелёк')
            ->assertSee('Одобрен')->assertSee('На проверке')->assertSee('Архивирован')
            ->assertSee('TApprov…s001')->assertSee('TPendin…s002')
            ->assertSee('TApprovedFullWalletAddress001')->assertSee('TPendingFullWalletAddress002')
            ->assertDontSee('TForeignPrivateWallet999')
            ->assertSee('Копировать')->assertSee('navigator.clipboard.writeText', false)
            ->assertSee('x-bind:aria-expanded="expanded.toString()"', false)->assertSee('lg:hidden', false)
            ->assertSee('role="dialog"', false)->assertSee('aria-modal="true"', false)->assertSee('x-ref="walletLabel"', false)
            ->assertSee('После добавления кошелёк будет отправлен на проверку.')
            ->assertSee('Изменение адреса требует добавления нового кошелька и повторной проверки.')
            ->assertSee('Отменить добавление кошелька?')->assertSee('Кошелёк будет удалён из списка ожидающих проверку.')
            ->assertDontSee('archive('.$approved->id.')', false);
    }

    public function test_wallet_modal_validation_and_empty_state_are_present(): void
    {
        [$user] = $this->context(); $this->actingAs($user);

        Livewire::test(Wallets::class)
            ->assertSee('У вас пока нет кошельков.')
            ->assertSee('Добавьте адрес, на который будут выполняться выплаты.')
            ->assertSee('+ Добавить кошелёк')
            ->set('address', '')->call('add')->assertHasErrors(['address' => 'required']);
    }

    public function test_approved_wallet_cannot_be_archived_by_investor(): void
    {
        [$user, , , $investor] = $this->context();
        $wallet = $this->wallet($investor); $this->actingAs($user);

        $this->expectException(\DomainException::class);
        Livewire::test(Wallets::class)->call('archive', $wallet->id);
    }

    public function test_foreign_pending_wallet_cannot_be_archived_by_investor(): void
    {
        [$user] = $this->context(); [, , , $foreignInvestor] = $this->context('foreign-wallet-archive@example.com');
        $wallet = InvestorWallet::create(['investor_id'=>$foreignInvestor->id,'currency'=>'USDT','network'=>'TRC20','address'=>'TForeignPendingArchive','status'=>'pending']);
        $this->actingAs($user);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Livewire::test(Wallets::class)->call('archive', $wallet->id);
    }

    private function context(string $email = 'investor@example.com'): array
    {
        $user = User::factory()->create(['email'=>$email,'role'=>'investor','is_active'=>true]);
        $investor = Investor::create(['user_id'=>$user->id,'code'=>'INV-'.strtoupper(substr(md5($email),0,5)),'status'=>'active']);
        $account = InvestmentAccount::create(['investor_id'=>$investor->id,'currency'=>'USDT','status'=>'active','opened_at'=>'2026-01-01']);
        InvestorWithdrawalDetail::create(['investor_id'=>$investor->id,'currency'=>'USDT','network'=>'TRC20','address'=>'TAssignedWithdrawal'.$investor->id,'memo'=>'INVESTOR-MEMO','is_active'=>true]);
        $lot = $this->lot($account, '500.00000000', '3.0000');
        InvestmentTerm::create(['investment_account_id'=>$account->id,'monthly_rate'=>'3.0000','lock_months'=>3,'valid_from'=>'2026-01-01']);
        return [$user,$account,$lot,$investor];
    }

    private function lot(InvestmentAccount $account, string $amount, string $rate): InvestmentLot
    {
        return InvestmentLot::create(['investment_account_id'=>$account->id,'original_amount'=>$amount,'remaining_amount'=>$amount,'currency'=>'USDT','received_at'=>'2026-01-01','accrual_start_date'=>'2026-01-02','lock_months'=>3,'unlock_date'=>'2026-12-01','monthly_rate'=>$rate,'status'=>'active']);
    }

    private function accrual(InvestmentAccount $account, InvestmentLot $lot, string $date, string $amount): DailyAccrual
    {
        return DailyAccrual::create(['investment_account_id'=>$account->id,'investment_lot_id'=>$lot->id,'accrual_date'=>$date,'principal_amount'=>$lot->remaining_amount,'monthly_rate'=>$lot->monthly_rate,'days_in_month'=>Carbon::parse($date)->daysInMonth,'calculated_amount'=>$amount,'adjustment_amount'=>'0.00000000','final_amount'=>$amount,'status'=>'calculated']);
    }

    private function wallet(Investor $investor): InvestorWallet
    {
        return InvestorWallet::create(['investor_id'=>$investor->id,'currency'=>'USDT','network'=>'TRC20','address'=>'TAlexeyWallet'.$investor->id.'t001','status'=>'approved','approved_at'=>now()]);
    }

    private function fee(string $type, string $amount, string $payer): FeeRule
    {
        return FeeRule::create(['operation_type'=>$type,'scope'=>'global','currency'=>'USDT','fee_type'=>'fixed','fixed_value'=>$amount,'payer'=>$payer,'valid_from'=>'2026-01-01','is_active'=>true]);
    }
}
