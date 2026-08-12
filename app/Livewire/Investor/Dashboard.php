<?php

namespace App\Livewire\Investor;

use App\Livewire\Investor\Concerns\HasAccrualPeriod;
use App\Livewire\Investor\Concerns\InteractsWithInvestorData;
use App\Models\InvestmentTerm;
use App\Services\AccrualCalculator;
use App\Services\AvailableBalanceService;
use App\Services\DividendCapitalizationService;
use App\Services\FeeCalculatorService;
use App\Services\InvestorWithdrawalDetailService;
use App\Services\WithdrawalRequestService;
use Carbon\Carbon;
use DomainException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.investor')]
class Dashboard extends Component
{
    use HasAccrualPeriod, InteractsWithInvestorData;

    public ?string $dialog = null;
    public string $dialogStep = 'form';
    public ?string $dialogError = null;
    public string $capitalizationAmount = '';
    public string $withdrawalAmount = '';

    public function openDialog(string $dialog): void
    {
        abort_unless(in_array($dialog, ['capitalization', 'dividend', 'capital'], true), 422);
        $this->resetValidation();
        $this->dialog = $dialog;
        $this->dialogStep = 'form';
        $this->dialogError = null;
        $this->withdrawalAmount = '';
        $this->capitalizationAmount = '';
    }

    public function closeDialog(): void
    {
        $this->dialog = null;
        $this->dialogStep = 'form';
        $this->dialogError = null;
        $this->resetValidation();
    }

    public function backToDialogForm(): void
    {
        $this->dialogStep = 'form';
        $this->dialogError = null;
        $this->resetValidation();
    }

    public function applyPeriod(): void
    {
        if ($this->periodMode !== 'custom') {
            return;
        }

        $this->periodDates($this->account());
    }

    public function resetPeriod(): void
    {
        $this->periodMode = 'month';
        $this->periodFrom = null;
        $this->periodTo = null;
        $this->resetValidation(['periodFrom', 'periodTo']);
    }

    public function useAllDividends(AvailableBalanceService $balances): void
    {
        $this->capitalizationAmount = $balances->availableDividendBalance($this->account());
        $this->dialogStep = 'form';
    }

    public function useAllWithdrawal(AvailableBalanceService $balances): void
    {
        $this->withdrawalAmount = $this->dialog === 'capital'
            ? $balances->availableCapitalForWithdrawal($this->account(), Carbon::today())
            : $balances->availableDividendBalance($this->account());
        $this->dialogStep = 'form';
    }

    public function continueOperation(AvailableBalanceService $balances, AccrualCalculator $decimal, FeeCalculatorService $fees): void
    {
        $property = $this->dialog === 'capitalization' ? 'capitalizationAmount' : 'withdrawalAmount';
        $this->validateAmount($property);
        $amount = $this->{$property};
        $available = $this->dialog === 'capital'
            ? $balances->availableCapitalForWithdrawal($this->account(), Carbon::today())
            : $balances->availableDividendBalance($this->account());

        if ($decimal->compare($amount, '0') <= 0) {
            $this->addError($property, 'Сумма должна быть больше нуля.');
            return;
        }

        if ($decimal->compare($amount, $available) > 0) {
            $this->addError($property, $this->dialog === 'capital' ? 'Сумма превышает доступный капитал.' : 'Недостаточно доступных дивидендов.');
            return;
        }

        if ($this->dialog !== 'capitalization' && $this->withdrawalDetail() === null) {
            $this->addError('withdrawalDetail', 'Администратор ещё не назначил реквизиты для вывода средств.');
            return;
        }

        $type = match ($this->dialog) {
            'capitalization' => 'capitalization', 'capital' => 'capital_withdrawal', default => 'dividend_withdrawal',
        };
        $preview = $fees->calculate($type, $this->investor(), $amount, $this->account()->currency, Carbon::today());

        if ($decimal->compare($preview['net_amount'], '0') <= 0) {
            $this->addError($property, 'Итоговая сумма должна быть больше нуля.');
            return;
        }

        $this->dialogError = null;
        $this->dialogStep = 'confirm';
    }

    public function capitalize(DividendCapitalizationService $service): void
    {
        $this->validateAmount('capitalizationAmount');

        try {
            $service->capitalize($this->account(), $this->capitalizationAmount, auth()->user());
        } catch (DomainException $exception) {
            $this->dialogError = $this->friendlyDomainError($exception, 'capitalization');
            $this->dialogStep = 'form';
            return;
        }

        $this->dialog = null;
        $this->capitalizationAmount = '';
        session()->flash('success', 'Дивиденды успешно переведены в капитал.');
    }

    public function requestDividendWithdrawal(WithdrawalRequestService $service): void
    {
        $this->createWithdrawal($service, 'dividend');
    }

    public function requestCapitalWithdrawal(WithdrawalRequestService $service): void
    {
        $this->createWithdrawal($service, 'capital');
    }

