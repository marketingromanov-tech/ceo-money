<?php

namespace App\Livewire\Admin\Fees;

use App\Models\FeeRule;
use App\Models\Investor;
use App\Services\AccrualCalculator;
use App\Services\AuditLogService;
use App\Services\FeeCalculatorService;
use App\Services\FeeAnalyticsService;
use Carbon\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.admin')]
class Index extends Component
{
    use WithPagination;

    public string $operationFilter=''; public string $scopeFilter=''; public string $statusFilter=''; public string $investorFilter=''; public string $currencyFilter=''; public string $search='';
    public bool $showModal=false; public ?int $editingId=null;
    public string $operationType='deposit'; public string $scope='global'; public string $investorId=''; public string $feeType='percent'; public string $economicType='platform_fee'; public string $fixedValue='0'; public string $percentValue='1'; public string $minimumFee=''; public string $maximumFee=''; public string $payer='investor'; public string $currency='USDT'; public string $validFrom=''; public string $validTo=''; public bool $isActive=true; public string $previewAmount='1000';
    public string $analyticsPeriod='month'; public string $analyticsFrom=''; public string $analyticsTo='';
    public string $resolveOperation='deposit'; public string $resolveInvestorId=''; public string $resolveCurrency='USDT'; public string $resolveDate=''; public string $resolveAmount='1000'; public ?array $resolution=null;

    public function mount(): void { $this->validFrom=now()->toDateString(); $this->resolveDate=now()->toDateString(); }
    public function updated($property): void { if (str_ends_with($property, 'Filter') || $property==='search') $this->resetPage(); }

    public function openCreate(): void { $this->resetEditor(); $this->showModal=true; }
    public function edit(int $id): void
    {
        $rule=FeeRule::findOrFail($id); $this->editingId=$rule->id; $this->operationType=$rule->operation_type; $this->scope=$rule->scope; $this->investorId=(string)($rule->investor_id??''); $this->feeType=$rule->fee_type; $this->economicType=(string)($rule->economic_type??''); $this->fixedValue=(string)$rule->fixed_value; $this->percentValue=(string)$rule->percent_value; $this->minimumFee=(string)($rule->minimum_fee??''); $this->maximumFee=(string)($rule->maximum_fee??''); $this->payer=$rule->payer; $this->currency=(string)($rule->currency??''); $this->validFrom=$rule->valid_from->toDateString(); $this->validTo=$rule->valid_to?->toDateString()??''; $this->isActive=$rule->is_active; $this->showModal=true;
    }
    public function closeModal(): void { $this->showModal=false; $this->resetValidation(); }

    public function save(AuditLogService $audit, AccrualCalculator $decimal): void
    {
        $data=$this->validateEditor($decimal); $rule=$this->editingId ? FeeRule::findOrFail($this->editingId) : new FeeRule(); $old=$rule->exists?$rule->getAttributes():null;
        $rule->fill($data); if(!$rule->exists)$rule->created_by=auth()->id(); $rule->save();
        $audit->log($old?'fee_rule_updated':'fee_rule_created',$rule,auth()->user(),$old,$rule->getAttributes());
        $this->showModal=false; session()->flash('success',$old?'Правило обновлено.':'Правило добавлено.');
    }

    public function toggle(int $id, AuditLogService $audit): void
    {
        $rule=FeeRule::findOrFail($id); $old=['is_active'=>$rule->is_active]; $rule->update(['is_active'=>!$rule->is_active]);
        $audit->log($rule->is_active?'fee_rule_activated':'fee_rule_deactivated',$rule,auth()->user(),$old,['is_active'=>$rule->is_active]);
    }

    public function resolve(FeeCalculatorService $fees): void
    {
        $this->validate(['resolveOperation'=>['required',Rule::in(FeeRule::OPERATION_TYPES)],'resolveInvestorId'=>['required','exists:investors,id'],'resolveCurrency'=>['required','string'],'resolveDate'=>['required','date'],'resolveAmount'=>['required','regex:/^\d+(\.\d{1,8})?$/']]);
        $investor=Investor::findOrFail($this->resolveInvestorId); $result=$fees->calculate($this->resolveOperation,$investor,$this->resolveAmount,$this->resolveCurrency,Carbon::parse($this->resolveDate)); $rule=$result['fee_rule_id']?FeeRule::find($result['fee_rule_id']):null;
        $this->resolution=['rule_id'=>$rule?->id,'scope'=>$rule?->scope,'fee_type'=>$rule?->fee_type,'payer'=>$result['payer'],'fee_amount'=>$result['fee_amount'],'net_amount'=>$result['net_amount']];
    }

