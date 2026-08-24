<?php

namespace App\Livewire\Investor;

use App\Livewire\Investor\Concerns\InteractsWithInvestorData;
use App\Models\InvestmentProgram;
use App\Models\InvestorInvestmentTermVersion;
use App\Services\AccrualCalculator;
use App\Services\DepositRequestService;
use App\Services\InvestmentProgramService;
use App\Services\EffectiveInvestmentTermsResolver;
use App\Support\MoneyFormatter;
use Carbon\Carbon;
use DomainException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.investor')]
class CreateDeposit extends Component
{
    use InteractsWithInvestorData;

    public int $investmentProgramId = 0;
    public ?int $individualTermId = null;
    public string $amount = '';
    public int $step = 1;
    public ?int $createdRequestId = null;

    public function mount(): void
    {
        $this->investmentProgramId = (int) request()->query('investment_program_id', 0);
        $this->individualTermId = request()->filled('individual_term_id') ? (int) request()->query('individual_term_id') : null;
        $amount = trim((string) request()->query('amount', ''));
        $this->amount = $this->isDecimalAmount($amount) ? MoneyFormatter::input($amount) : $amount;
    }

    public function review(InvestmentProgramService $programs, AccrualCalculator $decimal, EffectiveInvestmentTermsResolver $resolver): void
    {
        $this->normalizeAmountForDisplay();
        if ($this->validateSelection($programs, $decimal, $resolver) !== null) $this->step = 2;
    }

    public function back(): void { $this->step = 1; $this->resetErrorBag(); }

    public function normalizeAmountForDisplay(): void
    {
        $amount = trim($this->amount);
        if ($this->isDecimalAmount($amount)) $this->amount = MoneyFormatter::input($amount);
    }

    public function useMinimum(InvestmentProgramService $programs, AccrualCalculator $decimal, EffectiveInvestmentTermsResolver $resolver): void
    {
        if ($this->individualTermId !== null) {
            $version=$this->availableIndividualVersion();
            if($version===null){$this->addError('program','Персональные условия больше недоступны');return;}
            if($version->min_amount===null){$this->addError('amount','Укажите сумму инвестиции.');return;}
            $this->amount=MoneyFormatter::input((string)$version->min_amount);
        } else {
            $program=$this->availableProgram();
            if($program===null){$this->addError('program','Программа больше недоступна');return;}
            $this->amount=MoneyFormatter::input((string)$program->min_amount);
        }
        $this->review($programs,$decimal,$resolver);
    }

    public function submit(DepositRequestService $deposits, InvestmentProgramService $programs, AccrualCalculator $decimal, EffectiveInvestmentTermsResolver $resolver): void
    {
        $this->normalizeAmountForDisplay();
        $selection = $this->validateSelection($programs, $decimal, $resolver);
        if ($selection === null) return;
        $program = $selection instanceof InvestmentProgram ? $selection : null;
        try {
            $effectiveTerms = $resolver->resolve(auth()->user(), $this->account()->currency, $this->amount, Carbon::today());
            $request = $deposits->create($this->account(), $this->amount, actor: auth()->user(), program: $program, effectiveTerms: $effectiveTerms);
            $this->createdRequestId = $request->id;
            $this->step = 3;
        } catch (DomainException $exception) {
            $this->addError('amount', $exception->getMessage());
            $this->step = 1;
        }
    }

