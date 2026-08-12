<?php

namespace App\Livewire\Admin\InvestmentPrograms;

use App\Models\InvestmentProgram;
use App\Services\AuditLogService;
use App\Services\InvestmentProgramService;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.admin')]
class Index extends Component
{
    use WithPagination;

    public ?int $editingId = null;
    public string $name = '', $description = '', $currency = 'USDT', $minAmount = '', $maxAmount = '', $status = 'draft', $monthlyRate = '', $lockMonths = '0', $validFrom = '';
    public bool $partialWithdrawal = true;

    public function create(): void { $this->resetForm(); $this->dispatch('open-program-form'); }
    public function edit(int $id, InvestmentProgramService $service): void { $p=InvestmentProgram::findOrFail($id);$version=$service->activeVersion($p,Carbon::today());$this->editingId=$p->id;$this->name=$p->name;$this->description=$p->description??'';$this->currency=$p->currency;$this->minAmount=(string)$p->min_amount;$this->maxAmount=(string)($p->max_amount??'');$this->status=$p->status;$this->partialWithdrawal=$p->is_partial_withdrawal_allowed;$this->monthlyRate=(string)($version?->monthly_rate??'');$this->lockMonths=(string)($version?->lock_months??0);$this->validFrom=Carbon::today()->addDay()->toDateString();$this->dispatch('open-program-form'); }

    public function save(InvestmentProgramService $service, AuditLogService $audit): void
    {
        $data=$this->validate(['name'=>'required|string|max:120','description'=>'nullable|string|max:2000','currency'=>'required|string|max:12','minAmount'=>'required|decimal:0,8|min:0.00000001','maxAmount'=>'nullable|decimal:0,8|gt:minAmount','status'=>['required',Rule::in(['draft','active','paused','archived'])],'partialWithdrawal'=>'boolean','monthlyRate'=>'required|decimal:0,4|min:0','lockMonths'=>'required|integer|min:0','validFrom'=>$this->editingId?'required|date|after_or_equal:today':'required|date']);
        if (in_array($data['status'], ['active', 'paused'], true)) {
            $overlap = InvestmentProgram::query()->where('currency', $data['currency'])->whereIn('status', ['active', 'paused'])
                ->when($this->editingId, fn ($query) => $query->whereKeyNot($this->editingId))
                ->where(fn ($query) => $data['maxAmount'] === '' ? $query : $query->where('min_amount', '<=', $data['maxAmount']))
                ->where(fn ($query) => $query->whereNull('max_amount')->orWhere('max_amount', '>=', $data['minAmount']))->exists();
            if ($overlap) throw ValidationException::withMessages(['minAmount' => 'Диапазон пересекается с другой программой этой валюты.']);
        }
        if($this->editingId){$program=InvestmentProgram::findOrFail($this->editingId);$old=$program->only(['name','description','status','currency','min_amount','max_amount','is_partial_withdrawal_allowed']);$program->update(['name'=>$data['name'],'description'=>$data['description']?:null,'status'=>$data['status'],'currency'=>$data['currency'],'min_amount'=>$data['minAmount'],'max_amount'=>$data['maxAmount']?:null,'is_partial_withdrawal_allowed'=>$data['partialWithdrawal']]);$audit->log('investment_program.updated',$program,auth()->user(),$old,$program->only(array_keys($old)));$service->updateConditions($program,$data['monthlyRate'],(int)$data['lockMonths'],Carbon::parse($data['validFrom']),auth()->user());}
        else{$program=InvestmentProgram::create(['name'=>$data['name'],'slug'=>$this->uniqueSlug($data['name']),'description'=>$data['description']?:null,'status'=>$data['status'],'currency'=>$data['currency'],'min_amount'=>$data['minAmount'],'max_amount'=>$data['maxAmount']?:null,'is_partial_withdrawal_allowed'=>$data['partialWithdrawal'],'created_by'=>auth()->id()]);$audit->log('investment_program.created',$program,auth()->user(),null,['program_id'=>$program->id,'name'=>$program->name,'status'=>$program->status]);$service->createVersion($program,$data['monthlyRate'],(int)$data['lockMonths'],Carbon::parse($data['validFrom']),auth()->user());}
        $this->dispatch('close-program-form');$this->resetForm();
    }

    public function addVersion(int $id): void {$this->editingId=$id;$this->monthlyRate='';$this->lockMonths='0';$this->validFrom=now()->addDay()->toDateString();$this->dispatch('open-version-form');}
    public function saveVersion(InvestmentProgramService $service): void {$data=$this->validate(['monthlyRate'=>'required|decimal:0,4|min:0','lockMonths'=>'required|integer|min:0','validFrom'=>'required|date']);$service->createVersion(InvestmentProgram::findOrFail($this->editingId),$data['monthlyRate'],(int)$data['lockMonths'],Carbon::parse($data['validFrom']),auth()->user());$this->dispatch('close-version-form');}
    public function archive(int $id, AuditLogService $audit): void {$p=InvestmentProgram::findOrFail($id);$old=['status'=>$p->status];$p->update(['status'=>'archived']);$audit->log('investment_program.archived',$p,auth()->user(),$old,['program_id'=>$p->id,'status'=>'archived']);}

    public function render()
    {
        $programs=InvestmentProgram::with(['versions'=>fn($q)=>$q->latest('valid_from')->latest('id'),'lots.investmentAccount'])->withCount('lots')->orderBy('min_amount')->paginate(15);
        return view('livewire.admin.investment-programs.index',['programs'=>$programs])->title('Инвестиционные программы — CEO Money');
    }

    private function resetForm():void{$this->reset(['editingId','name','description','minAmount','maxAmount','monthlyRate']);$this->currency='USDT';$this->status='draft';$this->lockMonths='0';$this->validFrom=now()->toDateString();$this->partialWithdrawal=true;$this->resetValidation();}
    private function uniqueSlug(string $name):string{$base=Str::slug($name)?:'program';$slug=$base;$i=2;while(InvestmentProgram::where('slug',$slug)->exists())$slug=$base.'-'.$i++;return$slug;}
}
