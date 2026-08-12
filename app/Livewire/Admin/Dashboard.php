<?php

namespace App\Livewire\Admin;

use App\Models\CapitalWithdrawalAllocation;
use App\Models\DailyAccrual;
use App\Models\DepositRequest;
use App\Models\DividendPayment;
use App\Models\InvestmentAccount;
use App\Models\InvestmentLot;
use App\Models\Investor;
use App\Models\InvestorWallet;
use App\Models\WithdrawalRequest;
use App\Services\AccrualCalculator;
use App\Services\AvailableBalanceService;
use App\Services\CeoAnalyticsService;
use App\Services\FeeAnalyticsService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.admin')]
class Dashboard extends Component
{
    public string $analyticsPeriod='month';
    public function setAnalyticsPeriod(string $period):void{if(in_array($period,['today','7','month','year'],true))$this->analyticsPeriod=$period;}

    public function render(AvailableBalanceService $balances, AccrualCalculator $decimal, FeeAnalyticsService $feeAnalytics,CeoAnalyticsService $analytics)
    {
        $today = Carbon::today();
        $accounts = InvestmentAccount::query()->get();
        $availableDividends = '0.00000000';

        foreach ($accounts as $account) {
            $availableDividends = $decimal->add($availableDividends, $balances->availableDividendBalance($account));
        }

        $capital = (string) (InvestmentLot::sum('remaining_amount') ?: '0');
        $todayAccrual = (string) (DailyAccrual::whereDate('accrual_date', $today)->sum('final_amount') ?: '0');
        $monthAccrual = (string) (DailyAccrual::whereBetween('accrual_date', [$today->copy()->startOfMonth(), $today->copy()->endOfMonth()])->sum('final_amount') ?: '0');
        $totalAccrual = (string) (DailyAccrual::sum('final_amount') ?: '0');
        $paidDividends = (string) (DividendPayment::sum('gross_amount') ?: '0');
        $investors = Investor::count();
        $activeInvestors = Investor::where('status', 'active')->count();
        $withdrawalCount = WithdrawalRequest::whereIn('status', ['new', 'review', 'approved'])->count();
        $depositCount = DepositRequest::whereIn('status', ['pending', 'payment_submitted', 'submitted'])->count();
        $feeSummary = [
            'today' => $feeAnalytics->today(),
            'month' => $feeAnalytics->currentMonth(),
            'all' => $feeAnalytics->allTime(),
        ];
        $todayDeposited=$this->sumStrings(DepositRequest::where('status','confirmed')->whereDate('confirmed_at',$today)->pluck('net_investment_amount'),$decimal);
        $todayWithdrawn=$this->sumStrings(WithdrawalRequest::where('status','paid')->whereDate('paid_at',$today)->pluck('requested_amount'),$decimal);
        $monthDeposited=$this->sumStrings(DepositRequest::where('status','confirmed')->whereBetween('confirmed_at',[$today->copy()->startOfMonth(),$today->copy()->endOfMonth()])->pluck('net_investment_amount'),$decimal);
        $monthWithdrawn=$this->sumStrings(WithdrawalRequest::where('status','paid')->whereBetween('paid_at',[$today->copy()->startOfMonth(),$today->copy()->endOfMonth()])->pluck('requested_amount'),$decimal);
        $financialOperations=['todayDeposited'=>$todayDeposited,'todayWithdrawn'=>$todayWithdrawn,'todayFees'=>$feeSummary['today']['platform_revenue']['USDT']??'0.00000000','waiting'=>$depositCount+$withdrawalCount,'monthDeposited'=>$monthDeposited,'monthWithdrawn'=>$monthWithdrawn,'monthRevenue'=>$feeSummary['month']['platform_revenue']['USDT']??'0.00000000'];
        $riskIndicators=$this->riskIndicators($decimal,$today);
        [$analyticsFrom,$analyticsTo]=$this->analyticsRange($today);$ceoPortfolio=$analytics->portfolio($analyticsFrom,$analyticsTo);$ceoCashFlow=$analytics->cashFlow($analyticsFrom,$analyticsTo);$ceoRevenue=$analytics->platformRevenue($analyticsFrom,$analyticsTo);$ceoRanking=$analytics->investorRanking($analyticsFrom,$analyticsTo);$ceoRisks=$analytics->riskIndicators();$ceoPrograms=$analytics->investmentPrograms();$ceoTrends=$analytics->trends($analyticsFrom,$analyticsTo);$trendCapital=collect($ceoTrends)->pluck('capital')->map(fn($values)=>$values['USDT']??'0');$trendAccruals=collect($ceoTrends)->pluck('accruals')->map(fn($values)=>$values['USDT']??'0');$trendRevenue=collect($ceoTrends)->pluck('revenue')->map(fn($values)=>$values['USDT']??'0');

        $lastThirtyDays = collect(range(29, 0))->map(fn ($offset) => $today->copy()->subDays($offset));
        $capitalSeries = $this->capitalSeries($lastThirtyDays, $decimal);
        $accrualSeries = $this->dailySeries($lastThirtyDays, DailyAccrual::class, 'accrual_date', 'final_amount');
        $dividendSeries = $this->dailySeries($lastThirtyDays, DividendPayment::class, 'paid_at', 'gross_amount');
        $monthDays = collect(range(0, $today->day - 1))->map(fn ($offset) => $today->copy()->startOfMonth()->addDays($offset));
        $monthCapital = $this->capitalSeries($monthDays, $decimal);
        $monthAccrualDaily = $this->dailySeries($monthDays, DailyAccrual::class, 'accrual_date', 'final_amount');
        $monthAccrualCumulative = $this->cumulative($monthAccrualDaily, $decimal);

        $attention = DepositRequest::with('investor.user')->whereIn('status', ['pending', 'payment_submitted', 'submitted'])
            ->latest('requested_at')->limit(6)->get()->map(fn ($item) => [
                'kind' => 'Пополнение', 'investor' => $item->investor->user->name,
                'amount' => $item->requested_amount, 'status' => $item->status,
                'date' => $item->requested_at, 'route' => route('admin.deposits.index'),
            ])->concat(
                WithdrawalRequest::with('investor.user')->whereIn('status', ['new', 'review', 'approved'])
                    ->latest('requested_at')->limit(6)->get()->map(fn ($item) => [
                        'kind' => $item->type === 'capital' ? 'Вывод капитала' : 'Дивиденды',
                        'investor' => $item->investor->user->name, 'amount' => $item->requested_amount,
                        'status' => $item->status, 'date' => $item->requested_at,
                        'route' => route('admin.withdrawals.index'),
                    ])
            )->sortByDesc('date')->take(6)->values();

        return view('livewire.admin.dashboard', [
            'summary' => compact(
                'capital', 'todayAccrual', 'monthAccrual', 'totalAccrual', 'availableDividends',
                'paidDividends', 'investors', 'activeInvestors', 'withdrawalCount', 'depositCount',
            ),
            'kpis' => [
                ['Общий капитал', $capital, $this->chartPoints($capitalSeries, 160, 38)],
                ['Начислено сегодня', $todayAccrual, $this->chartPoints($accrualSeries->take(-7), 160, 38)],
                ['Начислено за месяц', $monthAccrual, $this->chartPoints($monthAccrualCumulative->take(-7), 160, 38)],
                ['Начислено всего', $totalAccrual, $this->chartPoints($this->cumulative($accrualSeries, $decimal)->take(-7), 160, 38)],
                ['Доступно к выплате', $availableDividends, $this->chartPoints($capitalSeries->take(-7), 160, 38)],
            ],
            'monthCapitalPoints' => $this->chartPoints($monthCapital),
            'monthAccrualPoints' => $this->chartPoints($monthAccrualCumulative),
            'capitalPoints' => $this->chartPoints($capitalSeries, 760, 210),
            'accrualPoints' => $this->chartPoints($accrualSeries, 760, 210),
            'dividendPoints' => $this->chartPoints($dividendSeries, 760, 210),
            'chartLabels' => $lastThirtyDays->filter(fn ($date, $index) => in_array($index, [0, 7, 14, 21, 29], true))->values(),
            'attention' => $attention,
            'feeSummary' => $feeSummary,
            'financialOperations'=>$financialOperations,'riskIndicators'=>$riskIndicators,
            'ceoPortfolio'=>$ceoPortfolio,'ceoCashFlow'=>$ceoCashFlow,'ceoRevenue'=>$ceoRevenue,'ceoRanking'=>$ceoRanking,'ceoRisks'=>$ceoRisks,'ceoPrograms'=>$ceoPrograms,'ceoTrendLabels'=>collect($ceoTrends)->pluck('label'),'ceoTrendPoints'=>['capital'=>$this->chartPoints($trendCapital,620,150),'accruals'=>$this->chartPoints($trendAccruals,620,150),'revenue'=>$this->chartPoints($trendRevenue,620,150)],
        ])->title('Dashboard — CEO Money');
    }

