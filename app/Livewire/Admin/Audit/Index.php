<?php

namespace App\Livewire\Admin\Audit;

use App\Models\AuditLog;
use App\Models\DepositAddress;
use App\Models\DepositRequest;
use App\Models\DepositVerificationCheck;
use App\Models\DividendCapitalization;
use App\Models\FeeRule;
use App\Models\InvestmentTerm;
use App\Models\InvestmentTransaction;
use App\Models\Investor;
use App\Models\InvestorWallet;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Models\WithdrawalVerificationCheck;
use App\Support\AuditPresentation;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.admin')]
class Index extends Component
{
    use WithPagination;

    public string $search=''; public string $category=''; public string $action=''; public string $actorId=''; public string $investorId=''; public string $objectType=''; public string $period='30';
    public string $customFrom=''; public string $customTo=''; public string $appliedFrom=''; public string $appliedTo='';

    public function updated(string $property): void
    {
        if (! in_array($property,['customFrom','customTo'],true)) $this->resetPage();
    }

    public function applyCustomPeriod(): void
    {
        $data=$this->validate(['customFrom'=>['required','date'],'customTo'=>['required','date','after_or_equal:customFrom']]);
        $this->appliedFrom=$data['customFrom']; $this->appliedTo=$data['customTo']; $this->period='custom'; $this->resetPage();
    }

    public function resetCustomPeriod(): void
    {
        $this->reset(['customFrom','customTo','appliedFrom','appliedTo']); $this->period='30'; $this->resetValidation(); $this->resetPage();
    }

    public function render()
    {
        $query=AuditLog::query()->with('user');
        $this->applyFilters($query);
        $logs=$query->latest('created_at')->latest('id')->paginate(25);
        [$subjects,$logInvestors]=$this->resolvePage($logs->getCollection());
        $financial=array_keys(array_filter(AuditPresentation::actions(),fn($item)=>$item[1]==='financial'));
        $administrative=array_keys(array_filter(AuditPresentation::actions(),fn($item)=>$item[1]==='administrative'));
        $now=Carbon::now();
        $kpis=[
            'today'=>AuditLog::whereBetween('created_at',[$now->copy()->startOfDay(),$now->copy()->endOfDay()])->count(),
            'week'=>AuditLog::where('created_at','>=',$now->copy()->subDays(6)->startOfDay())->count(),
            'financial'=>AuditLog::whereIn('action',$financial)->count(), 'administrative'=>AuditLog::whereIn('action',$administrative)->count(),
        ];
        $actors=User::whereIn('id',AuditLog::whereNotNull('user_id')->distinct()->pluck('user_id'))->orderBy('name')->get();
        $investors=Investor::with('user')->orderBy('code')->get();

        return view('livewire.admin.audit.index',[
            'logs'=>$logs,'subjects'=>$subjects,'logInvestors'=>$logInvestors,'kpis'=>$kpis,'actors'=>$actors,'investors'=>$investors,
            'actions'=>AuditPresentation::actions(),'objectTypes'=>AuditPresentation::entityTypes(),
        ])->title('Аудит — CEO Money');
    }

    private function applyFilters(Builder $query): void
    {
        if($this->category){$actions=array_keys(array_filter(AuditPresentation::actions(),fn($item)=>$item[1]===$this->category));$this->category==='system'?$query->whereNotIn('action',array_keys(AuditPresentation::actions())):$query->whereIn('action',$actions);}
        $query->when($this->action,fn($q)=>$q->where('action',$this->action))->when($this->actorId,fn($q)=>$q->where('user_id',$this->actorId))->when($this->objectType,fn($q)=>$q->where('entity_type',$this->objectType));
        if($this->investorId)$query->where(fn($q)=>$this->whereInvestorEntities($q,collect([(int)$this->investorId])));
        if($this->search){$search=trim($this->search);$investorIds=Investor::where('code','like','%'.$search.'%')->orWhereHas('user',fn($q)=>$q->where('name','like','%'.$search.'%')->orWhere('email','like','%'.$search.'%'))->pluck('id');$query->where(function($q)use($search,$investorIds){$q->where('action','like','%'.$search.'%')->orWhereHas('user',fn($u)=>$u->where('name','like','%'.$search.'%')->orWhere('email','like','%'.$search.'%'));if(ctype_digit($search))$q->orWhere('entity_id',(int)$search);if($investorIds->isNotEmpty())$q->orWhere(fn($nested)=>$this->whereInvestorEntities($nested,$investorIds));});}
        $now=Carbon::now();
        match($this->period){'today'=>$query->whereBetween('created_at',[$now->copy()->startOfDay(),$now->copy()->endOfDay()]),'7'=>$query->where('created_at','>=',$now->copy()->subDays(6)->startOfDay()),'30'=>$query->where('created_at','>=',$now->copy()->subDays(29)->startOfDay()),'custom'=>$this->appliedFrom&&$this->appliedTo?$query->whereBetween('created_at',[Carbon::parse($this->appliedFrom)->startOfDay(),Carbon::parse($this->appliedTo)->endOfDay()]):null,default=>null};
    }

