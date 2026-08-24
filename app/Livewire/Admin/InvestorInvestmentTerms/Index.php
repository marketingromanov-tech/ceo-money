<?php

namespace App\Livewire\Admin\InvestorInvestmentTerms;

use App\Livewire\Concerns\RequiresRecentAdminAuthentication;
use App\Models\InvestorInvestmentTerm;
use App\Models\User;
use App\Services\InvestorInvestmentTermService;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.admin')]
class Index extends Component
{
    use RequiresRecentAdminAuthentication, WithPagination;

    public int|string $investorId = '';
    public string $currency = 'USDT';
    public string $minAmount = '';
    public string $maxAmount = '';
    public string $monthlyRate = '';
    public int|string $termMonths = '';
    public int|string $lockDays = '';
    public bool $partialWithdrawal = false;
    public string $startsAt = '';
    public string $endsAt = '';
    public string $status = 'active';
    public string $notes = '';

    public function mount(): void
    {
        $this->startsAt = now()->toDateString();
    }

    public function save(InvestorInvestmentTermService $service): void
    {
        if (! $this->requireRecentAdminAuthentication()) return;
        $data = $this->validate([
            'investorId' => ['required', 'integer', Rule::exists('users', 'id')->where('role', 'investor')],
            'currency' => ['required', 'string', 'max:12'],
            'minAmount' => ['nullable', 'decimal:0,8', 'min:0'],
            'maxAmount' => ['nullable', 'decimal:0,8', 'gt:minAmount'],
            'monthlyRate' => ['required', 'decimal:0,4', 'gt:0'],
            'termMonths' => ['required', 'integer', 'gt:0'],
            'lockDays' => ['nullable', 'integer', 'min:0'],
            'partialWithdrawal' => ['boolean'],
            'startsAt' => ['required', 'date'],
            'endsAt' => ['nullable', 'date', 'after_or_equal:startsAt'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        $service->create(User::findOrFail($data['investorId']), [
            'currency' => strtoupper($data['currency']),
            'min_amount' => $data['minAmount'] ?: null,
            'max_amount' => $data['maxAmount'] ?: null,
            'monthly_rate' => $data['monthlyRate'],
            'term_months' => (int) $data['termMonths'],
            'lock_days' => $data['lockDays'] === '' ? null : (int) $data['lockDays'],
            'partial_withdrawal' => $data['partialWithdrawal'],
            'starts_at' => $data['startsAt'],
            'ends_at' => $data['endsAt'] ?: null,
            'status' => $data['status'],
            'notes' => $data['notes'] ?: null,
        ], auth()->user());
        $this->reset(['investorId', 'minAmount', 'maxAmount', 'monthlyRate', 'termMonths', 'lockDays', 'partialWithdrawal', 'endsAt', 'notes']);
        $this->startsAt = now()->toDateString();$this->status = 'active';$this->currency = 'USDT';
        session()->flash('success', 'Индивидуальные условия созданы.');
    }

    public function render()
    {
        return view('livewire.admin.investor-investment-terms.index', [
            'investors' => User::query()->where('role', 'investor')->where('is_active', true)->orderBy('name')->get(['id', 'name', 'email']),
            'terms' => InvestorInvestmentTerm::with(['investor', 'createdByAdmin'])->latest('starts_at')->latest('id')->paginate(20),
        ])->title('Индивидуальные условия — CEO Money');
    }
}
