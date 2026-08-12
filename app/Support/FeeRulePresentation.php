<?php

namespace App\Support;

use App\Models\FeeRule;
use Carbon\Carbon;

class FeeRulePresentation
{
    public static function operation(string $value): string
    {
        return [
            'deposit'=>'Пополнение', 'dividend_withdrawal'=>'Вывод дивидендов',
            'capital_withdrawal'=>'Вывод капитала', 'capitalization'=>'Капитализация дивидендов',
            'manual_adjustment'=>'Ручная корректировка',
        ][$value] ?? 'Операция';
    }

    public static function feeType(string $value): string
    {
        return ['fixed'=>'Фиксированная', 'percent'=>'Процентная', 'mixed'=>'Смешанная'][$value] ?? '—';
    }

    public static function payer(string $value): string
    {
        return ['investor'=>'Инвестор', 'company'=>'Компания'][$value] ?? '—';
    }

    public static function economicType(?string $value): string
    {
        return match ($value) {
            'platform_fee' => 'Комиссия платформы',
            'provider_cost' => 'Расход провайдера / сети',
            default => 'Не классифицировано',
        };
    }

    public static function status(FeeRule $rule, ?Carbon $today = null): string
    {
        $today ??= Carbon::today();
        if (! $rule->is_active) return 'Неактивно';
        if ($rule->valid_from->gt($today)) return 'Запланировано';
        if ($rule->valid_to?->lt($today)) return 'Истекло';
        return 'Активно';
    }

    public static function commission(FeeRule $rule): string
    {
        $currency = $rule->currency ?: 'в валюте операции';
        $fixed = MoneyFormatter::format($rule->fixed_value).' '.$currency;
        $percent = MoneyFormatter::format($rule->percent_value).'%';
        return match ($rule->fee_type) { 'fixed'=>$fixed, 'percent'=>$percent, 'mixed'=>$fixed.' + '.$percent, default=>'—' };
    }
}