    private function analyticsRange(Carbon $today):array{return match($this->analyticsPeriod){'today'=>[$today->copy()->startOfDay(),$today->copy()->endOfDay()],'7'=>[$today->copy()->subDays(6)->startOfDay(),$today->copy()->endOfDay()],'year'=>[$today->copy()->startOfYear(),$today->copy()->endOfDay()],default=>[$today->copy()->startOfMonth(),$today->copy()->endOfDay()]};}

    private function riskIndicators(AccrualCalculator $decimal,Carbon $today):Collection
    {
        $active=WithdrawalRequest::with('investor.user')->whereIn('status',['new','review','approved'])->limit(50)->get();$average=$active->isEmpty()?'0.00000000':$decimal->divideByInteger($this->sumStrings($active->pluck('requested_amount'),$decimal),$active->count());$risks=collect();
        foreach($active as $request){if($decimal->compare((string)$request->requested_amount,$average)>0&&$decimal->compare($average,'0')>0)$risks->push(['label'=>'Вывод больше среднего','detail'=>$request->investor->user->name.' · '.$request->requested_amount.' '.$request->currency,'route'=>route('admin.withdrawals.index').'?withdrawal='.$request->id]);if($decimal->compare((string)$request->fee_amount,'100')>0)$risks->push(['label'=>'Большая комиссия','detail'=>$request->investor->user->name.' · '.$request->fee_amount.' '.$request->currency,'route'=>route('admin.withdrawals.index').'?withdrawal='.$request->id]);if($request->requested_at->lt($today->copy()->subDay()))$risks->push(['label'=>'Зависшая заявка','detail'=>$request->investor->user->name.' · '.$request->requested_at->diffForHumans(now(),true),'route'=>route('admin.withdrawals.index').'?withdrawal='.$request->id]);}
        foreach(InvestorWallet::with('investor.user')->where('status','pending')->limit(10)->get() as $wallet)$risks->push(['label'=>'Новый кошелёк','detail'=>$wallet->investor->user->name.' · '.$wallet->network,'route'=>route('admin.wallets.index')]);return$risks->take(6);
    }

