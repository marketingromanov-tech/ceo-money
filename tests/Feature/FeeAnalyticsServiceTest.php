<?php

namespace Tests\Feature;

use App\Models\DepositRequest;
use App\Models\DividendCapitalization;
use App\Models\FeeRule;
use App\Models\InvestmentAccount;
use App\Models\Investor;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\FeeAnalyticsService;
use App\Services\FeeCalculatorService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeeAnalyticsServiceTest extends TestCase
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

    public function test_completed_canonical_sources_are_classified_without_double_counting(): void
    {
        [$investor, $account] = $this->context();
        $this->deposit($investor, $account, '10.12345678', 'platform_fee', 'confirmed', '2026-08-11 09:00:00');
        $this->withdrawal($investor, $account, 'dividend', '2.00000000', 'platform_fee', 'investor', 'paid', '2026-08-11 10:00:00');
        $this->withdrawal($investor, $account, 'capital', '3.00000000', 'platform_fee', 'company', 'paid', '2026-08-10 10:00:00');
        $this->capitalization($investor, $account, '4.00000000', 'platform_fee', 'completed', '2026-08-11 11:00:00');
        $this->deposit($investor, $account, '5.00000000', 'provider_cost', 'confirmed', '2026-08-11 09:00:00');
        $this->deposit($investor, $account, '6.00000000', null, 'confirmed', '2026-08-11 09:00:00');
        $this->deposit($investor, $account, '99.00000000', 'platform_fee', 'submitted', null);

        $summary = app(FeeAnalyticsService::class)->today();

        $this->assertSame('16.12345678', $summary['platform_revenue']['USDT']);
        $this->assertSame('5.00000000', $summary['provider_cost']['USDT']);
        $this->assertSame('6.00000000', $summary['unclassified']['USDT']);
        $this->assertSame('19.12345678', app(FeeAnalyticsService::class)->currentMonth()['platform_revenue']['USDT']);
    }

    public function test_period_is_inclusive_currency_safe_and_uses_decimal_strings(): void
    {
        [$investor, $account] = $this->context();
        $this->deposit($investor, $account, '9999999999.99999999', 'platform_fee', 'confirmed', '2026-08-01 00:00:00');
        $this->deposit($investor, $account, '0.00000001', 'platform_fee', 'confirmed', '2026-08-31 23:59:59', 'USDT');
        $this->deposit($investor, $account, '7.50000000', 'platform_fee', 'confirmed', '2026-08-11 09:00:00', 'USDC');

        $service = app(FeeAnalyticsService::class);
        $summary = $service->forPeriod(Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'));

        $this->assertSame('10000000000.00000000', $summary['platform_revenue']['USDT']);
        $this->assertSame('7.50000000', $summary['platform_revenue']['USDC']);
        $this->assertIsString($summary['platform_revenue']['USDT']);
        $this->assertSame(['USDC', 'USDT'], array_keys($summary['platform_revenue']));
    }

    public function test_incomplete_operations_and_transaction_reversals_do_not_change_revenue(): void
    {
        [$investor, $account] = $this->context();
        $this->withdrawal($investor, $account, 'dividend', '8', 'platform_fee', 'investor', 'review', null);
        $this->capitalization($investor, $account, '9', 'provider_cost', 'pending', null);

        $this->assertSame([], app(FeeAnalyticsService::class)->allTime()['platform_revenue']);
        $this->assertSame([], app(FeeAnalyticsService::class)->allTime()['provider_cost']);
    }

    public function test_economic_type_is_independent_from_payer_and_is_returned_by_calculator(): void
    {
        [$investor] = $this->context();

        foreach (['investor', 'company'] as $payer) {
            foreach (FeeRule::ECONOMIC_TYPES as $economicType) {
                FeeRule::query()->delete();
                FeeRule::create(['operation_type'=>'deposit','scope'=>'global','currency'=>'USDT','fee_type'=>'fixed','fixed_value'=>'1','percent_value'=>'0','payer'=>$payer,'economic_type'=>$economicType,'valid_from'=>'2026-01-01','is_active'=>true]);
                $result = app(FeeCalculatorService::class)->calculate('deposit', $investor, '100', 'USDT', Carbon::today());
                $this->assertSame($economicType, $result['economic_type']);
                $this->assertSame($payer, $result['payer']);
                $this->assertSame($payer === 'company' ? '100.00000000' : '99.00000000', $result['net_amount']);
            }
        }
    }

    private function context(): array
    {
        $user = User::factory()->create(['role' => 'investor']);
        $investor = Investor::create(['user_id' => $user->id, 'code' => 'INV-ANALYTICS', 'status' => 'active']);
        $account = InvestmentAccount::create(['investor_id' => $investor->id, 'currency' => 'USDT', 'status' => 'active']);

        return [$investor, $account];
    }

    private function deposit(Investor $investor, InvestmentAccount $account, string $fee, ?string $type, string $status, ?string $at, string $currency = 'USDT'): DepositRequest
    {
        return DepositRequest::create(['investor_id'=>$investor->id,'investment_account_id'=>$account->id,'requested_amount'=>'100','fee_amount'=>$fee,'fee_payer'=>'investor','fee_economic_type_snapshot'=>$type,'currency'=>$currency,'status'=>$status,'requested_at'=>'2026-08-01','confirmed_at'=>$at]);
    }

    private function withdrawal(Investor $investor, InvestmentAccount $account, string $operation, string $fee, ?string $type, string $payer, string $status, ?string $at): WithdrawalRequest
    {
        return WithdrawalRequest::create(['investor_id'=>$investor->id,'investment_account_id'=>$account->id,'type'=>$operation,'requested_amount'=>'100','reserved_amount'=>'100','fee_amount'=>$fee,'fee_payer'=>$payer,'fee_economic_type_snapshot'=>$type,'net_amount'=>'90','currency'=>'USDT','status'=>$status,'requested_at'=>'2026-08-01','paid_at'=>$at]);
    }

    private function capitalization(Investor $investor, InvestmentAccount $account, string $fee, ?string $type, string $status, ?string $at): DividendCapitalization
    {
        return DividendCapitalization::create(['investor_id'=>$investor->id,'investment_account_id'=>$account->id,'requested_amount'=>'100','fee_amount'=>$fee,'fee_payer'=>'investor','fee_economic_type_snapshot'=>$type,'capitalized_amount'=>'90','currency'=>'USDT','status'=>$status,'capitalized_at'=>$at ?? now()]);
    }
}