    public function render(FeeCalculatorService $fees, FeeAnalyticsService $analytics)
    {
        $today=Carbon::today();
        $rules=FeeRule::with('investor.user')->when($this->operationFilter,fn($q)=>$q->where('operation_type',$this->operationFilter))->when($this->scopeFilter,fn($q)=>$q->where('scope',$this->scopeFilter))->when($this->investorFilter,fn($q)=>$q->where('investor_id',$this->investorFilter))->when($this->currencyFilter,fn($q)=>$q->where('currency',$this->currencyFilter))->when($this->search,function($q){$s='%'.trim($this->search).'%';$q->whereHas('investor',fn($i)=>$i->where('code','like',$s)->orWhereHas('user',fn($u)=>$u->where('name','like',$s)->orWhere('email','like',$s)));})->when($this->statusFilter==='active',fn($q)=>$q->where('is_active',true)->whereDate('valid_from','<=',$today)->where(fn($x)=>$x->whereNull('valid_to')->orWhereDate('valid_to','>=',$today)))->when($this->statusFilter==='inactive',fn($q)=>$q->where('is_active',false))->when($this->statusFilter==='scheduled',fn($q)=>$q->where('is_active',true)->whereDate('valid_from','>',$today))->when($this->statusFilter==='expired',fn($q)=>$q->where('is_active',true)->whereDate('valid_to','<',$today))->orderBy('operation_type')->orderByRaw('CASE WHEN investor_id IS NULL THEN 0 ELSE 1 END')->orderByDesc('valid_from')->orderByDesc('id')->paginate(20);
        $activeBase=FeeRule::where('is_active',true)->whereDate('valid_from','<=',$today)->where(fn($q)=>$q->whereNull('valid_to')->orWhereDate('valid_to','>=',$today));
        $kpis=['active'=>(clone $activeBase)->count(),'global'=>(clone $activeBase)->where('scope','global')->count(),'personal'=>(clone $activeBase)->where('scope','investor')->count(),'operations'=>(clone $activeBase)->distinct()->count('operation_type')];
        $investors=Investor::with('user')->orderBy('code')->get(); $currencies=FeeRule::whereNotNull('currency')->distinct()->orderBy('currency')->pluck('currency');
        $preview=null; if(preg_match('/^\d+(\.\d{1,8})?$/',$this->previewAmount)){try{$preview=$fees->calculateForRule($this->formRule(),$this->previewAmount);}catch(\Throwable){}}
        $overlap=$this->overlapExists();
        $actualFees = match ($this->analyticsPeriod) {
            'today' => $analytics->today(),
            'all' => $analytics->allTime(),
            'custom' => ($this->analyticsFrom && $this->analyticsTo) ? $analytics->forPeriod(Carbon::parse($this->analyticsFrom), Carbon::parse($this->analyticsTo)) : $analytics->currentMonth(),
            default => $analytics->currentMonth(),
        };
        $from = match ($this->analyticsPeriod) {
            'today' => $today->copy(),
            'all' => Carbon::create(1970, 1, 1),
            'custom' => $this->analyticsFrom ? Carbon::parse($this->analyticsFrom) : $today->copy()->startOfMonth(),
            default => $today->copy()->startOfMonth(),
        };
        $to = $this->analyticsPeriod === 'custom' && $this->analyticsTo ? Carbon::parse($this->analyticsTo) : ($this->analyticsPeriod === 'today' ? $today->copy() : $today->copy()->endOfMonth());
        $actualBreakdown = $analytics->breakdownByOperation($from, $to);
        $latestFees = $analytics->latestCompleted();
        return view('livewire.admin.fees.index',compact('rules','kpis','investors','currencies','preview','overlap','actualFees','actualBreakdown','latestFees'))->title('Комиссии — CEO Money');
    }

