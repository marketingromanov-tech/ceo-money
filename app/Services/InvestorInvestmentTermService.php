<?php

namespace App\Services;

use App\Models\InvestorInvestmentTerm;
use App\Models\InvestorInvestmentTermVersion;
use App\Models\User;
use Carbon\CarbonInterface;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;

class InvestorInvestmentTermService
{
    public function __construct(private readonly AuditLogService $audit, private readonly AccrualCalculator $decimal) {}

    public function create(User $investor, array $attributes, User $admin): InvestorInvestmentTerm
    {
        $this->assertAdmin($admin);
        if ($investor->role !== 'investor') throw new DomainException('Индивидуальные условия можно назначить только инвестору.');
        $this->assertAttributes($attributes);

        return DB::transaction(function () use ($investor, $attributes, $admin): InvestorInvestmentTerm {
            $term = InvestorInvestmentTerm::create([...$attributes, 'investor_id' => $investor->id, 'created_by_admin_id' => $admin->id]);
            $version = $term->versions()->firstOrFail();
            $this->audit->log('individual_term.created', $term, $admin, null, $term->only([
                'investor_id', 'currency', 'min_amount', 'max_amount', 'monthly_rate', 'term_months',
                'lock_days', 'partial_withdrawal', 'starts_at', 'ends_at', 'status',
            ]));
            $this->audit->log('individual_term.version_created', $version, $admin, null, $this->versionAuditData($version));

            return $term;
        });
    }

    public function update(InvestorInvestmentTerm $term, array $attributes, User $admin): InvestorInvestmentTerm
    {
        $this->assertAdmin($admin);
        $this->assertAttributes($attributes);

        return DB::transaction(function () use ($term, $attributes, $admin): InvestorInvestmentTerm {
            $term = InvestorInvestmentTerm::query()->lockForUpdate()->findOrFail($term->id);
            $latest = $term->versions()->lockForUpdate()->firstOrFail();
            $validFrom = Carbon::parse($attributes['starts_at'])->startOfDay();
            if ($validFrom->lte($latest->valid_from)) {
                throw new DomainException('Дата начала новой версии должна быть позже даты начала предыдущей версии.');
            }
            if ($term->versions()->whereDate('valid_from', '<=', $attributes['ends_at'] ?: '9999-12-31')
                ->where(fn ($query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $validFrom))->whereKeyNot($latest->id)->exists()) {
                throw new DomainException('Периоды действия версий не могут пересекаться.');
            }
            $old = $this->versionAuditData($latest);
            $oldStatus = $term->status;
            $latest->update(['valid_to' => $validFrom->copy()->subDay()->toDateString()]);
            $version = $term->versions()->create($this->versionAttributes($attributes, $admin));
            if (array_key_exists('status', $attributes) && $term->status !== $attributes['status']) $term->update(['status' => $attributes['status']]);
            $new = $this->versionAuditData($version);
            $this->audit->log('individual_term.updated', $term, $admin, $old, $new);
            $this->audit->log('individual_term.version_updated', $version, $admin, $old, $new);
            if ($oldStatus !== $term->status) {
                $this->audit->log($term->status === 'active' ? 'individual_term.activated' : 'individual_term.deactivated', $term, $admin, ['status' => $oldStatus], ['status' => $term->status]);
            }

            return $term;
        });
    }

    public function createVersion(InvestorInvestmentTerm $term, array $attributes, User $admin): InvestorInvestmentTermVersion
    {
        $this->update($term, $attributes, $admin);

        return $term->versions()->firstOrFail();
    }

    public function deactivate(InvestorInvestmentTerm $term, User $admin): InvestorInvestmentTerm
    {
        $this->assertAdmin($admin);
        if ($term->status === 'inactive') return $term;
        $term->update(['status' => 'inactive']);
        $this->audit->log('individual_term.deactivated', $term, $admin, ['status' => 'active'], ['status' => 'inactive']);

        return $term;
    }

    public function currentFor(User $investor, string $currency, CarbonInterface|string $date): ?InvestorInvestmentTerm
    {
        return $investor->investorInvestmentTerms()->active()->validForDate($date)
            ->where('currency', $currency)->latest('starts_at')->latest('id')->first();
    }

    private function assertAdmin(User $admin): void
    {
        if ($admin->role !== 'admin' || ! $admin->is_active) throw new DomainException('Назначать условия может только активный администратор.');
    }

    private function assertAttributes(array $attributes): void
    {
        $rate = (string) ($attributes['monthly_rate'] ?? '');
        if (! preg_match('/^\d+(?:\.\d{1,4})?$/', $rate) || $this->isZero($rate)) throw new DomainException('Ставка должна быть больше 0.');
        if (filter_var($attributes['term_months'] ?? null, FILTER_VALIDATE_INT) === false || (int) $attributes['term_months'] <= 0) throw new DomainException('Срок должен быть больше 0.');
        if (empty($attributes['starts_at'])) throw new DomainException('Дата начала обязательна.');
        if (! empty($attributes['ends_at']) && Carbon::parse($attributes['ends_at'])->lt(Carbon::parse($attributes['starts_at']))) throw new DomainException('Дата окончания не может быть раньше даты начала.');
        foreach (['min_amount', 'max_amount'] as $field) if (($attributes[$field] ?? null) !== null && ! preg_match('/^\d+(?:\.\d{1,8})?$/', (string) $attributes[$field])) throw new DomainException('Сумма должна быть неотрицательным decimal-числом.');
        if (($attributes['min_amount'] ?? null) !== null && ($attributes['max_amount'] ?? null) !== null && $this->decimal->compare((string) $attributes['max_amount'], (string) $attributes['min_amount']) < 0) throw new DomainException('Максимальная сумма не может быть меньше минимальной.');
    }

    private function isZero(string $decimal): bool
    {
        return trim(str_replace(['0', '.'], '', $decimal)) === '';
    }

    private function versionAttributes(array $attributes, User $admin): array
    {
        return [
            'currency' => $attributes['currency'], 'min_amount' => $attributes['min_amount'] ?? null,
            'max_amount' => $attributes['max_amount'] ?? null, 'monthly_rate' => $attributes['monthly_rate'],
            'term_months' => (int) $attributes['term_months'], 'lock_days' => $attributes['lock_days'] ?? null,
            'partial_withdrawal' => (bool) ($attributes['partial_withdrawal'] ?? false),
            'valid_from' => $attributes['starts_at'], 'valid_to' => $attributes['ends_at'] ?? null,
            'created_by_admin_id' => $admin->id, 'notes' => $attributes['notes'] ?? null,
        ];
    }

    private function versionAuditData(InvestorInvestmentTermVersion $version): array
    {
        return $version->only([
            'investor_investment_term_id', 'currency', 'min_amount', 'max_amount', 'monthly_rate',
            'term_months', 'lock_days', 'partial_withdrawal', 'valid_from', 'valid_to', 'notes',
        ]);
    }
}
