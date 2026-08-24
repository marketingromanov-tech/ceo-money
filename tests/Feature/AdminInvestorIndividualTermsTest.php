<?php

namespace Tests\Feature;

use App\Livewire\Admin\Investors\Show;
use App\Models\InvestmentAccount;
use App\Models\Investor;
use App\Models\InvestorInvestmentTerm;
use App\Models\User;
use App\Services\InvestorInvestmentTermService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminInvestorIndividualTermsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sees_individual_terms_block_in_investor_card(): void
    {
        [$admin, $investor] = $this->context();
        Livewire::actingAs($admin)->test(Show::class, ['investor' => $investor])->call('selectTab', 'terms')
            ->assertSee('Индивидуальные инвестиционные условия')->assertSee('+ Добавить индивидуальные условия');
    }

    public function test_admin_creates_term_for_card_investor(): void
    {
        [$admin, $investor] = $this->context();
        $this->form(Livewire::actingAs($admin)->test(Show::class, ['investor' => $investor])->call('selectTab', 'terms')->call('openIndividualTermForm'))
            ->call('saveIndividualTerm')->assertHasNoErrors();
        $this->assertDatabaseHas('investor_investment_terms', ['investor_id' => $investor->user_id, 'monthly_rate' => '4.2500', 'term_months' => 9]);
    }

    public function test_individual_term_is_displayed_in_investor_card(): void
    {
        [$admin, $investor] = $this->context();$term = $this->term($admin, $investor);
        Livewire::actingAs($admin)->test(Show::class, ['investor' => $investor])->call('selectTab', 'terms')
            ->assertSee('4.25%')->assertSee('9 мес.')->assertSee('1 000.00 USDT')->assertSee('50 000.00 USDT')
            ->assertSee('Разрешён')->assertSee('Особый контракт')->assertSee($admin->name)->assertSee($term->created_at->format('d.m.Y H:i'));
    }

    public function test_admin_updates_individual_term(): void
    {
        [$admin, $investor] = $this->context();$term = $this->term($admin, $investor);
        Livewire::actingAs($admin)->test(Show::class, ['investor' => $investor])->call('selectTab', 'terms')->call('openIndividualTermForm', $term->id)
            ->set('individualMonthlyRate', '5.5000')->set('individualTermMonths', 12)->set('individualNotes', 'Обновлено')
            ->call('saveIndividualTerm')->assertHasNoErrors();
        $this->assertDatabaseHas('investor_investment_term_versions', ['investor_investment_term_id' => $term->id, 'monthly_rate' => '5.5000', 'term_months' => 12, 'notes' => 'Обновлено']);
        $this->assertSame(2, $term->versions()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'individual_term.updated', 'entity_id' => $term->id]);
    }

    public function test_admin_deactivates_individual_term_and_audit_is_created(): void
    {
        [$admin, $investor] = $this->context();$term = $this->term($admin, $investor);
        Livewire::actingAs($admin)->test(Show::class, ['investor' => $investor])->call('deactivateIndividualTerm', $term->id)->assertHasNoErrors();
        $this->assertSame('inactive', $term->fresh()->status);
        foreach (['individual_term.created', 'individual_term.deactivated'] as $action) $this->assertDatabaseHas('audit_logs', ['action' => $action, 'entity_id' => $term->id]);
    }

    public function test_investor_cannot_access_admin_investor_card(): void
    {
        [, $investor] = $this->context();
        $this->actingAs($investor->user)->get(route('admin.investors.show', $investor))->assertForbidden();
    }

    private function form($component)
    {
        return $component->set('individualCurrency', 'USDT')->set('individualMinAmount', '1000')->set('individualMaxAmount', '50000')
            ->set('individualMonthlyRate', '4.2500')->set('individualTermMonths', 9)->set('individualLockDays', 45)
            ->set('individualPartialWithdrawal', true)->set('individualStartsAt', '2026-08-24')->set('individualEndsAt', '2027-05-24')->set('individualNotes', 'Особый контракт');
    }

    private function term(User $admin, Investor $investor): InvestorInvestmentTerm
    {
        return app(InvestorInvestmentTermService::class)->create($investor->user, ['currency' => 'USDT', 'min_amount' => '1000', 'max_amount' => '50000', 'monthly_rate' => '4.2500', 'term_months' => 9, 'lock_days' => 45, 'partial_withdrawal' => true, 'starts_at' => '2026-08-24', 'ends_at' => '2027-05-24', 'status' => 'active', 'notes' => 'Особый контракт'], $admin);
    }

    private function context(): array
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);$user = User::factory()->create(['role' => 'investor', 'is_active' => true]);
        $investor = Investor::create(['user_id' => $user->id, 'code' => 'IND-'.$user->id, 'status' => 'active']);
        InvestmentAccount::create(['investor_id' => $investor->id, 'currency' => 'USDT', 'status' => 'active']);
        return [$admin, $investor];
    }
}
