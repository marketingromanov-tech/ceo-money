<?php

namespace App\Livewire\Investor\Concerns;

use App\Models\InvestmentAccount;
use Carbon\Carbon;

trait HasAccrualPeriod
{
    public string $periodMode = 'month';
    public ?string $periodFrom = null;
    public ?string $periodTo = null;

    public function selectPeriod(string $mode): void
    {
        abort_unless(in_array($mode, ['today', 'month', 'all', 'custom'], true), 422);
        $this->periodMode = $mode;
        $this->resetValidation(['periodFrom', 'periodTo']);
    }

    protected function periodDates(InvestmentAccount $account): array
    {
        $today = Carbon::today();

        if ($this->periodMode === 'custom') {
            if ($this->periodFrom === null || $this->periodTo === null || $this->periodFrom === '' || $this->periodTo === '') {
                return [$today->copy(), $today->copy()];
            }

            try {
                $from = Carbon::parse($this->periodFrom)->startOfDay();
                $to = Carbon::parse($this->periodTo)->startOfDay();
            } catch (\Throwable) {
                $this->addError('periodFrom', 'Укажите корректный диапазон дат.');

                return [$today->copy(), $today->copy()];
            }

            if ($to->gt($today)) {
                $this->addError('periodTo', 'Дата окончания не может быть позже сегодня.');

                return [$today->copy(), $today->copy()];
            }

            if ($from->gt($to)) {
                $this->addError('periodFrom', 'Дата начала должна быть не позже даты окончания.');

                return [$today->copy(), $today->copy()];
            }

            $this->resetValidation(['periodFrom', 'periodTo']);

            return [$from, $to];
        }

        return match ($this->periodMode) {
            'today' => [$today->copy(), $today->copy()],
            'all' => [Carbon::parse($account->opened_at ?? $account->created_at)->startOfDay(), $today],
            default => [$today->copy()->startOfMonth(), $today],
        };
    }

    protected function periodAccrual(InvestmentAccount $account, Carbon $from, Carbon $to): string
    {
        return (string) ($account->dailyAccruals()
            ->whereBetween('accrual_date', [$from->toDateString(), $to->toDateString()])
            ->sum('final_amount') ?: '0');
    }

    protected function accrualSeries(InvestmentAccount $account, Carbon $from, Carbon $to): array
    {
        $grouped = $account->dailyAccruals()
            ->selectRaw('accrual_date, SUM(final_amount) as total')
            ->whereBetween('accrual_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('accrual_date')->orderBy('accrual_date')->pluck('total', 'accrual_date');
        $series = [];

        for ($date = $from->copy(); $date->lte($to); $date->addDay()) {
            $series[] = ['date' => $date->toDateString(), 'amount' => (string) ($grouped[$date->toDateString()] ?? '0')];
        }

        return $series;
    }

    protected function chartPoints(array $series, int $width = 900, int $height = 220): string
    {
        if ($series === []) return '';
        $values = array_map(fn ($item) => $this->chartInteger($item['amount']), $series);
        $min = min($values); $max = max($values); $range = max(1, $max - $min); $count = max(1, count($values) - 1); $padding = 10;

        return collect($values)->map(fn ($value, $index) =>
            ($padding + intdiv($index * ($width - 2 * $padding), $count)).','.
            ($max === $min
                ? intdiv($height, 2)
                : $height - $padding - intdiv(($value - $min) * ($height - 2 * $padding), $range))
        )->implode(' ');
    }

    private function chartInteger(string $value): int
    {
        [$whole, $fraction] = array_pad(explode('.', ltrim($value, '+-'), 2), 2, '');
        $scaled = ltrim($whole.str_pad(substr($fraction, 0, 4), 4, '0'), '0') ?: '0';
        return strlen($scaled) > 17 ? PHP_INT_MAX : (int) $scaled;
    }
}
