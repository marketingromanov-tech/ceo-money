<?php

namespace App\Livewire\Admin\Inbox;

use App\Models\{AuditLog,DepositRequest,InvestorWallet,SupportTicket,User,WithdrawalRequest};
use App\Services\{DepositVerificationService,FinancialOperationReadinessService,SupportTicketService,WithdrawalVerificationService};
use App\Support\{AuditPresentation,DepositVerificationPresentation,InvestorPresentation,MoneyFormatter,SupportPresentation,WalletPresentation,WithdrawalVerificationPresentation};
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.admin')]
class Index extends Component
{
    public string $tab='action',$sort='priority',$type='',$search='';
    public ?int $selectedTicketId=null; public string $replyText='',$noteText='',$status='',$assigneeId='';

    public function mount():void { if(($id=request()->integer('ticket'))&&SupportTicket::whereKey($id)->exists()){$this->tab='support';$this->openTicket($id);} }
    public function openTicket(int $id):void{$this->selectedTicketId=$id;$ticket=SupportTicket::findOrFail($id);$ticket->update(['admin_last_read_at'=>now()]);$this->status=$ticket->status;$this->assigneeId=(string)($ticket->assigned_to_user_id??'');}
    public function reply(SupportTicketService $s):void{$this->validate(['replyText'=>'required|string|max:5000']);$s->reply($this->ticket(),auth()->user(),$this->replyText);$this->replyText='';}
    public function note(SupportTicketService $s):void{$this->validate(['noteText'=>'required|string|max:5000']);$s->addInternalNote($this->ticket(),auth()->user(),$this->noteText);$this->noteText='';}
    public function changeStatus(SupportTicketService $s):void{$s->changeStatus($this->ticket(),auth()->user(),$this->status);}
    public function assign(SupportTicketService $s):void{$s->assign($this->ticket(),auth()->user(),User::where('role','admin')->findOrFail($this->assigneeId));}
    private function ticket():SupportTicket{return SupportTicket::findOrFail($this->selectedTicketId);}

    public function render(FinancialOperationReadinessService $readiness)
    {
        $items=$this->items($readiness);$attention=$this->items($readiness,'action');$review=$this->items($readiness,'review');$ready=$this->items($readiness,'ready');
        return view('livewire.admin.inbox.index',[
            'items'=>$items,'ticket'=>$this->selectedTicketId?SupportTicket::with(['investor.user','assignee','messages.user'])->find($this->selectedTicketId):null,
            'admins'=>User::where('role','admin')->where('is_active',true)->get(),
            'kpis'=>['attention'=>$attention->count(),'review'=>$review->count(),'ready'=>$ready->count(),'support'=>SupportTicket::whereIn('status',['new','open','waiting_investor'])->count(),'new'=>SupportTicket::where('status','new')->count(),'financial'=>$attention->where('source_type','!=','support')->count(),'open'=>SupportTicket::where('status','open')->count(),'urgent'=>SupportTicket::where('priority','urgent')->whereNotIn('status',['resolved','closed'])->count()],
        ])->title('Financial Operations Center — CEO Money');
    }

    private function items(FinancialOperationReadinessService $readiness,?string $forcedTab=null):Collection
    {
        $tab=$forcedTab??$this->tab;$items=collect();
        $deposits=DepositRequest::with(['investor.user','verificationChecks'])->withCount(['verificationChecks as verification_passed_count'=>fn($q)=>$q->where('status','passed')])->whereIn('status',$tab==='completed'?['confirmed','rejected','cancelled']:['pending','payment_submitted','submitted'])->oldest('requested_at')->limit(50)->get();
        foreach($deposits as $r){$state=$readiness->deposit($r);$include=match($tab){'action'=>in_array($r->status,['pending','payment_submitted'],true)||!$state['ready'],'review'=>$r->status==='submitted'&&!$state['ready'],'ready'=>$state['ready'],'completed'=>in_array($r->status,['confirmed','rejected','cancelled'],true),default=>false};if(!$include)continue;$items->push($this->depositItem($r,$state));}

        $withdrawals=WithdrawalRequest::with(['investor.user','investorWallet','verificationChecks'])->withCount(['verificationChecks as verification_passed_count'=>fn($q)=>$q->where('status','passed')])->whereIn('status',$tab==='completed'?['paid','rejected','cancelled']:['new','review','approved'])->oldest('requested_at')->limit(50)->get();
        foreach($withdrawals as $r){$state=$readiness->withdrawal($r);$include=match($tab){'action'=>$r->status==='new'||($r->status==='review'&&!$state['ready']),'review'=>$r->status==='review'&&!$state['ready'],'ready'=>$state['ready'],'completed'=>in_array($r->status,['paid','rejected','cancelled'],true),default=>false};if(!$include)continue;$items->push($this->withdrawalItem($r,$state));}

        if($tab==='action')foreach(InvestorWallet::with('investor.user')->where('status','pending')->oldest()->limit(50)->get() as $r)$items->push(['source_type'=>'wallet','source_id'=>$r->id,'entity_type'=>InvestorWallet::class,'investor_name'=>$r->investor->user->name,'title'=>'Новый кошелёк','amount'=>null,'currency'=>$r->currency,'finance'=>null,'subtitle'=>$r->currency.' · '.$r->network.' · '.WalletPresentation::shortAddress($r->address),'status'=>'Ожидает проверки','priority'=>'financial','created_at'=>$r->created_at,'target'=>route('admin.wallets.index'),'action'=>'Открыть','progress'=>null,'waiting'=>[]]);
        if(in_array($tab,['action','support'],true))foreach(SupportTicket::with('investor.user')->whereIn('status',['new','open','waiting_investor'])->when($tab==='action',fn($q)=>$q->where(fn($x)=>$x->where('status','new')->orWhereIn('priority',['high','urgent'])))->oldest()->limit(50)->get() as $r)$items->push(['source_type'=>'support','source_id'=>$r->id,'entity_type'=>SupportTicket::class,'investor_name'=>$r->investor->user->name,'title'=>$r->subject,'amount'=>null,'currency'=>null,'finance'=>null,'subtitle'=>SupportPresentation::category($r->category),'status'=>SupportPresentation::status($r->status),'priority'=>$r->priority,'created_at'=>$r->created_at,'target'=>'ticket','action'=>'Открыть','progress'=>null,'waiting'=>[]]);

        $this->attachTimeline($items);
        if($this->type)$items=$items->filter(fn($i)=>$this->type==='withdrawal'?str_starts_with($i['source_type'],'withdrawal'):$i['source_type']===$this->type);
        if(trim($this->search)!==''){$s=mb_strtolower(trim($this->search));$items=$items->filter(fn($i)=>str_contains(mb_strtolower($i['investor_name'].' '.$i['title'].' '.$i['subtitle'].' '.$i['source_id']),$s));}
        $rank=['urgent'=>0,'high'=>1,'financial'=>2,'normal'=>3];return $items->sortBy(fn($i)=>$this->sort==='newest'?-$i['created_at']->timestamp:($this->sort==='oldest'?$i['created_at']->timestamp:sprintf('%02d-%012d',$rank[$i['priority']]??9,$i['created_at']->timestamp)))->values();
    }