    public function render(AvailableBalanceService $balances, AccrualCalculator $decimal, FeeCalculatorService $fees)
    {
        $account = $this->account();
        $investor = $account->investor()->with('user')->firstOrFail();
        [$from, $to] = $this->periodDates($account);
        $today = Carbon::today();
        $capital = (string) ($account->investmentLots()->sum('remaining_amount') ?: '0');
        $availableCapital = $balances->availableCapitalForWithdrawal($account, $today);
        $availableDividends = $balances->availableDividendBalance($account);
        $reservedDividends = (string) ($account->withdrawalRequests()->where('type', 'dividend')
            ->whereIn('status', \App\Models\WithdrawalRequest::ACTIVE_RESERVATION_STATUSES)->sum('reserved_amount') ?: '0');
        $reservedCapital = (string) ($account->withdrawalRequests()->where('type', 'capital')
            ->whereIn('status', \App\Models\WithdrawalRequest::ACTIVE_RESERVATION_STATUSES)->sum('reserved_amount') ?: '0');
        $series = $this->accrualSeries($account, $from, $to);
        $chartMaximum = '0.00000000';

        foreach ($series as $point) {
            if ($decimal->compare($point['amount'], $chartMaximum) > 0) {
                $chartMaximum = $point['amount'];
            }
        }
        $term = $this->accountTerm($account, $today);
        $currentRate = $term?->monthly_rate
            ?? $account->investmentLots()->where('status', 'active')->latest('received_at')->value('monthly_rate')
            ?? '0.0000';

        $capitalizationPreview = $this->preview($fees, 'capitalization', $this->capitalizationAmount);
        $withdrawalPreview = $this->preview($fees, $this->dialog === 'capital' ? 'capital_withdrawal' : 'dividend_withdrawal', $this->withdrawalAmount);
        $capitalizationRequested = $capitalizationPreview['gross_amount'] ?? '0.00000000';
        $capitalizationNet = $capitalizationPreview['net_amount'] ?? '0.00000000';
        $withdrawalRequested = $withdrawalPreview['gross_amount'] ?? '0.00000000';
        $withdrawalDetail = $this->withdrawalDetail();

        return view('livewire.investor.dashboard', [
            'account' => $account,
            'investor' => $investor,
            'summary' => [
                'capital' => $capital,
                'rate' => (string) $currentRate,
                'today' => (string) ($account->dailyAccruals()->whereDate('accrual_date', $today)->sum('final_amount') ?: '0'),
                'month' => (string) ($account->dailyAccruals()->whereBetween('accrual_date', [$today->copy()->startOfMonth(), $today])->sum('final_amount') ?: '0'),
                'total' => (string) ($account->dailyAccruals()->sum('final_amount') ?: '0'),
                'period' => $this->periodAccrual($account, $from, $to),
                'paid' => (string) ($account->dividendPayments()->sum('gross_amount') ?: '0'),
                'availableDividends' => $availableDividends,
                'lockedCapital' => $decimal->subtract($capital, $availableCapital),
                'availableCapital' => $availableCapital,
            ],
            'from' => $from, 'to' => $to, 'series' => $series,
            'chartPoints' => $this->chartPoints($series, 900, 160),
            'chartMaximum' => $chartMaximum,
            'withdrawalDetail' => $withdrawalDetail,
            'term' => $term,
            'capitalizationPreview' => $capitalizationPreview,
            'withdrawalPreview' => $withdrawalPreview,
            'operationChanges' => [
                'capitalAfter' => $decimal->add($capital, $capitalizationNet),
                'dividendsAfterCapitalization' => $this->nonNegative($decimal, $decimal->subtract($availableDividends, $capitalizationRequested)),
                'dividendsAfterWithdrawal' => $this->nonNegative($decimal, $decimal->subtract($availableDividends, $withdrawalRequested)),
                'dividendReserved' => $reservedDividends,
                'dividendReservedAfter' => $decimal->add($reservedDividends, $withdrawalRequested),
                'capitalReserved' => $reservedCapital,
                'capitalReservedAfter' => $decimal->add($reservedCapital, $withdrawalRequested),
                'availableCapitalAfter' => $this->nonNegative($decimal, $decimal->subtract($availableCapital, $withdrawalRequested)),
            ],
            'capitalizationUnlockDate' => $term && $term->lock_months > 0 ? $today->copy()->addMonthsNoOverflow($term->lock_months) : null,
            'capitalizationValid' => $this->validPreviewAmount($decimal, $this->capitalizationAmount, $availableDividends, $capitalizationPreview),
            'withdrawalValid' => $this->validPreviewAmount(
                $decimal, $this->withdrawalAmount,
                $this->dialog === 'capital' ? $availableCapital : $availableDividends,
                $withdrawalPreview,
            ) && $withdrawalDetail !== null,
            'nearestUnlock' => $account->investmentLots()->where('remaining_amount', '>', 0)->whereDate('unlock_date', '>', $today)->orderBy('unlock_date')->value('unlock_date'),
            'history' => $this->recentOperations($account),
        ])->title('Обзор — CEO Money');
    }

