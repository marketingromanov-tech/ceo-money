<?php

namespace Tests\Feature;

use App\Models\InvestmentAccount;
use App\Models\InvestmentLot;
use App\Models\InvestmentTransaction;
use App\Models\Investor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialCoreModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_financial_core_models_can_be_created_with_their_relationships(): void
    {
        $user = User::factory()->create([
            'role' => 'investor',
            'is_active' => true,
        ]);

        $investor = Investor::create([
            'user_id' => $user->id,
            'code' => 'INV-001',
            'status' => 'active',
        ]);

        $account = InvestmentAccount::create([
            'investor_id' => $investor->id,
        ]);

        $lot = InvestmentLot::create([
            'investment_account_id' => $account->id,
            'original_amount' => '1000.00000000',
            'remaining_amount' => '1000.00000000',
            'received_at' => '2026-08-10 12:00:00',
            'accrual_start_date' => '2026-08-11',
            'monthly_rate' => '2.5000',
        ]);

        $transaction = InvestmentTransaction::create([
            'investment_account_id' => $account->id,
            'investment_lot_id' => $lot->id,
            'type' => 'deposit',
            'amount' => '1000.00000000',
            'effective_date' => '2026-08-10',
            'created_by' => $user->id,
        ]);

        $account->refresh();
        $transaction->refresh();

        $this->assertTrue($user->investor->is($investor));
        $this->assertTrue($investor->user->is($user));
        $this->assertTrue($investor->investmentAccounts->contains($account));
        $this->assertTrue($account->investor->is($investor));
        $this->assertTrue($account->investmentLots->contains($lot));
        $this->assertTrue($account->investmentTransactions->contains($transaction));
        $this->assertTrue($lot->investmentAccount->is($account));
        $this->assertTrue($lot->investmentTransactions->contains($transaction));
        $this->assertTrue($transaction->investmentAccount->is($account));
        $this->assertTrue($transaction->investmentLot->is($lot));
        $this->assertTrue($transaction->creator->is($user));
        $this->assertSame('USDT', $account->currency);
        $this->assertSame('active', $account->status);
        $this->assertSame('pending', $transaction->status);
    }
}
