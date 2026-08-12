<?php

namespace Tests\Feature;

use App\Models\FeeRule;
use App\Models\Investor;
use App\Models\User;
use App\Services\FeeCalculatorService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeeCalculatorServiceTest extends TestCase
{
    use RefreshDatabase;

    private Investor $investor;
    private Carbon $date;

    protected function setUp(): void
    {
        parent::setUp();
        $this->investor = Investor::create([
            'user_id' => User::factory()->create()->id,
            'status' => 'active',
        ]);
        $this->date = Carbon::parse('2026-08-10');
    }

    public function test_fixed_percent_and_mixed_fees(): void
    {
        $fixed = $this->rule(['fee_type' => 'fixed', 'fixed_value' => '7.50000000']);
        $this->assertSame('7.50000000', $this->calculate()['fee_amount']);
        $fixed->delete();

        $percent = $this->rule(['fee_type' => 'percent', 'percent_value' => '2.3333']);
        $this->assertSame('23.33300000', $this->calculate()['fee_amount']);
        $percent->delete();

        $this->rule(['fee_type' => 'mixed', 'percent_value' => '2.0000', 'fixed_value' => '3.00000000']);
        $this->assertSame('23.00000000', $this->calculate()['fee_amount']);
    }

    public function test_minimum_and_maximum_fee_limits(): void
    {
        $minimum = $this->rule([
            'fee_type' => 'percent', 'percent_value' => '1.0000', 'minimum_fee' => '15.00000000',
        ]);
        $this->assertSame('15.00000000', $this->calculate()['fee_amount']);
        $minimum->delete();

        $this->rule([
            'fee_type' => 'percent', 'percent_value' => '10.0000', 'maximum_fee' => '25.00000000',
        ]);
        $this->assertSame('25.00000000', $this->calculate()['fee_amount']);
    }

    public function test_investor_rule_has_priority_and_global_rule_is_fallback(): void
    {
        $global = $this->rule(['fee_type' => 'fixed', 'fixed_value' => '5.00000000']);
        $investorRule = $this->rule([
            'scope' => 'investor', 'investor_id' => $this->investor->id,
            'fee_type' => 'fixed', 'fixed_value' => '3.00000000',
        ]);

        $this->assertSame($investorRule->id, $this->calculate()['fee_rule_id']);

        $investorRule->update(['is_active' => false]);
        $this->assertSame($global->id, $this->calculate()['fee_rule_id']);
    }

    public function test_currency_specific_rule_has_priority_over_currency_neutral_rule(): void
    {
        $this->rule(['fee_type' => 'fixed', 'fixed_value' => '5.00000000']);
        $specific = $this->rule([
            'currency' => 'USDT', 'fee_type' => 'fixed', 'fixed_value' => '2.00000000',
        ]);

        $this->assertSame($specific->id, $this->calculate()['fee_rule_id']);
    }

    public function test_validity_boundaries_are_inclusive_and_inactive_rules_are_ignored(): void
    {
        $active = $this->rule([
            'fee_type' => 'fixed', 'fixed_value' => '4.00000000',
            'valid_from' => '2026-08-10', 'valid_to' => '2026-08-10',
        ]);
        $this->rule([
            'fee_type' => 'fixed', 'fixed_value' => '9.00000000', 'is_active' => false,
        ]);

        $this->assertSame($active->id, $this->calculate()['fee_rule_id']);
    }

    public function test_company_and_investor_payers_affect_net_amount(): void
    {
        $company = $this->rule([
            'fee_type' => 'fixed', 'fixed_value' => '10.00000000', 'payer' => 'company',
        ]);
        $result = $this->calculate();
        $this->assertSame('1000.00000000', $result['net_amount']);
        $this->assertSame('company', $result['payer']);
        $company->delete();

        $this->rule(['fee_type' => 'fixed', 'fixed_value' => '10.00000000', 'payer' => 'investor']);
        $result = $this->calculate();
        $this->assertSame('990.00000000', $result['net_amount']);
        $this->assertSame('investor', $result['payer']);
    }

    public function test_net_amount_never_becomes_negative(): void
    {
        $this->rule(['fee_type' => 'fixed', 'fixed_value' => '2000.00000000']);

        $this->assertSame('0.00000000', $this->calculate()['net_amount']);
    }

    private function calculate(): array
    {
        return app(FeeCalculatorService::class)->calculate(
            'deposit', $this->investor, '1000.00000000', 'USDT', $this->date,
        );
    }

    private function rule(array $attributes): FeeRule
    {
        return FeeRule::create(array_merge([
            'operation_type' => 'deposit',
            'scope' => 'global',
            'fee_type' => 'fixed',
            'valid_from' => '2026-01-01',
        ], $attributes));
    }
}
