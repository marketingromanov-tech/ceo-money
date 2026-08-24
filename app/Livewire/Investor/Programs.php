<?php

namespace App\Livewire\Investor;

use App\Models\InvestmentProgram;
use App\Services\InvestmentProgramService;
use Carbon\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.investor')]
class Programs extends Component
{
    public function selectProgram(int $programId, InvestmentProgramService $programs): void
    {
        $account=auth()->user()->investor?->investmentAccounts()->where('status','active')->firstOrFail();
        $program=InvestmentProgram::query()->whereKey($programId)->where('status','active')->where('currency',$account->currency)->first();
        if($program===null||$programs->activeVersion($program,Carbon::today())===null){$this->addError('program','Программа больше недоступна');return;}
        $this->redirectRoute('investor.finance.create',['investment_program_id'=>$program->id],navigate:true);
    }

    public function render()
    {
        $today=Carbon::today();
        $currency=auth()->user()->investor?->investmentAccounts()->where('status','active')->value('currency')??'USDT';
        $programs=InvestmentProgram::query()->where('status','active')->where('currency',$currency)
            ->whereHas('versions',fn($query)=>$query->activeOn($today))
            ->with(['versions'=>fn($query)=>$query->activeOn($today)->latest('valid_from')->latest('id')])->orderBy('min_amount')->get();
        $individualTerms=auth()->user()->investorInvestmentTerms()->active()
            ->whereHas('versions',fn($query)=>$query->validForDate($today)->where('currency',$currency))
            ->with(['versions'=>fn($query)=>$query->validForDate($today)->where('currency',$currency)->latest('valid_from')->latest('id')])
            ->latest('id')->get();
        return view('livewire.investor.programs',compact('programs','currency','individualTerms'))->title('Доступные программы — CEO Money');
    }
}
