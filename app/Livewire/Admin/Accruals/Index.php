<?php

namespace App\Livewire\Admin\Accruals;

use App\Models\DailyAccrual;
use App\Models\InvestmentLot;
use App\Models\Investor;
use Carbon\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.admin')]
class Index extends Component
{
    use WithPagination;

    public string $search = '';
    public string $period = 'month';
    public string $dateFrom = '';
    public string $dateTo = '';
    public string $appliedFrom = '';
    public string $appliedTo = '';
    public string $investorId = '';
    public string $investmentLotId = '';
    public string $adjustmentFilter = 'all';

    public function mount(): void
    {
        $this->resetCustomDates();
    }

    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedAdjustmentFilter(): void { $this->resetPage(); }
    public function updatedInvestmentLotId(): void { $this->resetPage(); }
    public function updatedInvestorId(): void
    {
        $this->investmentLotId = '';
        $this->resetPage();
    }

    public function selectPeriod(string $period): void
    {
        if (! in_array($period, ['today', 'month', 'all', 'custom'], true)) return;
        $this->period = $period;
        if ($period !== 'custom') $this->resetPage();
    }

    public function applyCustomPeriod(): void
    {
        $this->validate([
            'dateFrom' => ['required', 'date'],
            'dateTo' => ['required', 'date', 'after_or_equal:dateFrom'],
        ], ['dateTo.after_or_equal' => 'Дата окончания должна быть не раньше даты начала.']);
        $this->appliedFrom = $this->dateFrom;
        $this->appliedTo = $this->dateTo;
        $this->period = 'custom';
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset('search', 'investorId', 'investmentLotId');
        $this->adjustmentFilter = 'all';
        $this->period = 'month';
        $this->resetCustomDates();
        $this->resetValidation();
        $this->resetPage();
    }

    public function render()
    {
        $today = Carbon::today();
        $query = DailyAccrual::query()
            ->with(['investmentAccount.investor.user', 'investmentLot.dividendCapitalization'])
            ->when(trim($this->search) !== '', function ($query) {
                $term = '%'.trim($this->search).'%';
                $query->whereHas('investmentAccount.investor', fn ($investorQuery) => $investorQuery
                    ->where('code', 'like', $term)
                    ->orWhereHas('user', fn ($userQuery) => $userQuery->where('name', 'like', $term)->orWhere('email', 'like', $term)));
            })
            ->when($this->investorId !== '', fn ($query) => $query->whereHas('investmentAccount', fn ($accountQuery) => $accountQuery->where('investor_id', $this->investorId)))
            ->when($this->investmentLotId !== '', fn ($query) => $query->where('investment_lot_id', $this->investmentLotId))
            ->when($this->adjustmentFilter === 'with', fn ($query) => $query->where('adjustment_amount', '!=', '0'))
            ->when($this->adjustmentFilter === 'without', fn ($query) => $query->where('adjustment_amount', '=', '0'));

        match ($this->period) {
            'today' => $query->whereDate('accrual_date', $today),
            'month' => $query->whereBetween('accrual_date', [$today->copy()->startOfMonth(), $today->copy()->endOfMonth()]),
            'custom' => $query->whereBetween('accrual_date', [$this->appliedFrom, $this->appliedTo]),
            default => null,
        };

        $accruals = $query->orderByDesc('accrual_date')->orderByDesc('id')->paginate(25);
        $accountIds = $accruals->getCollection()->pluck('investment_account_id')->unique();
        $numberedLots = InvestmentLot::query()->whereIn('investment_account_id', $accountIds)
            ->orderBy('investment_account_id')->orderBy('received_at')->orderBy('id')->get(['id', 'investment_account_id'])
            ->groupBy('investment_account_id');
        $lotNumbers = collect();
        foreach ($numberedLots as $lots) {
            foreach ($lots->values() as $index => $lot) $lotNumbers->put($lot->id, $index + 1);
        }

        $monthStart = $today->copy()->startOfMonth();
        $monthEnd = $today->copy()->endOfMonth();
        $kpis = [
            'today' => (string) (DailyAccrual::whereDate('accrual_date', $today)->sum('final_amount') ?: '0'),
            'month' => (string) (DailyAccrual::whereBetween('accrual_date', [$monthStart, $monthEnd])->sum('final_amount') ?: '0'),
            'total' => (string) (DailyAccrual::sum('final_amount') ?: '0'),
            'adjustments' => (string) (DailyAccrual::whereBetween('accrual_date', [$monthStart, $monthEnd])->sum('adjustment_amount') ?: '0'),
        ];

        $investors = Investor::query()->with('user')->orderBy('code')->limit(200)->get();
        $investmentOptions = collect();
        if ($this->investorId !== '') {
            $investmentOptions = InvestmentLot::query()->whereHas('investmentAccount', fn ($query) => $query->where('investor_id', $this->investorId))
                ->orderBy('received_at')->orderBy('id')->get()->values();
        }

        return view('livewire.admin.accruals.index', compact('accruals', 'lotNumbers', 'kpis', 'investors', 'investmentOptions'))
            ->title('Начисления — CEO Money');
    }

    private function resetCustomDates(): void
    {
        $this->dateFrom = now()->startOfMonth()->toDateString();
        $this->dateTo = now()->toDateString();
        $this->appliedFrom = $this->dateFrom;
        $this->appliedTo = $this->dateTo;
    }
}
