<?php

namespace Tests\Unit;

use App\Services\Payroll\PayrollJournalCapacity;
use App\Services\Accounting\HistoricalDecimal;
use PHPUnit\Framework\TestCase;

final class PayrollJournalCapacityTest extends TestCase
{
    public function test_99_and_100_lines_preserve_the_existing_limit(): void
    {
        PayrollJournalCapacity::assert(99);
        PayrollJournalCapacity::assert(100);
        $this->addToAssertionCount(2);
    }

    public function test_101_lines_fail_before_posting(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('101');
        PayrollJournalCapacity::assert(101);
    }

    public function test_payroll_example_uses_exact_three_decimal_units(): void
    {
        $net = HistoricalDecimal::units('1000.000') + HistoricalDecimal::units('200.000')
            - HistoricalDecimal::units('200.250');
        $this->assertSame('999.750', HistoricalDecimal::format($net));
        $this->assertSame(HistoricalDecimal::units('999.75'), $net);
    }
}
