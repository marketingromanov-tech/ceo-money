<?php

namespace App\Livewire\Investor;

use App\Livewire\Investor\Concerns\HasAccrualPeriod;
use App\Livewire\Investor\Concerns\InteractsWithInvestorData;
use App\Services\AccrualCalculator;
use Carbon\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.investor')]
class Accruals extends Component
{
    use HasAccrualPeriod, InteractsWithInvestorData;

    public string $selectedMonth;

    public function mount(): void { $this->selectedMonth = now()->format('Y-m'); }
    public function previousMonth(): void { $this->selectedMonth = Carbon::createFromFormat('Y-m-d', $this->selectedMonth.'-01')->subMonth()->format('Y-m'); }
    public function nextMonth(): void
    {
        $next = Carbon::createFromFormat('Y-m-d', $this->selectedMonth.'-01')->addMonth()->startOfMonth();
        if ($next->lte(now()->startOfMonth())) $this->selectedMonth = $next->format('Y-m');
    }

    public function render(AccrualCalculator $decimal)
    {
        $account = $this->account();
        $today = Carbon::today();
        [$from, $to] = $this->periodDates($account);
        $month = Carbon::createFromFormat('Y-m-d', $this->selectedMonth.'-01')->startOfMonth();
        $series = $this->accrualSeries($account, $from, $to);
        $accruals = $account->dailyAccruals()->with('investmentLot')
            ->whereBetween('accrual_date', [$month->toDateString(), $month->copy()->endOfMonth()->toDateString()])
            ->latest('accrual_date')->latest('id')->get();
        $investmentNumbers = $account->investmentLots()
            ->orderBy('received_at')->orderBy('id')
            ->pluck('id')->values()
            ->mapWithKeys(fn ($lotId, $index) => [$lotId => $index + 1]);
        $monthTotal = '0.00000000';

        foreach ($accruals as $accrual) {
            $monthTotal = $decimal->add($monthTotal, (string) $accrual->final_amount);
        }

        $periodTotal = $this->periodAccrual($account, $from, $to);
        $accrualDays = $account->dailyAccruals()
            ->whereBetween('accrual_date', [$from->toDateString(), $to->toDateString()])
            ->distinct()->count('accrual_date');
        $averagePerDay = $accrualDays > 0
            ? $decimal->divideByInteger($periodTotal, $accrualDays)
            : '0.00000000';
        $chartMaximum = '0.00000000';

        foreach ($series as $point) {
            if ($decimal->compare($point['amount'], $chartMaximum) > 0) {
                $chartMaximum = $point['amount'];
            }
        }

        return view('livewire.investor.accruals', [
            'summary' => [
                'today' => (string) ($account->dailyAccruals()->whereDate('accrual_date', $today)->sum('final_amount') ?: '0'),
                'month' => (string) ($account->dailyAccruals()->whereBetween('accrual_date', [$today->copy()->startOfMonth(), $today])->sum('final_amount') ?: '0'),
                'total' => (string) ($account->dailyAccruals()->sum('final_amount') ?: '0'),
                'period' => $periodTotal,
            ],
            'from' => $from,
            'to' => $to,
            'series' => $series,
            'chartPoints' => $this->chartPoints($series),
            'month' => $month,
            'monthLabel' => $this->russianMonth($month),
            'accruals' => $accruals,
            'investmentNumbers' => $investmentNumbers,
            'monthSummary' => ['count' => $accruals->count(), 'total' => $monthTotal],
            'chartSummary' => ['total' => $periodTotal, 'average' => $averagePerDay, 'days' => $accrualDays],
            'chartMaximum' => $chartMaximum,
        ])->title('Начисления — CEO Money');
    }

    private function russianMonth(Carbon $month): string
    {
        $months = [
            1 => 'Январь', 2 => 'Февраль', 3 => 'Март', 4 => 'Апрель', 5 => 'Май', 6 => 'Июнь',
            7 => 'Июль', 8 => 'Август', 9 => 'Сентябрь', 10 => 'Октябрь', 11 => 'Ноябрь', 12 => 'Декабрь',
        ];

        return $months[$month->month].' '.$month->year;
    }
}
