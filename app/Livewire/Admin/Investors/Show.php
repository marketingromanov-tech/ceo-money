<?php

namespace App\Livewire\Admin\Investors;

use App\Models\DailyAccrual;
use App\Models\DepositRequest;
use App\Models\DividendPayment;
use App\Models\InvestmentLot;
use App\Models\InvestmentTransaction;
use App\Models\InvestmentTerm;
use App\Models\Investor;
use App\Models\InvestorPaymentDetail;
use App\Models\WithdrawalRequest;
use App\Services\AccrualCalculator;
use App\Services\AvailableBalanceService;
use App\Services\InvestmentTermService;
use App\Services\InvestorWithdrawalDetailService;
use Carbon\Carbon;
use DomainException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.admin')]
class Show extends Component
{
    public Investor $investor;
    public string $activeTab = 'overview';
    public string $selectedMonth;
    public bool $showTermsModal = false;
    public string $termMonthlyRate = '';
    public int|string $termLockMonths = 0;
    public string $termMinimumBalance = '0.00000000';
    public bool $termPartialWithdrawalAllowed = true;
    public string $termMinimumDividendWithdrawal = '0.00000000';
    public string $termValidFrom = '';
    public string $paymentCurrency = 'USDT';
    public string $paymentNetwork = '';
    public string $paymentAddress = '';
    public string $paymentMemo = '';
    public bool $paymentIsActive = true;
    public bool $showPaymentDetailForm = false;
    public ?int $selectedPaymentDetailId = null;
    public string $withdrawalDetailCurrency = 'USDT';
    public string $withdrawalDetailNetwork = '';
    public string $withdrawalDetailAddress = '';
    public string $withdrawalDetailMemo = '';
    public bool $withdrawalDetailIsActive = true;
    public bool $showWithdrawalDetailForm = false;
    public ?int $selectedWithdrawalDetailId = null;
    public ?string $walletConversionMessage = null;

    public function mount(Investor $investor): void
    {
        $this->investor = $investor;
        $this->selectedMonth = now()->format('Y-m');
        if (request()->query('tab') === 'terms') {
            $this->activeTab = 'terms';
        }
    }

    public function selectTab(string $tab): void
    {
        if (in_array($tab, ['overview', 'finance', 'accruals', 'wallets', 'terms'], true)) $this->activeTab = $tab;
    }

    public function openPaymentDetailForm(?int $id = null): void
    {
        $this->resetValidation();$this->selectedPaymentDetailId=$id;$this->showPaymentDetailForm=true;
        if($id===null){$this->paymentCurrency='USDT';$this->paymentNetwork='';$this->paymentAddress='';$this->paymentMemo='';$this->paymentIsActive=true;return;}
        $detail=$this->investor->paymentDetails()->whereKey($id)->firstOrFail();
        $this->paymentCurrency=$detail->currency;$this->paymentNetwork=$detail->network;$this->paymentAddress=$detail->address;$this->paymentMemo=(string)$detail->memo;$this->paymentIsActive=$detail->is_active;
    }

    public function closePaymentDetailForm(): void
    {
        $this->showPaymentDetailForm=false;$this->selectedPaymentDetailId=null;$this->resetValidation();
    }

    public function savePaymentDetail(): void
    {
        $data=$this->validate(['paymentCurrency'=>['required','string','max:16'],'paymentNetwork'=>['required','string','max:50'],'paymentAddress'=>['required','string','max:255'],'paymentMemo'=>['nullable','string','max:255'],'paymentIsActive'=>['boolean']]);
        $values=['currency'=>mb_strtoupper(trim($data['paymentCurrency'])),'network'=>trim($data['paymentNetwork']),'address'=>trim($data['paymentAddress']),'memo'=>filled($data['paymentMemo'])?trim($data['paymentMemo']):null,'is_active'=>$data['paymentIsActive']];
        if($this->selectedPaymentDetailId)$this->investor->paymentDetails()->whereKey($this->selectedPaymentDetailId)->firstOrFail()->update($values);else$this->investor->paymentDetails()->create($values);
        $this->closePaymentDetailForm();session()->flash('status','Реквизиты пополнения сохранены.');
    }

    public function togglePaymentDetail(int $id): void
    {
        $detail=$this->investor->paymentDetails()->whereKey($id)->firstOrFail();$detail->update(['is_active'=>!$detail->is_active]);
    }

