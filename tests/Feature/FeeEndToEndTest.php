<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\DepositRequest;
use App\Models\DividendCapitalization;
use App\Models\FeeRule;
use App\Models\InvestmentLot;
use App\Models\Investor;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\FeeAnalyticsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeeEndToEndTest extends TestCase
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

    public function test_seeded_fee_path_uses_real_workflows_snapshots_analytics_ui_and_audit(): void
    {
        $this->seed();
        $analytics = app(FeeAnalyticsService::class);
        $alexey = Investor::where('code', 'INV-002')->firstOrFail();
        $personalRule = FeeRule::where('scope', 'investor')->where('investor_id', $alexey->id)->sole();
        $globalRule = FeeRule::where('scope', 'global')->where('operation_type', 'dividend_withdrawal')->sole();

        $this->assertSame(['deposit','capital_withdrawal','dividend_withdrawal','capitalization','manual_adjustment'], FeeRule::OPERATION_TYPES);
        $this->assertSame('0.7500', $personalRule->percent_value);
        $this->assertSame('1.0000', $globalRule->percent_value);

        $personal = WithdrawalRequest::where('txid', 'demo-personal-dividend-fee')->sole();
        $this->assertSame($personalRule->id, $personal->fee_rule_id);
        $this->assertSame('100.00000000', $personal->requested_amount);
        $this->assertSame('0.75000000', $personal->fee_amount);
        $this->assertSame('99.25000000', $personal->net_amount);
        $this->assertSame('investor', $personal->fee_payer);
        $this->assertSame('platform_fee', $personal->fee_economic_type_snapshot);
        $this->assertSame('paid', $personal->status);
        $this->assertNotNull($personal->paid_at);
        $this->assertDatabaseHas('dividend_payments', ['withdrawal_request_id'=>$personal->id,'gross_amount'=>'100.00000000','fee_amount'=>'0.75000000','net_amount'=>'99.25000000']);

        $global = WithdrawalRequest::where('txid', 'demo-global-dividend-fee')->sole();
        $this->assertSame($globalRule->id, $global->fee_rule_id);
        $this->assertSame('1.00000000', $global->fee_amount);
        $this->assertSame('99.00000000', $global->net_amount);

        $capital = WithdrawalRequest::where('txid', 'demo-capital-fee')->sole();
        $this->assertSame('7.00000000', $capital->fee_amount);
        $this->assertSame('993.00000000', $capital->net_amount);
        $this->assertSame('platform_fee', $capital->fee_economic_type_snapshot);
        $this->assertSame('1000.00000000', (string) $capital->capitalWithdrawalAllocations()->sum('amount'));
        $this->assertDatabaseHas('investment_transactions', ['investment_account_id'=>$capital->investment_account_id,'type'=>'withdrawal','amount'=>'1000.00000000','status'=>'confirmed']);

        $capitalization = DividendCapitalization::where('investor_id', $alexey->id)->sole();
        $this->assertSame('2.00000000', $capitalization->fee_amount);
        $this->assertSame('398.00000000', $capitalization->capitalized_amount);
        $this->assertSame('platform_fee', $capitalization->fee_economic_type_snapshot);
        $this->assertSame('398.00000000', $capitalization->investmentLot->original_amount);
        $this->assertSame('2.5000', $capitalization->investmentLot->monthly_rate);
        $this->assertSame(3, $capitalization->investmentLot->lock_months);

        $deposit = DepositRequest::where('txid', 'demo-provider-cost-deposit')->sole();
        $this->assertSame(13, $deposit->verificationChecks()->count());
        $this->assertSame(13, $deposit->verificationChecks()->where('status', 'passed')->count());
        $mariaDeposit = DepositRequest::where('txid', 'demo-maria-submitted-001')->sole();
        $this->assertSame(13, $mariaDeposit->verificationChecks()->count());
        $this->assertSame(7, $mariaDeposit->verificationChecks()->where('status', 'passed')->count());
        $this->assertSame('1.00000000', $deposit->fee_amount);
        $this->assertSame('1000.00000000', $deposit->net_investment_amount);
        $this->assertSame('company', $deposit->fee_payer);
        $this->assertSame('provider_cost', $deposit->fee_economic_type_snapshot);
        $this->assertSame('confirmed', $deposit->status);
        $this->assertNotNull($deposit->confirmed_at);
        $this->assertDatabaseHas('investment_lots', ['investment_account_id'=>$deposit->investment_account_id,'original_amount'=>'1000.00000000','remaining_amount'=>'1000.00000000']);
        $this->assertDatabaseHas('investment_transactions', ['investment_account_id'=>$deposit->investment_account_id,'type'=>'deposit','amount'=>'1000.00000000']);

        $legacy = WithdrawalRequest::where('requested_amount', '1200.00000000')->where('status', 'paid')->sole();
        $this->assertNull($legacy->fee_economic_type_snapshot);
        $this->assertSame('10.75000000', $analytics->today()['platform_revenue']['USDT']);
        $this->assertSame('1.00000000', $analytics->today()['provider_cost']['USDT']);
        $this->assertSame('12.00000000', $analytics->allTime()['unclassified']['USDT']);
        $this->assertSame('10.75000000', $analytics->currentMonth()['platform_revenue']['USDT']);
        $this->assertSame('10.75000000', $analytics->forPeriod(Carbon::today(), Carbon::today())['platform_revenue']['USDT']);

        $operations = $analytics->breakdownByOperation(Carbon::today(), Carbon::today());
        $this->assertSame('1.75000000', $operations['dividend_withdrawal']['platform_revenue']['USDT']);
        $this->assertSame('7.00000000', $operations['capital_withdrawal']['platform_revenue']['USDT']);
        $this->assertSame('2.00000000', $operations['capitalization']['platform_revenue']['USDT']);
        $this->assertSame('1.00000000', $operations['deposit']['provider_cost']['USDT']);
        $this->assertSame('10.75000000', $analytics->breakdownByCurrency(Carbon::today(), Carbon::today())['USDT']['platform_revenue']);
        $this->assertTrue($analytics->latestCompleted()->contains(fn ($row) => $row['record_id'] === $personal->id && $row['operation'] === 'dividend_withdrawal'));

        $personalRule->update(['economic_type' => 'provider_cost', 'is_active' => false]);
        $this->assertSame('platform_fee', $personal->fresh()->fee_economic_type_snapshot);
        $this->assertSame('10.75000000', $analytics->allTime()['platform_revenue']['USDT']);

        $actions = AuditLog::where('entity_type', WithdrawalRequest::class)->where('entity_id', $personal->id)->orderBy('id')->pluck('action')->all();
        $this->assertSame(['withdrawal.created','withdrawal.review','withdrawal.approved','withdrawal.dividend_paid'], $actions);
        $this->assertSame(['deposit_request.created','deposit_request.submitted','deposit_request.confirmed'], AuditLog::where('entity_type', DepositRequest::class)->where('entity_id', $deposit->id)->orderBy('id')->pluck('action')->all());
        $this->assertSame(User::where('email', 'admin@example.com')->value('id'), AuditLog::where('entity_type', DepositRequest::class)->where('entity_id', $deposit->id)->where('action', 'deposit_request.created')->value('user_id'));
        $this->assertDatabaseHas('audit_logs', ['action'=>'withdrawal.capital_paid','entity_id'=>$capital->id]);
        $this->assertDatabaseHas('audit_logs', ['action'=>'dividend_capitalization','entity_id'=>$capitalization->id]);

        $admin = User::where('email', 'admin@example.com')->sole();
        $this->actingAs($admin)->get('/admin/fees')->assertOk()->assertSee('Комиссия платформы')->assertSee('Расход провайдера / сети')->assertSee('Фактические комиссии')->assertSee('Алексей Смирнов');
        $this->actingAs($admin)->get('/admin')->assertOk()->assertSee('Доход платформы')->assertSee('Расход провайдера')->assertSee('Не классифицировано');
    }

    public function test_period_boundaries_and_multi_currency_are_exact_and_separate(): void
    {
        $this->seed();
        $alexey = Investor::where('code', 'INV-002')->firstOrFail();
        foreach ([
            ['USDC','2.00000000','2026-08-01 00:00:00'],
            ['USDC','3.00000000','2026-08-31 23:59:59'],
            ['USDC','9.00000000','2026-09-01 00:00:00'],
        ] as [$currency,$fee,$at]) {
            DepositRequest::create(['investor_id'=>$alexey->id,'investment_account_id'=>$alexey->investmentAccounts()->value('id'),'requested_amount'=>'100','fee_amount'=>$fee,'fee_payer'=>'investor','fee_economic_type_snapshot'=>'platform_fee','currency'=>$currency,'status'=>'confirmed','requested_at'=>$at,'confirmed_at'=>$at]);
        }

        $summary = app(FeeAnalyticsService::class)->forPeriod(Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'));
        $this->assertSame('5.00000000', $summary['platform_revenue']['USDC']);
        $this->assertSame('10.75000000', $summary['platform_revenue']['USDT']);
        $this->assertCount(2, $summary['platform_revenue']);
    }
}
