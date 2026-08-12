<?php

namespace App\Livewire\Investor;

use App\Livewire\Investor\Concerns\InteractsWithInvestorData;
use App\Models\InvestorWallet;
use App\Services\WalletService;
use App\Services\AdminNotificationService;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.investor')]
class Wallets extends Component
{
    use InteractsWithInvestorData;

    public string $currency = 'USDT';
    public string $network = 'TRC20';
    public string $address = '';
    public string $label = '';

    public function add(): void
    {
        $investor = $this->investor();
        $data = $this->validate([
            'currency' => ['required', 'string', 'max:20'],
            'network' => ['required', 'string', 'max:50'],
            'address' => ['required', 'string', 'max:255', Rule::unique('investor_wallets')->where(fn ($q) => $q->where('investor_id', $investor->id)->where('network', $this->network))],
            'label' => ['nullable', 'string', 'max:100'],
        ]);
        $wallet = $investor->wallets()->create(array_merge($data, ['status' => 'pending']));
        app(AdminNotificationService::class)->walletCreated($wallet);
        $this->reset('address', 'label');
        $this->resetValidation();
        session()->flash('success', 'Кошелёк добавлен и отправлен на проверку.');
        $this->dispatch('wallet-added');
    }

    public function cancelAdd(): void
    {
        $this->reset('address', 'label');
        $this->resetValidation();
    }

    public function archive(int $walletId, WalletService $service): void
    {
        $wallet = InvestorWallet::query()->where('investor_id', $this->investor()->id)->findOrFail($walletId);
        $service->archivePendingByInvestor($wallet, auth()->user());
        session()->flash('success', 'Кошелёк перемещён в архив.');
    }

    public function render()
    {
        $wallets = $this->investor()->wallets()->latest()->get();

        return view('livewire.investor.wallets', [
            'wallets' => $wallets,
            'walletSummary' => [
                'total' => $wallets->count(),
                'approved' => $wallets->where('status', 'approved')->count(),
                'pending' => $wallets->where('status', 'pending')->count(),
            ],
        ])->title('Кошельки — CEO Money');
    }
}
