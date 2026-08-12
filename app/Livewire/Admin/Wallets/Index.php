<?php

namespace App\Livewire\Admin\Wallets;

use App\Models\DepositAddress;
use App\Models\Investor;
use App\Models\InvestorWallet;
use App\Services\DepositAddressService;
use App\Services\WalletService;
use DomainException;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.admin')]
class Index extends Component
{
    use WithPagination;

    public string $activeTab = 'wallets';
    public string $walletSearch = ''; public string $walletStatus = ''; public string $walletNetwork = ''; public string $walletCurrency = ''; public string $walletInvestor = '';
    public string $addressSearch = ''; public string $addressStatus = ''; public string $addressNetwork = ''; public string $addressCurrency = ''; public string $addressProvider = '';
    public bool $showAddressModal = false; public string $newInvestorId = ''; public string $newCurrency = 'USDT'; public string $newNetwork = 'TRC20'; public string $newAddress = ''; public string $newProvider = ''; public string $newLabel = '';
    public bool $showAssignModal = false; public ?int $assignAddressId = null; public string $assignInvestorId = '';
    public bool $showConfirmModal = false; public string $confirmAction = ''; public ?int $confirmId = null;

    public function selectTab(string $tab): void
    {
        if (in_array($tab, ['wallets', 'addresses'], true)) {
            $this->activeTab = $tab;
        }
    }

    public function updated(string $property): void
    {
        if (str_starts_with($property, 'wallet')) $this->resetPage('walletsPage');
        if (str_starts_with($property, 'address')) $this->resetPage('addressesPage');
    }

    public function confirm(string $action, int $id): void
    {
        if (! in_array($action, ['approve-wallet', 'block-wallet', 'archive-wallet', 'archive-address'], true)) return;
        $this->confirmAction = $action; $this->confirmId = $id; $this->showConfirmModal = true;
    }

    public function closeConfirm(): void { $this->showConfirmModal = false; $this->confirmAction = ''; $this->confirmId = null; }

    public function executeConfirmed(WalletService $wallets, DepositAddressService $addresses): void
    {
        try {
            match ($this->confirmAction) {
                'approve-wallet' => $wallets->approveInvestorWallet(InvestorWallet::findOrFail($this->confirmId), auth()->user()),
                'block-wallet' => $wallets->blockInvestorWallet(InvestorWallet::findOrFail($this->confirmId), auth()->user()),
                'archive-wallet' => $wallets->archiveInvestorWallet(InvestorWallet::findOrFail($this->confirmId), auth()->user()),
                'archive-address' => $addresses->archive(DepositAddress::findOrFail($this->confirmId), auth()->user()),
                default => null,
            };
            session()->flash('success', 'Изменение сохранено.');
            $this->closeConfirm();
        } catch (DomainException $exception) {
            $this->addError('confirmation', $exception->getMessage());
        }
    }

    public function openAddressModal(): void
    {
        $this->reset(['newInvestorId', 'newAddress', 'newProvider', 'newLabel']);
        $this->newCurrency = 'USDT'; $this->newNetwork = 'TRC20'; $this->resetValidation(); $this->showAddressModal = true;
    }

    public function closeAddressModal(): void { $this->showAddressModal = false; $this->resetValidation(); }

    public function createAddress(DepositAddressService $service): void
    {
        $data = $this->validate([
            'newInvestorId' => ['nullable', 'exists:investors,id'], 'newCurrency' => ['required', 'string', 'max:20'],
            'newNetwork' => ['required', 'string', 'max:50'], 'newAddress' => ['required', 'string', 'max:255', Rule::unique('deposit_addresses', 'address')->where('network', $this->newNetwork)],
            'newProvider' => ['nullable', 'string', 'max:100'], 'newLabel' => ['nullable', 'string', 'max:100'],
        ]);
        $investor = $data['newInvestorId'] !== '' ? Investor::findOrFail($data['newInvestorId']) : null;
        $service->create([
            'currency' => $data['newCurrency'], 'network' => $data['newNetwork'], 'address' => $data['newAddress'],
            'provider' => $data['newProvider'] ?: null, 'label' => $data['newLabel'] ?: null,
        ], $investor, auth()->user());
        $this->showAddressModal = false; session()->flash('success', 'Адрес пополнения добавлен.');
    }

    public function openAssign(int $id): void
    {
        $address = DepositAddress::findOrFail($id);
        if (! $address->is_active || $address->archived_at !== null || $address->investor_id !== null) return;
        $this->assignAddressId = $address->id; $this->assignInvestorId = ''; $this->resetValidation(); $this->showAssignModal = true;
    }

