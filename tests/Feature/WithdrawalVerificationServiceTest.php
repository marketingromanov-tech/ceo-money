<?php

namespace Tests\Feature;

use App\Models\{AuditLog, DailyAccrual, InvestmentAccount, InvestmentLot, Investor, User, WithdrawalRequest};
use App\Services\WithdrawalVerificationService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WithdrawalVerificationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_initializes_twelve_checks_and_system_snapshots(): void
    {
        [$request] = $this->context(); $service = app(WithdrawalVerificationService::class); $service->initializeForRequest($request);
        $this->assertSame(WithdrawalVerificationService::KEYS, $request->verificationChecks()->orderBy('id')->pluck('check_key')->all());
        $this->assertSame(5, $request->verificationChecks()->where('status', 'passed')->count());
        $this->assertSame('TRC20', $request->verificationChecks()->where('check_key', 'wallet_snapshot_exists')->sole()->value_snapshot['network']);
    }

    public function test_manual_checks_admin_final_order_audit_and_terminal_freeze(): void
    {
        [$request,$admin] = $this->context(); $service=app(WithdrawalVerificationService::class); $service->initializeForRequest($request);
        try{$service->markPassed($request,'wallet_address_verified',$request->investor->user);$this->fail('Only admin');}catch(DomainException){$this->addToAssertionCount(1);}
        try{$service->markPassed($request,'final_reconciliation',$admin);$this->fail('Final must be last');}catch(DomainException){$this->addToAssertionCount(1);}
        foreach(array_diff(WithdrawalVerificationService::MANUAL_KEYS,['final_reconciliation']) as $key)$service->markPassed($request,$key,$admin);
        $service->markPassed($request,'final_reconciliation',$admin);$this->assertTrue($service->allRequiredPassed($request));$this->assertSame(7,AuditLog::where('action','withdrawal_verification.passed')->count());
        $request->update(['status'=>'cancelled']);$this->expectException(DomainException::class);$service->resetManualCheck($request,'wallet_address_verified',$admin);
    }

    public function test_payout_gate_rechecks_balance_race(): void
    {
        [$request,$admin,$account]=$this->context();$service=app(WithdrawalVerificationService::class);$service->initializeForRequest($request);foreach(WithdrawalVerificationService::MANUAL_KEYS as $key)$service->markPassed($request,$key,$admin);$request->update(['status'=>'approved']);$service->assertReadyForPayout($request);
        $account->dailyAccruals()->delete();try{$service->assertReadyForPayout($request);$this->fail('Balance race');}catch(DomainException){$this->addToAssertionCount(1);}$this->assertSame('passed',$request->verificationChecks()->where('check_key','available_balance_verified')->sole()->status);
    }

    public function test_missing_wallet_bad_fee_and_locked_capital_fail(): void
    {
        [$request,,$account]=$this->context('capital');$request->update(['wallet_address_snapshot'=>null,'net_amount'=>'98']);$account->investmentLots()->update(['unlock_date'=>now()->addMonth()]);app(WithdrawalVerificationService::class)->initializeForRequest($request);
        foreach(WithdrawalVerificationService::SYSTEM_KEYS as $key)$this->assertSame('failed',$request->verificationChecks()->where('check_key',$key)->sole()->status);
    }

    private function context(string $type='dividend'):array
    {
        $admin=User::factory()->create(['role'=>'admin','is_active'=>true]);$user=User::factory()->create(['role'=>'investor','is_active'=>true]);$investor=Investor::create(['user_id'=>$user->id,'status'=>'active']);$account=InvestmentAccount::create(['investor_id'=>$investor->id,'currency'=>'USDT','status'=>'active']);$lot=InvestmentLot::create(['investment_account_id'=>$account->id,'original_amount'=>'1000','remaining_amount'=>'1000','currency'=>'USDT','received_at'=>now()->subYear(),'accrual_start_date'=>now()->subYear(),'lock_months'=>0,'unlock_date'=>now()->subDay(),'monthly_rate'=>'3','status'=>'active']);DailyAccrual::create(['investment_account_id'=>$account->id,'investment_lot_id'=>$lot->id,'accrual_date'=>now(),'principal_amount'=>'1000','monthly_rate'=>'3','days_in_month'=>31,'calculated_amount'=>'200','final_amount'=>'200']);$request=WithdrawalRequest::create(['investor_id'=>$investor->id,'investment_account_id'=>$account->id,'type'=>$type,'requested_amount'=>'100','reserved_amount'=>'100','fee_amount'=>'1','fee_payer'=>'investor','fee_economic_type_snapshot'=>'platform_fee','net_amount'=>'99','currency'=>'USDT','wallet_address_snapshot'=>'TVerifiedWallet001','network_snapshot'=>'TRC20','status'=>'review','requested_at'=>now()]);return[$request,$admin,$account];
    }
}