    private function depositItem(DepositRequest $r,array $state):array
    {
        $waiting=$r->verificationChecks->where('status','!=','passed')->take(3)->map(fn($c)=>DepositVerificationPresentation::label($c->check_key))->values()->all();
        if(!$waiting&&$state['reason'])$waiting=[$state['reason']];
        return ['source_type'=>'deposit','source_id'=>$r->id,'entity_type'=>DepositRequest::class,'investor_name'=>$r->investor->user->name,'title'=>'Пополнение','amount'=>(string)$r->requested_amount,'currency'=>$r->currency,'finance'=>['gross'=>(string)($r->received_amount??$r->requested_amount),'fee'=>(string)$r->fee_amount,'net'=>(string)($r->net_investment_amount??'0'),'economic'=>$r->fee_economic_type_snapshot,'payer'=>$r->fee_payer],'subtitle'=>$r->network?:'Сеть не указана','status'=>$state['ready']?'Готово к подтверждению':InvestorPresentation::adminDepositStatus($r->status),'priority'=>'financial','created_at'=>$r->requested_at,'target'=>route('admin.deposits.index').'?deposit='.$r->id,'action'=>$state['ready']?'Перейти к подтверждению':'Открыть','progress'=>[$r->verification_passed_count,count(DepositVerificationService::KEYS)],'waiting'=>$waiting,'reason'=>$state['reason']];
    }

    private function withdrawalItem(WithdrawalRequest $r,array $state):array
    {
        $waiting=$r->verificationChecks->where('status','!=','passed')->take(3)->map(fn($c)=>WithdrawalVerificationPresentation::label($c->check_key))->values()->all();
        if(!$waiting&&$state['reason'])$waiting=[$state['reason']];
        return ['source_type'=>$r->type==='capital'?'withdrawal_capital':'withdrawal_dividend','source_id'=>$r->id,'entity_type'=>WithdrawalRequest::class,'investor_name'=>$r->investor->user->name,'title'=>$r->type==='capital'?'Вывод капитала':'Вывод дивидендов','amount'=>(string)$r->requested_amount,'currency'=>$r->currency,'finance'=>['gross'=>(string)$r->requested_amount,'fee'=>(string)$r->fee_amount,'net'=>(string)$r->net_amount,'economic'=>$r->fee_economic_type_snapshot,'payer'=>$r->fee_payer],'subtitle'=>$r->network_snapshot?:'Сеть не указана','status'=>$state['ready']?'Готово к выплате':InvestorPresentation::status($r->status),'priority'=>'financial','created_at'=>$r->requested_at,'target'=>route('admin.withdrawals.index').'?withdrawal='.$r->id,'action'=>$state['ready']?'Перейти к выплате':'Открыть','progress'=>[$r->verification_passed_count,count(WithdrawalVerificationService::KEYS)],'waiting'=>$waiting,'reason'=>$state['reason']];
    }

    private function attachTimeline(Collection $items):void
    {
        if($items->isEmpty())return;
        $groups=$items->groupBy('entity_type');$logs=AuditLog::with('user')->where(function($query)use($groups){foreach($groups as $type=>$rows)$query->orWhere(fn($q)=>$q->where('entity_type',$type)->whereIn('entity_id',$rows->pluck('source_id')));})->oldest('created_at')->limit(500)->get()->groupBy(fn($log)=>$log->entity_type.'#'.$log->entity_id);
        $items->transform(function($item)use($logs){$item['timeline']=$logs->get($item['entity_type'].'#'.$item['source_id'],collect())->map(fn($log)=>['time'=>$log->created_at,'label'=>AuditPresentation::label($log->action),'actor'=>$log->user?->name??'Система'])->all();return$item;});
    }
}
