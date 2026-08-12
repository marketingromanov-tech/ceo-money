<?php

namespace Tests\Feature;

use App\Livewire\Admin\Dashboard;
use App\Livewire\Admin\Inbox\Index;
use App\Models\{DailyAccrual,DepositRequest,InvestmentAccount,InvestmentLot,InvestmentTerm,Investor,InvestorWallet,InvestorWithdrawalDetail,User,WithdrawalRequest};
use App\Services\{DepositVerificationService,FinancialOperationReadinessService,WithdrawalVerificationService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FinancialOperationsCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_readiness_reasons_and_ready_queue_deep_links():void
    {
        [$admin,$investor,$account,$lot]=$this->context();
        $deposit=DepositRequest::create(['investor_id'=>$investor->id,'investment_account_id'=>$account->id,'requested_amount'=>'100','received_amount'=>'100','fee_amount'=>'0','fee_payer'=>'investor','net_investment_amount'=>'100','currency'=>'USDT','txid'=>'ready-center-deposit','status'=>'submitted','requested_at'=>now()]);
        $depositChecks=app(DepositVerificationService::class);$depositChecks->initializeForRequest($deposit);foreach(DepositVerificationService::MANUAL_KEYS as $key)$depositChecks->markPassed($deposit,$key,$admin);
        $wallet=InvestorWallet::create(['investor_id'=>$investor->id,'currency'=>'USDT','network'=>'TRC20','address'=>'TReadyCenterWallet','status'=>'approved','approved_by'=>$admin->id,'approved_at'=>now()]);
        $withdrawal=WithdrawalRequest::create(['investor_id'=>$investor->id,'investment_account_id'=>$account->id,'investor_wallet_id'=>$wallet->id,'type'=>'dividend','requested_amount'=>'50','reserved_amount'=>'50','fee_amount'=>'1','fee_payer'=>'investor','fee_economic_type_snapshot'=>'platform_fee','net_amount'=>'49','currency'=>'USDT','wallet_address_snapshot'=>$wallet->address,'network_snapshot'=>'TRC20','status'=>'approved','requested_at'=>now()]);
        $withdrawalChecks=app(WithdrawalVerificationService::class);$withdrawalChecks->initializeForRequest($withdrawal);foreach(WithdrawalVerificationService::MANUAL_KEYS as $key)$withdrawalChecks->markPassed($withdrawal,$key,$admin);
        $readiness=app(FinancialOperationReadinessService::class);$this->assertTrue($readiness->deposit($deposit)['ready']);$this->assertTrue($readiness->withdrawal($withdrawal)['ready']);

        Livewire::actingAs($admin)->test(Index::class)->set('tab','ready')->assertSee('Готово к подтверждению')->assertSee('Готово к выплате')->assertSee('?deposit='.$deposit->id,false)->assertSee('?withdrawal='.$withdrawal->id,false)->assertSee('13/13')->assertSee('12/12')->assertSee('Детали операции и история');
        $withdrawal->verificationChecks()->where('check_key','wallet_address_verified')->update(['status'=>'failed']);$state=$readiness->withdrawal($withdrawal->fresh('verificationChecks'));$this->assertFalse($state['ready']);$this->assertStringContainsString('Адрес кошелька',$state['reason']);
    }

    public function test_action_review_support_tabs_and_dashboard_operations_risk():void
    {
        [$admin,$investor,$account]=$this->context();
        DepositRequest::create(['investor_id'=>$investor->id,'investment_account_id'=>$account->id,'requested_amount'=>'20','currency'=>'USDT','status'=>'pending','requested_at'=>now()->subHours(2)]);
        WithdrawalRequest::create(['investor_id'=>$investor->id,'investment_account_id'=>$account->id,'type'=>'dividend','requested_amount'=>'25','reserved_amount'=>'25','fee_amount'=>'2','fee_payer'=>'investor','fee_economic_type_snapshot'=>'platform_fee','net_amount'=>'23','currency'=>'USDT','wallet_address_snapshot'=>'TRisk','network_snapshot'=>'TRC20','status'=>'review','requested_at'=>now()->subDays(2)]);
        InvestorWallet::create(['investor_id'=>$investor->id,'currency'=>'USDT','network'=>'TRC20','address'=>'TPendingRiskWallet','status'=>'pending']);

        Livewire::actingAs($admin)->test(Index::class)->assertSee('Требуют действия')->assertSee('Новый кошелёк')->set('tab','review')->assertSee('Вывод дивидендов')->assertSee('Ожидает');
        Livewire::actingAs($admin)->test(Dashboard::class)->assertViewHas('financialOperations',fn($values)=>$values['waiting']===2)->assertViewHas('riskIndicators',fn($items)=>$items->contains(fn($item)=>$item['label']==='Новый кошелёк')&&$items->contains(fn($item)=>$item['label']==='Зависшая заявка'))->assertSee('Финансовые операции')->assertSee('Риск-индикаторы');
    }

    public function test_readiness_accepts_permanent_details_and_preserves_legacy_wallet_compatibility(): void
    {
        [$admin, $investor, $account] = $this->context();
        $detail = InvestorWithdrawalDetail::create([
            'investor_id' => $investor->id, 'currency' => 'USDT', 'network' => 'TRC20',
            'address' => 'TPermanentReadyAddress', 'is_active' => true,
        ]);
        $request = WithdrawalRequest::create([
            'investor_id' => $investor->id, 'investment_account_id' => $account->id,
            'type' => 'dividend', 'requested_amount' => '50', 'reserved_amount' => '50',
            'fee_amount' => '1', 'fee_payer' => 'investor', 'fee_economic_type_snapshot' => 'platform_fee',
            'net_amount' => '49', 'currency' => 'USDT', 'wallet_address_snapshot' => $detail->address,
            'network_snapshot' => $detail->network, 'status' => 'approved', 'requested_at' => now(),
        ]);
        $verification = app(WithdrawalVerificationService::class);
        $verification->initializeForRequest($request);
        foreach (WithdrawalVerificationService::MANUAL_KEYS as $key) $verification->markPassed($request, $key, $admin);

        $readiness = app(FinancialOperationReadinessService::class);
        $this->assertTrue($readiness->withdrawal($request->fresh('verificationChecks'))['ready']);

        $detail->update(['is_active' => false]);
        $this->assertFalse($readiness->withdrawal($request->fresh('verificationChecks'))['ready']);

        $legacy = InvestorWallet::create([
            'investor_id' => $investor->id, 'currency' => 'USDT', 'network' => 'TRC20',
            'address' => 'TLegacyReadyAddress', 'status' => 'approved',
        ]);
        $request->update(['investor_wallet_id' => $legacy->id]);
        $this->assertTrue($readiness->withdrawal($request->fresh(['verificationChecks', 'investorWallet']))['ready']);
    }

    private function context():array
    {
        $admin=User::factory()->create(['role'=>'admin','is_active'=>true]);$user=User::factory()->create(['role'=>'investor','is_active'=>true]);$investor=Investor::create(['user_id'=>$user->id,'status'=>'active']);$account=InvestmentAccount::create(['investor_id'=>$investor->id,'currency'=>'USDT','status'=>'active']);InvestmentTerm::create(['investment_account_id'=>$account->id,'monthly_rate'=>'3','lock_months'=>0,'valid_from'=>'2020-01-01']);$lot=InvestmentLot::create(['investment_account_id'=>$account->id,'original_amount'=>'1000','remaining_amount'=>'1000','currency'=>'USDT','received_at'=>now()->subYear(),'accrual_start_date'=>now()->subYear(),'lock_months'=>0,'unlock_date'=>now()->subDay(),'monthly_rate'=>'3','status'=>'active']);DailyAccrual::create(['investment_account_id'=>$account->id,'investment_lot_id'=>$lot->id,'accrual_date'=>now(),'principal_amount'=>'1000','monthly_rate'=>'3','days_in_month'=>31,'calculated_amount'=>'200','final_amount'=>'200']);return[$admin,$investor,$account,$lot];
    }
}
