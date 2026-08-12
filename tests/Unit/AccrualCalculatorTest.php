<?php

namespace Tests\Unit;

use App\Services\AccrualCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AccrualCalculatorTest extends TestCase
{
    public function test_it_divides_decimal_strings_by_integer_without_float(): void
    {
        $calculator = new AccrualCalculator();

        $this->assertSame('3.33333333', $calculator->divideByInteger('10.00000000', 3));
        $this->assertSame('0.00000001', $calculator->divideByInteger('0.00000002', 2));
        $this->assertSame('-2.50000000', $calculator->divideByInteger('-5.00000000', 2));
    }

    private AccrualCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new AccrualCalculator;
    }

    #[DataProvider('calculationCases')]
    public function test_it_calculates_exact_decimal_results(
        string $principal,
        string $rate,
        int $days,
        string $expected,
    ): void {
        $result = $this->calculator->calculate($principal, $rate, $days);

        $this->assertSame($expected, $result);
        $this->assertMatchesRegularExpression('/^-?\d+\.\d{8}$/', $result);
    }

    public static function calculationCases(): array
    {
        return [
            'zero principal' => ['0', '3.0000', 31, '0.00000000'],
            'zero rate' => ['10000.00000000', '0', 31, '0.00000000'],
            'minimum principal' => ['0.00000001', '2.3333', 31, '0.00000000'],
            'large principal and fractional rate' => ['9999999999.99999999', '2.3333', 31, '7526774.19354839'],
            'leap-year February' => ['10000', '3', 29, '10.34482759'],
            'thirty-day month' => ['10000', '3', 30, '10.00000000'],
            'thirty-one-day month' => ['10000', '3', 31, '9.67741935'],
            'rounds down from ninth digit' => ['1', '1.0000', 31, '0.00032258'],
            'rounds up half-up from ninth digit' => ['1', '1.0002', 31, '0.00032265'],
            'product exceeds PHP integer range' => ['9999999999.99999999', '9999.9999', 30, '33333332999.99999997'],
        ];
    }

    #[DataProvider('adjustmentCases')]
    public function test_it_applies_signed_adjustments_exactly(
        string $calculated,
        string $adjustment,
        string $expected,
    ): void {
        $result = $this->calculator->add($calculated, $adjustment);

        $this->assertSame($expected, $result);
        $this->assertMatchesRegularExpression('/^-?\d+\.\d{8}$/', $result);
    }

    public static function adjustmentCases(): array
    {
        return [
            'positive adjustment' => ['9.67741935', '1.25000000', '10.92741935'],
            'negative adjustment' => ['9.67741935', '-1.25000000', '8.42741935'],
            'negative final amount' => ['1.00000000', '-2.50000000', '-1.50000000'],
        ];
    }
}
