<?php

namespace App\Services;

use App\Models\{DepositRequest,Investor,InvestorWallet,SupportMessage,SupportTicket,User,WithdrawalRequest};
use DomainException;
use Illuminate\Support\Facades\DB;

class SupportTicketService
{
    private const TRANSITIONS=['new'=>['open','resolved','closed'],'open'=>['waiting_investor','resolved','closed'],'waiting_investor'=>['open','resolved','closed'],'resolved'=>['open','closed'],'closed'=>[]];
    public function __construct(private readonly AuditLogService $audit){}

    public function create(Investor $investor,User $actor,string $category,string $subject,string $message,string $priority='normal',?string $relatedType=null,?int $relatedId=null):SupportTicket
    {
        $this->assertActor($investor,$actor); $this->assertValue($category,SupportTicket::CATEGORIES,'category');
        $allowed=$actor->role==='admin'?SupportTicket::PRIORITIES:SupportTicket::INVESTOR_PRIORITIES; $this->assertValue($priority,$allowed,'priority');
        if(trim($subject)===''||mb_strlen($subject)>255||trim($message)==='')throw new DomainException('Subject and message are required.');
        $this->assertRelated($investor,$relatedType,$relatedId);
        return DB::transaction(function()use($investor,$actor,$category,$subject,$message,$priority,$relatedType,$relatedId){
            $ticket=SupportTicket::create(['investor_id'=>$investor->id,'created_by_user_id'=>$actor->id,'category'=>$category,'subject'=>trim($subject),'status'=>'new','priority'=>$priority,'related_type'=>$relatedType,'related_id'=>$relatedId,'last_message_at'=>now()]);
            $first=SupportMessage::create(['support_ticket_id'=>$ticket->id,'user_id'=>$actor->id,'message'=>trim($message),'is_internal'=>false]);
            $this->audit->log('support_ticket.created',$ticket,$actor,null,['ticket_id'=>$ticket->id,'investor_id'=>$investor->id,'category'=>$category,'status'=>'new','priority'=>$priority,'message_id'=>$first->id,'related_type'=>$relatedType,'related_id'=>$relatedId]);
            return $ticket->load('messages.user');
        });
    }
    public function reply(SupportTicket $ticket,User $actor,string $message):SupportMessage
    {
        $this->assertActor($ticket->investor,$actor); if($ticket->status==='closed')throw new DomainException('Closed ticket cannot receive replies.');
        if(trim($message)==='')throw new DomainException('Message is required.');
        return DB::transaction(function()use($ticket,$actor,$message){$ticket=SupportTicket::lockForUpdate()->findOrFail($ticket->id);$old=$ticket->status;$status=$actor->role==='investor'&&in_array($old,['waiting_investor','resolved'],true)?'open':$old;$item=SupportMessage::create(['support_ticket_id'=>$ticket->id,'user_id'=>$actor->id,'message'=>trim($message),'is_internal'=>false]);$ticket->update(['status'=>$status,'last_message_at'=>now(),'resolved_at'=>$status==='resolved'?$ticket->resolved_at:null]);$this->auditMessage($ticket,$item,$actor,false);if($status!==$old)$this->auditStatus($ticket,$actor,$old,$status);return $item;});
    }
    public function addInternalNote(SupportTicket $ticket,User $admin,string $message):SupportMessage
    {
        $this->assertAdmin($admin); if($ticket->status==='closed')throw new DomainException('Closed ticket cannot receive notes.'); if(trim($message)==='')throw new DomainException('Message is required.');
        return DB::transaction(function()use($ticket,$admin,$message){$item=SupportMessage::create(['support_ticket_id'=>$ticket->id,'user_id'=>$admin->id,'message'=>trim($message),'is_internal'=>true]);$ticket->update(['last_message_at'=>now()]);$this->auditMessage($ticket,$item,$admin,true);return $item;});
    }
    public function assign(SupportTicket $ticket,User $admin,User $assignee):SupportTicket
    {
        $this->assertAdmin($admin);$this->assertAdmin($assignee);return DB::transaction(function()use($ticket,$admin,$assignee){$old=$ticket->assigned_to_user_id;$ticket->update(['assigned_to_user_id'=>$assignee->id]);$this->audit->log('support_ticket.assigned',$ticket,$admin,['assigned_to_user_id'=>$old],['ticket_id'=>$ticket->id,'investor_id'=>$ticket->investor_id,'assigned_to_user_id'=>$assignee->id]);return $ticket;});
    }
    public function changeStatus(SupportTicket $ticket,User $admin,string $status):SupportTicket
    {
        $this->assertAdmin($admin);$this->assertValue($status,SupportTicket::STATUSES,'status');$old=$ticket->status;if($old===$status)return $ticket;if(!in_array($status,self::TRANSITIONS[$old]??[],true))throw new DomainException('Invalid support ticket transition.');
        return DB::transaction(function()use($ticket,$admin,$old,$status){$ticket->update(['status'=>$status,'resolved_at'=>$status==='resolved'?now():($old==='resolved'?null:$ticket->resolved_at),'closed_at'=>$status==='closed'?now():null]);$this->auditStatus($ticket,$admin,$old,$status);return $ticket;});
    }
    public function resolve(SupportTicket $ticket,User $admin):SupportTicket{return $this->changeStatus($ticket,$admin,'resolved');}
    public function close(SupportTicket $ticket,User $admin):SupportTicket{return $this->changeStatus($ticket,$admin,'closed');}
    private function assertActor(Investor $investor,User $actor):void{if($actor->role==='admin')return;if($actor->role!=='investor'||$actor->investor?->id!==$investor->id)throw new DomainException('Actor does not own this ticket.');}
    private function assertAdmin(User $user):void{if($user->role!=='admin'||!$user->is_active)throw new DomainException('Only an active admin is allowed.');}
    private function assertValue(string $value,array $allowed,string $field):void{if(!in_array($value,$allowed,true))throw new DomainException("Invalid {$field}.");}
    private function assertRelated(Investor $investor,?string $type,?int $id):void{if($type===null&&$id===null)return;if(!in_array($type,SupportTicket::RELATED_TYPES,true)||!$id)throw new DomainException('Invalid related object.');$model=match($type){'withdrawal_request'=>WithdrawalRequest::class,'deposit_request'=>DepositRequest::class,'investor_wallet'=>InvestorWallet::class};if(!$model::whereKey($id)->where('investor_id',$investor->id)->exists())throw new DomainException('Related object does not belong to investor.');}
    private function auditMessage(SupportTicket $ticket,SupportMessage $message,User $actor,bool $internal):void{$this->audit->log('support_ticket.message_sent',$ticket,$actor,null,['ticket_id'=>$ticket->id,'investor_id'=>$ticket->investor_id,'message_id'=>$message->id,'is_internal'=>$internal]);}
    private function auditStatus(SupportTicket $ticket,User $actor,string $old,string $new):void{$this->audit->log('support_ticket.status_changed',$ticket,$actor,['status'=>$old],['ticket_id'=>$ticket->id,'investor_id'=>$ticket->investor_id,'status'=>$new]);}
}
