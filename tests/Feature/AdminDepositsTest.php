<?php

namespace Tests\Feature;

use App\Livewire\Admin\Deposits\Index;
use App\Models\AuditLog;
use App\Models\DepositRequest;
use App\Models\InvestmentAccount;
use App\Models\InvestmentTerm;
use App\Models\Investor;
use App\Models\User;
use App\Services\DepositRequestService;
use App\Services\DepositVerificationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminDepositsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sees_actions_and_can_open_snapshot_details(): void
    {
        [$admin, $investor, $account] = $this->context();
        $pending = $this->deposit($investor, $account, ['status' => 'pending']);
        $submitted = $this->deposit($investor, $account, [
            'status' => 'submitted', 'deposit_address_snapshot' => 'TOldSnapshotAddress001',
            'provider_snapshot' => 'Demo Provider', 'txid' => 'long-demo-transaction-id-001',
            'fee_amount' => '1.25000000', 'net_investment_amount' => '98.75000000',
            'fee_payer' => 'investor', 'fee_economic_type_snapshot' => 'provider_cost',
        ]);
        $this->deposit($investor, $account, ['status' => 'confirmed']);
        $this->deposit($investor, $account, ['status' => 'rejected']);
        $this->deposit($investor, $account, ['status' => 'cancelled']);

        Livewire::actingAs($admin)->test(Index::class)
            ->assertSee('Действие')->assertSee('Взять в обработку')->assertSee('Открыть проверку')
            ->call('openAction', $submitted->id)->assertSet('showActionModal', true)
            ->assertSee($investor->user->name)->assertSee('TOldSnapshotAddress001')
            ->assertSee('Demo Provider')->assertSee('long-demo-transaction-id-001')
            ->assertSee('1.25 USDT')->assertSee('98.75 USDT')
            ->assertSee('Расход провайдера')->assertSee('Инвестор')
            ->assertSee('Подтвердить пополнение')->call('closeAction')
            ->assertSet('selectedDepositId', null)->assertSet('actionStep', 'details');

        $this->assertSame('pending', $pending->fresh()->status);
    }

    public function test_submitted_deposit_is_confirmed_only_through_service_and_cannot_duplicate_effects(): void
    {
        [$admin, $investor, $account] = $this->context();
        $request = $this->deposit($investor, $account, [
            'status' => 'submitted', 'received_amount' => '125.00000000',
        ]);
        $this->completeVerification($request, $admin);

        Livewire::actingAs($admin)->test(Index::class)
            ->call('openAction', $request->id)->call('requestConfirmation')
            ->assertSet('actionStep', 'confirm')
            ->assertSee('После подтверждения будет создана новая инвестиция')
            ->call('confirmDeposit')->assertHasNoErrors()
            ->assertSet('showActionModal', false)->assertSee('Пополнение подтверждено.');

        $this->assertDatabaseHas('deposit_requests', ['id' => $request->id, 'status' => 'confirmed']);
        $this->assertDatabaseCount('investment_lots', 1);
        $this->assertDatabaseCount('investment_transactions', 1);
        $this->assertSame(1, AuditLog::where('action', 'deposit_request.confirmed')->count());

        app(DepositRequestService::class)->confirm($request->fresh(), $admin);
        $this->assertDatabaseCount('investment_lots', 1);
        $this->assertDatabaseCount('investment_transactions', 1);
    }

    public function test_pending_modal_submits_actual_amount_and_txid_then_supports_reject_and_cancel(): void
    {
        [$admin, $investor, $account] = $this->context();
        $pending = $this->deposit($investor, $account, ['status' => 'pending']);

        Livewire::actingAs($admin)->test(Index::class)
            ->call('openAction', $pending->id)
            ->assertSee('Фактически получено')->assertSee('Взять на проверку')
            ->set('receivedAmount', '97.50000000')->set('txid', ' UI-TX-001 ')
            ->call('requestSubmitConfirmation')->assertSet('actionStep', 'submit-confirm')
            ->assertSee('Подтвердить передачу на проверку')
            ->call('submitDeposit')->assertHasNoErrors()->assertSet('showActionModal', false);

        $this->assertDatabaseHas('deposit_requests', [
            'id' => $pending->id, 'status' => 'submitted',
            'received_amount' => '97.50000000', 'txid' => 'ui-tx-001',
        ]);

        $rejected = $this->deposit($investor, $account, ['status' => 'submitted', 'received_amount' => '50', 'net_investment_amount' => '50']);
        Livewire::actingAs($admin)->test(Index::class)->call('openAction', $rejected->id)
            ->call('beginDecision', 'reject')->assertSee('Отклонить пополнение')
            ->set('decisionReason', 'TXID не найден')->call('rejectDeposit')->assertHasNoErrors();
        $this->assertDatabaseHas('deposit_requests', ['id' => $rejected->id, 'status' => 'rejected', 'rejected_reason' => 'TXID не найден']);

        $cancelled = $this->deposit($investor, $account, ['status' => 'pending']);
        Livewire::actingAs($admin)->test(Index::class)->call('openAction', $cancelled->id)
            ->call('beginDecision', 'cancel')->assertSee('Отменить заявку')
            ->set('decisionReason', 'Дубликат заявки')->call('cancelDeposit')->assertHasNoErrors();
        $this->assertDatabaseHas('deposit_requests', ['id' => $cancelled->id, 'status' => 'cancelled', 'cancellation_reason' => 'Дубликат заявки']);
    }

    public function test_submitted_checklist_ui_progress_bingx_instructions_and_complete_gate(): void
    {
        [$admin, $investor, $account] = $this->context();
        $request = $this->deposit($investor, $account, [
            'status'=>'submitted','received_amount'=>'100.00000000','fee_amount'=>'0.00000000',
            'fee_payer'=>'investor','net_investment_amount'=>'100.00000000','txid'=>'ui-checklist',
            'provider_snapshot'=>'BingX','deposit_address_snapshot'=>'TChecklistUiAddress',
        ]);
        $component=Livewire::actingAs($admin)->test(Index::class)->call('openAction',$request->id)
            ->assertSee('Проверка поступления')->assertSee('Проверено 3/13')
            ->assertSee('Инструкция проверки BingX')->assertSee('Финальная сверка выполнена')
            ->assertSeeHtml('disabled')->call('requestConfirmation')->assertHasErrors('action');

        foreach(array_diff(DepositVerificationService::MANUAL_KEYS,['final_reconciliation']) as $key)$component->call('passVerification',$key)->assertHasNoErrors();
        $component->call('passVerification','final_reconciliation')->assertHasNoErrors()
            ->assertSee('Проверено 13/13')->call('requestConfirmation')->assertHasNoErrors()
            ->assertSet('actionStep','confirm')->call('confirmDeposit')->assertHasNoErrors();
        $this->assertSame('confirmed',$request->fresh()->status);

        Livewire::actingAs($admin)->test(\App\Livewire\Admin\Inbox\Index::class)
            ->set('tab','completed')->assertViewHas('items',fn($items)=>collect($items)->contains(fn($item)=>$item['source_id']===$request->id&&$item['status']==='Подтверждено'));
    }

    public function test_stale_status_is_rejected_and_modal_stays_open(): void
    {
        [$admin, $investor, $account] = $this->context();
        $request = $this->deposit($investor, $account, ['status' => 'submitted']);
        $component = Livewire::actingAs($admin)->test(Index::class)
            ->call('openAction', $request->id)->call('requestConfirmation');
        $request->update(['status' => 'cancelled']);

        $component->call('confirmDeposit')->assertHasErrors('action')->assertSet('showActionModal', true);
        $this->assertDatabaseCount('investment_lots', 0);
        $this->assertDatabaseCount('investment_transactions', 0);
    }

    public function test_confirmation_preflight_shows_active_term_and_blocks_missing_future_or_expired_terms(): void
    {
        Carbon::setTestNow('2026-08-11 12:00:00');
        [$admin, $investor, $account] = $this->context(false);
        $request = $this->deposit($investor, $account, [
            'status' => 'submitted', 'received_amount' => '100.00000000',
            'fee_amount' => '1.00000000', 'fee_payer' => 'investor',
            'net_investment_amount' => '99.00000000', 'txid' => 'preflight-current',
        ]);

        Livewire::actingAs($admin)->test(Index::class)->call('openAction', $request->id)
            ->assertSee('Для инвестора не настроены действующие условия инвестирования')
            ->assertSee('Настроить условия инвестора')
            ->assertSee('?tab=terms', false)
            ->assertDontSee('Explicit lot terms are required')
            ->call('requestConfirmation')->assertHasErrors('action')->assertSet('actionStep', 'details');

        InvestmentTerm::create([
            'investment_account_id' => $account->id, 'monthly_rate' => '4.2500', 'lock_months' => 6,
            'valid_from' => '2026-08-12',
        ]);
        Livewire::actingAs($admin)->test(Index::class)->call('openAction', $request->id)
            ->assertSee('Для инвестора не настроены действующие условия инвестирования')
            ->call('requestConfirmation')->assertHasErrors('action');

        InvestmentTerm::where('investment_account_id', $account->id)->delete();
        InvestmentTerm::create([
            'investment_account_id' => $account->id, 'monthly_rate' => '4.2500', 'lock_months' => 6,
            'valid_from' => '2026-07-01', 'valid_to' => '2026-08-10',
        ]);
        Livewire::actingAs($admin)->test(Index::class)->call('openAction', $request->id)
            ->assertSee('Для инвестора не настроены действующие условия инвестирования')
            ->call('requestConfirmation')->assertHasErrors('action');

        InvestmentTerm::create([
            'investment_account_id' => $account->id, 'monthly_rate' => '4.2500', 'lock_months' => 6,
            'valid_from' => '2026-08-11',
        ]);
        $this->completeVerification($request, $admin);
        Livewire::actingAs($admin)->test(Index::class)->call('openAction', $request->id)
            ->assertSee('Условия новой инвестиции')->assertSee('4.25% в месяц')
            ->assertSee('6 месяцев')->assertSee('11.08.2026')
            ->call('requestConfirmation')->assertHasNoErrors()->assertSet('actionStep', 'confirm')
            ->call('confirmDeposit')->assertHasNoErrors();

        $request->refresh();
        $this->assertSame('confirmed', $request->status);
        $this->assertSame('1.00000000', $request->fee_amount);
        $this->assertSame('99.00000000', $request->net_investment_amount);
        $this->assertSame('preflight-current', $request->txid);
        Carbon::setTestNow();
    }

    public function test_terms_deep_link_opens_investor_terms_tab(): void
    {
        [$admin, $investor] = $this->context(false);

        $this->actingAs($admin)->get(route('admin.investors.show', $investor).'?tab=terms')
            ->assertOk()->assertSee('Изменить условия');
    }

    public function test_term_removed_after_preflight_keeps_modal_open_with_russian_message(): void
    {
        [$admin, $investor, $account] = $this->context();
        $request = $this->deposit($investor, $account, [
            'status' => 'submitted', 'received_amount' => '100.00000000',
            'net_investment_amount' => '100.00000000', 'txid' => 'term-race',
        ]);
        $this->completeVerification($request, $admin);
        $component = Livewire::actingAs($admin)->test(Index::class)
            ->call('openAction', $request->id)->call('requestConfirmation')
            ->assertSet('actionStep', 'confirm');

        InvestmentTerm::where('investment_account_id', $account->id)->delete();

        $component->call('confirmDeposit')
            ->assertHasErrors('action')->assertSet('showActionModal', true)
            ->assertSee('Для инвестора не настроены действующие условия инвестирования')
            ->assertDontSee('Explicit lot terms are required');
        $this->assertSame('submitted', $request->fresh()->status);
        $this->assertDatabaseCount('investment_lots', 0);
    }

    public function test_deep_link_opens_existing_request_and_ignores_unknown_id(): void
    {
        [$admin, $investor, $account] = $this->context();
        $request = $this->deposit($investor, $account, ['status' => 'submitted']);

        $this->actingAs($admin)->get('/admin/deposits?deposit='.$request->id)
            ->assertOk()->assertSee('Обработка заявки на пополнение');
        $this->actingAs($admin)->get('/admin/deposits?deposit=999999')
            ->assertOk()->assertDontSee('Обработка заявки на пополнение');
    }

    public function test_admin_inbox_deposit_target_uses_modal_deep_link(): void
    {
        [$admin, $investor, $account] = $this->context();
        $request = $this->deposit($investor, $account, ['status' => 'submitted']);

        Livewire::actingAs($admin)->test(\App\Livewire\Admin\Inbox\Index::class)
            ->assertViewHas('items', fn ($items) => collect($items)->contains(
                fn ($item) => $item['target'] === route('admin.deposits.index').'?deposit='.$request->id
                    && $item['action'] === 'Открыть'
            ));
    }

    public function test_admin_confirms_or_rejects_investor_payment_inside_existing_review_flow(): void
    {
        [$admin,$investor,$account,$investorUser]=$this->context();
        $snapshot=['payment_detail_id'=>10,'currency'=>'USDT','network'=>'TRC20','address'=>'TPaymentReviewSnapshot001','memo'=>'REVIEW-10'];
        $programSnapshot=['program_id'=>3,'version_id'=>5,'name'=>'Advanced','slug'=>'advanced','currency'=>'USDT','min_amount'=>'5000.00000000','max_amount'=>'9999.00000000','monthly_rate'=>'3.0000','lock_months'=>6,'partial_withdrawal_allowed'=>true];
        $request=$this->deposit($investor,$account,['status'=>'payment_submitted','requested_amount'=>'7000','payment_details_snapshot'=>$snapshot,'investment_program_snapshot'=>$programSnapshot]);
        $before=[\App\Models\InvestmentLot::count(),\App\Models\InvestmentTransaction::count(),\App\Models\DailyAccrual::count()];

        Livewire::actingAs($admin)->test(Index::class)->assertSee('Оплачено инвестором')->assertSee('Advanced')->assertSee('7 000.00 USDT')->assertSee('TRC20')->assertSee('TPayme…t001')->assertSee('Проверить оплату')->call('openAction',$request->id)->assertSee('TPaymentReviewSnapshot001')->assertSee('REVIEW-10')->assertSee('Подтвердить платёж')->assertSee('Отклонить платёж')->set('receivedAmount','7000')->set('txid','payment-review-txid-001')->call('requestSubmitConfirmation')->assertSet('actionStep','submit-confirm')->assertSee('Подтверждение платежа')->call('submitDeposit')->assertHasNoErrors();
        $request->refresh();$this->assertSame('submitted',$request->status);$this->assertEqualsCanonicalizing($snapshot,$request->payment_details_snapshot);$this->assertEqualsCanonicalizing($programSnapshot,$request->investment_program_snapshot);$this->assertSame($before,[\App\Models\InvestmentLot::count(),\App\Models\InvestmentTransaction::count(),\App\Models\DailyAccrual::count()]);

        $rejected=$this->deposit($investor,$account,['status'=>'payment_submitted','payment_details_snapshot'=>$snapshot,'investment_program_snapshot'=>$programSnapshot]);
        $component=Livewire::actingAs($admin)->test(Index::class)->call('openAction',$rejected->id)->call('beginDecision','reject')->call('rejectDeposit')->assertHasErrors('action')->set('decisionReason','Платёж не найден в сети')->call('rejectDeposit')->assertHasNoErrors();
        $this->assertSame('rejected',$rejected->fresh()->status);$this->assertSame('Платёж не найден в сети',$rejected->fresh()->rejected_reason);$this->assertEqualsCanonicalizing($snapshot,$rejected->fresh()->payment_details_snapshot);
        Livewire::actingAs($investorUser)->test(\App\Livewire\Investor\Finance::class)->assertSee('Отклонено')->assertSee('Платёж не найден в сети');
    }

    public function test_investor_cannot_access_admin_deposits(): void
    {
        [, , , $investorUser] = $this->context();
        $this->actingAs($investorUser)->get('/admin/deposits')->assertForbidden();
    }

    private function context(bool $withTerm = true): array
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $user = User::factory()->create(['role' => 'investor', 'is_active' => true]);
        $investor = Investor::create(['user_id' => $user->id, 'code' => 'DEP-UI', 'status' => 'active']);
        $account = InvestmentAccount::create(['investor_id' => $investor->id, 'currency' => 'USDT', 'status' => 'active']);
        if ($withTerm) {
            InvestmentTerm::create([
                'investment_account_id' => $account->id, 'monthly_rate' => '3.0000',
                'lock_months' => 3, 'valid_from' => '2020-01-01',
            ]);
        }

        return [$admin, $investor, $account, $user];
    }

    private function deposit(Investor $investor, InvestmentAccount $account, array $attributes): DepositRequest
    {
        return DepositRequest::create(array_merge([
            'investor_id' => $investor->id, 'investment_account_id' => $account->id,
            'requested_amount' => '100.00000000', 'currency' => 'USDT', 'network' => 'TRC20',
            'status' => 'pending', 'requested_at' => now(),
        ], $attributes));
    }

    private function completeVerification(DepositRequest $request, User $admin): void
    {
        if ($request->txid === null) $request->update(['txid' => 'admin-test-'.$request->id]);
        if ($request->net_investment_amount === null) {
            $fee=app(DepositRequestService::class)->preview($request,(string)($request->received_amount??$request->requested_amount));$request->update(['fee_rule_id'=>$fee['fee_rule_id'],'fee_amount'=>$fee['fee_amount'],'fee_payer'=>$fee['payer'],'fee_economic_type_snapshot'=>$fee['economic_type'],'net_investment_amount'=>$fee['net_amount']]);
        }
        $service=app(DepositVerificationService::class);$service->initializeForRequest($request);foreach(DepositVerificationService::MANUAL_KEYS as $key)$service->markPassed($request,$key,$admin);
    }
}
