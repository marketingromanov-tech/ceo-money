<?php

namespace App\Services;

use App\Models\{CapitalWithdrawalAllocation,DailyAccrual,DepositRequest,DividendCapitalization,InvestmentAccount,InvestmentLot,InvestmentProgram,Investor,InvestorWallet,WithdrawalRequest};
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CeoAnalyticsService
{
    public function __construct(private readonly AccrualCalculator $decimal,private readonly FeeAnalyticsService $fees,private readonly AvailableBalanceService $balances){}

    public function portfolio(?Carbon $from=null,?Carbon $to=null):array
    {
        $capital = $this->currencyTotals(
            InvestmentLot::query()->where('remaining_amount', '>', 0),
            'remaining_amount',
        );
        $counts = InvestmentLot::query()
            ->where('remaining_amount', '>', 0)
            ->selectRaw('currency, count(*) as aggregate')
            ->groupBy('currency')
            ->pluck('aggregate', 'currency')
            ->map(fn ($value) => (int) $value)
            ->all();
        $investorCounts = InvestmentAccount::query()
            ->join('investors', 'investors.id', '=', 'investment_accounts.investor_id')
            ->join('investment_lots', 'investment_lots.investment_account_id', '=', 'investment_accounts.id')
            ->where('investors.status', 'active')
            ->where('investment_lots.remaining_amount', '>', 0)
            ->selectRaw('investment_accounts.currency, count(distinct investment_accounts.investor_id) as aggregate')
            ->groupBy('investment_accounts.currency')
            ->pluck('aggregate', 'currency')
            ->map(fn ($value) => (int) $value)
            ->all();
        $average = [];
        foreach ($capital as $currency => $amount) {
            $average[$currency] = $this->decimal->divideByInteger($amount, max(1, $investorCounts[$currency] ?? 0));
        }
        $new=InvestmentLot::query()->when($from&&$to,fn($q)=>$q->whereBetween('received_at',[$from,$to]))->selectRaw('currency, count(*) as aggregate')->groupBy('currency')->pluck('aggregate','currency')->map(fn($v)=>(int)$v)->all();
        return['capital'=>$capital,'active_investors'=>Investor::where('status','active')->count(),'investments'=>array_sum($counts),'investments_by_currency'=>$counts,'average_investment'=>$average,'new_investments'=>$new];
    }

    public function cashFlow(Carbon $from,Carbon $to):array
    {
        $deposits=$this->currencyTotals(DepositRequest::where('status','confirmed')->whereBetween('confirmed_at',[$from,$to]),'net_investment_amount');
        $withdrawals=$this->currencyTotals(WithdrawalRequest::where('status','paid')->whereBetween('paid_at',[$from,$to]),'requested_amount');
        $net=[];foreach(array_unique([...array_keys($deposits),...array_keys($withdrawals)]) as $currency)$net[$currency]=$this->decimal->subtract($deposits[$currency]??'0',$withdrawals[$currency]??'0');
        return compact('deposits','withdrawals','net');
    }

    public function platformRevenue(Carbon $from,Carbon $to):array
    {
        $summary=$this->fees->forPeriod($from,$to);
        return['platform_revenue'=>$summary['platform_revenue'],'provider_costs'=>$summary['provider_cost'],'unclassified_fees'=>$summary['unclassified'],'by_operation'=>$this->fees->breakdownByOperation($from,$to),'by_currency'=>$this->fees->breakdownByCurrency($from,$to)];
    }

    public function investorRanking(Carbon $from,Carbon $to,int $limit=5):array
    {
        $capital=DB::table('investment_lots')->join('investment_accounts','investment_accounts.id','=','investment_lots.investment_account_id')->where('investment_lots.remaining_amount','>',0)->selectRaw('investment_accounts.investor_id, investment_lots.currency, sum(investment_lots.remaining_amount) as amount')->groupBy('investment_accounts.investor_id','investment_lots.currency')->get();
        $accrued=DB::table('daily_accruals')->join('investment_accounts','investment_accounts.id','=','daily_accruals.investment_account_id')->whereBetween('daily_accruals.accrual_date',[$from->toDateString(),$to->toDateString()])->selectRaw('investment_accounts.investor_id, investment_accounts.currency, sum(daily_accruals.final_amount) as amount')->groupBy('investment_accounts.investor_id','investment_accounts.currency')->get()->keyBy(fn($r)=>$r->investor_id.'|'.$r->currency);
        $withdrawn=DB::table('withdrawal_requests')->where('status','paid')->whereBetween('paid_at',[$from,$to])->selectRaw('investor_id, currency, sum(requested_amount) as amount')->groupBy('investor_id','currency')->get()->keyBy(fn($r)=>$r->investor_id.'|'.$r->currency);
        $investors=Investor::with(['user','investmentAccounts'])->whereIn('id',$capital->pluck('investor_id'))->get()->keyBy('id');$rows=[];
        foreach($capital as $row){$investor=$investors->get($row->investor_id);if(!$investor)continue;$account=$investor->investmentAccounts->firstWhere('currency',$row->currency);$available='0.00000000';if($account)$available=$this->decimal->add($this->balances->availableDividendBalance($account),$this->balances->availableCapitalForWithdrawal($account,Carbon::today()));$key=$row->investor_id.'|'.$row->currency;$rows[]=['investor'=>$investor,'currency'=>$row->currency,'invested_capital'=>(string)$row->amount,'accrued'=>(string)($accrued->get($key)?->amount??'0'),'withdrawn'=>(string)($withdrawn->get($key)?->amount??'0'),'available_balance'=>$available];}
        usort($rows,fn($a,$b)=>-$this->decimal->compare($a['invested_capital'],$b['invested_capital']));return array_slice($rows,0,$limit);
    }

    public function riskIndicators():array
    {
        $pending=DepositRequest::whereIn('status',['pending','payment_submitted','submitted'])->count()+WithdrawalRequest::whereIn('status',['new','review','approved'])->count();$wallets=InvestorWallet::where('status','pending')->count();$inactive=Investor::where('status','!=','active')->count()+Investor::where('status','active')->whereDoesntHave('investmentAccounts.investmentTransactions',fn($q)=>$q->where('created_at','>=',now()->subDays(30)))->count();
        $large=[];foreach(WithdrawalRequest::whereIn('status',['new','review','approved'])->select('id','investor_id','requested_amount','currency')->with('investor.user')->limit(50)->get()->groupBy('currency') as $currency=>$items){$average=$this->decimal->divideByInteger($this->sum($items->pluck('requested_amount')),$items->count());foreach($items as $item)if($this->decimal->compare((string)$item->requested_amount,$average)>0)$large[]=['id'=>$item->id,'investor'=>$item->investor->user->name,'amount'=>(string)$item->requested_amount,'currency'=>$currency];}
        return['pending_operations'=>$pending,'new_wallets'=>$wallets,'inactive_investors'=>$inactive,'large_withdrawals'=>array_slice($large,0,3)];
    }

    public function investmentPrograms(): array
    {
        $rows = DB::table('investment_programs')
            ->leftJoin('investment_lots', 'investment_lots.investment_program_id', '=', 'investment_programs.id')
            ->leftJoin('investment_accounts', 'investment_accounts.id', '=', 'investment_lots.investment_account_id')
            ->selectRaw('investment_programs.id, investment_programs.currency, coalesce(sum(investment_lots.remaining_amount), 0) as capital, count(investment_lots.id) as lots_count, count(distinct investment_accounts.investor_id) as investors_count')
            ->groupBy('investment_programs.id', 'investment_programs.currency')
            ->get();
        $programs = InvestmentProgram::with(['versions' => fn ($query) => $query->latest('valid_from')->latest('id')])->whereIn('id', $rows->pluck('id'))->get()->keyBy('id');

        return $rows->map(fn ($row) => [
            'program' => $programs->get($row->id),
            'currency' => $row->currency,
            'capital' => (string) $row->capital,
            'investors_count' => (int) $row->investors_count,
            'lots_count' => (int) $row->lots_count,
            'average_lot' => $this->decimal->divideByInteger((string) $row->capital, max(1, (int) $row->lots_count)),
        ])->all();
    }

    public function trends(Carbon $from,Carbon $to):array
    {
        $monthly=$from->diffInDays($to)>93;$buckets=collect();$cursor=$from->copy()->startOfDay();while($cursor->lte($to)){$key=$monthly?$cursor->format('Y-m'):$cursor->toDateString();if(!$buckets->has($key))$buckets->put($key,['label'=>$monthly?$cursor->format('m.Y'):$cursor->format('d.m'),'capital'=>[],'accruals'=>[],'revenue'=>[]]);$cursor->addDay();}
        $capital=[];foreach(InvestmentLot::whereBetween('received_at',[$from,$to])->get(['received_at','original_amount','currency']) as $row)$this->bucketAdd($capital,$this->key($row->received_at,$monthly),$row->currency,(string)$row->original_amount);foreach(CapitalWithdrawalAllocation::whereBetween('created_at',[$from,$to])->with('withdrawalRequest:id,currency')->get() as $row)$this->bucketAdd($capital,$this->key($row->created_at,$monthly),$row->withdrawalRequest->currency,$this->decimal->subtract('0',(string)$row->amount));
        $accruals=[];foreach(DailyAccrual::whereBetween('accrual_date',[$from->toDateString(),$to->toDateString()])->with('investmentAccount:id,currency')->get() as $row)$this->bucketAdd($accruals,$this->key($row->accrual_date,$monthly),$row->investmentAccount->currency,(string)$row->final_amount);
        $revenue=[];foreach($this->completedFeeRows($from,$to) as $row)if($row['economic']==='platform_fee')$this->bucketAdd($revenue,$this->key($row['date'],$monthly),$row['currency'],$row['amount']);$running=[];
        return $buckets->map(function($bucket,$key)use($capital,$accruals,$revenue,&$running){foreach($capital[$key]??[] as $currency=>$amount)$running[$currency]=$this->decimal->add($running[$currency]??'0',$amount);$bucket['capital']=$running;$bucket['accruals']=$accruals[$key]??[];$bucket['revenue']=$revenue[$key]??[];return$bucket;})->values()->all();
    }

    private function completedFeeRows(Carbon $from,Carbon $to):array{$rows=[];foreach([[DepositRequest::where('status','confirmed')->whereBetween('confirmed_at',[$from,$to])->get(),'confirmed_at'],[WithdrawalRequest::where('status','paid')->whereBetween('paid_at',[$from,$to])->get(),'paid_at'],[DividendCapitalization::where('status','completed')->whereBetween('capitalized_at',[$from,$to])->get(),'capitalized_at']] as [$models,$date])foreach($models as $m)$rows[]=['date'=>$m->{$date},'currency'=>$m->currency,'amount'=>(string)$m->fee_amount,'economic'=>$m->fee_economic_type_snapshot];return$rows;}
    private function currencyTotals($query,string $column):array{return$query->selectRaw("currency, sum({$column}) as aggregate")->groupBy('currency')->pluck('aggregate','currency')->map(fn($v)=>(string)$v)->all();}
    private function sum(iterable $values):string{$sum='0.00000000';foreach($values as $value)$sum=$this->decimal->add($sum,(string)$value);return$sum;}
    private function key($date,bool $monthly):string{return Carbon::parse($date)->format($monthly?'Y-m':'Y-m-d');}
    private function bucketAdd(array &$target,string $key,string $currency,string $amount):void{$target[$key][$currency]=$this->decimal->add($target[$key][$currency]??'0',$amount);}
}
