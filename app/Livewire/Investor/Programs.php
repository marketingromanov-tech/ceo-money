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
        $currency=auth()->user()->investor?->investmentAccounts()->where('status','active')->value('currency')??'USDT';
        $programs=InvestmentProgram::query()->where('status','active')->where('currency',$currency)
            ->whereHas('versions',fn($query)=>$query->activeOn(Carbon::today()))
            ->with(['versions'=>fn($query)=>$query->activeOn(Carbon::today())->latest('valid_from')->latest('id')])->orderBy('min_amount')->get();
        return view('livewire.investor.programs',compact('programs','currency'))->title('Доступные программы — CEO Money');
    }
}
