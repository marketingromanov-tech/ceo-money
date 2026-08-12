<?php

namespace App\Livewire\Admin\Investors;

use App\Models\Investor;
use App\Models\InvestmentAccount;
use App\Models\InvestmentLot;
use App\Services\AccrualCalculator;
use App\Services\AvailableBalanceService;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.admin')]
class Index extends Component
{
    use WithPagination;

    public string $search = '';
    public string $status = '';
    public string $sortBy = 'created_at';
    public string $sortDirection = 'desc';

    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedStatus(): void { $this->resetPage(); }
    public function updatedSortBy(): void
    {
        if (! in_array($this->sortBy, ['code', 'status', 'created_at'], true)) $this->sortBy = 'created_at';
        $this->resetPage();
    }
    public function updatedSortDirection(): void
    {
        if (! in_array($this->sortDirection, ['asc', 'desc'], true)) $this->sortDirection = 'desc';
        $this->resetPage();
    }

    public function sort(string $column): void
    {
        abort_unless(in_array($column, ['code', 'status', 'created_at'], true), 400);
        $this->sortDirection = $this->sortBy === $column && $this->sortDirection === 'asc' ? 'desc' : 'asc';
        $this->sortBy = $column;
    }

    public function render(AvailableBalanceService $balances, AccrualCalculator $decimal)
    {
        $investors = Investor::query()
            ->with(['user', 'investmentAccounts.investmentLots', 'investmentAccounts.dailyAccruals'])
            ->when($this->search, fn ($query) => $query->where(function ($query) {
                $term = '%'.$this->search.'%';
                $query->where('code', 'like', $term)
                    ->orWhereHas('user', fn ($query) => $query->where('name', 'like', $term)->orWhere('email', 'like', $term));
            }))
            ->when($this->status, fn ($query) => $query->where('status', $this->status))
            ->orderBy($this->sortBy, $this->sortDirection)
            ->paginate(15);

        $availableDividends = '0.00000000';
        foreach (InvestmentAccount::query()->get() as $account) {
            $availableDividends = $decimal->add($availableDividends, $balances->availableDividendBalance($account));
        }

        return view('livewire.admin.investors.index', [
            'investors' => $investors,
            'summary' => [
                ['Всего инвесторов', (string) Investor::count(), false],
                ['Активных', (string) Investor::where('status', 'active')->count(), false],
                ['Общий капитал', (string) (InvestmentLot::sum('remaining_amount') ?: '0'), true],
                ['Доступно дивидендов', $availableDividends, true],
            ],
        ])->title('Инвесторы — CEO Money');
    }
}