    public function openWithdrawalDetailForm(?int $id = null): void
    {
        $this->resetValidation();
        $this->selectedWithdrawalDetailId = $id;
        $this->showWithdrawalDetailForm = true;

        if ($id === null) {
            $this->withdrawalDetailCurrency = 'USDT';
            $this->withdrawalDetailNetwork = '';
            $this->withdrawalDetailAddress = '';
            $this->withdrawalDetailMemo = '';
            $this->withdrawalDetailIsActive = true;
            return;
        }

        $detail = $this->investor->withdrawalDetails()->whereKey($id)->firstOrFail();
        $this->withdrawalDetailCurrency = $detail->currency;
        $this->withdrawalDetailNetwork = $detail->network;
        $this->withdrawalDetailAddress = $detail->address;
        $this->withdrawalDetailMemo = (string) $detail->memo;
        $this->withdrawalDetailIsActive = $detail->is_active;
    }

    public function closeWithdrawalDetailForm(): void
    {
        $this->showWithdrawalDetailForm = false;
        $this->selectedWithdrawalDetailId = null;
        $this->resetValidation();
    }

    public function saveWithdrawalDetail(): void
    {
        $data = $this->validate([
            'withdrawalDetailCurrency' => ['required', 'string', 'max:16'],
            'withdrawalDetailNetwork' => ['required', 'string', 'max:50'],
            'withdrawalDetailAddress' => ['required', 'string', 'max:255'],
            'withdrawalDetailMemo' => ['nullable', 'string', 'max:255'],
            'withdrawalDetailIsActive' => ['boolean'],
        ]);
        $values = [
            'currency' => mb_strtoupper(trim($data['withdrawalDetailCurrency'])),
            'network' => trim($data['withdrawalDetailNetwork']),
            'address' => trim($data['withdrawalDetailAddress']),
            'memo' => filled($data['withdrawalDetailMemo']) ? trim($data['withdrawalDetailMemo']) : null,
            'is_active' => $data['withdrawalDetailIsActive'],
        ];

        if ($this->selectedWithdrawalDetailId) {
            $this->investor->withdrawalDetails()->whereKey($this->selectedWithdrawalDetailId)->firstOrFail()->update($values);
        } else {
            $this->investor->withdrawalDetails()->create($values);
        }

        $this->closeWithdrawalDetailForm();
        session()->flash('status', 'Реквизиты для вывода сохранены.');
    }

    public function toggleWithdrawalDetail(int $id): void
    {
        $detail = $this->investor->withdrawalDetails()->whereKey($id)->firstOrFail();
        $detail->update(['is_active' => ! $detail->is_active]);
    }

    public function useWalletForWithdrawal(int $walletId, InvestorWithdrawalDetailService $service): void
    {
        $this->walletConversionMessage = null;
        $wallet = $this->investor->wallets()->whereKey($walletId)->firstOrFail();

        try {
            $result = $service->useApprovedWallet($wallet, $this->investor, auth()->user());
        } catch (DomainException $exception) {
            $this->addError('investorWallet', $exception->getMessage());
            return;
        }

        $this->walletConversionMessage = match ($result['outcome']) {
            'exists' => 'Эти реквизиты уже используются для вывода',
            'reactivated' => 'Реквизиты для вывода повторно активированы.',
            default => 'Кошелёк добавлен в реквизиты для вывода.',
        };
        session()->flash('status', $this->walletConversionMessage);
    }

    public function openTermsModal(): void
    {
        $account = $this->investor->investmentAccounts()->firstOrFail();
        $term = $this->currentAccountTerm($account->id, Carbon::today());
        $this->resetValidation();
        $this->termMonthlyRate = (string) ($term?->monthly_rate ?? '0.0000');
        $this->termLockMonths = $term?->lock_months ?? 0;
        $this->termMinimumBalance = (string) ($term?->minimum_balance ?? '0.00000000');
        $this->termPartialWithdrawalAllowed = $term?->partial_withdrawal_allowed ?? true;
        $this->termMinimumDividendWithdrawal = (string) ($term?->minimum_dividend_withdrawal ?? '0.00000000');
        $this->termValidFrom = Carbon::today()->toDateString();
        $this->showTermsModal = true;
    }

    public function closeTermsModal(): void
    {
        $this->showTermsModal = false;
        $this->resetValidation();
    }

