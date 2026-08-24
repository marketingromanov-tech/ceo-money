<?php

namespace App\Livewire\Investor;

use App\Livewire\Investor\Concerns\InteractsWithInvestorData;
use App\Services\AccrualCalculator;
use App\Services\AvailableBalanceService;
use App\Models\DepositRequest;
use App\Services\DepositRequestService;
use DomainException;
use Carbon\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.investor')]
class Finance extends Component
{
    use InteractsWithInvestorData;

    public string $investmentFilter = 'active';
    public bool $showPaymentModal = false;
    public ?int $selectedPaymentRequestId = null;

    public function openPayment(int $requestId, DepositRequestService $service): void
    {
        $request=$this->account()->depositRequests()->whereKey($requestId)->firstOrFail();
        if($request->status!=='pending')return;
        try{$request=$service->ensurePaymentDetailsSnapshot($request,auth()->user());}catch(DomainException $e){$this->addError('payment',$e->getMessage());return;}
        $this->selectedPaymentRequestId=$request->id;$this->showPaymentModal=true;$this->resetErrorBag();
    }

    public function closePayment(): void { $this->showPaymentModal=false;$this->selectedPaymentRequestId=null;$this->resetErrorBag(); }

    public function markPaid(DepositRequestService $service): void
    {
        $request=$this->account()->depositRequests()->whereKey($this->selectedPaymentRequestId)->firstOrFail();
        try{$service->markPaymentSubmitted($request,auth()->user());$this->closePayment();session()->flash('status','Оплата отправлена на проверку.');}
        catch(DomainException $e){$this->addError('payment',$e->getMessage());}
    }

    public function setInvestmentFilter(string $filter): void
    {
        if (in_array($filter, ['active', 'closed', 'all'], true)) {
            $this->investmentFilter = $filter;
        }
    }

    public function render(AvailableBalanceService $balances, AccrualCalculator $decimal, DepositRequestService $deposits)
    {
        $account = $this->account();
        $today = Carbon::today();
        $lots = $account->investmentLots()->with('dividendCapitalization')
            ->orderBy('received_at')->orderBy('id')->get();
        $capital = '0.00000000';
        $activeLots = 0;

        foreach ($lots as $index => $lot) {
            $capital = $decimal->add($capital, (string) $lot->remaining_amount);
            if ($lot->status === 'active' && $decimal->compare((string) $lot->remaining_amount, '0') > 0) {
                $activeLots++;
            }
            $lot->setAttribute('display_number', $index + 1);
            $lot->setAttribute('display_terms_source', match ($lot->effective_terms_source) {
                'individual' => 'Individual Investor Term',
                'program' => 'Investment Program',
                default => 'Legacy investment terms',
            });
            $lot->setAttribute('display_source', $lot->dividendCapitalization ? 'Капитализация дивидендов' : 'Пополнение');
            $lot->setAttribute('is_withdrawable', $lot->status === 'active'
                && $decimal->compare((string) $lot->remaining_amount, '0') > 0
                && ($lot->unlock_date === null || $lot->unlock_date->lte($today)));
        }

        $investmentCounts = [
            'active' => $lots->where('status', 'active')->count(),
            'closed' => $lots->where('status', 'closed')->count(),
            'all' => $lots->count(),
        ];
        $visibleLots = match ($this->investmentFilter) {
            'active' => $lots->where('status', 'active')->values(),
            'closed' => $lots->where('status', 'closed')->values(),
            default => $lots,
        };

        $availableCapital = $balances->availableCapitalForWithdrawal($account, $today);
        $lockedCapital = $decimal->subtract($capital, $availableCapital);
        if ($decimal->compare($lockedCapital, '0') < 0) $lockedCapital = '0.00000000';

        $depositRequests = $account->depositRequests()->with('investmentProgram')->latest('requested_at')->latest('id')->limit(20)->get();
        $activePaymentDetail=$deposits->paymentDetailsFor($account);
        $selectedPaymentRequest=$this->selectedPaymentRequestId?$depositRequests->firstWhere('id',$this->selectedPaymentRequestId):null;

        return view('livewire.investor.finance', [
            'account' => $account,
            'lots' => $lots,
            'visibleLots' => $visibleLots,
            'investmentCounts' => $investmentCounts,
            'financeSummary' => [
                'capital' => $capital,
                'activeLots' => $activeLots,
                'availableCapital' => $availableCapital,
                'lockedCapital' => $lockedCapital,
            ],
            'operations' => $this->operations($account),
            'depositRequests' => $depositRequests,
            'selectedPaymentRequest' => $selectedPaymentRequest,
            'activePaymentDetail' => $activePaymentDetail,
        ])->title('Финансы — CEO Money');
    }

    private function operations($account)
    {
        $transactions = $account->investmentTransactions()->latest('effective_date')->latest('id')->limit(50)->get()
            ->map(fn ($item) => [
                'type' => $item->type,
                'detail' => $item->type === 'capitalization' ? 'Дивиденды → капитал' : null,
                'date' => $item->effective_date,
                'status' => $item->status,
                'amount' => $item->amount,
                'direction' => in_array($item->type, ['deposit', 'capitalization'], true) ? 'in' : 'neutral',
            ]);
        $withdrawals = $account->withdrawalRequests()->latest('requested_at')->limit(30)->get()
            ->map(fn ($item) => [
                'type' => $item->type === 'capital' ? 'capital_withdrawal' : 'dividend_withdrawal',
                'detail' => null, 'date' => $item->requested_at, 'status' => $item->status,
                'amount' => $item->requested_amount, 'direction' => 'out',
            ]);
        $payments = $account->dividendPayments()->latest('paid_at')->limit(30)->get()
            ->map(fn ($item) => [
                'type' => 'dividend_payment', 'detail' => null, 'date' => $item->paid_at,
                'status' => 'paid', 'amount' => $item->gross_amount, 'direction' => 'out',
            ]);

        return $transactions->concat($withdrawals)->concat($payments)
            ->sortByDesc('date')->values()->take(60);
    }
}
