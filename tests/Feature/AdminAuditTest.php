<?php

namespace Tests\Feature;

use App\Livewire\Admin\Audit\Index;
use App\Models\AuditLog;
use App\Models\DepositAddress;
use App\Models\FeeRule;
use App\Models\InvestmentAccount;
use App\Models\InvestmentTerm;
use App\Models\InvestmentTransaction;
use App\Models\Investor;
use App\Models\InvestorWallet;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); Carbon::setTestNow('2026-08-10 22:15:00'); }
    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    public function test_access_route_sidebar_and_read_only_page(): void
    {
        [$admin,$investorUser]=$this->context();
        $this->get(route('admin.audit.index'))->assertRedirect(route('login'));
        $this->actingAs($investorUser)->get(route('admin.audit.index'))->assertForbidden();
        $this->actingAs($admin)->get(route('admin.audit.index'))->assertOk()->assertSee('Журнал финансовых и административных действий')->assertSee('Пользователь, инвестор, действие или ID…')->assertDontSee('Пользователь, инвестор, action или ID…')->assertDontSee('Удалить журнал');
        $this->assertSame('/admin/audit',route('admin.audit.index',absolute:false));
    }

    public function test_newest_first_actions_actor_system_object_investor_and_unknown_are_safe(): void
    {
        [$admin,,$investor]=$this->context();
        $wallet=InvestorWallet::create(['investor_id'=>$investor->id,'currency'=>'USDT','network'=>'TRC20','address'=>'TAuditWalletAddress001','status'=>'approved']);
        $older=$this->audit('fee_rule_created',FeeRule::class,999,$admin,['is_active'=>false],['is_active'=>true],'2026-08-10 20:00:00');
        $newer=$this->audit('investor_wallet.approved',InvestorWallet::class,$wallet->id,$admin,['status'=>'pending'],['status'=>'approved'],'2026-08-10 21:00:00');
        $this->audit('unknown.custom_action','App\\Models\\DeletedSubject',999,null,null,['note'=>'ok'],'2026-08-10 19:00:00');

        Livewire::actingAs($admin)->test(Index::class)->set('period','all')
            ->assertViewHas('logs',fn($logs)=>$logs->first()->id===$newer->id&&$logs->last()->id!==$newer->id)
            ->assertSee('Кошелёк инвестора одобрен')->assertSee('Технический код')->assertSee('investor_wallet.approved')->assertSee($admin->name)->assertSee('Система')
            ->assertSee('Кошелёк инвестора')->assertSee($investor->user->name)->assertSee($investor->code)
            ->assertSee('Unknown Custom Action')->assertSee('DeletedSubject')->assertSee('x-data="{ expanded:false }"',false)
            ->assertSee('lg:hidden',false)->assertSee('Подробнее')->assertDontSee('Удалить')->assertDontSee('Восстановить');
    }

    public function test_real_action_presentations_and_categories_are_centralized(): void
    {
        [$admin,,$investor,$account]=$this->context();
        $term=InvestmentTerm::create(['investment_account_id'=>$account->id,'monthly_rate'=>'3.0000','lock_months'=>6,'valid_from'=>'2026-08-01']);
        $wallet=InvestorWallet::create(['investor_id'=>$investor->id,'currency'=>'USDT','network'=>'TRC20','address'=>'TWallet','status'=>'approved']);
        $address=DepositAddress::create(['investor_id'=>$investor->id,'currency'=>'USDT','network'=>'TRC20','address'=>'TDeposit','is_active'=>true]);
        $transaction=InvestmentTransaction::create(['investment_account_id'=>$account->id,'type'=>'deposit','amount'=>'100.00000000','currency'=>'USDT','effective_date'=>'2026-08-10','status'=>'confirmed']);
        foreach([['investment_term_created',$term],['investor_wallet.blocked',$wallet],['deposit_address.archived',$address],['investment_transaction.reversed',$transaction]] as [$action,$entity])$this->audit($action,$entity::class,$entity->id,$admin,null,[]);
        $this->audit('dividend_capitalization',\App\Models\DividendCapitalization::class,999,$admin,null,['requested_amount'=>'400.00000000','fee'=>'0.00000000','capitalized_amount'=>'400.00000000','investment_lot_id'=>3,'investment_transaction_id'=>4]);

        Livewire::actingAs($admin)->test(Index::class)->assertSee('Изменены условия инвестирования')->assertSee('Кошелёк инвестора заблокирован')->assertSee('Адрес пополнения архивирован')->assertSee('Финансовая операция сторнирована')->assertSee('Дивиденды капитализированы')->assertSee('Капитализация дивидендов')->assertSee('Запрошено')->assertSee('Переведено в капитал')->assertSee('Внутренний ID инвестиции')->assertSee('Внутренний ID операции')->assertSee('400.00 USDT')->assertSee('0.00 USDT')->assertSee('ID 3')->assertSee('Финансовое')->assertSee('Административное')->assertSee('Основная информация')->assertSee('Изменения')->assertSee('Технические данные')->assertSee('Источник запроса')->assertDontSee('User agent');
    }

    public function test_old_new_comparison_formats_values_and_masks_sensitive_fields(): void
    {
        [$admin,,,$account]=$this->context(); $term=InvestmentTerm::create(['investment_account_id'=>$account->id,'monthly_rate'=>'3.0000','lock_months'=>6,'valid_from'=>'2026-08-01']);
        $this->audit('investment_term_created',InvestmentTerm::class,$term->id,$admin,['monthly_rate'=>'2.5000','lock_months'=>3,'password'=>'old','api_key'=>'secret'],['monthly_rate'=>'3.0000','lock_months'=>6,'password'=>'new','private_key'=>'private']);
        Livewire::actingAs($admin)->test(Index::class)->assertSee('Ставка')->assertSee('2.50%')->assertSee('3.00%')->assertSee('3 месяца')->assertSee('6 месяцев')->assertSee('••••••••')->assertDontSee('>old<',false)->assertDontSee('>private<',false);
    }

    public function test_payment_id_is_presented_as_identifier_and_boolean_fields_remain_boolean(): void
    {
        $this->assertSame('ID платежа', \App\Support\AuditPresentation::field('payment_id'));
        $this->assertSame('1', \App\Support\AuditPresentation::value('payment_id', 1));
        $this->assertNotSame('Да', \App\Support\AuditPresentation::value('payment_id', 1));
        $this->assertSame('Да', \App\Support\AuditPresentation::value('partial_withdrawal_allowed', true));
        $this->assertSame('Нет', \App\Support\AuditPresentation::value('is_active', 0));
        $this->assertSame('ID 1', \App\Support\AuditPresentation::value('investment_lot_id', 1));
        $this->assertSame('1', \App\Support\AuditPresentation::value('entity_id', 1));
        $this->assertSame('1', \App\Support\AuditPresentation::value('user_id', 1));
        $this->assertSame('1', \App\Support\AuditPresentation::value('account_id', 1));
    }

    public function test_action_category_actor_investor_object_and_search_filters(): void
    {
        [$admin,,$investor]=$this->context(); [$otherAdmin,,$otherInvestor]=$this->context('audit-other@example.com');
        $wallet=InvestorWallet::create(['investor_id'=>$investor->id,'currency'=>'USDT','network'=>'TRC20','address'=>'TFilterWallet','status'=>'approved']);
        $other=InvestorWallet::create(['investor_id'=>$otherInvestor->id,'currency'=>'USDT','network'=>'ERC20','address'=>'TOtherWallet','status'=>'blocked']);
        $this->audit('investor_wallet.approved',InvestorWallet::class,$wallet->id,$admin); $this->audit('investor_wallet.blocked',InvestorWallet::class,$other->id,$otherAdmin); $this->audit('withdrawal.review',\App\Models\WithdrawalRequest::class,999,$admin);
        $component=Livewire::actingAs($admin)->test(Index::class);
        $component->set('action','investor_wallet.approved')->assertViewHas('logs',fn($p)=>$p->total()===1)->set('action','')->set('category','financial')->assertViewHas('logs',fn($p)=>$p->total()===1)->set('category','')->set('actorId',(string)$otherAdmin->id)->assertViewHas('logs',fn($p)=>$p->total()===1)->set('actorId','')->set('investorId',(string)$investor->id)->assertViewHas('logs',fn($p)=>$p->total()===1)->set('investorId','')->set('objectType',InvestorWallet::class)->assertViewHas('logs',fn($p)=>$p->total()===2)->set('objectType','')->set('search',$otherInvestor->code)->assertViewHas('logs',fn($p)=>$p->total()===1);
    }

    public function test_today_week_month_and_custom_period_are_inclusive(): void
    {
        [$admin]=$this->context();
        foreach(['2026-08-10 00:00:00','2026-08-04 00:00:00','2026-07-12 12:00:00','2026-07-11 23:59:59'] as $i=>$date)$this->audit('fee_rule_created',FeeRule::class,100+$i,$admin,null,null,$date);
        $component=Livewire::actingAs($admin)->test(Index::class)->set('period','today')->assertViewHas('logs',fn($p)=>$p->total()===1)->set('period','7')->assertViewHas('logs',fn($p)=>$p->total()===2)->set('period','30')->assertViewHas('logs',fn($p)=>$p->total()===3)->set('period','custom')->set('customFrom','2026-07-11')->set('customTo','2026-08-04')->call('applyCustomPeriod')->assertHasNoErrors()->assertViewHas('logs',fn($p)=>$p->total()===3);
    }

    public function test_pagination_empty_state_and_filters_persist_when_page_changes(): void
    {
        [$admin]=$this->context(); for($i=1;$i<=26;$i++)$this->audit('fee_rule_created',FeeRule::class,$i,$admin);
        Livewire::actingAs($admin)->test(Index::class)->set('action','fee_rule_created')->assertViewHas('logs',fn($p)=>$p->perPage()===25&&$p->total()===26)->call('nextPage')->assertSet('action','fee_rule_created')->assertSet('paginators.page',2)->set('search','nothing-can-match')->assertSee('События не найдены');
    }

    public function test_critical_lifecycle_actions_have_russian_labels_categories_and_objects(): void
    {
        $presentation = \App\Support\AuditPresentation::class;
        $this->assertSame('Создана заявка на пополнение', $presentation::label('deposit_request.created'));
        $this->assertSame('Пополнение принято на проверку', $presentation::label('deposit_request.submitted'));
        $this->assertSame('Пополнение отклонено', $presentation::label('deposit_request.rejected'));
        $this->assertSame('Пополнение отменено', $presentation::label('deposit_request.cancelled'));
        $this->assertSame('Пополнение подтверждено', $presentation::label('deposit_request.confirmed'));
        $this->assertSame('Создана заявка на вывод', $presentation::label('withdrawal.created'));
        $this->assertSame('Создан инвестор', $presentation::label('investor.created'));
        $this->assertSame('financial', $presentation::category('deposit_request.created'));
        $this->assertSame('administrative', $presentation::category('investor.created'));
        $this->assertSame('Заявка на пополнение', $presentation::object(\App\Models\DepositRequest::class));
        $this->assertSame('Инвестор', $presentation::object(Investor::class));
    }

    private function context(string $email='audit-investor@example.com'): array
    {
        $admin=User::factory()->create(['role'=>'admin','is_active'=>true]);$user=User::factory()->create(['role'=>'investor','is_active'=>true,'email'=>$email]);$investor=Investor::create(['user_id'=>$user->id,'code'=>'AUD-'.User::count(),'status'=>'active']);$account=InvestmentAccount::create(['investor_id'=>$investor->id,'currency'=>'USDT','status'=>'active']);return[$admin,$user,$investor,$account];
    }

    private function audit(string $action,string $type,int $id,?User $user=null,?array $old=null,?array $new=null,?string $date=null): AuditLog
    {
        return AuditLog::forceCreate(['user_id'=>$user?->id,'action'=>$action,'entity_type'=>$type,'entity_id'=>$id,'old_values'=>$old,'new_values'=>$new,'ip_address'=>'127.0.0.1','user_agent'=>'PHPUnit','created_at'=>$date??now()]);
    }
}
