<?php
declare(strict_types=1);

namespace BPMedical\Booking\Core;

final class FixedClock implements Clock
{
    private int $now;

    public function __construct(int $now)
    {
        $this->now = $now;
    }

    public function now(): int
    {
        return $this->now;
    }

    public function set(int $now): void
    {
        $this->now = $now;
    }
}
