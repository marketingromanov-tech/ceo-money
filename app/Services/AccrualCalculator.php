<?php

namespace App\Services;

use InvalidArgumentException;

class AccrualCalculator
{
    public function calculate(string $principal, string $monthlyRate, int $daysInMonth): string
    {
        if ($daysInMonth < 1) {
            throw new InvalidArgumentException('Days in month must be greater than zero.');
        }

        $principalInteger = $this->toScaledInteger($principal, 8, false);
        $rateInteger = $this->toScaledInteger($monthlyRate, 4, false);
        $product = $this->multiplyUnsigned($principalInteger, $rateInteger);

        // (principal / 10^8) * (rate / 10^4) / 100 / days, returned at scale 8.
        return $this->formatScaled(
            $this->divideAndRoundUnsigned($product, 1_000_000 * $daysInMonth),
            8,
        );
    }

    public function add(string $left, string $right): string
    {
        $leftInteger = $this->toScaledInteger($left, 8, true);
        $rightInteger = $this->toScaledInteger($right, 8, true);

        return $this->formatScaled($this->addSigned($leftInteger, $rightInteger), 8);
    }

    public function subtract(string $left, string $right): string
    {
        $right = trim($right);
        $negativeRight = match ($right[0] ?? '') {
            '-' => substr($right, 1),
            '+' => '-'.substr($right, 1),
            default => '-'.$right,
        };

        return $this->add($left, $negativeRight);
    }

    public function compare(string $left, string $right): int
    {
        $difference = $this->toScaledInteger($this->subtract($left, $right), 8, true);

        return $difference === '0' ? 0 : (str_starts_with($difference, '-') ? -1 : 1);
    }

    public function divideByInteger(string $amount, int $divisor): string
    {
        if ($divisor < 1) {
            throw new InvalidArgumentException('Divisor must be greater than zero.');
        }

        $scaled = $this->toScaledInteger($amount, 8, true);
        $negative = str_starts_with($scaled, '-');
        $quotient = $this->divideAndRoundUnsigned(ltrim($scaled, '-'), $divisor);

        return $this->formatScaled($negative && $quotient !== '0' ? '-'.$quotient : $quotient, 8);
    }

    private function toScaledInteger(string $value, int $scale, bool $allowNegative): string
    {
        $value = trim($value);

        if (! preg_match('/^([+-]?)(\d+)(?:\.(\d+))?$/', $value, $matches)) {
            throw new InvalidArgumentException("Invalid decimal value: {$value}");
        }

        $negative = $matches[1] === '-';

        if ($negative && ! $allowNegative) {
            throw new InvalidArgumentException('Negative values are not supported for this calculation.');
        }

        $fraction = $matches[3] ?? '';

        if (strlen($fraction) > $scale && trim(substr($fraction, $scale), '0') !== '') {
            throw new InvalidArgumentException("Decimal value has more than {$scale} fractional digits.");
        }

        $digits = ltrim($matches[2].str_pad(substr($fraction, 0, $scale), $scale, '0'), '0');
        $digits = $digits === '' ? '0' : $digits;

        return $negative && $digits !== '0' ? '-'.$digits : $digits;
    }

    private function multiplyUnsigned(string $left, string $right): string
    {
        if ($left === '0' || $right === '0') {
            return '0';
        }

        $result = array_fill(0, strlen($left) + strlen($right), 0);

        for ($i = strlen($left) - 1; $i >= 0; $i--) {
            for ($j = strlen($right) - 1; $j >= 0; $j--) {
                $position = $i + $j + 1;
                $value = ((int) $left[$i] * (int) $right[$j]) + $result[$position];
                $result[$position] = $value % 10;
                $result[$position - 1] += intdiv($value, 10);
            }
        }

        return ltrim(implode('', $result), '0') ?: '0';
    }

    private function divideAndRoundUnsigned(string $dividend, int $divisor): string
    {
        $quotient = '';
        $remainder = 0;

        foreach (str_split($dividend) as $digit) {
            $current = ($remainder * 10) + (int) $digit;
            $quotient .= (string) intdiv($current, $divisor);
            $remainder = $current % $divisor;
        }

        $quotient = ltrim($quotient, '0') ?: '0';

        if ($remainder * 2 >= $divisor) {
            $quotient = $this->addUnsigned($quotient, '1');
        }

        return $quotient;
    }

    private function addSigned(string $left, string $right): string
    {
        $leftNegative = str_starts_with($left, '-');
        $rightNegative = str_starts_with($right, '-');
        $leftAbsolute = ltrim($left, '-');
        $rightAbsolute = ltrim($right, '-');

        if ($leftNegative === $rightNegative) {
            $sum = $this->addUnsigned($leftAbsolute, $rightAbsolute);

            return $leftNegative && $sum !== '0' ? '-'.$sum : $sum;
        }

        $comparison = $this->compareUnsigned($leftAbsolute, $rightAbsolute);

        if ($comparison === 0) {
            return '0';
        }

        $leftIsLarger = $comparison > 0;
        $difference = $leftIsLarger
            ? $this->subtractUnsigned($leftAbsolute, $rightAbsolute)
            : $this->subtractUnsigned($rightAbsolute, $leftAbsolute);
        $negative = $leftIsLarger ? $leftNegative : $rightNegative;

        return $negative ? '-'.$difference : $difference;
    }

    private function addUnsigned(string $left, string $right): string
    {
        $leftIndex = strlen($left) - 1;
        $rightIndex = strlen($right) - 1;
        $carry = 0;
        $result = '';

        while ($leftIndex >= 0 || $rightIndex >= 0 || $carry > 0) {
            $sum = ($leftIndex >= 0 ? (int) $left[$leftIndex--] : 0)
                + ($rightIndex >= 0 ? (int) $right[$rightIndex--] : 0)
                + $carry;
            $result = ($sum % 10).$result;
            $carry = intdiv($sum, 10);
        }

        return $result;
    }

    private function subtractUnsigned(string $left, string $right): string
    {
        $right = str_pad($right, strlen($left), '0', STR_PAD_LEFT);
        $borrow = 0;
        $result = '';

        for ($i = strlen($left) - 1; $i >= 0; $i--) {
            $digit = (int) $left[$i] - (int) $right[$i] - $borrow;
            $borrow = $digit < 0 ? 1 : 0;
            $result = ($digit < 0 ? $digit + 10 : $digit).$result;
        }

        return ltrim($result, '0') ?: '0';
    }

    private function compareUnsigned(string $left, string $right): int
    {
        return strlen($left) <=> strlen($right) ?: strcmp($left, $right);
    }

    private function formatScaled(string $integer, int $scale): string
    {
        $negative = str_starts_with($integer, '-');
        $digits = ltrim($integer, '-');
        $digits = str_pad($digits, $scale + 1, '0', STR_PAD_LEFT);
        $formatted = substr($digits, 0, -$scale).'.'.substr($digits, -$scale);

        return $negative && trim($digits, '0') !== '' ? '-'.$formatted : $formatted;
    }
}
