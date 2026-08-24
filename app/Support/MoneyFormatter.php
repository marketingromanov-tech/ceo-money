<?php

namespace App\Support;

class MoneyFormatter
{
    public static function format(string|int|null $value, int $scale = 2): string
    {
        $value = trim((string) ($value ?? '0'));
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '+-');
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $whole = ltrim($whole, '0') ?: '0';
        $fraction = str_pad($fraction, $scale + 1, '0');
        $kept = substr($fraction, 0, $scale);

        if ((int) ($fraction[$scale] ?? '0') >= 5) {
            [$whole, $kept] = self::increment($whole, $kept);
        }

        $whole = preg_replace('/\B(?=(\d{3})+(?!\d))/', ' ', $whole);
        $formatted = $scale > 0 ? $whole.'.'.$kept : $whole;

        return $negative && trim(str_replace([' ', '.'], '', $formatted), '0') !== '' ? '-'.$formatted : $formatted;
    }

    public static function input(string|int|null $value, int $scale = 2): string
    {
        return str_replace(' ', '', self::format($value, $scale));
    }

    private static function increment(string $whole, string $fraction): array
    {
        $digits = $whole.$fraction;
        $carry = 1;

        for ($index = strlen($digits) - 1; $index >= 0 && $carry; $index--) {
            $digit = (int) $digits[$index] + $carry;
            $digits[$index] = (string) ($digit % 10);
            $carry = intdiv($digit, 10);
        }

        if ($carry) {
            $digits = '1'.$digits;
        }

        return [substr($digits, 0, strlen($digits) - strlen($fraction)) ?: '0', substr($digits, -strlen($fraction))];
    }
}
