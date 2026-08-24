<?php

namespace Tests\Feature;

use App\Livewire\Admin\Investors\Show;
use App\Livewire\Investor\CreateDeposit;
use App\Livewire\Investor\Finance;
use App\Livewire\Investor\Programs;
use App\Models\InvestmentLot;
use App\Models\InvestmentProgram;
use App\Models\InvestorInvestmentTerm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class IndividualInvestmentTermsPresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_programs_page_shows_active_personal_terms_above_programs(): void
    {
        [$investor] = $this->context();
        $this->individualTerm($investor);

        Livewire::actingAs($investor)->test(Programs::class)
            ->assertSee('Ваши персональные условия')
            ->assertSee('Individual')
            ->assertSee('7.25%')
            ->assertSee('9 месяцев')
            ->assertSee('Создать инвестицию');
    }

    public function test_deposit_wizard_shows_individual_source_rate_term_and_lock_period(): void
    {
        [$investor, $program] = $this->context();
        $this->individualTerm($investor);

        Livewire::actingAs($investor)->test(CreateDeposit::class)
            ->set('investmentProgramId', $program->id)
            ->set('amount', '7000')
            ->assertSee('Individual Investor Term')
            ->assertSee('7.25%')
            ->assertSee('9 месяцев')
            ->assertSee('Период блокировки')
            ->assertSee('45 дней');
    }

    public function test_deposit_wizard_labels_program_source_when_personal_term_is_absent(): void
    {
        [$investor, $program] = $this->context();

        Livewire::actingAs($investor)->test(CreateDeposit::class)
            ->set('investmentProgramId', $program->id)
            ->set('amount', '7000')
            ->assertSee('Investment Program')
            ->assertSee('Период блокировки');
    }

    public function test_finance_list_shows_terms_source_from_lot_snapshot(): void
    {
        [$investor] = $this->context();
        $account = $investor->investor->investmentAccounts()->firstOrFail();
        InvestmentLot::create([
            'investment_account_id' => $account->id,
            'effective_terms_source' => 'individual',
            'effective_terms_snapshot' => ['source' => 'individual'],
            'original_amount' => '7000',
            'remaining_amount' => '7000',
            'currency' => 'USDT',
            'received_at' => now(),
            'accrual_start_date' => now()->toDateString(),
            'lock_months' => 9,
            'unlock_date' => now()->addDays(45)->toDateString(),
            'monthly_rate' => '7.2500',
            'status' => 'active',
        ]);

        Livewire::actingAs($investor)->test(Finance::class)
            ->assertSee('Источник условий')
            ->assertSee('Individual Investor Term');
    }

    public function test_admin_investor_show_displays_current_effective_terms(): void
    {
        [$investor] = $this->context();
        $this->individualTerm($investor);
        $admin = User::where('role', 'admin')->firstOrFail();

        Livewire::actingAs($admin)->test(Show::class, ['investor' => $investor->investor])
            ->set('activeTab', 'terms')
            ->assertSee('Эффективные условия')
            ->assertSee('Текущий источник')
            ->assertSee('Individual Investor Term')
            ->assertSee('7.25%')
            ->assertSee('9 месяцев')
            ->assertSee('Активно');
    }

    private function context(): array
    {
        $this->seed();

        return [
            User::where('email', 'alexey@example.com')->firstOrFail(),
            InvestmentProgram::where('slug', 'advanced')->firstOrFail(),
        ];
    }

    private function individualTerm(User $investor): InvestorInvestmentTerm
    {
        return InvestorInvestmentTerm::create([
            'investor_id' => $investor->id,
            'currency' => 'USDT',
            'min_amount' => '5000',
            'max_amount' => '9999',
            'monthly_rate' => '7.2500',
            'term_months' => 9,
            'lock_days' => 45,
            'partial_withdrawal' => true,
            'starts_at' => now()->subDay()->toDateString(),
            'status' => 'active',
        ]);
    }
}
