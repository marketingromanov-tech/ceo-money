<?php

namespace App\Services;

use App\Models\InvestmentProgram;
use App\Models\InvestorInvestmentTermVersion;
use App\Models\User;
use Carbon\CarbonInterface;
use Carbon\Carbon;
use DomainException;

class EffectiveInvestmentTermsResolver
{
    public function __construct(private readonly AccrualCalculator $decimal, private readonly InvestmentProgramService $programs) {}

    /** @return array{source:string,rate:string,term_months:int,lock_days:?int,partial_withdrawal:bool,individual_term_id?:int,program_id?:int,program_version_id?:int} */
    public function resolve(User $investor, string $currency, string $amount, CarbonInterface|string $date): array
    {
        if ($investor->role !== 'investor') throw new DomainException('Индивидуальные условия доступны только инвестору.');
        if (! preg_match('/^\d+(?:\.\d{1,8})?$/', $amount) || $this->decimal->compare($amount, '0') <= 0) throw new DomainException('Сумма инвестиции должна быть больше 0.');
        $dateString = $date instanceof CarbonInterface ? $date->toDateString() : $date;
        $resolvedDate = Carbon::parse($dateString);
        $individual = InvestorInvestmentTermVersion::query()
            ->whereHas('term', fn ($query) => $query->where('investor_id', $investor->id)->active())
            ->validForDate($dateString)->where('currency', $currency)
            ->where(fn ($query) => $query->whereNull('min_amount')->orWhere('min_amount', '<=', $amount))
            ->where(fn ($query) => $query->whereNull('max_amount')->orWhere('max_amount', '>=', $amount))
            ->latest('valid_from')->latest('id')->first();
        if ($individual) return ['source' => 'individual', 'rate' => (string) $individual->monthly_rate, 'term_months' => $individual->term_months, 'lock_days' => $individual->lock_days, 'partial_withdrawal' => $individual->partial_withdrawal, 'individual_term_id' => $individual->investor_investment_term_id, 'individual_term_version_id' => $individual->id];

        $program = InvestmentProgram::query()->where('status', 'active')->where('currency', $currency)
            ->where('min_amount', '<=', $amount)->where(fn ($query) => $query->whereNull('max_amount')->orWhere('max_amount', '>=', $amount))
            ->whereHas('versions', fn ($query) => $query->activeOn($resolvedDate))->orderByDesc('min_amount')->first();
        if (! $program || ! ($version = $this->programs->activeVersion($program, $resolvedDate))) throw new DomainException('Для указанной суммы нет действующих инвестиционных условий.');

        return ['source' => 'program', 'rate' => (string) $version->monthly_rate, 'term_months' => $version->lock_months, 'lock_days' => null, 'partial_withdrawal' => $program->is_partial_withdrawal_allowed, 'program_id' => $program->id, 'program_version_id' => $version->id];
    }
}
