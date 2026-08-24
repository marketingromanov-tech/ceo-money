<?php

namespace Tests\Feature;

use App\Livewire\Admin\InvestorInvestmentTerms\Index;
use App\Models\InvestorInvestmentTerm;
use App\Models\User;
use App\Services\InvestorInvestmentTermService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class InvestorInvestmentTermTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_individual_investment_term_for_investor(): void
    {
        [$admin, $investor] = $this->users();
        Livewire::actingAs($admin)->test(Index::class)
            ->set('investorId', $investor->id)->set('currency', 'USDT')->set('minAmount', '1000.00000000')
            ->set('maxAmount', '50000.00000000')->set('monthlyRate', '3.2500')->set('termMonths', 12)
            ->set('lockDays', 30)->set('partialWithdrawal', true)->set('startsAt', '2026-08-01')
            ->set('endsAt', '2027-07-31')->set('notes', 'Индивидуально')->call('save')->assertHasNoErrors();

        $this->assertDatabaseHas('investor_investment_terms', [
            'investor_id' => $investor->id, 'created_by_admin_id' => $admin->id,
            'monthly_rate' => '3.2500', 'term_months' => 12, 'status' => 'active',
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'individual_term.created', 'user_id' => $admin->id]);
    }

    public function test_term_belongs_to_investor_user(): void
    {
        [$admin, $investor] = $this->users();$term = $this->createTerm($admin, $investor);
        $this->assertTrue($term->investor->is($investor));
        $this->assertTrue($investor->investorInvestmentTerms->contains($term));
        $this->assertTrue($term->createdByAdmin->is($admin));
    }

    public function test_inactive_term_is_not_current(): void
    {
        [$admin, $investor] = $this->users();$this->createTerm($admin, $investor, ['status' => 'inactive']);
        $this->assertNull(app(InvestorInvestmentTermService::class)->currentFor($investor, 'USDT', '2026-08-24'));
        $this->assertSame(0, InvestorInvestmentTerm::active()->count());
    }

    public function test_term_with_future_start_date_is_not_current(): void
    {
        [$admin, $investor] = $this->users();$this->createTerm($admin, $investor, ['starts_at' => '2026-09-01']);
        $this->assertNull(app(InvestorInvestmentTermService::class)->currentFor($investor, 'USDT', '2026-08-24'));
    }

    public function test_term_with_expired_end_date_is_not_current(): void
    {
        [$admin, $investor] = $this->users();$this->createTerm($admin, $investor, ['starts_at' => '2026-01-01', 'ends_at' => '2026-08-23']);
        $this->assertNull(app(InvestorInvestmentTermService::class)->currentFor($investor, 'USDT', '2026-08-24'));
    }

    public function test_negative_rate_cannot_be_created(): void
    {
        [$admin, $investor] = $this->users();
        $this->expectException(DomainException::class);
        $this->createTerm($admin, $investor, ['monthly_rate' => '-1.0000']);
    }

    public function test_invalid_term_months_cannot_be_created(): void
    {
        [$admin, $investor] = $this->users();
        $this->expectException(DomainException::class);
        $this->createTerm($admin, $investor, ['term_months' => 0]);
    }

    private function users(): array
    {
        return [
            User::factory()->create(['role' => 'admin', 'is_active' => true]),
            User::factory()->create(['role' => 'investor', 'is_active' => true]),
        ];
    }

    private function createTerm(User $admin, User $investor, array $overrides = []): InvestorInvestmentTerm
    {
        return app(InvestorInvestmentTermService::class)->create($investor, array_merge([
            'currency' => 'USDT', 'min_amount' => null, 'max_amount' => null, 'monthly_rate' => '3.0000',
            'term_months' => 6, 'lock_days' => null, 'partial_withdrawal' => false,
            'starts_at' => '2026-01-01', 'ends_at' => null, 'status' => 'active', 'notes' => null,
        ], $overrides), $admin);
    }
}
