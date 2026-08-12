<?php

namespace Tests\Feature;

use App\Models\AccrualPause;
use App\Models\DailyAccrual;
use App\Models\InvestmentAccount;
use App\Models\InvestmentLot;
use App\Models\InvestmentTerm;
use App\Models\Investor;
use App\Models\User;
use App\Services\DailyAccrualService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DailyAccrualServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_calculates_daily_accrual_for_ten_thousand_at_three_percent_in_august(): void
    {
        $lot = $this->createLot();

        $accrual = $this->service()->calculateForLot($lot, Carbon::parse('2026-08-15'));

        $this->assertNotNull($accrual);
        $this->assertSame('10000.00000000', $accrual->principal_amount);
        $this->assertSame('3.0000', $accrual->monthly_rate);
        $this->assertSame(31, $accrual->days_in_month);
        $this->assertSame('9.67741935', $accrual->calculated_amount);
        $this->assertSame('9.67741935', $accrual->final_amount);
    }

    public function test_it_uses_twenty_nine_days_in_a_leap_year_february(): void
    {
        $lot = $this->createLot(['accrual_start_date' => '2028-01-01']);

        $accrual = $this->service()->calculateForLot($lot, Carbon::parse('2028-02-10'));

        $this->assertSame(29, $accrual->days_in_month);
        $this->assertSame('10.34482759', $accrual->calculated_amount);
    }

    public function test_rate_priority_is_explicit_lot_term_then_lot_snapshot(): void
    {
        $lot = $this->createLot(['monthly_rate' => '1.0000']);
        $date = Carbon::parse('2026-08-15');

        $fromLotRate = $this->service()->calculateForLot($lot, $date);
        $this->assertSame('1.0000', $fromLotRate->monthly_rate);

        InvestmentTerm::create([
            'investment_account_id' => $lot->investment_account_id,
            'monthly_rate' => '2.0000',
            'valid_from' => '2026-08-01',
        ]);
        $fromAccountTerm = $this->service()->calculateForLot($lot, $date);
        $this->assertSame('1.0000', $fromAccountTerm->monthly_rate);

        InvestmentTerm::create([
            'investment_account_id' => $lot->investment_account_id,
            'investment_lot_id' => $lot->id,
            'monthly_rate' => '3.0000',
            'valid_from' => '2026-08-01',
        ]);
        $fromLotTerm = $this->service()->calculateForLot($lot, $date);
        $this->assertSame('3.0000', $fromLotTerm->monthly_rate);

    }

    public function test_pause_produces_zero_accrual(): void
    {
        $lot = $this->createLot();
        AccrualPause::create([
            'investment_account_id' => $lot->investment_account_id,
            'investment_lot_id' => $lot->id,
            'start_date' => '2026-08-10',
            'end_date' => '2026-08-20',
            'reason' => 'Contract pause',
        ]);

        $accrual = $this->service()->calculateForLot($lot, Carbon::parse('2026-08-15'));

        $this->assertSame('0.00000000', $accrual->calculated_amount);
        $this->assertSame('0.00000000', $accrual->final_amount);
    }

    public function test_recalculation_does_not_create_a_duplicate(): void
    {
        $lot = $this->createLot();
        $date = Carbon::parse('2026-08-15');

        $service = $this->service();
        $service->recalculateForLot($lot, $date, $date);
        $first = DailyAccrual::firstOrFail();
        $service->recalculateForLot($lot, $date, $date);
        $second = DailyAccrual::firstOrFail();

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, DailyAccrual::count());
    }

    public function test_adjustment_amount_is_preserved_during_recalculation(): void
    {
        $lot = $this->createLot();
        $date = Carbon::parse('2026-08-15');
        $accrual = $this->service()->calculateForLot($lot, $date);
        $accrual->update(['adjustment_amount' => '1.25000000']);

        $this->service()->recalculateForLot($lot, $date, $date);
        $recalculated = $accrual->fresh();

        $this->assertSame('1.25000000', $recalculated->adjustment_amount);
        $this->assertSame('10.92741935', $recalculated->final_amount);
    }

    public function test_it_does_not_accrue_before_accrual_start_date(): void
    {
        $lot = $this->createLot(['accrual_start_date' => '2026-08-15']);

        $accrual = $this->service()->calculateForLot($lot, Carbon::parse('2026-08-14'));

        $this->assertNull($accrual);
        $this->assertDatabaseCount('daily_accruals', 0);
    }

    public function test_it_does_not_accrue_for_an_inactive_lot(): void
    {
        $lot = $this->createLot(['status' => 'closed']);

        $accrual = $this->service()->calculateForLot($lot, Carbon::parse('2026-08-15'));

        $this->assertNull($accrual);
        $this->assertDatabaseCount('daily_accruals', 0);
    }

    private function service(): DailyAccrualService
    {
        return app(DailyAccrualService::class);
    }

    private function createLot(array $attributes = []): InvestmentLot
    {
        $user = User::factory()->create(['role' => 'investor']);
        $investor = Investor::create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $account = InvestmentAccount::create([
            'investor_id' => $investor->id,
        ]);

        return InvestmentLot::create(array_merge([
            'investment_account_id' => $account->id,
            'original_amount' => '10000.00000000',
            'remaining_amount' => '10000.00000000',
            'received_at' => '2026-08-01 10:00:00',
            'accrual_start_date' => '2026-08-01',
            'monthly_rate' => '3.0000',
            'status' => 'active',
        ], $attributes));
    }
}
