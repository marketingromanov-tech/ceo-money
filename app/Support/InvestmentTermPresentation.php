<?php

namespace App\Support;

use App\Models\InvestmentTerm;
use Carbon\Carbon;

class InvestmentTermPresentation
{
    public static function rate(string $rate): string
    {
        [$whole, $fraction] = array_pad(explode('.', $rate, 2), 2, '');
        $fraction = str_pad($fraction, 4, '0');
        $hundredths = (int) substr($fraction, 0, 2);

        if ((int) $fraction[2] >= 5) {
            $hundredths++;
            if ($hundredths === 100) {
                $whole = (string) ((int) $whole + 1);
                $hundredths = 0;
            }
        }

        return $whole.'.'.str_pad((string) $hundredths, 2, '0', STR_PAD_LEFT).'%';
    }

    public static function status(InvestmentTerm $term, Carbon $today): string
    {
        if ($term->valid_from->gt($today)) {
            return 'Будет действовать';
        }

        return $term->valid_to !== null && $term->valid_to->lt($today) ? 'Завершено' : 'Действует';
    }

    public static function lockMonths(int $months): string
    {
        $lastTwo = $months % 100;
        $last = $months % 10;
        $word = $lastTwo >= 11 && $lastTwo <= 14
            ? 'месяцев'
            : match ($last) { 1 => 'месяц', 2, 3, 4 => 'месяца', default => 'месяцев' };

        return $months.' '.$word;
    }
}
