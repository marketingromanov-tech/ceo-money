<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\DepositRequest;
use App\Models\InvestmentAccount;
use App\Models\InvestmentTerm;
use App\Models\Investor;
use App\Models\User;
use App\Services\DepositRequestService;
use App\Services\DepositVerificationService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DepositVerificationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_initializes_unique_system_and_manual_checks_with_snapshots(): void
    {
        [$request, $admin] = $this->context();
        $service = app(DepositVerificationService::class);
        $service->initializeForRequest($request);
        $service->initializeForRequest($request);
        $summary = $service->summary($request);

        $this->assertSame(13, $summary['total']);
        $this->assertSame(3, $summary['passed']);
        $this->assertSame(DepositVerificationService::KEYS, $request->verificationChecks()->orderBy('id')->pluck('check_key')->all());
        $this->assertSame('passed', $summary['checks']['investment_terms_active']->status);
        $this->assertSame('passed', $summary['checks']['duplicate_txid_checked']->status);
        $this->assertSame('passed', $summary['checks']['fee_and_net_verified']->status);
        $this->assertNull($summary['checks']['provider_opened']->checked_by_user_id);

        $check = $service->markPassed($request, 'network_verified', $admin);
        $this->assertSame(['network' => 'TRC20'], $check->value_snapshot);
        $this->assertSame($admin->id, $check->checked_by_user_id);
        $this->assertNotNull($check->checked_at);
    }

    public function test_only_admin_can_change_manual_checks_and_system_checks_are_not_manual(): void
    {
        [$request, $admin, $investorUser] = $this->context();
        $service = app(DepositVerificationService::class);
        $service->initializeForRequest($request);
        foreach ([fn () => $service->markPassed($request, 'network_verified', $investorUser), fn () => $service->markPassed($request, 'investment_terms_active', $admin)] as $operation) {
            try { $operation(); $this->fail('Expected DomainException'); } catch (DomainException) { $this->addToAssertionCount(1); }
        }
    }

    public function test_final_reconciliation_requires_every_previous_check(): void
    {
        [$request, $admin] = $this->context();
        $service = app(DepositVerificationService::class);
        $service->initializeForRequest($request);
        try { $service->markPassed($request, 'final_reconciliation', $admin); $this->fail('Expected block'); }
        catch (DomainException $exception) { $this->assertStringContainsString('предыдущие', $exception->getMessage()); }

        foreach (array_diff(DepositVerificationService::MANUAL_KEYS, ['final_reconciliation']) as $key) $service->markPassed($request, $key, $admin);
        $service->markPassed($request, 'final_reconciliation', $admin);
        $this->assertTrue($service->allRequiredPassed($request));
    }

    public function test_confirm_is_blocked_until_complete_and_system_race_is_rechecked(): void
    {
        [$request, $admin] = $this->context();
        $verification = app(DepositVerificationService::class);
        $verification->initializeForRequest($request);
        try { app(DepositRequestService::class)->confirm($request, $admin); $this->fail('Expected checklist block'); }
        catch (DomainException $exception) { $this->assertStringContainsString('проверка поступления', $exception->getMessage()); }

        $this->complete($request, $admin);
        InvestmentTerm::where('investment_account_id', $request->investment_account_id)->delete();
        try { app(DepositRequestService::class)->confirm($request->fresh(), $admin); $this->fail('Expected terms race block'); }
        catch (DomainException) { $this->addToAssertionCount(1); }
        $this->assertSame('passed', $request->verificationChecks()->where('check_key', 'investment_terms_active')->value('status'));
        $this->assertDatabaseCount('investment_lots', 0);
    }

    public function test_complete_checklist_allows_confirm_and_then_is_read_only(): void
    {
        [$request, $admin] = $this->context();
        $this->complete($request, $admin);
        app(DepositRequestService::class)->confirm($request, $admin);
        $this->assertSame('confirmed', $request->fresh()->status);
        $this->assertDatabaseCount('investment_lots', 1);
        $this->assertDatabaseCount('investment_transactions', 1);

        foreach (['markPassed', 'markFailed', 'resetManualCheck'] as $method) {
            try { app(DepositVerificationService::class)->{$method}($request->fresh(), 'network_verified', $admin); $this->fail('Expected immutable checklist'); }
            catch (DomainException) { $this->addToAssertionCount(1); }
        }
    }

    public function test_txid_system_condition_is_rechecked_before_confirm(): void
    {
        [$request,$admin]=$this->context();$this->complete($request,$admin);$request->update(['txid'=>null]);
        try{app(DepositRequestService::class)->confirm($request->fresh(),$admin);$this->fail('Expected TXID check block');}catch(DomainException){$this->addToAssertionCount(1);}
        $this->assertSame('passed',$request->verificationChecks()->where('check_key','duplicate_txid_checked')->value('status'));
        $this->assertDatabaseCount('investment_lots',0);
    }

    public function test_rejected_and_cancelled_checklists_are_read_only_and_history_is_kept(): void
    {
        foreach (['reject', 'cancel'] as $transition) {
            [$request, $admin] = $this->context();
            $service = app(DepositVerificationService::class);
            $service->initializeForRequest($request);
            $service->markPassed($request, 'network_verified', $admin);
            app(DepositRequestService::class)->{$transition}($request, 'Причина', $admin);
            try { $service->resetManualCheck($request->fresh(), 'network_verified', $admin); $this->fail('Expected immutable checklist'); }
            catch (DomainException) { $this->addToAssertionCount(1); }
            $this->assertSame('passed', $request->verificationChecks()->where('check_key', 'network_verified')->value('status'));
        }
    }

    public function test_manual_actions_are_audited_without_external_secrets(): void
    {
        [$request, $admin] = $this->context();
        $service = app(DepositVerificationService::class);
        $service->initializeForRequest($request);
        $service->markPassed($request, 'provider_opened', $admin);
        $service->markFailed($request, 'provider_opened', $admin);
        $service->resetManualCheck($request, 'provider_opened', $admin);
        $this->assertSame(['deposit_verification.passed', 'deposit_verification.failed', 'deposit_verification.reset'], AuditLog::orderBy('id')->pluck('action')->all());
        $payload = AuditLog::get()->toJson();
        $this->assertStringNotContainsString('api_key', $payload);
        $this->assertStringNotContainsString('secret', $payload);
    }

    private function complete(DepositRequest $request, User $admin): void
    {
        $service = app(DepositVerificationService::class);
        $service->initializeForRequest($request);
        foreach (DepositVerificationService::MANUAL_KEYS as $key) $service->markPassed($request, $key, $admin);
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
            'requested_amount' => '100.00000000', 'received_amount' => '100.00000000',
            'fee_amount' => '1.00000000', 'fee_payer' => 'investor', 'net_investment_amount' => '99.00000000',
            'currency' => 'USDT', 'network' => 'TRC20', 'provider_snapshot' => 'BingX',
            'deposit_address_snapshot' => 'TChecklistAddress', 'txid' => 'checklist-'.str()->uuid(),
            'status' => 'submitted', 'requested_at' => now(), 'submitted_at' => now(),
        ]);
        return [$request, $admin, $user];
    }
}
