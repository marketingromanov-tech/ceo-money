<?php

namespace Tests\Feature;

use App\Livewire\Admin\Investors\Show;
use App\Livewire\Investor\CreateDeposit;
use App\Livewire\Investor\Finance;
use App\Livewire\Investor\Programs;
use App\Models\InvestmentLot;
use App\Models\DepositRequest;
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
        $term = $this->individualTerm($investor);

        Livewire::actingAs($investor)->test(Programs::class)
            ->assertSee('Ваши персональные условия')
            ->assertSee('Individual')
            ->assertSee('7.25%')
            ->assertSee('9 месяцев')
            ->assertSee('Создать инвестицию')
            ->assertSeeHtml('bg-gradient-to-r')
            ->assertSee(route('investor.finance.create', ['individual_term_id' => $term->id]), false);
    }

    public function test_personal_terms_cta_opens_deposit_wizard_without_program_id(): void
    {
        [$investor] = $this->context();
        $term = $this->individualTerm($investor);

        $this->actingAs($investor)->get(route('investor.finance.create', ['individual_term_id' => $term->id]))
            ->assertOk()->assertSee('Individual')->assertSee('Сумма инвестиции')->assertSee('Individual Investor Term');
    }

    public function test_personal_wizard_creates_request_only_after_confirmation_and_uses_individual_terms(): void
    {
        [$investor] = $this->context();
        $term = $this->individualTerm($investor);
        $before = DepositRequest::count();

        $wizard = Livewire::actingAs($investor)->test(CreateDeposit::class)
            ->set('individualTermId', $term->id)->set('amount', '7000')
            ->assertSee('Individual Investor Term')->assertSee('7.25%')
            ->call('review')->assertSet('step', 2);
        $this->assertSame($before, DepositRequest::count());

        $wizard->call('submit')->assertSet('step', 3);
        $request = DepositRequest::latest('id')->firstOrFail();
        $this->assertNull($request->investment_program_id);
        $this->assertSame('individual', $request->effective_terms_source);
        $this->assertSame($term->id, $request->effective_terms_snapshot['individual_term_id']);
        $this->assertSame('7.2500', $request->effective_terms_snapshot['rate']);
    }

    public function test_investor_without_personal_terms_does_not_see_personal_cta_block(): void
    {
        [$investor] = $this->context();

        Livewire::actingAs($investor)->test(Programs::class)
            ->assertDontSee('Ваши персональные условия')
            ->assertDontSee('Создать инвестицию');
    }

    public function test_regular_program_buttons_keep_the_existing_redirect(): void
    {
        [$investor, $program] = $this->context();

        Livewire::actingAs($investor)->test(Programs::class)->call('selectProgram', $program->id)
            ->assertRedirect(route('investor.finance.create', ['investment_program_id' => $program->id]));
    }

    public function test_wizard_formats_system_and_edited_amounts_with_two_decimal_places(): void
    {
        [$investor, $program] = $this->context();
        $wizard = Livewire::actingAs($investor)->test(CreateDeposit::class)->set('investmentProgramId', $program->id);

        $wizard->set('amount', '10000.00000000')->call('normalizeAmountForDisplay')->assertSet('amount', '10000.00');
        $wizard->set('amount', '12500.50000000')->call('normalizeAmountForDisplay')->assertSet('amount', '12500.50');
        $wizard->set('amount', '10000.12345678')->call('normalizeAmountForDisplay')->assertSet('amount', '10000.12');
        $wizard->set('amount', '12500.5')->call('normalizeAmountForDisplay')->assertSet('amount', '12500.50');
    }

    public function test_automatically_generated_wizard_amount_url_has_two_decimal_places(): void
    {
        [$investor, $program] = $this->context();

        Livewire::actingAs($investor)->test(CreateDeposit::class)
            ->set('investmentProgramId', $program->id)->set('amount', '10000.00000000')
            ->assertSee('amount=10000.00', false);
    }

    public function test_amount_normalization_keeps_projection_exact_and_request_decimal_correct(): void
    {
        [$investor, $program] = $this->context();
        $before = DepositRequest::count();

        $wizard = Livewire::actingAs($investor)->test(CreateDeposit::class)
            ->set('investmentProgramId', $program->id)->set('amount', '7000.12345678')
            ->call('review')->assertSet('amount', '7000.12')->assertSet('step', 2)
            ->assertViewHas('projection', fn (array $projection) => $projection['monthly'] === '210.00360000' && $projection['period'] === '1260.02160000');
        $this->assertSame($before, DepositRequest::count());

        $wizard->call('submit')->assertSet('step', 3);
        $request = DepositRequest::latest('id')->firstOrFail();
        $this->assertSame('7000.12000000', $request->requested_amount);
    }

    public function test_wizard_amount_formatting_does_not_use_float(): void
    {
        $this->assertStringNotContainsString('(float)', file_get_contents(app_path('Support/MoneyFormatter.php')));
        $this->assertStringNotContainsString('(float)', file_get_contents(app_path('Livewire/Investor/CreateDeposit.php')));
        $this->assertStringNotContainsString('(float)', file_get_contents(resource_path('views/livewire/investor/create-deposit.blade.php')));
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
