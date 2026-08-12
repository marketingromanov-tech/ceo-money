<?php

namespace Tests\Feature;

use App\Livewire\Admin\Dashboard;
use App\Models\DailyAccrual;
use App\Models\DepositRequest;
use App\Models\InvestmentAccount;
use App\Models\InvestmentLot;
use App\Models\Investor;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\CeoAnalyticsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CeoAnalyticsServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-11 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_portfolio_cash_flow_revenue_ranking_and_multi_currency_are_exact(): void
    {
        [$admin, $first, $usdt, $usdtLot] = $this->investor('Первый', 'USDT', '1000');
        [, $second, $usdc, $usdcLot] = $this->investor('Второй', 'USDC', '2500');
        $this->accrual($usdt, $usdtLot, '50');
        $this->accrual($usdc, $usdcLot, '75');
        $this->deposit($first, $usdt, '500', '10', '490', 'USDT');
        $this->deposit($second, $usdc, '800', '8', '792', 'USDC');
        $this->withdrawal($first, $usdt, '100', '2', '98', 'USDT');
        $this->withdrawal($second, $usdc, '200', '4', '196', 'USDC');

        $service = app(CeoAnalyticsService::class);
        $from = now()->startOfMonth();
        $to = now()->endOfDay();
        $portfolio = $service->portfolio($from, $to);
        $flow = $service->cashFlow($from, $to);
        $revenue = $service->platformRevenue($from, $to);
        $ranking = $service->investorRanking($from, $to);

        $this->assertSame('1000.00000000', $portfolio['capital']['USDT']);
        $this->assertSame('2500.00000000', $portfolio['capital']['USDC']);
        $this->assertSame(2, $portfolio['active_investors']);
        $this->assertSame(2, $portfolio['investments']);
        $this->assertSame('1000.00000000', $portfolio['average_investment']['USDT']);
        $this->assertSame('2500.00000000', $portfolio['average_investment']['USDC']);
        $this->assertSame('490.00000000', $flow['deposits']['USDT']);
        $this->assertSame('792.00000000', $flow['deposits']['USDC']);
        $this->assertSame('100.00000000', $flow['withdrawals']['USDT']);
        $this->assertSame('390.00000000', $flow['net']['USDT']);
        $this->assertSame('592.00000000', $flow['net']['USDC']);
        $this->assertSame('12.00000000', $revenue['platform_revenue']['USDT']);
        $this->assertSame('12.00000000', $revenue['platform_revenue']['USDC']);
        $this->assertCount(2, $ranking);
        $this->assertSame('USDC', $ranking[0]['currency']);
        $this->assertSame('75.00000000', $ranking[0]['accrued']);
    }

    public function test_dashboard_period_switches_without_financial_mutation(): void
    {
        [$admin, , $account, $lot] = $this->investor('Dashboard', 'USDT', '1500');
        $this->accrual($account, $lot, '25');
        $before = [InvestmentLot::count(), DepositRequest::count(), WithdrawalRequest::count()];

        Livewire::actingAs($admin)
            ->test(Dashboard::class)
            ->assertSee('CEO Analytics v1')
            ->assertSee('Общий капитал')
            ->assertSee('Денежный поток')
            ->assertSee('Лучшие инвесторы')
            ->call('setAnalyticsPeriod', '7')
            ->assertSet('analyticsPeriod', '7')
            ->call('setAnalyticsPeriod', 'year')
            ->assertSet('analyticsPeriod', 'year')
            ->assertSee('1 500.00');

        $this->assertSame($before, [InvestmentLot::count(), DepositRequest::count(), WithdrawalRequest::count()]);
    }

    private function investor(string $name, string $currency, string $capital): array
    {
        $admin = User::firstOrCreate(
            ['email' => 'analytics-admin@example.com'],
            ['name' => 'Admin', 'password' => 'x', 'role' => 'admin', 'is_active' => true],
        );
        $user = User::factory()->create(['name' => $name, 'role' => 'investor', 'is_active' => true]);
        $investor = Investor::create(['user_id' => $user->id, 'code' => 'AN-'.$user->id, 'status' => 'active']);
        $account = InvestmentAccount::create(['investor_id' => $investor->id, 'currency' => $currency, 'status' => 'active']);
        $lot = InvestmentLot::create([
            'investment_account_id' => $account->id,
            'original_amount' => $capital,
            'remaining_amount' => $capital,
            'currency' => $currency,
            'received_at' => now()->subDays(3),
            'accrual_start_date' => now()->subDays(3),
            'lock_months' => 0,
            'unlock_date' => now()->subDay(),
            'monthly_rate' => '3',
            'status' => 'active',
        ]);

        return [$admin, $investor, $account, $lot];
    }

    private function accrual(InvestmentAccount $account, InvestmentLot $lot, string $amount): void
    {
        DailyAccrual::create([
            'investment_account_id' => $account->id,
            'investment_lot_id' => $lot->id,
            'accrual_date' => now(),
            'principal_amount' => $lot->remaining_amount,
            'monthly_rate' => '3',
            'days_in_month' => 31,
            'calculated_amount' => $amount,
            'final_amount' => $amount,
        ]);
    }

    private function deposit(Investor $investor, InvestmentAccount $account, string $gross, string $fee, string $net, string $currency): void
    {
        DepositRequest::create([
            'investor_id' => $investor->id,
            'investment_account_id' => $account->id,
            'requested_amount' => $gross,
            'received_amount' => $gross,
            'fee_amount' => $fee,
            'fee_payer' => 'investor',
            'fee_economic_type_snapshot' => 'platform_fee',
            'net_investment_amount' => $net,
            'currency' => $currency,
            'status' => 'confirmed',
            'requested_at' => now(),
            'confirmed_at' => now(),
        ]);
    }

    private function withdrawal(Investor $investor, InvestmentAccount $account, string $gross, string $fee, string $net, string $currency): void
    {
        WithdrawalRequest::create([
            'investor_id' => $investor->id,
            'investment_account_id' => $account->id,
            'type' => 'dividend',
            'requested_amount' => $gross,
            'reserved_amount' => $gross,
            'fee_amount' => $fee,
            'fee_payer' => 'investor',
            'fee_economic_type_snapshot' => 'platform_fee',
            'net_amount' => $net,
            'currency' => $currency,
            'status' => 'paid',
            'requested_at' => now(),
            'paid_at' => now(),
        ]);
    }
}
