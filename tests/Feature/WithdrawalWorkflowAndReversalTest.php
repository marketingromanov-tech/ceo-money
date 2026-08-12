<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\InvestmentAccount;
use App\Models\InvestmentTransaction;
use App\Models\Investor;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\InvestmentTransactionService;
use App\Services\WithdrawalWorkflowService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WithdrawalWorkflowAndReversalTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_workflow_transitions_are_applied_and_audited(): void
    {
        [$request, $admin] = $this->context();
        $workflow = app(WithdrawalWorkflowService::class);

        $workflow->moveToReview($request, $admin);
        $workflow->moveToReview($request, $admin);
        $workflow->approve($request, $admin);

        $this->assertSame('approved', $request->fresh()->status);
        $this->assertSame($admin->id, $request->fresh()->approved_by);
        $this->assertDatabaseCount('audit_logs', 2);
        $this->assertDatabaseHas('audit_logs', ['action' => 'withdrawal.review']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'withdrawal.approved']);
    }

    public function test_cancel_and_reject_follow_allowed_transitions(): void
    {
        [$cancelled, $admin] = $this->context();
        $workflow = app(WithdrawalWorkflowService::class);
        $workflow->cancel($cancelled, $admin);
        $workflow->cancel($cancelled, $admin);
        $this->assertSame('cancelled', $cancelled->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'withdrawal.cancelled', 'entity_id' => $cancelled->id]);

        [$rejected] = $this->context();
        $workflow->moveToReview($rejected, $admin);
        $workflow->reject($rejected, $admin, 'Compliance');
        $this->assertSame('rejected', $rejected->fresh()->status);
        $this->assertSame('Compliance', $rejected->fresh()->rejected_reason);
        $this->assertDatabaseHas('audit_logs', ['action' => 'withdrawal.rejected', 'entity_id' => $rejected->id]);
    }

    public function test_invalid_workflow_transition_throws_domain_exception(): void
    {
        [$request, $admin] = $this->context();
        $request->update(['status' => 'paid']);

        $this->expectException(DomainException::class);
        app(WithdrawalWorkflowService::class)->cancel($request, $admin);
    }

    public function test_only_active_admin_actor_can_perform_sensitive_transitions(): void
    {
        [$request, $admin] = $this->context();
        $investorUser = User::factory()->create(['role' => 'investor', 'is_active' => true]);

        $admin->update(['is_active' => false]);

        foreach ([$investorUser, $admin] as $actor) {
            try {
                app(WithdrawalWorkflowService::class)->moveToReview($request, $actor);
                $this->fail('A non-admin or inactive admin must not transition a withdrawal.');
            } catch (DomainException) {
                $this->assertSame('new', $request->fresh()->status);
            }
        }

        $admin->update(['is_active' => true]);
        app(WithdrawalWorkflowService::class)->moveToReview($request, $admin);
        $this->assertSame('review', $request->fresh()->status);
    }

    public function test_approved_can_be_cancelled_but_not_rejected_and_snapshots_stay_frozen(): void
    {
        [$request,$admin]= $this->context();$workflow=app(WithdrawalWorkflowService::class);$request->update(['wallet_address_snapshot'=>'TFrozenWallet','network_snapshot'=>'TRC20','fee_amount'=>'1','net_amount'=>'9']);$workflow->moveToReview($request,$admin);$workflow->approve($request->fresh(),$admin);$before=$request->fresh()->only(['requested_amount','reserved_amount','fee_amount','net_amount','wallet_address_snapshot','network_snapshot']);
        try{$workflow->reject($request->fresh(),$admin,'Late reject');$this->fail('Approved reject must fail');}catch(DomainException){$this->addToAssertionCount(1);}
        $workflow->cancel($request->fresh(),$admin,'Отмена выплаты');$this->assertSame($before,$request->fresh()->only(array_keys($before)));$this->assertSame('Отмена выплаты',$request->fresh()->cancellation_reason);
    }

    public function test_reversal_is_audit_trail_original_is_unchanged_and_second_is_forbidden(): void
    {
        [$request, $admin, $account] = $this->context();
        $original = InvestmentTransaction::create([
            'investment_account_id' => $account->id, 'type' => 'deposit',
            'amount' => '100.00000000', 'currency' => 'USDT',
            'effective_date' => '2026-08-10', 'status' => 'confirmed',
        ]);
        $service = app(InvestmentTransactionService::class);
        $reversal = $service->reverse($original, $admin, 'Incorrect posting');

        $this->assertSame('reversal', $reversal->type);
        $this->assertSame($original->id, $reversal->reversal_of_id);
        $this->assertSame('100.00000000', $reversal->amount);
        $this->assertSame('deposit', $original->fresh()->type);
        $this->assertSame('confirmed', $original->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'investment_transaction.reversed']);

        $this->expectException(DomainException::class);
        $service->reverse($original, $admin, 'Again');
    }

    private function context(): array
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $investor = Investor::create(['user_id' => User::factory()->create()->id, 'status' => 'active']);
        $account = InvestmentAccount::create(['investor_id' => $investor->id, 'currency' => 'USDT']);
        $request = WithdrawalRequest::create([
            'investor_id' => $investor->id, 'investment_account_id' => $account->id,
            'type' => 'capital', 'requested_amount' => '10.00000000',
            'reserved_amount' => '10.00000000', 'currency' => 'USDT',
            'status' => 'new', 'requested_at' => now(),
        ]);

        return [$request, $admin, $account];
    }
}
