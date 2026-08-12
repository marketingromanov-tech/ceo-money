<?php

namespace App\Livewire\Admin\Withdrawals;

use App\Models\WithdrawalRequest;
use App\Services\{CapitalWithdrawalService,DividendWithdrawalService,WithdrawalVerificationService,WithdrawalWorkflowService};
use DomainException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.admin')]
class Index extends Component
{
    use WithPagination;
    public string $type='',$status='',$actionStep='details',$txid='',$rejectionReason='';
    public ?int $selectedWithdrawalId=null;
    public bool $showActionModal=false;

    public function mount():void
    {
        $id=request()->integer('withdrawal');
        if($id && WithdrawalRequest::whereKey($id)->exists())$this->openAction($id);
    }
    public function updatedType():void{$this->resetPage();}
    public function updatedStatus():void{$this->resetPage();}
    public function openAction(int $id):void{$request=WithdrawalRequest::findOrFail($id);$this->selectedWithdrawalId=$request->id;$this->showActionModal=true;$this->actionStep='details';$this->txid=(string)($request->txid??'');$this->rejectionReason='';$this->resetErrorBag();app(WithdrawalVerificationService::class)->initializeForRequest($request);}
    public function closeAction():void{$this->reset(['selectedWithdrawalId','showActionModal','txid','rejectionReason']);$this->actionStep='details';$this->resetErrorBag();}
    public function takeToReview(WithdrawalWorkflowService $service):void{$this->run(fn()=> $service->moveToReview($this->selected(),auth()->user()),'Заявка взята на проверку.');}
    public function approve(WithdrawalWorkflowService $service):void{$this->run(fn()=> $service->approve($this->selected(),auth()->user()),'Заявка одобрена.');}
    public function reject(WithdrawalWorkflowService $service):void{$this->run(fn()=> $service->reject($this->selected(),auth()->user(),$this->rejectionReason?:null),'Заявка отклонена.');}
    public function cancel(WithdrawalWorkflowService $service):void{$this->run(fn()=> $service->cancel($this->selected(),auth()->user(),$this->rejectionReason?:null),'Заявка отменена.');}
    public function passVerification(string $key,WithdrawalVerificationService $service):void{$this->verification(fn()=> $service->markPassed($this->selected(),$key,auth()->user()));}
    public function failVerification(string $key,WithdrawalVerificationService $service):void{$this->verification(fn()=> $service->markFailed($this->selected(),$key,auth()->user()));}
    public function resetVerification(string $key,WithdrawalVerificationService $service):void{$this->verification(fn()=> $service->resetManualCheck($this->selected(),$key,auth()->user()));}
    public function requestPaymentConfirmation(WithdrawalVerificationService $verification):void{try{$verification->assertReadyForPayout($this->selected());$this->actionStep='confirm';$this->resetErrorBag();}catch(DomainException $e){$this->addError('action',$e->getMessage());}}
    public function confirmPayment(DividendWithdrawalService $dividends,CapitalWithdrawalService $capital):void
    {
        $this->validate(['txid'=>['nullable','string','max:255']]);
        try{$request=$this->selected();app(WithdrawalVerificationService::class)->assertReadyForPayout($request);$request->type==='dividend'?$dividends->pay($request,auth()->user(),$this->txid?:null):$capital->pay($request,auth()->user(),$this->txid?:null);$this->success('Выплата выполнена.');}catch(DomainException $e){$this->addError('action',$e->getMessage());}
    }
    private function selected():WithdrawalRequest{return WithdrawalRequest::with(['investor.user'])->findOrFail($this->selectedWithdrawalId);}
    private function run(callable $operation,string $message):void{try{$operation();$this->success($message);}catch(DomainException $e){$this->addError('action',$e->getMessage());}}
    private function verification(callable $operation):void{try{$operation();$this->resetErrorBag();}catch(DomainException $e){$this->addError('action',$e->getMessage());}}
    private function success(string $message):void{session()->flash('success',$message);$this->closeAction();}
    public function render(){ $selected=$this->selectedWithdrawalId?WithdrawalRequest::with('investor.user')->find($this->selectedWithdrawalId):null;$summary=null;if($selected){$verification=app(WithdrawalVerificationService::class);$verification->initializeForRequest($selected);$summary=$verification->summary($selected);}return view('livewire.admin.withdrawals.index',['withdrawals'=>WithdrawalRequest::with('investor.user')->withCount(['verificationChecks as verification_passed_count'=>fn($q)=>$q->where('status','passed')])->when($this->type,fn($q)=>$q->where('type',$this->type))->when($this->status,fn($q)=>$q->where('status',$this->status))->latest('requested_at')->paginate(20),'selectedWithdrawal'=>$selected,'verificationSummary'=>$summary])->title('Выводы — CEO Money');}
}
