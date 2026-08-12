<?php
namespace Tests\Feature;

use App\Livewire\Admin\Fees\Index;
use App\Models\AuditLog;
use App\Models\FeeRule;
use App\Models\InvestmentAccount;
use App\Models\Investor;
use App\Models\User;
use App\Models\WithdrawalRequest;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminFeesTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp():void{parent::setUp();Carbon::setTestNow('2026-08-10 12:00:00');}
    protected function tearDown():void{Carbon::setTestNow();parent::tearDown();}

    public function test_access_list_and_presentations():void
    {
        $admin=$this->admin(); [$investor]=$this->investor();
        $this->rule(['fee_type'=>'fixed','fixed_value'=>'5','minimum_fee'=>'2','maximum_fee'=>'100','currency'=>'USDT']);
        $this->rule(['operation_type'=>'dividend_withdrawal','scope'=>'investor','investor_id'=>$investor->id,'fee_type'=>'percent','percent_value'=>'1','valid_from'=>'2026-08-20']);
        $this->rule(['operation_type'=>'capital_withdrawal','fee_type'=>'mixed','fixed_value'=>'5','percent_value'=>'0.5','currency'=>'USDT','valid_from'=>'2026-01-01','valid_to'=>'2026-08-01']);
        $this->rule(['operation_type'=>'capitalization','is_active'=>false]);
        $this->actingAs($admin)->get('/admin/fees')->assertOk()->assertSee('Правила комиссий для финансовых операций');
        Livewire::test(Index::class)->assertSee('Пополнение')->assertSee('Вывод дивидендов')->assertSee('Вывод капитала')->assertSee('Капитализация дивидендов')->assertSee('Глобальное правило')->assertSee('Алексей Смирнов')->assertSee('5.00 USDT')->assertSee('1.00%')->assertSee('5.00 USDT + 0.50%')->assertSee('Инвестор')->assertSee('Активно')->assertSee('Запланировано')->assertSee('Истекло')->assertSee('Неактивно')->assertSee('мин. 2.00')->assertSee('макс. 100.00');
        $this->actingAs(User::factory()->create(['role'=>'investor']))->get('/admin/fees')->assertForbidden();
    }

    public function test_create_edit_toggle_validation_overlap_and_audit():void
    {
        $admin=$this->admin(); [$investor]=$this->investor(); $this->actingAs($admin);
        $component=Livewire::test(Index::class)->call('openCreate')->set('operationType','deposit')->set('scope','investor')->set('investorId',(string)$investor->id)->set('feeType','mixed')->set('fixedValue','5')->set('percentValue','1.5')->set('minimumFee','2')->set('maximumFee','100')->set('payer','company')->set('currency','USDT')->set('validFrom','2026-08-10')->call('save')->assertHasNoErrors();
        $rule=FeeRule::sole(); $this->assertSame('investor',$rule->scope); $this->assertDatabaseHas('audit_logs',['action'=>'fee_rule_created','entity_id'=>$rule->id,'user_id'=>$admin->id]);
        Livewire::test(Index::class)->call('edit',$rule->id)->set('percentValue','2.25')->call('save')->assertHasNoErrors();
        $this->assertSame('2.2500',$rule->fresh()->percent_value); $this->assertDatabaseHas('audit_logs',['action'=>'fee_rule_updated','entity_id'=>$rule->id]);
        Livewire::test(Index::class)->call('toggle',$rule->id); $this->assertFalse($rule->fresh()->is_active); $this->assertDatabaseHas('audit_logs',['action'=>'fee_rule_deactivated','entity_id'=>$rule->id]);
        Livewire::test(Index::class)->call('toggle',$rule->id); $this->assertTrue($rule->fresh()->is_active); $this->assertDatabaseHas('audit_logs',['action'=>'fee_rule_activated','entity_id'=>$rule->id]); $this->assertDatabaseCount('fee_rules',1);
        Livewire::test(Index::class)->call('openCreate')->set('feeType','percent')->set('percentValue','0')->call('save')->assertHasErrors('percentValue');
        Livewire::test(Index::class)->call('openCreate')->set('minimumFee','10')->set('maximumFee','5')->call('save')->assertHasErrors('maximumFee');
        Livewire::test(Index::class)->call('openCreate')->set('validFrom','2026-09-01')->set('validTo','2026-08-01')->call('save')->assertHasErrors('validTo');
        $this->rule(['operation_type'=>'deposit','currency'=>'USDT','valid_from'=>'2026-08-01']);
        Livewire::test(Index::class)->call('openCreate')->assertSee('В этом периоде уже существует правило');
    }

    public function test_filters_pagination_preview_resolution_priorities_and_snapshot_stability():void
    {
        $admin=$this->admin(); [$investor,$account]=$this->investor(); $this->actingAs($admin);
        $global=$this->rule(['fixed_value'=>'9','currency'=>null,'valid_from'=>'2026-01-01']);
        $specific=$this->rule(['fixed_value'=>'7','currency'=>'USDT','valid_from'=>'2026-02-01']);
        $personalOld=$this->rule(['scope'=>'investor','investor_id'=>$investor->id,'fixed_value'=>'5','currency'=>'USDT','valid_from'=>'2026-03-01']);
        $personalLatest=$this->rule(['scope'=>'investor','investor_id'=>$investor->id,'fixed_value'=>'3','currency'=>'USDT','valid_from'=>'2026-04-01']);
        for($i=0;$i<18;$i++)$this->rule(['operation_type'=>'capitalization','fixed_value'=>(string)($i+1),'valid_from'=>'2025-01-01']);
        Livewire::test(Index::class)->set('operationFilter','capitalization')->assertViewHas('rules',fn($p)=>$p->total()===18)->set('operationFilter','')->set('scopeFilter','investor')->assertViewHas('rules',fn($p)=>$p->total()===2)->set('scopeFilter','')->set('currencyFilter','USDT')->assertViewHas('rules',fn($p)=>$p->total()===3)->set('currencyFilter','')->call('nextPage')->assertViewHas('rules',fn($p)=>$p->currentPage()===2&&$p->total()===22)->set('operationFilter','deposit')->assertViewHas('rules',fn($p)=>$p->currentPage()===1&&$p->total()===4);
        Livewire::test(Index::class)->set('resolveInvestorId',(string)$investor->id)->set('resolveOperation','deposit')->set('resolveCurrency','USDT')->set('resolveDate','2026-08-10')->set('resolveAmount','1000')->call('resolve')->assertViewHas('resolution',fn($r)=>$r['rule_id']===$personalLatest->id&&$r['fee_amount']==='3.00000000');
        Livewire::test(Index::class)->call('edit',$personalLatest->id)->set('previewAmount','1000')->assertViewHas('preview',fn($p)=>$p['fee_amount']==='3.00000000');
        $request=WithdrawalRequest::create(['investor_id'=>$investor->id,'investment_account_id'=>$account->id,'type'=>'dividend','requested_amount'=>'100','reserved_amount'=>'100','fee_rule_id'=>$personalLatest->id,'fee_amount'=>'3','fee_payer'=>'investor','net_amount'=>'97','currency'=>'USDT','status'=>'new','requested_at'=>now()]);
        Livewire::test(Index::class)->call('edit',$personalLatest->id)->set('fixedValue','20')->call('save');
        $this->assertSame('3.00000000',$request->fresh()->fee_amount); $this->assertSame('97.00000000',$request->fresh()->net_amount);
    }

    public function test_economic_type_is_required_and_legacy_rule_must_be_classified_on_edit(): void
    {
        $this->actingAs($this->admin());
        Livewire::test(Index::class)->call('openCreate')->set('economicType', '')->call('save')->assertHasErrors('economicType');
        $legacy = $this->rule(['economic_type' => null]);
        Livewire::test(Index::class)->call('edit', $legacy->id)->call('save')->assertHasErrors('economicType');
        Livewire::test(Index::class)->call('edit', $legacy->id)->set('economicType', 'provider_cost')->call('save')->assertHasNoErrors();
        $this->assertSame('provider_cost', $legacy->fresh()->economic_type);
    }

    private function admin():User{return User::factory()->create(['role'=>'admin','is_active'=>true]);}
    private function investor():array{$u=User::factory()->create(['name'=>'Алексей Смирнов','email'=>'alexey-fees@example.com','role'=>'investor']);$i=Investor::create(['user_id'=>$u->id,'code'=>'INV-FEES','status'=>'active']);$a=InvestmentAccount::create(['investor_id'=>$i->id,'currency'=>'USDT','status'=>'active']);return[$i,$a];}
    private function rule(array $a):FeeRule{return FeeRule::create(array_merge(['operation_type'=>'deposit','scope'=>'global','fee_type'=>'fixed','fixed_value'=>'1','percent_value'=>'0','payer'=>'investor','valid_from'=>'2026-01-01','is_active'=>true],$a));}
}