    public function saveTerms(InvestmentTermService $service): void
    {
        $data = $this->validate([
            'termMonthlyRate' => ['required', 'regex:/^\d{1,4}(?:\.\d{1,4})?$/'],
            'termLockMonths' => ['required', 'integer', 'min:0'],
            'termMinimumBalance' => ['required', 'regex:/^\d+(?:\.\d{1,8})?$/'],
            'termPartialWithdrawalAllowed' => ['boolean'],
            'termMinimumDividendWithdrawal' => ['required', 'regex:/^\d+(?:\.\d{1,8})?$/'],
            'termValidFrom' => ['required', 'date'],
        ]);

        $account = $this->investor->investmentAccounts()->firstOrFail();
        try {
            $service->createAccountTerm($account, [
                'monthly_rate' => $data['termMonthlyRate'],
                'lock_months' => (int) $data['termLockMonths'],
                'minimum_balance' => $data['termMinimumBalance'],
                'partial_withdrawal_allowed' => $data['termPartialWithdrawalAllowed'],
                'minimum_dividend_withdrawal' => $data['termMinimumDividendWithdrawal'],
                'valid_from' => $data['termValidFrom'],
            ], auth()->user());
        } catch (DomainException $exception) {
            $this->addError('termValidFrom', $exception->getMessage());
            return;
        }

        $this->showTermsModal = false;
        session()->flash('status', 'Новые условия сохранены.');
    }

    public function previousMonth(): void { $this->selectedMonth = Carbon::createFromFormat('Y-m', $this->selectedMonth)->subMonth()->format('Y-m'); }
    public function nextMonth(): void { $this->selectedMonth = Carbon::createFromFormat('Y-m', $this->selectedMonth)->addMonth()->format('Y-m'); }

