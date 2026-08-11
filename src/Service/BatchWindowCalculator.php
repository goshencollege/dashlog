<?php

namespace App\Service;

/**
 * Aligns timestamps to the fixed-size, clock-aligned windows log lines are
 * batched into before being written as a single object (see KeyScheme).
 */
class BatchWindowCalculator
{
    public function __construct(
        private readonly int $windowMinutes = 15,
    ) {
        if ($windowMinutes < 1 || 60 % $windowMinutes !== 0) {
            throw new \InvalidArgumentException('windowMinutes must evenly divide 60.');
        }
    }

    public function windowStartFor(\DateTimeImmutable $at): \DateTimeImmutable
    {
        $utc = $at->setTimezone(new \DateTimeZone('UTC'));
        $flooredMinute = intdiv((int) $utc->format('i'), $this->windowMinutes) * $this->windowMinutes;

        return $utc->setTime((int) $utc->format('H'), $flooredMinute, 0);
    }

    public function windowEndFor(\DateTimeImmutable $windowStart): \DateTimeImmutable
    {
        return $windowStart->modify("+{$this->windowMinutes} minutes");
    }
}
