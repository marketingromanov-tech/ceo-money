<?php

namespace App\Services;

use App\Models\FeeRule;
use App\Models\Investor;
use Carbon\Carbon;
use DomainException;

class FeeCalculatorService
{
    public function __construct(private readonly AccrualCalculator $decimal)
    {
    }

    /** @return array{gross_amount:string,fee_amount:string,net_amount:string,payer:string,economic_type:?string,fee_rule_id:?int,percent_value_snapshot:string,fixed_value_snapshot:string} */
    public function calculate(
        string $operationType,
        Investor $investor,
        string $grossAmount,
        string $currency,
        Carbon $date,
    ): array {
        if (! in_array($operationType, FeeRule::OPERATION_TYPES, true)) {
            throw new DomainException('Unsupported fee operation type.');
        }

        if ($this->decimal->compare($grossAmount, '0') < 0) {
            throw new DomainException('Gross amount cannot be negative.');
        }

        $grossAmount = $this->decimal->add($grossAmount, '0');
        $rule = $this->findRule($operationType, $investor, $currency, $date);

        if ($rule === null) {
            return [
                'gross_amount' => $grossAmount,
                'fee_amount' => '0.00000000',
                'net_amount' => $grossAmount,
                'payer' => 'investor',
                'economic_type' => null,
                'fee_rule_id' => null,
                'percent_value_snapshot' => '0.0000',
                'fixed_value_snapshot' => '0.00000000',
            ];
        }

        $result = $this->calculateForRule($rule, $grossAmount);

        return array_merge($result, [
            'fee_rule_id' => $rule->id,
            'economic_type' => $rule->economic_type,
            'percent_value_snapshot' => $rule->percent_value,
            'fixed_value_snapshot' => $rule->fixed_value,
        ]);
    }

    /** @return array{gross_amount:string,fee_amount:string,net_amount:string,payer:string,economic_type:?string} */
    public function calculateForRule(FeeRule $rule, string $grossAmount): array
    {
        if ($this->decimal->compare($grossAmount, '0') < 0) {
            throw new DomainException('Gross amount cannot be negative.');
        }
        $grossAmount = $this->decimal->add($grossAmount, '0');
        $percentFee = $this->decimal->calculate($grossAmount, (string) $rule->percent_value, 1);
        $fee = match ($rule->fee_type) {
            'fixed' => $rule->fixed_value,
            'percent' => $percentFee,
            'mixed' => $this->decimal->add($percentFee, $rule->fixed_value),
            default => throw new DomainException('Unsupported fee type.'),
        };

        if ($rule->minimum_fee !== null && $this->decimal->compare($fee, $rule->minimum_fee) < 0) {
            $fee = $rule->minimum_fee;
        }

        if ($rule->maximum_fee !== null && $this->decimal->compare($fee, $rule->maximum_fee) > 0) {
            $fee = $rule->maximum_fee;
        }

        $fee = $this->decimal->add($fee, '0');
        $net = $rule->payer === 'company'
            ? $grossAmount
            : $this->decimal->subtract($grossAmount, $fee);

        if ($this->decimal->compare($net, '0') < 0) {
            $net = '0.00000000';
        }

        return [
            'gross_amount' => $grossAmount,
            'fee_amount' => $fee,
            'net_amount' => $net,
            'payer' => $rule->payer,
            'economic_type' => $rule->economic_type,
        ];
    }

    public function findRule(
        string $operationType,
        Investor $investor,
        string $currency,
        Carbon $date,
    ): ?FeeRule {
        return FeeRule::query()
            ->where('operation_type', $operationType)
            ->where('is_active', true)
            ->whereDate('valid_from', '<=', $date)
            ->where(fn ($query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date))
            ->where(fn ($query) => $query
                ->where(fn ($query) => $query->where('scope', 'investor')->where('investor_id', $investor->id))
                ->orWhere(fn ($query) => $query->where('scope', 'global')->whereNull('investor_id')))
            ->where(fn ($query) => $query->where('currency', $currency)->orWhereNull('currency'))
            ->orderByRaw("CASE WHEN scope = 'investor' THEN 0 ELSE 1 END")
            ->orderByRaw('CASE WHEN currency = ? THEN 0 ELSE 1 END', [$currency])
            ->latest('valid_from')
            ->latest('id')
            ->first();
    }
}