    private function sumStrings(iterable $values,AccrualCalculator $decimal):string{$sum='0.00000000';foreach($values as $value)$sum=$decimal->add($sum,(string)($value??'0'));return$sum;}

    private function capitalSeries(Collection $days, AccrualCalculator $decimal): Collection
    {
        $lots = InvestmentLot::query()->get();
        $allocations = CapitalWithdrawalAllocation::query()->get();

        return $days->map(function (Carbon $date) use ($lots, $allocations, $decimal) {
            $total = '0.00000000';

            foreach ($lots->filter(fn ($lot) => $lot->received_at->lte($date->copy()->endOfDay())) as $lot) {
                $remaining = (string) $lot->original_amount;
                foreach ($allocations->where('investment_lot_id', $lot->id)->filter(fn ($allocation) => $allocation->created_at->lte($date->copy()->endOfDay())) as $allocation) {
                    $remaining = $decimal->subtract($remaining, (string) $allocation->amount);
                }
                $total = $decimal->add($total, $remaining);
            }

            return $total;
        });
    }

    private function dailySeries(Collection $days, string $model, string $dateColumn, string $amountColumn): Collection
    {
        return $days->map(fn (Carbon $date) => (string) ($model::query()->whereDate($dateColumn, $date)->sum($amountColumn) ?: '0'));
    }

    private function cumulative(Collection $values, AccrualCalculator $decimal): Collection
    {
        $total = '0.00000000';

        return $values->map(function ($value) use (&$total, $decimal) {
            $total = $decimal->add($total, (string) $value);

            return $total;
        });
    }

    private function chartPoints(Collection $values, int $width = 620, int $height = 180): string
    {
        $numbers = $values->values()->map(fn ($value) => $this->toChartInteger((string) $value));

        if ($numbers->isEmpty()) {
            return '';
        }

        $minimum = $numbers->min();
        $maximum = $numbers->max();
        $range = max(1, $maximum - $minimum);
        $count = max(1, $numbers->count() - 1);
        $padding = 8;

        return $numbers->map(function (int $value, int $index) use ($width, $height, $minimum, $range, $count, $padding) {
            $x = $padding + intdiv($index * ($width - ($padding * 2)), $count);
            $y = $height - $padding - intdiv(($value - $minimum) * ($height - ($padding * 2)), $range);

            return $x.','.$y;
        })->implode(' ');
    }

    private function toChartInteger(string $value): int
    {
        [$whole, $fraction] = array_pad(explode('.', ltrim($value, '+-'), 2), 2, '');
        $cents = ltrim($whole.str_pad(substr($fraction, 0, 2), 2, '0'), '0') ?: '0';

        if (strlen($cents) > 17) {
            return PHP_INT_MAX;
        }

        return (int) $cents;
    }
}
