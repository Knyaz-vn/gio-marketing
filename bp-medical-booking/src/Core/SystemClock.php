<?php
declare(strict_types=1);

namespace BPMedical\Booking\Core;

final class SystemClock implements Clock
{
    public function now(): int
    {
        return time();
    }
}