    public function render(AvailableBalanceService $balances, AccrualCalculator $decimal)
    {
        $this->investor->load('user', 'investmentAccounts');
        $accountIds = $this->investor->investmentAccounts->pluck('id');
        $primaryAccount = $this->investor->investmentAccounts->first();
        $today = Carbon::today();
        $terms = $primaryAccount?->investmentTerms()->whereNull('investment_lot_id')
            ->with('creator')->orderByDesc('valid_from')->orderByDesc('id')->get() ?? collect();
        $currentTerm = $primaryAccount ? $this->currentAccountTerm($primaryAccount->id, $today) : null;
        $month = Carbon::createFromFormat('Y-m', $this->selectedMonth)->startOfMonth();
        $sum = fn ($query, string $column) => (string) ($query->sum($column) ?: '0');
        $capital = $sum(InvestmentLot::whereIn('investment_account_id', $accountIds), 'remaining_amount');
        $todayAccrual = $sum(DailyAccrual::whereIn('investment_account_id', $accountIds)->whereDate('accrual_date', $today), 'final_amount');
        $monthAccrual = $sum(DailyAccrual::whereIn('investment_account_id', $accountIds)->whereBetween('accrual_date', [$today->copy()->startOfMonth(), $today->copy()->endOfMonth()]), 'final_amount');
        $totalAccrual = $sum(DailyAccrual::whereIn('investment_account_id', $accountIds), 'final_amount');
        $availableDividends = '0.00000000';
        foreach ($this->investor->investmentAccounts as $account) $availableDividends = $decimal->add($availableDividends, $balances->availableDividendBalance($account));
        $availableCapital = $primaryAccount ? $balances->availableCapitalForWithdrawal($primaryAccount, $today) : '0.00000000';
        $blockedCapital = $sum(InvestmentLot::whereIn('investment_account_id', $accountIds)->where('remaining_amount', '>', 0)->whereDate('unlock_date', '>', $today), 'remaining_amount');
        $currentRate = InvestmentLot::whereIn('investment_account_id', $accountIds)->where('status', 'active')->latest('received_at')->value('monthly_rate') ?? '0.0000';
        $lots = InvestmentLot::whereIn('investment_account_id', $accountIds)->latest('received_at')->get();
        $transactions = InvestmentTransaction::whereIn('investment_account_id', $accountIds)->latest('effective_date')->get();
        $deposits = DepositRequest::whereIn('investment_account_id', $accountIds)->latest('requested_at')->get();
        $capitalWithdrawals = WithdrawalRequest::whereIn('investment_account_id', $accountIds)->where('type', 'capital')->latest('requested_at')->get();
        $accruals = DailyAccrual::whereIn('investment_account_id', $accountIds)->whereBetween('accrual_date', [$month, $month->copy()->endOfMonth()])->latest('accrual_date')->get();
        $recentOperations = $transactions->take(8)->map(fn ($item) => [
            'date' => $item->effective_date, 'type' => $item->type, 'amount' => $item->amount,
            'status' => $item->status, 'source' => 'Операция',
        ])->concat($deposits->take(8)->map(fn ($item) => [
            'date' => $item->requested_at, 'type' => 'deposit_request', 'amount' => $item->requested_amount,
            'status' => $item->status, 'source' => 'Пополнение',
        ]))->concat(WithdrawalRequest::whereIn('investment_account_id', $accountIds)->latest('requested_at')->take(8)->get()->map(fn ($item) => [
            'date' => $item->requested_at, 'type' => $item->type.'_withdrawal', 'amount' => $item->requested_amount,
            'status' => $item->status, 'source' => 'Вывод',
        ]))->sortByDesc('date')->take(8)->values();
        $chartValues = $accruals->sortBy('accrual_date')->pluck('final_amount')->values();

        return view('livewire.admin.investors.show', [
            'metrics' => [
                ['Инвестиционный капитал', $capital], ['Текущая ставка', $currentRate, '%'],
                ['Начислено сегодня', $todayAccrual], ['Начислено за месяц', $monthAccrual],
                ['Начислено всего', $totalAccrual],
                ['Выплачено', $sum(DividendPayment::whereIn('investment_account_id', $accountIds), 'gross_amount')],
                ['Доступно к выплате', $availableDividends], ['Доступный капитал к выводу', $availableCapital],
            ],
            'lots' => $lots,
            'transactions' => $transactions,
            'deposits' => $deposits,
            'capitalWithdrawals' => $capitalWithdrawals,
            'accruals' => $accruals,
            'selectedMonthLabel' => $month->locale('ru')->translatedFormat('F Y'),
            'accrualTotals' => [$todayAccrual, $monthAccrual, $totalAccrual],
            'blockedCapital' => $blockedCapital,
            'recentOperations' => $recentOperations,
            'firstInvestmentDate' => $lots->sortBy('received_at')->first()?->received_at,
            'nearestUnlockDate' => $lots->where('remaining_amount', '>', 0)->whereNotNull('unlock_date')->where('unlock_date', '>=', $today)->sortBy('unlock_date')->first()?->unlock_date,
            'accrualChartPoints' => $this->chartPoints($chartValues),
            'currentTerm' => $currentTerm,
            'terms' => $terms,
            'paymentDetails' => $this->investor->paymentDetails()->latest('id')->get(),
            'investorWallets' => $this->investor->wallets()->latest('created_at')->latest('id')->get(),
            'withdrawalDetails' => $this->investor->withdrawalDetails()->latest('id')->get(),
        ])->title($this->investor->user->name.' — CEO Money');
    }

    private function currentAccountTerm(int $accountId, Carbon $date): ?InvestmentTerm
    {
        return InvestmentTerm::query()->where('investment_account_id', $accountId)
            ->whereNull('investment_lot_id')->whereDate('valid_from', '<=', $date)
            ->where(fn ($query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date))
            ->latest('valid_from')->latest('id')->first();
    }

    private function chartPoints($values, int $width = 720, int $height = 150): string
    {
        $numbers = collect($values)->map(function ($value) {
            [$whole, $fraction] = array_pad(explode('.', ltrim((string) $value, '+-'), 2), 2, '');
            $cents = ltrim($whole.str_pad(substr($fraction, 0, 2), 2, '0'), '0') ?: '0';
            return strlen($cents) > 17 ? PHP_INT_MAX : (int) $cents;
        })->values();
        if ($numbers->isEmpty()) return '';
        $min = $numbers->min(); $max = $numbers->max(); $range = max(1, $max - $min); $count = max(1, $numbers->count() - 1); $padding = 8;
        return $numbers->map(fn ($value, $index) => ($padding + intdiv($index * ($width - 2 * $padding), $count)).','.($height - $padding - intdiv(($value - $min) * ($height - 2 * $padding), $range)))->implode(' ');
    }
}
