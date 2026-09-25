<?php
declare(strict_types=1);

namespace BPMedical\Booking\Core;

final class Slot
{
    public int $start;
    /** Кінець прийому (без буфера) */
    public int $end;
    /** Кінець зайнятості з буфером */
    public int $blockEnd;
    public string $locationId;

    public function __construct(int $start, int $end, int $blockEnd, string $locationId)
    {
        $this->start = $start;
        $this->end = $end;
        $this->blockEnd = $blockEnd;
        $this->locationId = $locationId;
    }

    /** @return array<string, mixed> публічне представлення */
    public function toArray(): array
    {
        return [
            'start' => Tz::rfc3339Local($this->start),
            'date' => Tz::date($this->start),
            'time' => Tz::time($this->start),
            'end_time' => Tz::time($this->end),
            'location_id' => $this->locationId,
        ];
    }
}
