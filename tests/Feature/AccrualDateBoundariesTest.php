<?php

namespace Tests\Feature;

use App\Models\AccrualPause;
use App\Models\InvestmentAccount;
use App\Models\InvestmentLot;
use App\Models\InvestmentTerm;
use App\Models\Investor;
use App\Models\User;
use App\Services\DailyAccrualService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccrualDateBoundariesTest extends TestCase
{
    use RefreshDatabase;

    public function test_term_valid_from_is_inclusive(): void
    {
        $lot = $this->createLot();
        $this->createTerm($lot, ['monthly_rate' => '4.0000', 'valid_from' => '2026-08-10']);

        $this->assertRate($lot, '2026-08-09', '1.0000');
        $this->assertRate($lot, '2026-08-10', '4.0000');
    }

    public function test_term_valid_to_is_inclusive(): void
    {
        $lot = $this->createLot();
        $this->createTerm($lot, [
            'monthly_rate' => '4.0000',
            'valid_from' => '2026-08-01',
            'valid_to' => '2026-08-10',
        ]);

        $this->assertRate($lot, '2026-08-10', '4.0000');
        $this->assertRate($lot, '2026-08-11', '1.0000');
    }

    public function test_next_term_applies_from_its_valid_from_date(): void
    {
        $lot = $this->createLot();
        $this->createTerm($lot, [
            'monthly_rate' => '2.0000',
            'valid_from' => '2026-08-01',
            'valid_to' => '2026-08-09',
        ]);
        $this->createTerm($lot, ['monthly_rate' => '3.0000', 'valid_from' => '2026-08-10']);

        $this->assertRate($lot, '2026-08-09', '2.0000');
        $this->assertRate($lot, '2026-08-10', '3.0000');
    }

    public function test_lot_term_has_priority_over_account_term(): void
    {
        $lot = $this->createLot();
        $this->createTerm($lot, ['monthly_rate' => '2.0000', 'investment_lot_id' => null]);
        $this->createTerm($lot, ['monthly_rate' => '3.0000']);

        $this->assertRate($lot, '2026-08-10', '3.0000');
    }

    public function test_lot_snapshot_is_used_after_explicit_lot_term_expires(): void
    {
        $lot = $this->createLot();
        $this->createTerm($lot, ['monthly_rate' => '2.0000', 'investment_lot_id' => null]);
        $this->createTerm($lot, [
            'monthly_rate' => '3.0000',
            'valid_to' => '2026-08-09',
        ]);

        $this->assertRate($lot, '2026-08-09', '3.0000');
        $this->assertRate($lot, '2026-08-10', '1.0000');
    }

    public function test_lot_rate_is_used_when_no_term_is_active(): void
    {
        $lot = $this->createLot(['monthly_rate' => '1.2345']);
        $this->createTerm($lot, [
            'monthly_rate' => '3.0000',
            'valid_from' => '2026-07-01',
            'valid_to' => '2026-07-31',
        ]);

        $this->assertRate($lot, '2026-08-10', '1.2345');
    }

    public function test_pause_start_date_is_inclusive(): void
    {
        $lot = $this->createLot();
        $this->createPause($lot, ['start_date' => '2026-08-10', 'end_date' => '2026-08-12']);

        $this->assertNotPaused($lot, '2026-08-09');
        $this->assertPaused($lot, '2026-08-10');
    }

    public function test_pause_end_date_is_inclusive(): void
    {
        $lot = $this->createLot();
        $this->createPause($lot, ['start_date' => '2026-08-10', 'end_date' => '2026-08-12']);

        $this->assertPaused($lot, '2026-08-12');
        $this->assertNotPaused($lot, '2026-08-13');
    }

    public function test_open_ended_pause_remains_active(): void
    {
        $lot = $this->createLot();
        $this->createPause($lot, ['start_date' => '2026-08-10', 'end_date' => null]);

        $this->assertPaused($lot, '2027-08-10');
    }

    public function test_pause_can_target_a_specific_lot(): void
    {
        $lot = $this->createLot();
        $this->createPause($lot);

        $this->assertPaused($lot, '2026-08-10');
    }

    public function test_account_pause_applies_to_all_its_lots(): void
    {
        $lot = $this->createLot();
        $otherLot = $this->createLotForAccount($lot->investmentAccount);
        $this->createPause($lot, ['investment_lot_id' => null]);

        $this->assertPaused($lot, '2026-08-10');
        $this->assertPaused($otherLot, '2026-08-10');
    }

    public function test_pause_for_another_lot_does_not_affect_current_lot(): void
    {
        $lot = $this->createLot();
        $otherLot = $this->createLotForAccount($lot->investmentAccount);
        $this->createPause($otherLot);

        $this->assertPaused($otherLot, '2026-08-10');
        $this->assertNotPaused($lot, '2026-08-10');
    }

    private function assertRate(InvestmentLot $lot, string $date, string $expected): void
    {
        $accrual = $this->service()->calculateForLot($lot, Carbon::parse($date));

        $this->assertSame($expected, $accrual->monthly_rate);
    }

    private function assertPaused(InvestmentLot $lot, string $date): void
    {
        $accrual = $this->service()->calculateForLot($lot, Carbon::parse($date));

        $this->assertSame('0.00000000', $accrual->final_amount);
    }

    private function assertNotPaused(InvestmentLot $lot, string $date): void
    {
        $accrual = $this->service()->calculateForLot($lot, Carbon::parse($date));

        $this->assertNotSame('0.00000000', $accrual->final_amount);
    }

    private function createTerm(InvestmentLot $lot, array $attributes = []): InvestmentTerm
    {
        return InvestmentTerm::create(array_merge([
            'investment_account_id' => $lot->investment_account_id,
            'investment_lot_id' => $lot->id,
            'monthly_rate' => '2.0000',
            'valid_from' => '2026-08-01',
        ], $attributes));
    }

    private function createPause(InvestmentLot $lot, array $attributes = []): AccrualPause
    {
        return AccrualPause::create(array_merge([
            'investment_account_id' => $lot->investment_account_id,
            'investment_lot_id' => $lot->id,
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
        ], $attributes));
    }

    private function createLot(array $attributes = []): InvestmentLot
    {
        $user = User::factory()->create(['role' => 'investor']);
        $investor = Investor::create(['user_id' => $user->id, 'status' => 'active']);
        $account = InvestmentAccount::create(['investor_id' => $investor->id]);

        return $this->createLotForAccount($account, $attributes);
    }

    private function createLotForAccount(InvestmentAccount $account, array $attributes = []): InvestmentLot
    {
        return InvestmentLot::create(array_merge([
            'investment_account_id' => $account->id,
            'original_amount' => '10000.00000000',
            'remaining_amount' => '10000.00000000',
            'received_at' => '2026-08-01 10:00:00',
            'accrual_start_date' => '2026-08-01',
            'monthly_rate' => '1.0000',
            'status' => 'active',
        ], $attributes));
    }

    private function service(): DailyAccrualService
    {
        return app(DailyAccrualService::class);
    }
}
