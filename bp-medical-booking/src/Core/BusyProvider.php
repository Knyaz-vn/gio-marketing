<?php
declare(strict_types=1);

namespace BPMedical\Booking\Core;

/** Джерело зайнятості лікаря: події календаря + holds + активні записи. */
interface BusyProvider
{
    /** @return Interval[] */
    public function busy(string $doctorId, int $from, int $to, ?string $excludeHoldToken = null): array;
}
