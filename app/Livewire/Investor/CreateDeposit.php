<?php

namespace App\Livewire\Investor;

use App\Livewire\Investor\Concerns\InteractsWithInvestorData;
use App\Models\InvestmentProgram;
use App\Services\AccrualCalculator;
use App\Services\DepositRequestService;
use App\Services\InvestmentProgramService;
use Carbon\Carbon;
use DomainException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.investor')]
class CreateDeposit extends Component
{
    use InteractsWithInvestorData;

    public int $investmentProgramId;
    public string $amount = '';
    public int $step = 1;
    public ?int $createdRequestId = null;

    public function mount(): void
    {
        $this->investmentProgramId = (int) request()->query('investment_program_id', 0);
        $this->amount = trim((string) request()->query('amount', ''));
    }

    public function review(InvestmentProgramService $programs, AccrualCalculator $decimal): void
    {
        if ($this->validateSelection($programs, $decimal) !== null) $this->step = 2;
    }

    public function back(): void { $this->step = 1; $this->resetErrorBag(); }

    public function useMinimum(InvestmentProgramService $programs, AccrualCalculator $decimal): void
    {
        $program=$this->availableProgram();
        if($program===null){$this->addError('program','Программа больше недоступна');return;}
        $this->amount=(string)$program->min_amount;
        $this->review($programs,$decimal);
    }

    public function submit(DepositRequestService $deposits, InvestmentProgramService $programs, AccrualCalculator $decimal): void
    {
        $program = $this->validateSelection($programs, $decimal);
        if ($program === null) return;
        try {
            $request = $deposits->create($this->account(), $this->amount, actor: auth()->user(), program: $program);
            $this->createdRequestId = $request->id;
            $this->step = 3;
        } catch (DomainException $exception) {
            $this->addError('amount', $exception->getMessage());
            $this->step = 1;
        }
    }

    public function render(InvestmentProgramService $programs, AccrualCalculator $decimal)
    {
        $program = $this->availableProgram();
        $version = $program ? $programs->activeVersion($program, Carbon::today()) : null;
        $projection = null;$belowMinimum=false;$aboveMaximum=false;$shortfall=null;$suggestedPrograms=collect();
        $validAmount=preg_match('/^\d+(?:\.\d{1,8})?$/',$this->amount)===1;
        if($program&&$validAmount){$belowMinimum=$decimal->compare($this->amount,(string)$program->min_amount)<0;$aboveMaximum=$program->max_amount!==null&&$decimal->compare($this->amount,(string)$program->max_amount)>0;if($belowMinimum)$shortfall=$decimal->subtract((string)$program->min_amount,$this->amount);}
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
        if ($version && $validAmount && ! $belowMinimum && ! $aboveMaximum) {
            $monthly = $decimal->calculate($this->amount, (string) $version->monthly_rate, 1);
            $period = '0.00000000';
            for ($month=0;$month<$version->lock_months;$month++) $period=$decimal->add($period,$monthly);
            $projection=['monthly'=>$monthly,'period'=>$period];
        }
        return view('livewire.investor.create-deposit', compact('program','version','projection','belowMinimum','aboveMaximum','shortfall','suggestedPrograms'))->title('Новая заявка — CEO Money');
    }

    private function validateSelection(InvestmentProgramService $programs, AccrualCalculator $decimal): ?InvestmentProgram
    {
        $this->resetErrorBag();
        $program=$this->availableProgram();
        if($program===null||$programs->activeVersion($program,Carbon::today())===null){$this->addError('program','Программа больше недоступна');return null;}
        if(!preg_match('/^\d+(?:\.\d{1,8})?$/',$this->amount)){$this->addError('amount','Укажите корректную сумму инвестиции.');return null;}
        if($decimal->compare($this->amount,(string)$program->min_amount)<0){$this->addError('amount','Минимальная сумма для '.$program->name.' — '.\App\Support\MoneyFormatter::format($program->min_amount,0).' '.$program->currency);return null;}
        if($program->max_amount!==null&&$decimal->compare($this->amount,(string)$program->max_amount)>0){$this->addError('amount','Сумма превышает максимальный диапазон программы '.$program->name.'.');return null;}
        return $program;
    }

    private function availableProgram(): ?InvestmentProgram
    {
        return InvestmentProgram::query()->whereKey($this->investmentProgramId)->where('status','active')->where('currency',$this->account()->currency)->first();
    }
}