    private function whereInvestorEntities(Builder $query, Collection $investorIds): void
    {
        $map=$this->entityIds($investorIds);$query->whereRaw('1=0');foreach($map as $type=>$ids){if($ids->isNotEmpty())$query->orWhere(fn($q)=>$q->where('entity_type',$type)->whereIn('entity_id',$ids));}
    }

    private function entityIds(Collection $investorIds): array
    {
        return [
            FeeRule::class=>FeeRule::whereIn('investor_id',$investorIds)->pluck('id'), InvestmentTerm::class=>InvestmentTerm::whereHas('investmentAccount',fn($q)=>$q->whereIn('investor_id',$investorIds))->pluck('id'),
            InvestorWallet::class=>InvestorWallet::whereIn('investor_id',$investorIds)->pluck('id'), DepositAddress::class=>DepositAddress::whereIn('investor_id',$investorIds)->pluck('id'), DepositRequest::class=>DepositRequest::whereIn('investor_id',$investorIds)->pluck('id'), Investor::class=>Investor::whereIn('id',$investorIds)->pluck('id'),
            DepositVerificationCheck::class=>DepositVerificationCheck::whereHas('depositRequest',fn($q)=>$q->whereIn('investor_id',$investorIds))->pluck('id'),
            WithdrawalVerificationCheck::class=>WithdrawalVerificationCheck::whereHas('withdrawalRequest',fn($q)=>$q->whereIn('investor_id',$investorIds))->pluck('id'),
            WithdrawalRequest::class=>WithdrawalRequest::whereIn('investor_id',$investorIds)->pluck('id'), InvestmentTransaction::class=>InvestmentTransaction::whereHas('investmentAccount',fn($q)=>$q->whereIn('investor_id',$investorIds))->pluck('id'),
            DividendCapitalization::class=>DividendCapitalization::whereIn('investor_id',$investorIds)->pluck('id'),
        ];
    }

    private function resolvePage(Collection $logs): array
    {
        $subjects=[];$investors=[];
        foreach(AuditPresentation::entityTypes() as $type=>$label){$ids=$logs->where('entity_type',$type)->pluck('entity_id');if($ids->isEmpty())continue;$relations=match($type){FeeRule::class,InvestorWallet::class,DepositAddress::class,DepositRequest::class,WithdrawalRequest::class,DividendCapitalization::class=>['investor.user'],DepositVerificationCheck::class=>['depositRequest.investor.user'],WithdrawalVerificationCheck::class=>['withdrawalRequest.investor.user'],Investor::class=>['user'],InvestmentTerm::class,InvestmentTransaction::class=>['investmentAccount.investor.user'],default=>[]};foreach($type::with($relations)->whereIn('id',$ids)->get() as $subject)$subjects[$type.'#'.$subject->getKey()]=$subject;}
        foreach($logs as $log){$subject=$subjects[$log->entity_type.'#'.$log->entity_id]??null;$investor=$this->subjectInvestor($subject);if($investor)$investors[$log->id]=$investor;}
        return [$subjects,$investors];
    }

    private function subjectInvestor(?Model $subject): ?Investor
    {
        if($subject instanceof InvestmentTerm||$subject instanceof InvestmentTransaction)return $subject->investmentAccount?->investor;
        if($subject instanceof DepositVerificationCheck)return $subject->depositRequest?->investor;
        if($subject instanceof WithdrawalVerificationCheck)return $subject->withdrawalRequest?->investor;
        if($subject instanceof Investor)return $subject;
        if($subject instanceof FeeRule||$subject instanceof InvestorWallet||$subject instanceof DepositAddress||$subject instanceof DepositRequest||$subject instanceof WithdrawalRequest||$subject instanceof DividendCapitalization)return $subject->investor;
        return null;
    }
}
