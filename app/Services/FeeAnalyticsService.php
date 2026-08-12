<?php

namespace App\Services;

use App\Models\DepositRequest;
use App\Models\DividendCapitalization;
use App\Models\WithdrawalRequest;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class FeeAnalyticsService
{
    public function __construct(private readonly AccrualCalculator $decimal)
    {
    }

    public function today(): array
    {
        return $this->forPeriod(Carbon::today(), Carbon::today());
    }

    public function currentMonth(): array
    {
        $today = Carbon::today();

        return $this->forPeriod($today->copy()->startOfMonth(), $today->copy()->endOfMonth());
    }

    public function allTime(): array
    {
        return $this->summarize(null, null);
    }

    public function forPeriod(Carbon $from, Carbon $to): array
    {
        return $this->summarize($from->copy()->startOfDay(), $to->copy()->endOfDay());
    }

    public function breakdownByOperation(Carbon $from, Carbon $to): array
    {
        $rows = $this->completedRows($from->copy()->startOfDay(), $to->copy()->endOfDay());
        $result = [];

        foreach ($rows->groupBy('operation') as $operation => $operationRows) {
            $result[$operation] = $this->totals($operationRows);
        }

        return $result;
    }

    public function breakdownByCurrency(Carbon $from, Carbon $to): array
    {
        $summary = $this->forPeriod($from, $to);
        $currencies = collect($summary)->flatMap(fn (array $values) => array_keys($values))->unique()->sort();

        return $currencies->mapWithKeys(fn (string $currency) => [$currency => [
            'platform_revenue' => $summary['platform_revenue'][$currency] ?? '0.00000000',
            'provider_cost' => $summary['provider_cost'][$currency] ?? '0.00000000',
            'unclassified' => $summary['unclassified'][$currency] ?? '0.00000000',
        ]])->all();
    }

    public function latestCompleted(int $limit = 10): Collection
    {
        return $this->completedRows(null, null)->sortByDesc('completed_at')->take($limit)->values();
    }

    private function summarize(?Carbon $from, ?Carbon $to): array
    {
        return $this->totals($this->completedRows($from, $to));
    }

    private function totals(Collection $rows): array
    {
        $result = ['platform_revenue' => [], 'provider_cost' => [], 'unclassified' => []];

        foreach ($rows as $row) {
            $kind = match ($row['economic_type']) {
                'platform_fee' => 'platform_revenue',
                'provider_cost' => 'provider_cost',
                default => 'unclassified',
            };
            $currency = $row['currency'];
            $result[$kind][$currency] = $this->decimal->add(
                $result[$kind][$currency] ?? '0.00000000',
                $row['fee_amount'],
            );
        }

        foreach ($result as &$currencies) {
            ksort($currencies);
        }

        return $result;
    }

    private function completedRows(?Carbon $from, ?Carbon $to): Collection
    {
        $deposits = $this->dateRange(DepositRequest::query()->where('status', 'confirmed'), 'confirmed_at', $from, $to)
            ->get()->map(fn (DepositRequest $request) => $this->row('deposit', $request, $request->confirmed_at));
        $withdrawals = $this->dateRange(WithdrawalRequest::query()->where('status', 'paid'), 'paid_at', $from, $to)
            ->get()->map(fn (WithdrawalRequest $request) => $this->row(
                $request->type === 'capital' ? 'capital_withdrawal' : 'dividend_withdrawal', $request, $request->paid_at,
            ));
        $capitalizations = $this->dateRange(DividendCapitalization::query()->where('status', 'completed'), 'capitalized_at', $from, $to)
            ->get()->map(fn (DividendCapitalization $item) => $this->row('capitalization', $item, $item->capitalized_at));

        return $deposits->concat($withdrawals)->concat($capitalizations);
    }

    private function dateRange(Builder $query, string $column, ?Carbon $from, ?Carbon $to): Builder
    {
        return $from && $to ? $query->whereBetween($column, [$from, $to]) : $query;
    }

    private function row(string $operation, object $model, mixed $completedAt): array
    {
        return [
            'operation' => $operation,
            'record_type' => $model::class,
            'record_id' => $model->id,
            'fee_amount' => (string) $model->fee_amount,
            'currency' => $model->currency,
            'payer' => $model->fee_payer,
            'economic_type' => $model->fee_economic_type_snapshot,
            'completed_at' => $completedAt,
        ];
    }
}