    private function validateEditor(AccrualCalculator $decimal): array
    {
        $this->validate(['operationType'=>['required',Rule::in(FeeRule::OPERATION_TYPES)],'scope'=>['required',Rule::in(FeeRule::SCOPES)],'investorId'=>['nullable','required_if:scope,investor','exists:investors,id'],'feeType'=>['required',Rule::in(FeeRule::FEE_TYPES)],'economicType'=>['required',Rule::in(FeeRule::ECONOMIC_TYPES)],'fixedValue'=>['nullable','regex:/^\d+(\.\d{1,8})?$/'],'percentValue'=>['nullable','regex:/^\d+(\.\d{1,4})?$/'],'minimumFee'=>['nullable','regex:/^\d+(\.\d{1,8})?$/'],'maximumFee'=>['nullable','regex:/^\d+(\.\d{1,8})?$/'],'payer'=>['required',Rule::in(FeeRule::PAYERS)],'currency'=>['nullable','string','max:20'],'validFrom'=>['required','date'],'validTo'=>['nullable','date','after_or_equal:validFrom']]);
        $fixed=$this->fixedValue?:'0'; $percent=$this->percentValue?:'0'; if($this->feeType==='fixed'&&$decimal->compare($fixed,'0')<=0)$this->addError('fixedValue','Фиксированная комиссия должна быть больше нуля.'); if($this->feeType==='percent'&&$decimal->compare($percent,'0')<=0)$this->addError('percentValue','Процент должен быть больше нуля.'); if($this->feeType==='mixed'&&($decimal->compare($fixed,'0')<=0||$decimal->compare($percent,'0')<=0))$this->addError('fixedValue','Для смешанной комиссии оба компонента должны быть больше нуля.'); if($this->minimumFee!==''&&$this->maximumFee!==''&&$decimal->compare($this->minimumFee,$this->maximumFee)>0)$this->addError('maximumFee','Максимум должен быть не меньше минимума.'); if($this->getErrorBag()->isNotEmpty())throw \Illuminate\Validation\ValidationException::withMessages($this->getErrorBag()->toArray());
        return ['operation_type'=>$this->operationType,'scope'=>$this->scope,'investor_id'=>$this->scope==='investor'?(int)$this->investorId:null,'currency'=>$this->currency?:null,'fee_type'=>$this->feeType,'economic_type'=>$this->economicType,'fixed_value'=>$fixed,'percent_value'=>$percent,'minimum_fee'=>$this->minimumFee?:null,'maximum_fee'=>$this->maximumFee?:null,'payer'=>$this->payer,'valid_from'=>$this->validFrom,'valid_to'=>$this->validTo?:null,'is_active'=>$this->isActive];
    }
    private function formRule(): FeeRule { return new FeeRule(['fee_type'=>$this->feeType,'economic_type'=>$this->economicType?:null,'fixed_value'=>$this->fixedValue?:'0','percent_value'=>$this->percentValue?:'0','minimum_fee'=>$this->minimumFee?:null,'maximum_fee'=>$this->maximumFee?:null,'payer'=>$this->payer]); }
    private function overlapExists(): bool { if(!$this->showModal)return false; return FeeRule::where('operation_type',$this->operationType)->where('scope',$this->scope)->when($this->scope==='investor',fn($q)=>$q->where('investor_id',$this->investorId),fn($q)=>$q->whereNull('investor_id'))->when($this->currency,fn($q)=>$q->where('currency',$this->currency),fn($q)=>$q->whereNull('currency'))->where('is_active',true)->when($this->editingId,fn($q)=>$q->whereKeyNot($this->editingId))->where(fn($q)=>$q->whereNull('valid_to')->orWhereDate('valid_to','>=',$this->validFrom))->when($this->validTo,fn($q)=>$q->whereDate('valid_from','<=',$this->validTo))->exists(); }
    private function resetEditor(): void { $this->editingId=null;$this->operationType='deposit';$this->scope='global';$this->investorId='';$this->feeType='percent';$this->economicType='platform_fee';$this->fixedValue='0';$this->percentValue='1';$this->minimumFee='';$this->maximumFee='';$this->payer='investor';$this->currency='USDT';$this->validFrom=now()->toDateString();$this->validTo='';$this->isActive=true;$this->previewAmount='1000';$this->resetValidation(); }
}
