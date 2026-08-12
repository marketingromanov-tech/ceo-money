<?php

namespace App\Livewire\Investor;

use App\Livewire\Investor\Concerns\InteractsWithInvestorData;
use App\Models\WithdrawalRequest;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.investor')]
class Withdrawals extends Component
{
    use InteractsWithInvestorData, WithPagination;

    public string $typeFilter = '';
    public string $statusFilter = '';

    public function updatedTypeFilter(): void { $this->resetPage(); }
    public function updatedStatusFilter(): void { $this->resetPage(); }

    public function render()
    {
        $account = $this->account();
        $baseQuery = $account->withdrawalRequests();
        $summary = [
            'total' => (clone $baseQuery)->count(),
            'active' => (clone $baseQuery)->whereIn('status', WithdrawalRequest::ACTIVE_RESERVATION_STATUSES)->count(),
            'paid' => (clone $baseQuery)->where('status', 'paid')->count(),
            'declined' => (clone $baseQuery)->whereIn('status', ['rejected', 'cancelled'])->count(),
        ];
        $withdrawals = $account->withdrawalRequests()->with('investorWallet')
            ->when($this->typeFilter !== '', fn ($query) => $query->where('type', $this->typeFilter))
            ->when($this->statusFilter !== '', fn ($query) => $query->where('status', $this->statusFilter))
            ->orderByDesc('requested_at')->orderByDesc('id')
            ->paginate(20);

        return view('livewire.investor.withdrawals', [
            'withdrawals' => $withdrawals,
            'withdrawalSummary' => $summary,
        ])->title('Выводы — CEO Money');
    }
}
