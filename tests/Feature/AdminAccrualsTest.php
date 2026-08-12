<?php

namespace Tests\Feature;

use App\Livewire\Admin\Accruals\Index;
use App\Models\DailyAccrual;
use App\Models\DividendCapitalization;
use App\Models\InvestmentAccount;
use App\Models\InvestmentLot;
use App\Models\Investor;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminAccrualsTest extends TestCase
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

    public function test_admin_access_and_investor_is_forbidden(): void
    {
        $admin = User::factory()->create(['role'=>'admin']);
        $this->actingAs($admin)->get('/admin/accruals')->assertOk()->assertSee('Контроль начислений инвесторов');

        [$investorUser] = $this->context('blocked-investor@example.com', 'Инвестор', 'INV-BLOCK');
        $this->actingAs($investorUser)->get('/admin/accruals')->assertForbidden();
    }

    public function test_rows_kpis_stable_numbers_sources_and_daily_lots_are_exact(): void
    {
        [$userA, $investorA, $accountA, $lotA] = $this->context('alexey-accruals@example.com', 'Алексей Смирнов', 'INV-002');
        [, , $gapAccount] = $this->context('number-gap-admin@example.com', 'Gap', 'INV-GAP');
        $this->lot($gapAccount, '1.00000000', '1.0000', '2026-02-01');
        $lotB = $this->lot($accountA, '400.00000000', '2.5000', '2026-03-01');
        DividendCapitalization::create(['investor_id'=>$investorA->id,'investment_account_id'=>$accountA->id,'requested_amount'=>'400.00000000','capitalized_amount'=>'400.00000000','currency'=>'USDT','status'=>'completed','investment_lot_id'=>$lotB->id,'capitalized_at'=>'2026-03-01']);
        [, , $accountB, $mariaLot] = $this->context('maria-accruals@example.com', 'Мария Волкова', 'INV-003');

        $this->accrual($accountA, $lotA, '2026-08-10', '10.00000000');
        $this->accrual($accountA, $lotB, '2026-08-10', '2.00000000', '1.00000000');
        $this->accrual($accountB, $mariaLot, '2026-08-09', '5.00000000');
        $this->accrual($accountA, $lotA, '2026-07-31', '7.00000000');
        $admin = User::factory()->create(['role'=>'admin']); $this->actingAs($admin);

        Livewire::test(Index::class)
            ->assertViewHas('accruals', fn ($items) => $items->total() === 3)
            ->assertSee('Инвестиция №1')->assertSee('Инвестиция №2')->assertDontSee('Инвестиция №'.$lotB->id)
            ->assertSee('Алексей Смирнов')->assertSee('Мария Волкова')->assertSee('Капитализация дивидендов')->assertSee('Пополнение')
            ->assertSee('x-bind:aria-expanded="expanded.toString()"', false)->assertSee('lg:hidden', false)
            ->assertSee('Сумма инвестиции')->assertDontSee('Транш')
            ->assertSee('25 000.00 USDT')->assertSee('400.00 USDT')
            ->assertSeeInOrder(['Начислено сегодня', '13.00'])
            ->assertSeeInOrder(['Начислено за текущий месяц', '18.00'])
            ->assertSeeInOrder(['Начислено за всё время', '25.00'])
            ->assertSeeInOrder(['Корректировки за текущий месяц', '1.00']);
    }

    public function test_search_period_custom_boundaries_investor_and_adjustment_filters(): void
    {
        [, $alexey, $alexeyAccount, $alexeyLot] = $this->context('alexey-filter@example.com', 'Алексей Смирнов', 'INV-FILTER-A');
        [, $maria, $mariaAccount, $mariaLot] = $this->context('maria-filter@example.com', 'Мария Волкова', 'INV-FILTER-M');
        $this->accrual($alexeyAccount, $alexeyLot, '2026-08-01', '1.00000000');
        $this->accrual($alexeyAccount, $alexeyLot, '2026-08-10', '2.00000000', '0.50000000');
        $this->accrual($mariaAccount, $mariaLot, '2026-07-31', '3.00000000');
        $admin = User::factory()->create(['role'=>'admin']); $this->actingAs($admin);

        Livewire::test(Index::class)
            ->set('search', 'maria-filter@example.com')->assertViewHas('accruals', fn ($items) => $items->total() === 0)
            ->call('selectPeriod', 'all')->assertViewHas('accruals', fn ($items) => $items->total() === 1 && $items->first()->investment_account_id === $mariaAccount->id)
            ->set('search', '')->call('selectPeriod', 'today')->assertViewHas('accruals', fn ($items) => $items->total() === 1)
            ->call('selectPeriod', 'custom')->set('dateFrom', '2026-07-31')->set('dateTo', '2026-08-01')->call('applyCustomPeriod')
            ->assertViewHas('accruals', fn ($items) => $items->total() === 2)
            ->set('investorId', (string) $alexey->id)->assertViewHas('accruals', fn ($items) => $items->total() === 1)
            ->call('selectPeriod', 'all')->set('adjustmentFilter', 'with')->assertViewHas('accruals', fn ($items) => $items->total() === 1 && $items->first()->adjustment_amount === '0.50000000')
            ->set('adjustmentFilter', 'without')->assertViewHas('accruals', fn ($items) => $items->total() === 1)
            ->set('investmentLotId', (string) $alexeyLot->id)->assertViewHas('accruals', fn ($items) => $items->total() === 1);
    }

    public function test_pagination_sorting_and_filter_state_scale_past_twenty_five(): void
    {
        [, , $account, $lot] = $this->context('pagination-accruals@example.com', 'Пагинация', 'INV-PAGE');
        for ($day = 1; $day <= 30; $day++) $this->accrual($account, $lot, Carbon::parse('2026-06-01')->addDays($day)->toDateString(), $day.'.00000000');
        $admin = User::factory()->create(['role'=>'admin']); $this->actingAs($admin);

        Livewire::test(Index::class)->call('selectPeriod', 'all')->set('search', 'INV-PAGE')
            ->assertViewHas('accruals', fn ($items) => $items->total() === 30 && $items->count() === 25 && $items->first()->final_amount === '30.00000000')
            ->call('nextPage')->assertSet('search', 'INV-PAGE')->assertSet('period', 'all')
            ->assertViewHas('accruals', fn ($items) => $items->currentPage() === 2 && $items->count() === 5 && $items->last()->final_amount === '1.00000000')
            ->set('adjustmentFilter', 'without')->assertViewHas('accruals', fn ($items) => $items->currentPage() === 1 && $items->total() === 30);
    }

    private function context(string $email, string $name, string $code): array
    {
        $user = User::factory()->create(['name'=>$name,'email'=>$email,'role'=>'investor','is_active'=>true]);
        $investor = Investor::create(['user_id'=>$user->id,'code'=>$code,'status'=>'active']);
        $account = InvestmentAccount::create(['investor_id'=>$investor->id,'currency'=>'USDT','status'=>'active','opened_at'=>'2026-01-01']);
        $lot = $this->lot($account, $name === 'Алексей Смирнов' ? '25000.00000000' : '500.00000000', '2.5000', '2026-01-01');
        return [$user, $investor, $account, $lot];
    }

    private function lot(InvestmentAccount $account, string $amount, string $rate, string $receivedAt): InvestmentLot
    {
        return InvestmentLot::create(['investment_account_id'=>$account->id,'original_amount'=>$amount,'remaining_amount'=>$amount,'currency'=>'USDT','received_at'=>$receivedAt,'accrual_start_date'=>$receivedAt,'lock_months'=>0,'monthly_rate'=>$rate,'status'=>'active']);
    }

    private function accrual(InvestmentAccount $account, InvestmentLot $lot, string $date, string $calculated, string $adjustment = '0.00000000'): DailyAccrual
    {
        $final = app(\App\Services\AccrualCalculator::class)->add($calculated, $adjustment);
        return DailyAccrual::create(['investment_account_id'=>$account->id,'investment_lot_id'=>$lot->id,'accrual_date'=>$date,'principal_amount'=>$lot->remaining_amount,'monthly_rate'=>$lot->monthly_rate,'days_in_month'=>Carbon::parse($date)->daysInMonth,'calculated_amount'=>$calculated,'adjustment_amount'=>$adjustment,'final_amount'=>$final,'status'=>'calculated']);
    }
}
