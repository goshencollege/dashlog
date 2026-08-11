<?php

namespace App\Tests\Service;

use App\Service\BatchWindowCalculator;
use PHPUnit\Framework\TestCase;

class BatchWindowCalculatorTest extends TestCase
{
    public function testWindowStartFlooresToWindowBoundary(): void
    {
        $calculator = new BatchWindowCalculator(windowMinutes: 15);

        self::assertEquals(
            new \DateTimeImmutable('2026-08-11T14:15:00+00:00'),
            $calculator->windowStartFor(new \DateTimeImmutable('2026-08-11T14:22:59+00:00')),
        );
    }

    public function testWindowStartOnExactBoundaryIsUnchanged(): void
    {
        $calculator = new BatchWindowCalculator(windowMinutes: 15);

        self::assertEquals(
            new \DateTimeImmutable('2026-08-11T14:30:00+00:00'),
            $calculator->windowStartFor(new \DateTimeImmutable('2026-08-11T14:30:00+00:00')),
        );
    }

    public function testWindowStartNormalizesNonUtcTimezones(): void
    {
        $calculator = new BatchWindowCalculator(windowMinutes: 15);

        self::assertEquals(
            new \DateTimeImmutable('2026-08-11T14:15:00+00:00'),
            $calculator->windowStartFor(new \DateTimeImmutable('2026-08-11T10:22:59-04:00')),
        );
    }

    public function testWindowEndIsWindowMinutesAfterStart(): void
    {
        $calculator = new BatchWindowCalculator(windowMinutes: 15);
        $start = new \DateTimeImmutable('2026-08-11T14:15:00+00:00');

        self::assertEquals(new \DateTimeImmutable('2026-08-11T14:30:00+00:00'), $calculator->windowEndFor($start));
    }

    public function testConstructorRejectsWindowThatDoesNotDivide60(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new BatchWindowCalculator(windowMinutes: 7);
    }
}