    public function render(InvestmentProgramService $programs, AccrualCalculator $decimal, EffectiveInvestmentTermsResolver $resolver)
    {
        $program = $this->availableProgram();
        $version = $program ? $programs->activeVersion($program, Carbon::today()) : null;
        $individualMode = $this->individualTermId !== null;
        $individualVersion = $individualMode ? $this->availableIndividualVersion() : null;
        $projection = null;$belowMinimum=false;$aboveMaximum=false;$shortfall=null;$suggestedPrograms=collect();$effectiveTerms=null;
        $validAmount=preg_match('/^\d+(?:\.\d{1,8})?$/',$this->amount)===1;
        if(($program||$individualVersion)&&$validAmount){try{$effectiveTerms=$resolver->resolve(auth()->user(),$this->account()->currency,$this->amount,Carbon::today());}catch(DomainException){}if($individualMode){$usesSelectedIndividual=$effectiveTerms&&$effectiveTerms['source']==='individual'&&$effectiveTerms['individual_term_id']===$this->individualTermId;if(!$usesSelectedIndividual){$belowMinimum=$individualVersion->min_amount!==null&&$decimal->compare($this->amount,(string)$individualVersion->min_amount)<0;$aboveMaximum=$individualVersion->max_amount!==null&&$decimal->compare($this->amount,(string)$individualVersion->max_amount)>0;if($belowMinimum)$shortfall=$decimal->subtract((string)$individualVersion->min_amount,$this->amount);$effectiveTerms=null;}}else{$usesSelectedProgram=$effectiveTerms&&$effectiveTerms['source']==='program'&&$effectiveTerms['program_id']===$program->id;if(!$effectiveTerms||($effectiveTerms['source']==='program'&&!$usesSelectedProgram)){$belowMinimum=$decimal->compare($this->amount,(string)$program->min_amount)<0;$aboveMaximum=$program->max_amount!==null&&$decimal->compare($this->amount,(string)$program->max_amount)>0;if($belowMinimum)$shortfall=$decimal->subtract((string)$program->min_amount,$this->amount);}}}
        if ($program && $aboveMaximum) {
            $suggestedPrograms = InvestmentProgram::query()
                ->whereKeyNot($program->id)
                ->where('status', 'active')
                ->where('currency', $this->account()->currency)
                ->where('min_amount', '<=', $this->amount)
                ->where(fn ($query) => $query->whereNull('max_amount')->orWhere('max_amount', '>=', $this->amount))
                ->whereHas('versions', fn ($query) => $query->activeOn(Carbon::today()))
                ->orderBy('min_amount')
                ->limit(3)
                ->get();
        }
        if (($version || $individualVersion) && $effectiveTerms && $validAmount && ! $belowMinimum && ! $aboveMaximum) {
            $monthly = $decimal->calculate($this->amount, $effectiveTerms['rate'], 1);
            $period = '0.00000000';
            for ($month=0;$month<$effectiveTerms['term_months'];$month++) $period=$decimal->add($period,$monthly);
            $projection=['monthly'=>$monthly,'period'=>$period];
        }
        return view('livewire.investor.create-deposit', compact('program','version','individualMode','individualVersion','effectiveTerms','projection','belowMinimum','aboveMaximum','shortfall','suggestedPrograms'))->title('Новая заявка — CEO Money');
    }

    private function validateSelection(InvestmentProgramService $programs, AccrualCalculator $decimal, EffectiveInvestmentTermsResolver $resolver): InvestmentProgram|InvestorInvestmentTermVersion|null
    {
        $this->resetErrorBag();
        if ($this->individualTermId !== null) {
            $version=$this->availableIndividualVersion();
            if($version===null){$this->addError('program','Персональные условия больше недоступны');return null;}
            if(!preg_match('/^\d+(?:\.\d{1,8})?$/',$this->amount)){$this->addError('amount','Укажите корректную сумму инвестиции.');return null;}
            try{$effective=$resolver->resolve(auth()->user(),$this->account()->currency,$this->amount,Carbon::today());}catch(DomainException $exception){$this->addError('amount',$exception->getMessage());return null;}
            if($effective['source']!=='individual'||$effective['individual_term_id']!==$this->individualTermId){$this->addError('amount','Сумма не соответствует диапазону персональных условий.');return null;}
            return $version;
        }
        $program=$this->availableProgram();
        if($program===null||$programs->activeVersion($program,Carbon::today())===null){$this->addError('program','Программа больше недоступна');return null;}
        if(!preg_match('/^\d+(?:\.\d{1,8})?$/',$this->amount)){$this->addError('amount','Укажите корректную сумму инвестиции.');return null;}
        try{$effective=$resolver->resolve(auth()->user(),$this->account()->currency,$this->amount,Carbon::today());}catch(DomainException $exception){$this->addError('amount',$exception->getMessage());return null;}
        if($effective['source']==='individual')return $program;
        if($effective['program_id']!==$program->id){
        if($decimal->compare($this->amount,(string)$program->min_amount)<0){$this->addError('amount','Минимальная сумма для '.$program->name.' — '.\App\Support\MoneyFormatter::format($program->min_amount,0).' '.$program->currency);return null;}
        if($program->max_amount!==null&&$decimal->compare($this->amount,(string)$program->max_amount)>0){$this->addError('amount','Сумма превышает максимальный диапазон программы '.$program->name.'.');return null;}
        }
        return $program;
    }

    private function availableIndividualVersion(): ?InvestorInvestmentTermVersion
    {
        return InvestorInvestmentTermVersion::query()
            ->whereHas('term', fn ($query) => $query->whereKey($this->individualTermId)->where('investor_id', auth()->id())->active())
            ->validForDate(Carbon::today())->where('currency', $this->account()->currency)
            ->latest('valid_from')->latest('id')->first();
    }

    private function availableProgram(): ?InvestmentProgram
    {
        return InvestmentProgram::query()->whereKey($this->investmentProgramId)->where('status','active')->where('currency',$this->account()->currency)->first();
    }

    private function isDecimalAmount(string $amount): bool
    {
        return preg_match('/^\d+(?:\.\d{1,8})?$/', $amount) === 1;
    }
}
