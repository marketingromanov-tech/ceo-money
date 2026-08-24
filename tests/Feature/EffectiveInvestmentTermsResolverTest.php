<?php

namespace Tests\Feature;

use App\Livewire\Investor\CreateDeposit;
use App\Models\DepositRequest;
use App\Models\InvestmentLot;
use App\Models\InvestmentProgram;
use App\Models\InvestorInvestmentTerm;
use App\Models\User;
use App\Services\DepositRequestService;
use App\Services\DepositVerificationService;
use App\Services\EffectiveInvestmentTermsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class EffectiveInvestmentTermsResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_eligible_individual_term_has_priority_over_program_version(): void
    {
        [$user] = $this->context();$term = $this->individual($user);
        $resolved = app(EffectiveInvestmentTermsResolver::class)->resolve($user, 'USDT', '7000', now());
        $this->assertSame('individual', $resolved['source']);$this->assertSame($term->id, $resolved['individual_term_id']);$this->assertSame('7.2500', $resolved['rate']);$this->assertSame(9, $resolved['term_months']);$this->assertSame(45, $resolved['lock_days']);$this->assertTrue($resolved['partial_withdrawal']);
    }

    public function test_program_version_is_used_when_no_individual_term_exists(): void
    {
        [$user, $program] = $this->context();$resolved = app(EffectiveInvestmentTermsResolver::class)->resolve($user, 'USDT', '7000', now());
        $this->assertSame('program', $resolved['source']);$this->assertSame($program->id, $resolved['program_id']);$this->assertSame('3.0000', $resolved['rate']);$this->assertSame(6, $resolved['term_months']);$this->assertNull($resolved['lock_days']);
    }

    public function test_inactive_future_expired_out_of_range_and_other_currency_terms_do_not_override_program(): void
    {
        [$user] = $this->context();
        foreach ([
            ['status' => 'inactive'], ['starts_at' => now()->addDay()->toDateString()],
            ['ends_at' => now()->subDay()->toDateString()], ['min_amount' => '8000'],
            ['max_amount' => '6000'], ['currency' => 'USDC'],
        ] as $attributes) {
            $term = $this->individual($user, $attributes);
            $this->assertSame('program', app(EffectiveInvestmentTermsResolver::class)->resolve($user, 'USDT', '7000', now())['source']);
            $term->delete();
        }
    }

    public function test_wizard_snapshots_individual_source_and_terms_before_request_creation(): void
    {
        [$user, $program] = $this->context();$term = $this->individual($user);$before = DepositRequest::count();
        $wizard = Livewire::actingAs($user)->test(CreateDeposit::class)->set('investmentProgramId', $program->id)->set('amount', '7000')
            ->assertSee('Individual Investor Term')->assertSee('7.25%')->call('review')->assertSet('step', 2);
        $this->assertSame($before, DepositRequest::count());
        $wizard->call('submit')->assertSet('step', 3);
        $request = DepositRequest::latest('id')->firstOrFail();
        $this->assertSame('individual', $request->effective_terms_source);$this->assertSame($term->id, $request->effective_terms_snapshot['individual_term_id']);$this->assertSame('7.2500', $request->effective_terms_snapshot['rate']);
        $this->assertSame('3.0000', $request->investment_program_snapshot['monthly_rate']);
    }

    public function test_request_and_new_lot_keep_effective_snapshot_after_individual_term_changes(): void
    {
        [$user, $program] = $this->context();$term = $this->individual($user);$account = $user->investor->investmentAccounts()->firstOrFail();$service = app(DepositRequestService::class);
        $request = $service->create($account, '7000', actor: $user, program: $program);$snapshot = $request->effective_terms_snapshot;
        $term->update(['monthly_rate' => '9.0000', 'term_months' => 18, 'lock_days' => 90, 'status' => 'inactive']);
        $service->submit($request, '7000', 'effective-terms-'.$request->id, $user);
        $admin = User::where('role', 'admin')->firstOrFail();$verification = app(DepositVerificationService::class);$verification->initializeForRequest($request);
        foreach (DepositVerificationService::MANUAL_KEYS as $key) $verification->markPassed($request, $key, $admin);
        $service->confirm($request, $admin);
        $lot = InvestmentLot::where('deposit_request_id', $request->id)->sole();
        $this->assertEquals($snapshot, $request->fresh()->effective_terms_snapshot);$this->assertSame('individual', $lot->effective_terms_source);$this->assertEquals($snapshot, $lot->effective_terms_snapshot);
        $this->assertSame('7.2500', $lot->monthly_rate);$this->assertSame(9, $lot->lock_months);$this->assertSame(now()->addDays(45)->toDateString(), $lot->unlock_date->toDateString());
    }

    private function context(): array
    {
        $this->seed();$user = User::where('email', 'alexey@example.com')->firstOrFail();$program = InvestmentProgram::where('slug', 'advanced')->firstOrFail();
        return [$user, $program];
    }

    private function individual(User $user, array $attributes = []): InvestorInvestmentTerm
    {
        return InvestorInvestmentTerm::create(array_merge(['investor_id' => $user->id, 'currency' => 'USDT', 'min_amount' => '5000', 'max_amount' => '9999', 'monthly_rate' => '7.2500', 'term_months' => 9, 'lock_days' => 45, 'partial_withdrawal' => true, 'starts_at' => now()->subMonth()->toDateString(), 'ends_at' => null, 'status' => 'active'], $attributes));
    }
}
