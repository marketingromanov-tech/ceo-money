<?php

namespace Tests\Feature;

use App\Models\InvestmentLot;
use App\Models\InvestmentProgram;
use App\Models\InvestorInvestmentTerm;
use App\Models\User;
use App\Services\DepositRequestService;
use App\Services\EffectiveInvestmentTermsResolver;
use App\Services\InvestorInvestmentTermService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvestorInvestmentTermVersionTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_term_creates_initial_version(): void
    {
        [$admin, $investor] = $this->context();
        $term = $this->createTerm($admin, $investor);

        $this->assertSame(1, $term->versions()->count());
        $this->assertDatabaseHas('investor_investment_term_versions', [
            'investor_investment_term_id' => $term->id, 'monthly_rate' => '7.2500', 'valid_from' => now()->toDateString(),
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'individual_term.version_created']);
    }

    public function test_new_version_replaces_old_version_only_from_its_start_date(): void
    {
        [$admin, $investor] = $this->context();
        $term = $this->createTerm($admin, $investor);
        $start = now()->addDays(10);
        $this->newVersion($term, $admin, $start->toDateString());

        $old = $term->versions()->where('monthly_rate', '7.2500')->sole();
        $this->assertSame($start->copy()->subDay()->toDateString(), $old->valid_to->toDateString());
        $this->assertSame('7.2500', $this->resolve($investor, now()->addDays(9)->toDateString())['rate']);
        $this->assertSame('8.5000', $this->resolve($investor, $start->toDateString())['rate']);
    }

    public function test_existing_deposit_request_snapshot_does_not_change_after_new_version(): void
    {
        [$admin, $investor, $program] = $this->context();
        $term = $this->createTerm($admin, $investor);
        $account = $investor->investor->investmentAccounts()->firstOrFail();
        $request = app(DepositRequestService::class)->create($account, '7000', actor: $investor, program: $program);
        $snapshot = $request->effective_terms_snapshot;

        $this->newVersion($term, $admin, now()->addDay()->toDateString());

        $this->assertEquals($snapshot, $request->fresh()->effective_terms_snapshot);
        $this->assertSame('7.2500', $request->fresh()->effective_terms_snapshot['rate']);
    }

    public function test_existing_lot_snapshot_does_not_change_after_new_version(): void
    {
        [$admin, $investor] = $this->context();
        $term = $this->createTerm($admin, $investor);
        $account = $investor->investor->investmentAccounts()->firstOrFail();
        $snapshot = $this->resolve($investor, now()->toDateString());
        $lot = InvestmentLot::create([
            'investment_account_id' => $account->id, 'effective_terms_source' => 'individual',
            'effective_terms_snapshot' => $snapshot, 'original_amount' => '7000', 'remaining_amount' => '7000',
            'currency' => 'USDT', 'received_at' => now(), 'accrual_start_date' => now()->toDateString(),
            'lock_months' => 9, 'unlock_date' => now()->addDays(45)->toDateString(), 'monthly_rate' => '7.2500', 'status' => 'active',
        ]);

        $this->newVersion($term, $admin, now()->addDay()->toDateString());

        $this->assertEquals($snapshot, $lot->fresh()->effective_terms_snapshot);
        $this->assertSame('7.2500', $lot->fresh()->monthly_rate);
    }

    public function test_resolver_selects_version_valid_for_requested_date(): void
    {
        [$admin, $investor] = $this->context();
        $term = $this->createTerm($admin, $investor);
        $start = now()->addMonth()->startOfDay();
        $version = $this->newVersion($term, $admin, $start->toDateString());

        $this->assertSame($term->versions()->where('monthly_rate', '7.2500')->value('id'), $this->resolve($investor, $start->copy()->subDay()->toDateString())['individual_term_version_id']);
        $this->assertSame($version->id, $this->resolve($investor, $start->toDateString())['individual_term_version_id']);
    }

    public function test_overlapping_version_cannot_be_created(): void
    {
        [$admin, $investor] = $this->context();
        $term = $this->createTerm($admin, $investor);

        $this->expectException(DomainException::class);
        $this->newVersion($term, $admin, now()->toDateString());
    }

    private function context(): array
    {
        $this->seed();

        return [User::where('role', 'admin')->firstOrFail(), User::where('email', 'alexey@example.com')->firstOrFail(), InvestmentProgram::where('slug', 'advanced')->firstOrFail()];
    }

    private function createTerm(User $admin, User $investor): InvestorInvestmentTerm
    {
        return app(InvestorInvestmentTermService::class)->create($investor, [
            'currency' => 'USDT', 'min_amount' => '5000', 'max_amount' => '9999', 'monthly_rate' => '7.2500',
            'term_months' => 9, 'lock_days' => 45, 'partial_withdrawal' => true,
            'starts_at' => now()->toDateString(), 'ends_at' => null, 'status' => 'active', 'notes' => 'Первая версия',
        ], $admin);
    }

    private function newVersion(InvestorInvestmentTerm $term, User $admin, string $validFrom)
    {
        return app(InvestorInvestmentTermService::class)->createVersion($term, [
            'currency' => 'USDT', 'min_amount' => '5000', 'max_amount' => '9999', 'monthly_rate' => '8.5000',
            'term_months' => 12, 'lock_days' => 60, 'partial_withdrawal' => false,
            'starts_at' => $validFrom, 'ends_at' => null, 'status' => 'active', 'notes' => 'Новая версия',
        ], $admin);
    }

    private function resolve(User $investor, string $date): array
    {
        return app(EffectiveInvestmentTermsResolver::class)->resolve($investor, 'USDT', '7000', $date);
    }
}
