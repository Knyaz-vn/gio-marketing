<?php
declare(strict_types=1);

namespace BPMedical\Booking\Booking;

use BPMedical\Booking\Core\BusyProvider;
use BPMedical\Booking\Core\Clock;
use BPMedical\Booking\Storage\BookingRepository;
use BPMedical\Booking\Storage\EventCacheRepository;
use BPMedical\Booking\Storage\HoldRepository;

/** Зайнятість = події календаря з прізвищем лікаря + чужі holds + активні записи в БД. */
final class DbBusyProvider implements BusyProvider
{
    private EventCacheRepository $events;
    private HoldRepository $holds;
    private BookingRepository $bookings;
    private Clock $clock;

    public function __construct(EventCacheRepository $events, HoldRepository $holds, BookingRepository $bookings, Clock $clock)
    {
        $this->events = $events;
        $this->holds = $holds;
        $this->bookings = $bookings;
        $this->clock = $clock;
    }

    public function busy(string $doctorId, int $from, int $to, ?string $excludeHoldToken = null): array
    {
        return array_merge(
            $this->events->busyIntervals($doctorId, $from, $to),
            $this->holds->busyIntervals($doctorId, $from, $to, $this->clock->now(), $excludeHoldToken),
            $this->bookings->busyIntervals($doctorId, $from, $to)
        );
    }
}
