<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\DepositRequest;
use App\Models\FeeRule;
use App\Models\InvestmentAccount;
use App\Models\InvestmentTerm;
use App\Models\Investor;
use App\Models\User;
use App\Services\DepositRequestService;
use App\Services\FeeAnalyticsService;
use App\Services\DepositVerificationService;
use App\Support\InvestorPresentation;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DepositWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_is_submitted_with_actual_amount_txid_frozen_fee_and_audit(): void
    {
        [$request, $admin] = $this->context();
        $this->fee('2.5000');

        $result = app(DepositRequestService::class)->submit($request, '997.50000000', '  ABC-123  ', $admin);

        $this->assertSame('submitted', $result->status);
        $this->assertSame('997.50000000', $result->received_amount);
        $this->assertSame('abc-123', $result->txid);
        $this->assertSame('24.93750000', $result->fee_amount);
        $this->assertSame('972.56250000', $result->net_investment_amount);
        $this->assertSame('provider_cost', $result->fee_economic_type_snapshot);
        $this->assertNotNull($result->submitted_at);
        $this->assertSame($admin->id, $result->submitted_by);
        $this->assertTrue($result->submitter->is($admin));
        $log = AuditLog::where('action', 'deposit_request.submitted')->sole();
        $this->assertSame('pending', $log->old_values['status']);
        $this->assertSame('997.50000000', $log->new_values['received_amount']);
        $this->assertSame('abc-123', $log->new_values['txid']);
    }

    public function test_submit_and_terminal_transitions_are_idempotent_and_reasons_are_saved(): void
    {
        [$request, $admin] = $this->context();
        $service = app(DepositRequestService::class);
        $service->submit($request, '1000', 'submit-once', $admin);
        $service->submit($request->fresh(), '999', 'ignored', $admin);
        $this->assertSame(1, AuditLog::where('action', 'deposit_request.submitted')->count());
        $this->assertSame('1000.00000000', $request->fresh()->received_amount);

        $service->reject($request->fresh(), ' Платёж не найден ', $admin);
        $service->reject($request->fresh(), 'ignored', $admin);
        $this->assertSame('Платёж не найден', $request->fresh()->rejected_reason);
        $this->assertSame(1, AuditLog::where('action', 'deposit_request.rejected')->count());

        [$cancel, $admin] = $this->context();
        $service->cancel($cancel, 'Ошибка в заявке', $admin);
        $service->cancel($cancel->fresh(), 'ignored', $admin);
        $this->assertSame('Ошибка в заявке', $cancel->fresh()->cancellation_reason);
        $this->assertNotNull($cancel->fresh()->cancelled_at);
        $this->assertSame(1, AuditLog::where('action', 'deposit_request.cancelled')->count());
    }

    public function test_pending_and_submitted_can_both_be_rejected_or_cancelled(): void
    {
        $service = app(DepositRequestService::class);
        foreach ([['pending', 'reject'], ['pending', 'cancel'], ['submitted', 'reject'], ['submitted', 'cancel']] as $index => [$state, $action]) {
            [$request, $admin] = $this->context();
            if ($state === 'submitted') {
                $service->submit($request, '100', 'transition-'.$index, $admin);
            }
            $service->{$action}($request->fresh(), 'Причина '.$index, $admin);
            $this->assertSame($action === 'reject' ? 'rejected' : 'cancelled', $request->fresh()->status);
        }
    }

    public function test_reason_is_required_and_terminal_transitions_preserve_snapshots(): void
    {
        [$request, $admin] = $this->context();
        $service = app(DepositRequestService::class);
        $service->submit($request, '1000', 'snapshot-terminal', $admin);
        $before = $request->fresh()->only(['received_amount','txid','fee_rule_id','fee_amount','fee_payer','fee_economic_type_snapshot','net_investment_amount']);

        try { $service->reject($request->fresh(), '   ', $admin); $this->fail('Reason is required.'); }
        catch (DomainException $exception) { $this->assertStringContainsString('причину', $exception->getMessage()); }

        $service->cancel($request->fresh(), 'Операция остановлена', $admin);
        $this->assertSame($before, $request->fresh()->only(array_keys($before)));
        $this->assertDatabaseCount('investment_lots', 0);
        $this->assertDatabaseCount('investment_transactions', 0);
    }

    public function test_reject_wins_over_stale_confirm_without_double_terminal_state(): void
    {
        [$request, $admin] = $this->context();
        $service = app(DepositRequestService::class);
        $service->submit($request, '1000', 'reject-versus-confirm', $admin);
        $this->completeVerification($request, $admin);
        $stale = $request->fresh();
        $service->reject($request->fresh(), 'Проверка не пройдена', $admin);

        try { $service->confirm($stale, $admin); $this->fail('Stale confirm must fail.'); }
        catch (DomainException) { $this->addToAssertionCount(1); }

        $this->assertSame('rejected', $request->fresh()->status);
        $this->assertDatabaseCount('investment_lots', 0);
        $this->assertDatabaseCount('investment_transactions', 0);
    }

    public function test_investor_sees_safe_deposit_statuses_and_rejection_reason_only_for_own_account(): void
    {
        [$request, , $investor] = $this->context();
        $request->update(['status' => 'rejected', 'rejected_reason' => 'Неверная сеть']);
        [$other] = $this->context();
        $other->update(['status' => 'submitted', 'txid' => 'private-other-tx']);

        $this->actingAs($investor->user)->get(route('investor.finance'))
            ->assertOk()->assertSee('Заявки на пополнение')->assertSee('Отклонено')
            ->assertSee('Неверная сеть')->assertDontSee('private-other-tx')
            ->assertDontSee('Проверяется администратором');
    }

    public function test_confirm_uses_submitted_snapshots_after_fee_rule_changes(): void
    {
        [$request, $admin] = $this->context();
        $rule = $this->fee('10.0000');
        $service = app(DepositRequestService::class);
        $service->submit($request, '100.00000000', 'frozen-fee', $admin);
        $rule->update(['percent_value' => '99.0000']);
        $this->completeVerification($request, $admin);

        $service->confirm($request->fresh(), $admin);

        $this->assertSame('10.00000000', $request->fresh()->fee_amount);
        $this->assertSame('90.00000000', $request->fresh()->net_investment_amount);
        $this->assertDatabaseHas('investment_lots', ['original_amount' => '90.00000000']);
        $this->assertDatabaseHas('investment_transactions', ['amount' => '90.00000000', 'type' => 'deposit']);
        $this->assertSame(1, AuditLog::where('action', 'deposit_request.confirmed')->count());

        $service->confirm($request->fresh(), $admin);
        $this->assertDatabaseCount('investment_lots', 1);
        $this->assertDatabaseCount('investment_transactions', 1);
        $this->assertSame(1, AuditLog::where('action', 'deposit_request.confirmed')->count());
    }

    public function test_duplicate_normalized_txid_cannot_reach_double_credit(): void
    {
        [$first, $admin] = $this->context();
        [$second] = $this->context();
        $service = app(DepositRequestService::class);
        $service->submit($first, '100', ' UNIQUE-TX ', $admin);

        try {
            $service->submit($second, '100', 'unique-tx', $admin);
            $this->fail('Duplicate TXID must be rejected.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('TXID', $exception->getMessage());
        }

        $this->completeVerification($first, $admin);
        $service->confirm($first->fresh(), $admin);
        $this->assertSame('pending', $second->fresh()->status);
        $this->assertDatabaseCount('investment_lots', 1);
        $this->assertDatabaseCount('investment_transactions', 1);
    }

    public function test_fee_analytics_ignores_non_confirmed_deposits(): void
    {
        [$submitted, $admin] = $this->context();
        [$rejected] = $this->context();
        [$cancelled] = $this->context();
        $this->fee('1.0000');
        $service = app(DepositRequestService::class);
        $service->submit($submitted, '100', 'analytics-submitted', $admin);
        $service->submit($rejected, '100', 'analytics-rejected', $admin);
        $service->reject($rejected->fresh(), 'Нет платежа', $admin);
        $service->cancel($cancelled, 'Отменено', $admin);
        $this->assertSame([], app(FeeAnalyticsService::class)->allTime()['provider_cost']);

        $this->completeVerification($submitted, $admin);
        $service->confirm($submitted->fresh(), $admin);
        $this->assertSame('1.00000000', app(FeeAnalyticsService::class)->allTime()['provider_cost']['USDT']);
    }

    public function test_full_created_submitted_confirmed_lifecycle_increases_investor_capital(): void
    {
        [, $admin, $investor, $account] = $this->context();
        $this->fee('2.5000');
        $service = app(DepositRequestService::class);
        $request = $service->create($account, '1000.00000000', network: 'TRC20', actor: $investor->user);
        $service->submit($request, '997.50000000', 'e2e-deposit-tx', $admin);
        $this->completeVerification($request, $admin);
        $service->confirm($request->fresh(), $admin);

        $this->assertSame('confirmed', $request->fresh()->status);
        $this->assertSame('972.56250000', $account->investmentLots()->sole()->remaining_amount);
        $this->assertSame('972.56250000', $account->investmentTransactions()->where('type', 'deposit')->sole()->amount);
        $this->assertSame(
            ['deposit_request.created', 'deposit_request.submitted', 'deposit_request.confirmed'],
            AuditLog::where('entity_type', DepositRequest::class)->where('entity_id', $request->id)->orderBy('id')->pluck('action')->all(),
        );
        $this->assertSame('24.93750000', app(FeeAnalyticsService::class)->allTime()['provider_cost']['USDT']);
    }

    public function test_investor_deposit_statuses_have_safe_presentation(): void
    {
        $this->assertSame('Ожидает обработки', InvestorPresentation::status('pending'));
        $this->assertSame('На проверке', InvestorPresentation::status('submitted'));
        $this->assertSame('Подтверждено', InvestorPresentation::status('confirmed'));
        $this->assertSame('Отклонена', InvestorPresentation::status('rejected'));
        $this->assertSame('Отменена', InvestorPresentation::status('cancelled'));
    }

    #[DataProvider('exactAmounts')]
    public function test_actual_amount_preview_uses_exact_decimal_strings(string $amount): void
    {
        [$request] = $this->context();
        $preview = app(DepositRequestService::class)->preview($request, $amount);
        $this->assertSame(str_pad(str_contains($amount, '.') ? $amount : $amount.'.', strlen(explode('.', $amount)[0]) + 9, '0'), $preview['gross_amount']);
        $this->assertSame($preview['gross_amount'], $preview['net_amount']);
        $this->assertMatchesRegularExpression('/^\d+\.\d{8}$/', $preview['net_amount']);
    }

    public static function exactAmounts(): array
    {
        return [['1000.00000000'], ['997.50000000'], ['0.00000001'], ['9999999999.99999999']];
    }

    private function context(): array
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $user = User::factory()->create(['role' => 'investor', 'is_active' => true]);
        $investor = Investor::create(['user_id' => $user->id, 'status' => 'active']);
        $account = InvestmentAccount::create(['investor_id' => $investor->id, 'currency' => 'USDT', 'status' => 'active']);
        InvestmentTerm::create(['investment_account_id' => $account->id, 'monthly_rate' => '3.0000', 'lock_months' => 3, 'valid_from' => '2020-01-01']);
        $request = DepositRequest::create([
            'investor_id' => $investor->id, 'investment_account_id' => $account->id,
            'requested_amount' => '1000.00000000', 'currency' => 'USDT',
            'status' => 'pending', 'requested_at' => now(),
        ]);

        return [$request, $admin, $investor, $account];
    }

    private function fee(string $percent): FeeRule
    {
        return FeeRule::create([
            'operation_type' => 'deposit', 'scope' => 'global', 'currency' => 'USDT',
            'fee_type' => 'percent', 'percent_value' => $percent, 'fixed_value' => '0',
            'payer' => 'investor', 'economic_type' => 'provider_cost',
            'valid_from' => '2020-01-01', 'is_active' => true,
        ]);
    }

    private function completeVerification(DepositRequest $request, User $admin): void
    {
        $service=app(DepositVerificationService::class);$service->initializeForRequest($request);foreach(DepositVerificationService::MANUAL_KEYS as $key)$service->markPassed($request,$key,$admin);
    }
}
