<?php

namespace App\Services;

use App\Models\InvestmentAccount;
use App\Models\InvestmentLot;
use App\Models\InvestmentProgram;
use App\Models\InvestmentProgramVersion;
use App\Models\InvestmentTerm;
use App\Models\User;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;

class InvestmentProgramService
{
    public function __construct(private readonly AccrualCalculator $decimal, private readonly AuditLogService $audit) {}

    public function activeVersion(InvestmentProgram $program, Carbon $date): ?InvestmentProgramVersion
    {
        return $program->versions()->activeOn($date)->latest('valid_from')->latest('id')->first();
    }

    public function matching(string $amount, string $currency, Carbon $date)
    {
        return InvestmentProgram::query()->where('status', 'active')->where('currency', $currency)
            ->where('min_amount', '<=', $amount)->where(fn ($q) => $q->whereNull('max_amount')->orWhere('max_amount', '>=', $amount))
            ->whereHas('versions', fn ($q) => $q->activeOn($date))->with(['versions' => fn ($q) => $q->activeOn($date)->latest('valid_from')->latest('id')])
            ->orderBy('min_amount')->get();
    }

    public function createVersion(InvestmentProgram $program, string $rate, int $months, Carbon $validFrom, ?User $actor): InvestmentProgramVersion
    {
        return $this->persistVersion($program, $rate, $months, $validFrom, $actor, 'investment_program.version_created');
    }

    public function updateConditions(InvestmentProgram $program, string $rate, int $months, Carbon $validFrom, ?User $actor): InvestmentProgramVersion
    {
        $current = $this->activeVersion($program, Carbon::today());
        if ($current !== null && $this->decimal->compare((string) $current->monthly_rate, $rate) === 0 && $current->lock_months === $months) {
            return $current;
        }

        return $this->persistVersion($program, $rate, $months, $validFrom, $actor, 'investment_program.version_updated');
    }

    private function persistVersion(InvestmentProgram $program, string $rate, int $months, Carbon $validFrom, ?User $actor, string $action): InvestmentProgramVersion
    {
        return DB::transaction(function () use ($program, $rate, $months, $validFrom, $actor, $action) {
            $program = InvestmentProgram::lockForUpdate()->findOrFail($program->id);
            $previous = $program->versions()->whereDate('valid_from', '<', $validFrom)->where(fn ($q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $validFrom))->latest('valid_from')->latest('id')->first();
            if ($previous) $previous->update(['valid_to' => $validFrom->copy()->subDay()]);
            $version = $program->versions()->create(['monthly_rate' => $rate, 'lock_months' => $months, 'valid_from' => $validFrom, 'created_by' => $actor?->id]);
            $this->audit->log($action, $program, $actor, $previous?->only(['monthly_rate', 'lock_months', 'valid_from', 'valid_to']), ['program_id' => $program->id, 'version_id' => $version->id, 'admin_id' => $actor?->id, 'monthly_rate' => $rate, 'lock_months' => $months, 'valid_from' => $validFrom->toDateString()]);
            return $version;
        });
    }

    public function termFor(InvestmentProgram $program, InvestmentAccount $account, Carbon $date, ?User $actor): InvestmentTerm
    {
        $version = $this->activeVersion($program, $date) ?? throw new DomainException('У программы нет действующих условий на выбранную дату.');
        return InvestmentTerm::updateOrCreate(['investment_account_id' => $account->id, 'investment_lot_id' => null, 'valid_from' => $version->valid_from], ['monthly_rate' => $version->monthly_rate, 'lock_months' => $version->lock_months, 'minimum_balance' => '0', 'partial_withdrawal_allowed' => $program->is_partial_withdrawal_allowed, 'minimum_dividend_withdrawal' => '0', 'valid_to' => $version->valid_to, 'created_by' => $actor?->id]);
    }

    public function assertAmount(InvestmentProgram $program, string $amount, string $currency): void
    {
        if ($program->status !== 'active' || $program->currency !== $currency || $this->decimal->compare($amount, (string) $program->min_amount) < 0 || ($program->max_amount !== null && $this->decimal->compare($amount, (string) $program->max_amount) > 0)) throw new DomainException('Сумма не соответствует диапазону выбранной инвестиционной программы.');
    }

    public function snapshot(InvestmentLot $lot, InvestmentProgram $program, InvestmentProgramVersion $version): void
    {
        $lot->update(['investment_program_id' => $program->id, 'investment_program_name_snapshot' => $program->name, 'investment_program_version_snapshot' => 'v'.$program->versions()->where('id', '<=', $version->id)->count()]);
    }
}