    private function createWithdrawal(WithdrawalRequestService $service, string $type): void
    {
        $this->validateAmount('withdrawalAmount');
        if ($this->withdrawalDetail() === null) {
            $this->dialogError = 'Администратор ещё не назначил реквизиты для вывода средств.';
            $this->dialogStep = 'form';
            return;
        }

        try {
            $type === 'capital'
                ? $service->createCapitalRequest($this->account(), $this->withdrawalAmount, actor: auth()->user())
                : $service->createDividendRequest($this->account(), $this->withdrawalAmount, actor: auth()->user());
        } catch (DomainException $exception) {
            $this->dialogError = $this->friendlyDomainError($exception, $type);
            $this->dialogStep = 'form';
            return;
        }

        $this->dialog = null;
        $this->withdrawalAmount = '';
        session()->flash('success', $type === 'capital'
            ? 'Заявка на вывод капитала создана.'
            : 'Заявка на вывод дивидендов создана.');
    }

    private function validateAmount(string $property): void
    {
        $this->validate([$property => ['required', 'regex:/^\d+(?:\.\d{1,8})?$/']], [
            $property.'.required' => 'Введите сумму.',
            $property.'.regex' => 'Введите корректную сумму с точностью до 8 знаков.',
        ]);
    }

    private function withdrawalDetail()
    {
        return app(InvestorWithdrawalDetailService::class)->latestActiveFor($this->account());
    }

    private function accountTerm($account, Carbon $date): ?InvestmentTerm
    {
        return $account->investmentTerms()->whereNull('investment_lot_id')
            ->whereDate('valid_from', '<=', $date)
            ->where(fn ($query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date))
            ->latest('valid_from')->latest('id')->first();
    }

    private function preview(FeeCalculatorService $fees, string $type, string $amount): ?array
    {
        if (! preg_match('/^\d+(?:\.\d{1,8})?$/', $amount)) return null;

        return $fees->calculate($type, $this->investor(), $amount, $this->account()->currency, Carbon::today());
    }

    private function nonNegative(AccrualCalculator $decimal, string $amount): string
    {
        return $decimal->compare($amount, '0') < 0 ? '0.00000000' : $amount;
    }

    private function validPreviewAmount(AccrualCalculator $decimal, string $amount, string $available, ?array $preview): bool
    {
        return preg_match('/^\d+(?:\.\d{1,8})?$/', $amount) === 1
            && $decimal->compare($amount, '0') > 0
            && $decimal->compare($amount, $available) <= 0
            && $preview !== null
            && $decimal->compare($preview['net_amount'], '0') > 0;
    }

    private function friendlyDomainError(DomainException $exception, string $operation): string
    {
        $message = $exception->getMessage();

        return match (true) {
            str_contains($message, 'exceeds available') || str_contains($message, 'insufficient') => $operation === 'capital'
                ? 'Сумма превышает доступный капитал.' : 'Недостаточно доступных дивидендов.',
            str_contains($message, 'withdrawal details') => 'Администратор ещё не назначил реквизиты для вывода средств.',
            str_contains($message, 'wallet') => 'Этот кошелёк больше недоступен.',
            str_contains($message, 'minimum balance') => 'После вывода должен сохраняться минимальный остаток по условиям счёта.',
            str_contains($message, 'Partial capital') => 'Частичный вывод капитала запрещён условиями счёта.',
            str_contains($message, 'term') => 'Условия инвестирования изменились. Проверьте расчёт ещё раз.',
            str_contains($message, 'active investment account') || str_contains($message, 'active investor') => 'Операция временно недоступна для этого счёта.',
            default => 'Операцию не удалось выполнить. Проверьте данные и попробуйте снова.',
        };
    }

    private function recentOperations($account)
    {
        $transactions = $account->investmentTransactions()->latest('effective_date')->latest('id')->limit(8)->get()
            ->map(fn ($item) => [
                'kind' => $item->type,
                'label' => match ($item->type) {
                    'deposit' => 'Пополнение',
                    'capitalization' => 'Капитализация',
                    'reversal' => 'Сторно',
                    default => 'Финансовая операция',
                },
                'date' => $item->effective_date,
                'status' => $item->status,
                'amount' => $item->amount,
            ]);
        $withdrawals = $account->withdrawalRequests()->latest('requested_at')->limit(8)->get()
            ->map(fn ($item) => [
                'kind' => $item->type === 'capital' ? 'capital_withdrawal' : 'dividend_withdrawal',
                'label' => $item->type === 'capital' ? 'Вывод капитала' : 'Вывод дивидендов',
                'date' => $item->requested_at,
                'status' => $item->status,
                'amount' => $item->requested_amount,
            ]);
        $payments = $account->dividendPayments()->latest('paid_at')->limit(8)->get()
            ->map(fn ($item) => [
                'kind' => 'payment', 'label' => 'Выплата', 'date' => $item->paid_at,
                'status' => 'paid', 'amount' => $item->gross_amount,
            ]);

        return $transactions->concat($withdrawals)->concat($payments)
            ->sortByDesc('date')->take(5)->values();
    }
}