    public function assignAddress(WalletService $service): void
    {
        $data = $this->validate(['assignInvestorId' => ['required', 'exists:investors,id']]);
        try {
            $service->assignDepositAddress(DepositAddress::findOrFail($this->assignAddressId), Investor::findOrFail($data['assignInvestorId']), auth()->user());
            $this->showAssignModal = false; session()->flash('success', 'Адрес назначен инвестору.');
        } catch (DomainException $exception) {
            $this->addError('assignInvestorId', $exception->getMessage());
        }
    }

    public function render()
    {
        $wallets = InvestorWallet::query()->with(['investor.user', 'approvedBy'])
            ->when($this->walletSearch, function ($query) { $s='%'.trim($this->walletSearch).'%'; $query->where(fn($q)=>$q->where('address','like',$s)->orWhereHas('investor',fn($i)=>$i->where('code','like',$s)->orWhereHas('user',fn($u)=>$u->where('name','like',$s)->orWhere('email','like',$s)))); })
            ->when($this->walletStatus, fn($q)=>$q->where('status',$this->walletStatus))->when($this->walletNetwork,fn($q)=>$q->where('network',$this->walletNetwork))->when($this->walletCurrency,fn($q)=>$q->where('currency',$this->walletCurrency))->when($this->walletInvestor,fn($q)=>$q->where('investor_id',$this->walletInvestor))
            ->orderByRaw("CASE status WHEN 'pending' THEN 0 ELSE 1 END")->orderByDesc('created_at')->orderByDesc('id')->paginate(20, ['*'], 'walletsPage');
        $addresses = DepositAddress::query()->with(['investor.user','createdBy'])->withCount('depositRequests')
            ->when($this->addressSearch, function ($query) { $s='%'.trim($this->addressSearch).'%'; $query->where(fn($q)=>$q->where('address','like',$s)->orWhere('provider','like',$s)->orWhereHas('investor',fn($i)=>$i->where('code','like',$s)->orWhereHas('user',fn($u)=>$u->where('name','like',$s)->orWhere('email','like',$s)))); })
            ->when($this->addressStatus==='active',fn($q)=>$q->where('is_active',true)->whereNull('archived_at'))->when($this->addressStatus==='inactive',fn($q)=>$q->where('is_active',false)->whereNull('archived_at'))->when($this->addressStatus==='archived',fn($q)=>$q->whereNotNull('archived_at'))
            ->when($this->addressNetwork,fn($q)=>$q->where('network',$this->addressNetwork))->when($this->addressCurrency,fn($q)=>$q->where('currency',$this->addressCurrency))->when($this->addressProvider,fn($q)=>$q->where('provider',$this->addressProvider))->latest('created_at')->latest('id')->paginate(20, ['*'], 'addressesPage');
        $walletKpis=['total'=>InvestorWallet::count(),'pending'=>InvestorWallet::where('status','pending')->count(),'approved'=>InvestorWallet::where('status','approved')->count(),'archived'=>InvestorWallet::where('status','archived')->count()];
        $addressKpis=['total'=>DepositAddress::count(),'active'=>DepositAddress::where('is_active',true)->whereNull('archived_at')->count(),'assigned'=>DepositAddress::whereNotNull('investor_id')->count(),'archived'=>DepositAddress::whereNotNull('archived_at')->count()];
        $investors=Investor::with('user')->orderBy('code')->get();
        $walletNetworks=InvestorWallet::distinct()->orderBy('network')->pluck('network'); $walletCurrencies=InvestorWallet::distinct()->orderBy('currency')->pluck('currency');
        $addressNetworks=DepositAddress::distinct()->orderBy('network')->pluck('network'); $addressCurrencies=DepositAddress::distinct()->orderBy('currency')->pluck('currency'); $providers=DepositAddress::whereNotNull('provider')->distinct()->orderBy('provider')->pluck('provider');
        $confirmationModel = $this->confirmAction === 'archive-address' ? DepositAddress::find($this->confirmId) : InvestorWallet::with('investor.user')->find($this->confirmId);

        return view('livewire.admin.wallets.index', compact('wallets','addresses','walletKpis','addressKpis','investors','walletNetworks','walletCurrencies','addressNetworks','addressCurrencies','providers','confirmationModel'))->title('Кошельки — CEO Money');
    }
}
