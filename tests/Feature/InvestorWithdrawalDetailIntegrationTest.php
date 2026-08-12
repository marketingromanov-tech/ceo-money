<?php

namespace Tests\Feature;

use App\Models\DailyAccrual;
use App\Models\InvestmentAccount;
use App\Models\InvestmentLot;
use App\Models\InvestmentTransaction;
use App\Models\Investor;
use App\Models\InvestorWithdrawalDetail;
use App\Models\User;
use App\Services\WithdrawalRequestService;
use App\Services\WithdrawalWorkflowService;
use Carbon\Carbon;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvestorWithdrawalDetailIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_request_snapshots_latest_active_permanent_details_without_financial_side_effects(): void
    {
        [$account, $investor, $user] = $this->context();
        $investor->withdrawalDetails()->create([
            'currency' => 'USDT', 'network' => 'TRC20', 'address' => 'TOldWithdrawal',
            'memo' => 'OLD', 'is_active' => true,
        ]);
        $current = $investor->withdrawalDetails()->create([
            'currency' => 'USDT', 'network' => 'ERC20', 'address' => '0xPermanentWithdrawal',
            'memo' => 'MEMO-42', 'is_active' => true,
        ]);

        $request = app(WithdrawalRequestService::class)->createCapitalRequest(
            $account, '25.00000000', date: Carbon::today(), actor: $user,
        );

        $this->assertNull($request->investor_wallet_id);
        $this->assertSame('0xPermanentWithdrawal', $request->wallet_address_snapshot);
        $this->assertSame('ERC20', $request->network_snapshot);
        $this->assertSame('MEMO-42', $request->withdrawal_memo_snapshot);
        $this->assertDatabaseCount('investment_transactions', 0);
        $this->assertDatabaseCount('daily_accruals', 0);

        $current->update(['address' => '0xChangedLater', 'network' => 'BEP20', 'memo' => 'CHANGED', 'is_active' => false]);
        $request->refresh();
        $this->assertSame('0xPermanentWithdrawal', $request->wallet_address_snapshot);
        $this->assertSame('ERC20', $request->network_snapshot);
        $this->assertSame('MEMO-42', $request->withdrawal_memo_snapshot);
    }

    public function test_request_is_blocked_when_no_matching_active_details_exist(): void
    {
        [$account, $investor] = $this->context();
        InvestorWithdrawalDetail::create([
            'investor_id' => $investor->id, 'currency' => 'USDC', 'network' => 'TRC20',
            'address' => 'TWrongCurrency', 'is_active' => true,
        ]);

        try {
            app(WithdrawalRequestService::class)->createCapitalRequest($account, '10.00000000', date: Carbon::today());
            $this->fail('A request without matching active withdrawal details must be rejected.');
        } catch (DomainException $exception) {
            $this->assertSame('No active investor withdrawal details are assigned.', $exception->getMessage());
        }

        $this->assertDatabaseCount('withdrawal_requests', 0);
        $this->assertDatabaseCount('investment_transactions', 0);
    }

    public function test_admin_workflow_accepts_request_created_from_permanent_details(): void
    {
        [$account, $investor, $user] = $this->context();
        $investor->withdrawalDetails()->create([
            'currency' => 'USDT', 'network' => 'TRC20', 'address' => 'TAdminWorkflow',
            'is_active' => true,
        ]);
        $request = app(WithdrawalRequestService::class)->createCapitalRequest(
            $account, '10.00000000', date: Carbon::today(), actor: $user,
        );
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        app(WithdrawalWorkflowService::class)->moveToReview($request, $admin);

        $this->assertSame('review', $request->fresh()->status);
        $this->assertSame('TAdminWorkflow', $request->fresh()->wallet_address_snapshot);
        $this->assertDatabaseCount('investment_transactions', 0);
    }

    private function context(): array
    {
        $user = User::factory()->create(['role' => 'investor', 'is_active' => true]);
        $investor = Investor::create(['user_id' => $user->id, 'status' => 'active']);
        $account = InvestmentAccount::create([
            'investor_id' => $investor->id, 'currency' => 'USDT', 'status' => 'active',
        ]);
        InvestmentLot::create([
            'investment_account_id' => $account->id,
            'original_amount' => '100.00000000', 'remaining_amount' => '100.00000000',
            'currency' => 'USDT', 'received_at' => '2026-01-01',
            'accrual_start_date' => '2026-01-01', 'unlock_date' => null,
            'monthly_rate' => '3.0000', 'status' => 'active',
        ]);

        return [$account, $investor, $user];
    }
}
