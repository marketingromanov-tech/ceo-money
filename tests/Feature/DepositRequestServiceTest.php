<?php

namespace Tests\Feature;

use App\Models\DepositRequest;
use App\Models\AuditLog;
use App\Models\FeeRule;
use App\Models\InvestmentAccount;
use App\Models\InvestmentLot;
use App\Models\InvestmentTerm;
use App\Models\InvestmentTransaction;
use App\Models\Investor;
use App\Models\User;
use App\Services\DepositRequestService;
use App\Services\DepositVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DepositRequestServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmation_creates_one_transaction_and_one_lot_and_is_idempotent(): void
    {
        [$request] = $this->context();
        $service = app(DepositRequestService::class);
        $this->completeVerification($request);

        $service->confirm($request);
        $service->confirm($request);

        $this->assertDatabaseCount('investment_lots', 1);
        $this->assertDatabaseCount('investment_transactions', 1);
        $this->assertSame('confirmed', $request->fresh()->status);
        $this->assertSame('deposit', InvestmentTransaction::first()->type);
        $this->assertSame(1, AuditLog::where('action', 'deposit_request.confirmed')->count());
        $log = AuditLog::where('action', 'deposit_request.confirmed')->sole();
        $this->assertSame(InvestmentLot::first()->id, $log->new_values['investment_lot_id']);
        $this->assertSame(InvestmentTransaction::first()->id, $log->new_values['investment_transaction_id']);
    }

    public function test_investor_fee_reduces_net_investment_amount(): void
    {
        [$request, $account] = $this->context();
        $this->fee($account, 'investor');
        $this->completeVerification($request);

        app(DepositRequestService::class)->confirm($request);

        $this->assertSame('90.00000000', $request->fresh()->net_investment_amount);
        $this->assertSame('90.00000000', InvestmentLot::first()->original_amount);
    }

    public function test_company_fee_does_not_reduce_the_lot(): void
    {
        [$request, $account] = $this->context();
        $this->fee($account, 'company');
        $this->completeVerification($request);

        app(DepositRequestService::class)->confirm($request);

        $this->assertSame('10.00000000', $request->fresh()->fee_amount);
        $this->assertSame('100.00000000', InvestmentLot::first()->original_amount);
    }

    public function test_received_amount_is_used_and_account_term_is_snapshotted(): void
    {
        [$request, $account] = $this->context(['received_amount' => '120.00000000']);
        InvestmentTerm::create([
            'investment_account_id' => $account->id,
            'monthly_rate' => '2.5000',
            'lock_months' => 6,
            'valid_from' => '2020-01-01',
        ]);
        $this->completeVerification($request);

        app(DepositRequestService::class)->confirm($request);
        $lot = InvestmentLot::firstOrFail();

        $this->assertSame('120.00000000', $lot->original_amount);
        $this->assertSame('2.5000', $lot->monthly_rate);
        $this->assertSame(6, $lot->lock_months);
        $this->assertSame($lot->accrual_start_date->copy()->addMonthsNoOverflow(6)->toDateString(), $lot->unlock_date->toDateString());
    }

    private function context(array $requestAttributes = []): array
    {
        $user = User::factory()->create(['role' => 'investor']);
        $investor = Investor::create(['user_id' => $user->id, 'status' => 'active']);
        $account = InvestmentAccount::create([
            'investor_id' => $investor->id, 'currency' => 'USDT', 'status' => 'active',
        ]);
        InvestmentTerm::create([
            'investment_account_id' => $account->id,
            'monthly_rate' => '3.0000',
            'lock_months' => 0,
            'valid_from' => '2020-01-01',
        ]);
        $request = DepositRequest::create(array_merge([
            'investor_id' => $investor->id,
            'investment_account_id' => $account->id,
            'requested_amount' => '100.00000000',
            'received_amount' => '100.00000000',
            'currency' => 'USDT',
            'status' => 'submitted',
            'requested_at' => now(),
        ], $requestAttributes));

        return [$request, $account];
    }

    private function fee(InvestmentAccount $account, string $payer): FeeRule
    {
        return FeeRule::create([
            'operation_type' => 'deposit',
            'scope' => 'global',
            'currency' => $account->currency,
            'fee_type' => 'fixed',
            'fixed_value' => '10.00000000',
            'payer' => $payer,
            'valid_from' => '2020-01-01',
        ]);
    }

    private function completeVerification(DepositRequest $request): void
    {
        if ($request->txid === null) $request->update(['txid' => 'service-test-'.$request->id]);
        if ($request->net_investment_amount === null) {
            $fee = app(DepositRequestService::class)->preview($request, (string) ($request->received_amount ?? $request->requested_amount));
            $request->update(['fee_rule_id'=>$fee['fee_rule_id'],'fee_amount'=>$fee['fee_amount'],'fee_payer'=>$fee['payer'],'fee_economic_type_snapshot'=>$fee['economic_type'],'net_investment_amount'=>$fee['net_amount']]);
        }
        $admin=User::factory()->create(['role'=>'admin','is_active'=>true]);$service=app(DepositVerificationService::class);$service->initializeForRequest($request);foreach(DepositVerificationService::MANUAL_KEYS as $key)$service->markPassed($request,$key,$admin);
    }
}
