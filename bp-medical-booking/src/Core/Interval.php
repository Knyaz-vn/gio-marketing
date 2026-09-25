<?php
declare(strict_types=1);

namespace BPMedical\Booking\Core;

/** Напіввідкритий інтервал часу [start, end) у unix-секундах з необов'язковою локацією. */
final class Interval
{
    public int $start;
    public int $end;
    public ?string $locationId;

    public function __construct(int $start, int $end, ?string $locationId = null)
    {
        $this->start = $start;
        $this->end = $end;
        $this->locationId = $locationId;
    }

    public function length(): int
    {
        return $this->end - $this->start;
    }

    public function overlaps(int $start, int $end): bool
    {
        return $this->start < $end && $start < $this->end;
    }

    public function contains(int $start, int $end): bool
    {
        return $this->start <= $start && $end <= $this->end;
    }
}
