<?php

namespace Tests\Feature;

use App\Livewire\Admin\InvestmentPrograms\Index;
use App\Livewire\Investor\Programs;
use App\Livewire\Investor\CreateDeposit;
use App\Models\AuditLog;
use App\Models\DepositRequest;
use App\Models\DailyAccrual;
use App\Models\InvestmentAccount;
use App\Models\InvestmentLot;
use App\Models\InvestmentTransaction;
use App\Models\InvestmentProgram;
use App\Models\Investor;
use App\Models\InvestorPaymentDetail;
use App\Models\User;
use App\Services\CeoAnalyticsService;
use App\Services\DepositRequestService;
use App\Services\DepositVerificationService;
use App\Services\InvestmentProgramService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class InvestmentProgramTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); Carbon::setTestNow('2026-08-11'); }
    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    public function test_admin_creates_program_version_and_audit_and_investor_cannot_edit(): void
    {
        $admin=User::factory()->create(['role'=>'admin']);
        Livewire::actingAs($admin)->test(Index::class)->set('name','Start')->set('description','Начальная программа')->set('currency','USDT')->set('minAmount','1000')->set('maxAmount','2499')->set('status','active')->set('monthlyRate','2.0000')->set('lockMonths','3')->set('validFrom','2026-08-01')->call('save')->assertHasNoErrors();
        $program=InvestmentProgram::first();
        $this->assertSame('Start',$program->name);$this->assertSame('2.0000',$program->versions()->first()->monthly_rate);
        $this->assertDatabaseHas('audit_logs',['action'=>'investment_program.created','entity_id'=>$program->id]);
        $this->assertDatabaseHas('audit_logs',['action'=>'investment_program.version_created','entity_id'=>$program->id]);
        $investor=$this->investorUser();
        $this->actingAs($investor)->get(route('admin.investment-programs.index'))->assertForbidden();
        Livewire::actingAs($investor)->test(Programs::class)->assertSee('Start');
    }

    public function test_visibility_ranges_version_history_snapshots_and_old_lots_are_stable(): void
    {
        $admin=User::factory()->create(['role'=>'admin']);$service=app(InvestmentProgramService::class);
        $program=InvestmentProgram::create(['name'=>'Advanced','slug'=>'advanced','status'=>'active','currency'=>'USDT','min_amount'=>'5000','max_amount'=>'9999','is_partial_withdrawal_allowed'=>true,'created_by'=>$admin->id]);
        $v1=$service->createVersion($program,'3.0000',6,Carbon::parse('2026-01-01'),$admin);
        $this->assertCount(1,$service->matching('7000','USDT',Carbon::today()));$this->assertCount(0,$service->matching('500','USDT',Carbon::today()));
        $investor=Investor::create(['user_id'=>$this->investorUser()->id,'status'=>'active']);$account=InvestmentAccount::create(['investor_id'=>$investor->id,'currency'=>'USDT','status'=>'active']);
        $term=$service->termFor($program,$account,Carbon::today(),$admin);$this->assertSame('3.0000',$term->monthly_rate);$this->assertSame(6,$term->lock_months);
        $lot=InvestmentLot::create(['investment_account_id'=>$account->id,'original_amount'=>'7000','remaining_amount'=>'7000','currency'=>'USDT','received_at'=>now(),'accrual_start_date'=>now(),'lock_months'=>6,'unlock_date'=>now()->addMonths(6),'monthly_rate'=>'3','status'=>'active']);$service->snapshot($lot,$program,$v1);
        $service->createVersion($program,'3.5000',9,Carbon::parse('2026-09-01'),$admin);$lot->refresh();
        $this->assertSame('3.0000',$lot->monthly_rate);$this->assertSame(6,$lot->lock_months);$this->assertSame('Advanced',$lot->investment_program_name_snapshot);$this->assertSame('v1',$lot->investment_program_version_snapshot);
        $this->assertSame('2026-08-31',$v1->fresh()->valid_to->toDateString());
        $analytics=app(CeoAnalyticsService::class)->investmentPrograms();$row=collect($analytics)->firstWhere('program.id',$program->id);
        $this->assertSame('7000.00000000',$row['capital']);$this->assertSame(1,$row['investors_count']);$this->assertSame(1,$row['lots_count']);
        $program->update(['status'=>'archived']);Livewire::actingAs($investor->user)->test(Programs::class)->assertDontSee('Advanced');
    }

    public function test_seeded_program_distribution_and_decimal_presentation_have_no_float(): void
    {
        $this->seed();
        $this->assertSame(5,InvestmentProgram::count());
        $this->assertSame([1,2,2,1,1],InvestmentProgram::orderBy('min_amount')->withCount('lots')->pluck('lots_count')->all());
        $this->assertStringNotContainsString('(float)',file_get_contents(resource_path('views/livewire/admin/investment-programs/index.blade.php')));
        $this->assertStringNotContainsString('(float)',file_get_contents(resource_path('views/livewire/investor/programs.blade.php')));
    }

    public function test_admin_condition_edit_creates_version_keeps_old_lot_and_new_deposit_uses_new_version(): void
    {
        $admin=User::factory()->create(['role'=>'admin','is_active'=>true]);$service=app(InvestmentProgramService::class);
        $program=InvestmentProgram::create(['name'=>'Flexible','slug'=>'flexible','status'=>'active','currency'=>'USDT','min_amount'=>'100','max_amount'=>'1000','is_partial_withdrawal_allowed'=>true,'created_by'=>$admin->id]);
        $oldVersion=$service->createVersion($program,'2.0000',3,Carbon::parse('2026-01-01'),$admin);
        $investor=Investor::create(['user_id'=>$this->investorUser()->id,'status'=>'active']);$account=InvestmentAccount::create(['investor_id'=>$investor->id,'currency'=>'USDT','status'=>'active']);
        $oldLot=InvestmentLot::create(['investment_account_id'=>$account->id,'original_amount'=>'500','remaining_amount'=>'500','currency'=>'USDT','received_at'=>now(),'accrual_start_date'=>now(),'lock_months'=>3,'unlock_date'=>now()->addMonths(3),'monthly_rate'=>'2','status'=>'active']);$service->snapshot($oldLot,$program,$oldVersion);

        Livewire::actingAs($admin)->test(Index::class)->call('edit',$program->id)->assertSet('monthlyRate','2.0000')->assertSet('lockMonths','3')->assertSee('Изменение применяется только к новым инвестициям')->set('monthlyRate','2.7500')->set('lockMonths','6')->set('validFrom','2026-08-12')->call('save')->assertHasNoErrors();

        $this->assertSame(2,$program->versions()->count());$this->assertSame('2026-08-11',$oldVersion->fresh()->valid_to->toDateString());
        $this->assertDatabaseHas('investment_program_versions',['investment_program_id'=>$program->id,'monthly_rate'=>'2.7500','lock_months'=>6,'valid_from'=>'2026-08-12','created_by'=>$admin->id]);
        $this->assertDatabaseHas('audit_logs',['action'=>'investment_program.version_updated','entity_id'=>$program->id]);
        $this->assertSame('2.0000',$oldLot->fresh()->monthly_rate);$this->assertSame(3,$oldLot->fresh()->lock_months);

        Carbon::setTestNow('2026-08-12');
        $request=DepositRequest::create(['investor_id'=>$investor->id,'investment_account_id'=>$account->id,'requested_amount'=>'600','received_amount'=>'600','net_investment_amount'=>'600','fee_amount'=>'0','fee_payer'=>'investor','currency'=>'USDT','txid'=>'program-version-deposit','status'=>'submitted','requested_at'=>now()]);
        $service->termFor($program,$account,Carbon::today(),$admin);
        $verification=app(DepositVerificationService::class);$verification->initializeForRequest($request);foreach(DepositVerificationService::MANUAL_KEYS as $key)$verification->markPassed($request,$key,$admin);
        app(DepositRequestService::class)->confirm($request,$admin,program:$program);
        $newLot=InvestmentLot::latest('id')->first();$this->assertSame('2.7500',$newLot->monthly_rate);$this->assertSame(6,$newLot->lock_months);$this->assertSame('v2',$newLot->investment_program_version_snapshot);
    }

    public function test_admin_program_table_uses_business_column_labels(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Livewire::actingAs($admin)->test(Index::class)
            ->assertSee('Программа')
            ->assertSee('Диапазон суммы')
            ->assertSee('Доходность в месяц')
            ->assertSee('Доступность капитала')
            ->assertSee('Статус')
            ->assertSee('Инвесторы')
            ->assertSee('Активные инвестиции')
            ->assertSee('Капитал программы')
            ->assertSee('Действия');

        $view = file_get_contents(resource_path('views/livewire/admin/investment-programs/index.blade.php'));
        $this->assertSame(2, substr_count($view, 'min-[1380px]:grid-cols-[var(--program-grid)]'));
        $this->assertStringNotContainsString('grid-cols-[1.3fr_1fr_.9fr_.9fr_.7fr_.6fr_.7fr_.8fr_auto]', $view);
    }

    public function test_investor_selector_shows_every_active_program_without_amount_filter(): void
    {
        $this->seed();$user=User::where('email','alexey@example.com')->firstOrFail();
        Livewire::actingAs($user)->test(Programs::class)->assertSee('Start')->assertSee('Standard')->assertSee('Advanced')->assertSee('Premium')->assertSee('VIP')->assertSee('Выбрать Start')->assertSee('Выбрать Premium')->assertDontSee('Сколько вы планируете инвестировать?');
        $this->assertStringNotContainsString('(float)',file_get_contents(app_path('Livewire/Investor/Programs.php')));
        $this->assertStringNotContainsString('(float)',file_get_contents(resource_path('views/livewire/investor/programs.blade.php')));
    }

    public function test_selector_redirects_to_wizard_without_creating_request_or_investment(): void
    {
        $this->seed();$user=User::where('email','alexey@example.com')->firstOrFail();$program=InvestmentProgram::where('slug','premium')->firstOrFail();$before=[DepositRequest::count(),InvestmentLot::count(),InvestmentTransaction::count()];
        Livewire::actingAs($user)->test(Programs::class)->call('selectProgram',$program->id)->assertRedirect(route('investor.finance.create',['investment_program_id'=>$program->id]));
        $this->assertSame($before,[DepositRequest::count(),InvestmentLot::count(),InvestmentTransaction::count()]);
    }

    public function test_deposit_wizard_reviews_and_creates_only_pending_request_with_program(): void
    {
        $this->seed();$user=User::where('email','alexey@example.com')->firstOrFail();$program=InvestmentProgram::where('slug','advanced')->firstOrFail();$requests=DepositRequest::count();$lots=InvestmentLot::count();$transactions=InvestmentTransaction::count();
        $this->actingAs($user)->get(route('investor.finance'))->assertOk()->assertSee('Новая инвестиция')->assertSee('Пополнить')->assertSee(route('investor.programs'),false);
        Livewire::actingAs($user)->test(Programs::class)->call('selectProgram',$program->id)->assertRedirect(route('investor.finance.create',['investment_program_id'=>$program->id]));
        $this->assertSame($requests,DepositRequest::count());
        $this->actingAs($user)->get(route('investor.finance.create',['investment_program_id'=>$program->id]))->assertOk()->assertSee('Advanced')->assertSee('Сумма инвестиции');
        $wizard=Livewire::actingAs($user)->test(CreateDeposit::class)->set('investmentProgramId',$program->id)->set('amount','7000')->assertSee('210.00 USDT / месяц')->assertSee('1 260.00 USDT')->call('review')->assertSet('step',2)->assertSee('Проверьте данные');
        $this->assertSame($requests,DepositRequest::count());
        $wizard->call('submit')->assertSet('step',3)->assertSee('Заявка создана')->assertSee('Ожидает пополнения');
        $request=DepositRequest::latest('id')->firstOrFail();$this->assertSame($program->id,$request->investment_program_id);$this->assertSame('7000.00000000',$request->requested_amount);$this->assertSame('pending',$request->status);
        $this->assertEqualsCanonicalizing(['program_id'=>$program->id,'version_id'=>$program->versions()->sole()->id,'name'=>'Advanced','slug'=>'advanced','currency'=>'USDT','min_amount'=>'5000.00000000','max_amount'=>'9999.00000000','monthly_rate'=>'3.0000','lock_months'=>6,'partial_withdrawal_allowed'=>true],$request->investment_program_snapshot);
        $this->assertSame($lots,InvestmentLot::count());$this->assertSame($transactions,InvestmentTransaction::count());
        Livewire::actingAs($user)->test(\App\Livewire\Investor\Finance::class)->assertSee('Advanced')->assertSee('7 000.00 USDT')->assertSee('Реквизиты ожидаются');
    }

    public function test_deposit_request_program_snapshot_stays_immutable_when_program_changes_or_is_deleted(): void
    {
        $this->seed();$user=User::where('email','alexey@example.com')->firstOrFail();$program=InvestmentProgram::where('slug','advanced')->firstOrFail();
        Livewire::actingAs($user)->test(CreateDeposit::class)->set('investmentProgramId',$program->id)->set('amount','7000')->call('review')->call('submit')->assertSet('step',3);
        $request=DepositRequest::latest('id')->firstOrFail();$snapshot=$request->investment_program_snapshot;
        $program->update(['name'=>'Advanced Updated','min_amount'=>'6000']);$program->delete();
        $request->refresh();
        $this->assertNull($request->investment_program_id);$this->assertSame($snapshot,$request->investment_program_snapshot);$this->assertSame('Advanced',$request->investment_program_snapshot['name']);$this->assertSame('3.0000',$request->investment_program_snapshot['monthly_rate']);
        Livewire::actingAs($user)->test(\App\Livewire\Investor\Finance::class)->assertSee('Advanced')->assertDontSee('Advanced Updated');
    }

    public function test_wizard_snapshots_active_investor_payment_details_without_creating_financial_entities(): void
    {
        $this->seed();$user=User::where('email','alexey@example.com')->firstOrFail();$investor=$user->investor;$program=InvestmentProgram::where('slug','advanced')->firstOrFail();
        InvestorPaymentDetail::create(['investor_id'=>$investor->id,'currency'=>'USDC','network'=>'ERC20','address'=>'WrongCurrencyAddress','is_active'=>true]);
        $details=InvestorPaymentDetail::create(['investor_id'=>$investor->id,'currency'=>'USDT','network'=>'TRC20','address'=>'TInvestorPaymentDetails001','memo'=>'INV-002','is_active'=>true]);
        $lots=InvestmentLot::count();$accruals=DailyAccrual::count();

        Livewire::actingAs($user)->test(CreateDeposit::class)->set('investmentProgramId',$program->id)->set('amount','7000')->call('review')->call('submit')->assertSet('step',3);

        $request=DepositRequest::latest('id')->firstOrFail();
        $this->assertEqualsCanonicalizing(['payment_detail_id'=>$details->id,'currency'=>'USDT','network'=>'TRC20','address'=>'TInvestorPaymentDetails001','memo'=>'INV-002'],$request->payment_details_snapshot);
        $sameDetailsRequest=app(DepositRequestService::class)->create($investor->investmentAccounts()->where('status','active')->firstOrFail(),'7000',actor:$user,program:$program);
        $this->assertSame($details->id,$sameDetailsRequest->payment_details_snapshot['payment_detail_id']);$this->assertEqualsCanonicalizing($request->payment_details_snapshot,$sameDetailsRequest->payment_details_snapshot);
        $snapshot=$request->payment_details_snapshot;$details->update(['network'=>'BEP20','address'=>'ChangedPaymentAddress','memo'=>'CHANGED','is_active'=>false]);$request->refresh();
        $this->assertSame($snapshot,$request->payment_details_snapshot);$this->assertSame('TInvestorPaymentDetails001',$request->payment_details_snapshot['address']);
        $current=InvestorPaymentDetail::create(['investor_id'=>$investor->id,'currency'=>'USDT','network'=>'TRC20','address'=>'TCurrentPaymentDetails002','is_active'=>true]);
        $newRequest=app(DepositRequestService::class)->create($investor->investmentAccounts()->where('status','active')->firstOrFail(),'7000',actor:$user,program:$program);
        $this->assertSame($current->id,$newRequest->payment_details_snapshot['payment_detail_id']);$this->assertSame('TCurrentPaymentDetails002',$newRequest->payment_details_snapshot['address']);
        $this->assertSame($lots,InvestmentLot::count());$this->assertSame($accruals,DailyAccrual::count());
    }

    public function test_investor_opens_payment_details_and_marks_request_paid_without_creating_investment(): void
    {
        $this->seed();$user=User::where('email','alexey@example.com')->firstOrFail();$program=InvestmentProgram::where('slug','advanced')->firstOrFail();
        InvestorPaymentDetail::create(['investor_id'=>$user->investor->id,'currency'=>'USDT','network'=>'TRC20','address'=>'TPaymentModalAddress001','memo'=>'PAY-7000','is_active'=>true]);
        $lots=InvestmentLot::count();$accruals=DailyAccrual::count();
        Livewire::actingAs($user)->test(CreateDeposit::class)->set('investmentProgramId',$program->id)->set('amount','7000')->call('review')->call('submit');
        $request=DepositRequest::latest('id')->firstOrFail();
        Livewire::actingAs($user)->test(\App\Livewire\Investor\Finance::class)->assertSee('Ожидает оплаты')->assertSee('Оплатить')->call('openPayment',$request->id)->assertSet('showPaymentModal',true)->assertSee('Advanced')->assertSee('Точная сумма к отправке')->assertSee('7 000.00')->assertSee('USDT')->assertSee('TRC20')->assertSee('TPaymentModalAddress001')->assertSee('PAY-7000')->assertSee('Копировать')->assertSee('Я оплатил')->call('markPaid')->assertSet('showPaymentModal',false)->assertSee('Платёж отправлен')->assertSee('Ожидает проверки администратора');
        $this->assertSame('payment_submitted',$request->fresh()->status);$this->assertSame($lots,InvestmentLot::count());$this->assertSame($accruals,DailyAccrual::count());
        $admin=User::where('role','admin')->firstOrFail();Livewire::actingAs($admin)->test(\App\Livewire\Admin\Deposits\Index::class)->assertSee('Оплачено инвестором')->assertSee('Проверить оплату')->call('openAction',$request->id)->assertSet('selectedDepositId',$request->id);
    }

    public function test_pending_without_snapshot_has_no_payment_action_and_foreign_investor_cannot_mark_paid(): void
    {
        $this->seed();$owner=User::where('email','alexey@example.com')->firstOrFail();$foreign=User::where('email','maria@example.com')->firstOrFail();$account=$owner->investor->investmentAccounts()->where('status','active')->firstOrFail();
        $without=DepositRequest::create(['investor_id'=>$owner->investor->id,'investment_account_id'=>$account->id,'requested_amount'=>'100','currency'=>'USDT','status'=>'pending','requested_at'=>now()]);
        Livewire::actingAs($owner)->test(\App\Livewire\Investor\Finance::class)->assertSee('Администратор ещё не назначил реквизиты для пополнения')->assertSee('Реквизиты ожидаются')->assertDontSee('Ожидает оплаты');
        $assigned=InvestorPaymentDetail::create(['investor_id'=>$owner->investor->id,'currency'=>'USDT','network'=>'TRC20','address'=>'TLaterAssignedPermanentAddress','is_active'=>true]);
        Livewire::actingAs($owner)->test(\App\Livewire\Investor\Finance::class)->assertSee('Ожидает оплаты')->assertSee('Оплатить')->call('openPayment',$without->id)->assertSet('showPaymentModal',true)->assertSee('TLaterAssignedPermanentAddress');
        $this->assertSame($assigned->id,$without->fresh()->payment_details_snapshot['payment_detail_id']);
        $with=DepositRequest::create(['investor_id'=>$owner->investor->id,'investment_account_id'=>$account->id,'requested_amount'=>'100','currency'=>'USDT','status'=>'pending','requested_at'=>now()->addSecond(),'payment_details_snapshot'=>['payment_detail_id'=>1,'currency'=>'USDT','network'=>'TRC20','address'=>'TOwnedPaymentAddress','memo'=>null]]);
        try{app(DepositRequestService::class)->markPaymentSubmitted($with,$foreign);$this->fail('Foreign investor must not submit payment');}catch(\DomainException){$this->addToAssertionCount(1);}
        $this->assertSame('pending',$with->fresh()->status);
    }

    public function test_admin_manages_investor_payment_details(): void
    {
        $this->seed();$admin=User::where('role','admin')->firstOrFail();$investor=User::where('email','alexey@example.com')->firstOrFail()->investor;
        $component=Livewire::actingAs($admin)->test(\App\Livewire\Admin\Investors\Show::class,['investor'=>$investor])->call('selectTab','wallets')->assertSet('activeTab','wallets')->assertSee('Платёжные реквизиты для пополнения')->assertSee('Добавить реквизиты')->call('openPaymentDetailForm')->set('paymentCurrency','USDT')->set('paymentNetwork','TRC20')->set('paymentAddress','TAdminManagedPayment001')->set('paymentMemo','ADMIN-MEMO')->set('paymentIsActive',true)->call('savePaymentDetail')->assertHasNoErrors()->assertSee('TAdminManagedPayment001');
        $detail=InvestorPaymentDetail::where('address','TAdminManagedPayment001')->sole();$this->assertTrue($detail->is_active);$this->assertSame($investor->id,$detail->investor_id);
        $component->call('openPaymentDetailForm',$detail->id)->assertSet('paymentAddress','TAdminManagedPayment001')->set('paymentNetwork','ERC20')->set('paymentAddress','TAdminManagedPaymentEdited')->call('savePaymentDetail')->assertHasNoErrors();
        $this->assertDatabaseHas('investor_payment_details',['id'=>$detail->id,'investor_id'=>$investor->id,'network'=>'ERC20','address'=>'TAdminManagedPaymentEdited']);
        $component->call('togglePaymentDetail',$detail->id);$this->assertFalse($detail->fresh()->is_active);
        $investorUser=User::where('email','alexey@example.com')->firstOrFail();$this->actingAs($investorUser)->get(route('admin.investors.show',$investor))->assertForbidden();
    }

    public function test_deposit_wizard_rejects_archived_missing_version_out_of_range_and_foreign_currency_programs(): void
    {
        $this->seed();$user=User::where('email','alexey@example.com')->firstOrFail();$advanced=InvestmentProgram::where('slug','advanced')->firstOrFail();
        Livewire::actingAs($user)->test(CreateDeposit::class)->set('investmentProgramId',$advanced->id)->set('amount','4000')->assertSee('Минимальная инвестиция для программы Advanced — 5 000 USDT')->assertSee('Ваша сумма — 4 000.00 USDT')->assertSee('Необходимо добавить 1 000.00 USDT')->assertSee('Инвестировать 5 000 USDT')->call('review')->assertHasErrors('amount');
        Livewire::actingAs($user)->test(CreateDeposit::class)->set('investmentProgramId',$advanced->id)->set('amount','12000')->assertSee('Максимальная сумма программы — 9 999 USDT')->assertSee('Выбрать другую программу');
        $advanced->update(['status'=>'archived']);Livewire::actingAs($user)->test(CreateDeposit::class)->set('investmentProgramId',$advanced->id)->set('amount','7000')->call('review')->assertHasErrors('program')->assertSee('Программа больше недоступна');
        $foreign=InvestmentProgram::create(['name'=>'Foreign','slug'=>'foreign','status'=>'active','currency'=>'USDC','min_amount'=>'1000','max_amount'=>'9999','is_partial_withdrawal_allowed'=>true]);app(InvestmentProgramService::class)->createVersion($foreign,'3',3,Carbon::parse('2026-01-01'),User::where('role','admin')->first());
        Livewire::actingAs($user)->test(CreateDeposit::class)->set('investmentProgramId',$foreign->id)->set('amount','3000')->call('review')->assertHasErrors('program');
        $noVersion=InvestmentProgram::create(['name'=>'No version','slug'=>'no-version','status'=>'active','currency'=>'USDT','min_amount'=>'1000','max_amount'=>'9999','is_partial_withdrawal_allowed'=>true]);Livewire::actingAs($user)->test(CreateDeposit::class)->set('investmentProgramId',$noVersion->id)->set('amount','3000')->call('review')->assertHasErrors('program');
        $this->assertDatabaseMissing('deposit_requests',['investment_program_id'=>$foreign->id]);$this->assertDatabaseMissing('deposit_requests',['investment_program_id'=>$noVersion->id]);
    }

    public function test_inactive_program_is_not_visible_to_investor(): void
    {
        $this->seed();$user=User::where('email','alexey@example.com')->firstOrFail();InvestmentProgram::where('slug','advanced')->update(['status'=>'paused']);
        Livewire::actingAs($user)->test(Programs::class)->assertDontSee('Advanced');
    }

    private function investorUser(): User { return User::factory()->create(['role'=>'investor','is_active'=>true]); }
}
