<?php
declare(strict_types=1);

namespace BPMedical\Booking\Core;

final class AvailabilityService
{
    private Catalog $catalog;
    private SlotGenerator $generator;
    private BusyProvider $busy;

    public function __construct(Catalog $catalog, SlotGenerator $generator, BusyProvider $busy)
    {
        $this->catalog = $catalog;
        $this->generator = $generator;
        $this->busy = $busy;
    }

    public function generator(): SlotGenerator
    {
        return $this->generator;
    }

    /**
     * Дні з кількістю вільних слотів (для календаря у віджеті).
     *
     * @return list<array{date:string, free:int}>
     */
    public function days(string $doctorId, string $serviceId, string $fromYmd, string $toYmd): array
    {
        $doctor = $this->catalog->doctor($doctorId);
        if ($doctor === null) {
            return [];
        }
        $from = max($fromYmd, $this->generator->today());
        $to = min($toYmd, $this->generator->lastBookableDate());
        if ($from > $to) {
            return [];
        }
        [$dur, $buf] = $this->catalog->duration($doctorId, $serviceId);
        $busy = $this->busy->busy($doctorId, Tz::dayStart($from), Tz::dayStart(Tz::addDays($to, 1)));
        $out = [];
        for ($d = $from; $d <= $to; $d = Tz::addDays($d, 1)) {
            $out[] = ['date' => $d, 'free' => count($this->generator->slotsForDate($doctor, $dur, $buf, $d, $busy))];
        }
        return $out;
    }

    /**
     * @param Interval[] $extraBusy
     * @return Slot[]
     */
    public function slots(string $doctorId, string $serviceId, string $ymd, ?string $excludeHold = null, array $extraBusy = []): array
    {
        $doctor = $this->catalog->doctor($doctorId);
        if ($doctor === null) {
            return [];
        }
        [$dur, $buf] = $this->catalog->duration($doctorId, $serviceId);
        $busy = array_merge(
            $this->busy->busy($doctorId, Tz::dayStart($ymd), Tz::dayStart(Tz::addDays($ymd, 1)), $excludeHold),
            $extraBusy
        );
        return $this->generator->slotsForDate($doctor, $dur, $buf, $ymd, $busy);
    }

    /** Слот, що починається саме в $start, якщо він вільний. */
    public function findSlot(string $doctorId, string $serviceId, int $start, ?string $excludeHold = null): ?Slot
    {
        foreach ($this->slots($doctorId, $serviceId, Tz::date($start), $excludeHold) as $s) {
            if ($s->start === $start) {
                return $s;
            }
        }
        return null;
    }

    public function nearestDate(string $doctorId, string $serviceId): ?string
    {
        foreach ($this->days($doctorId, $serviceId, $this->generator->today(), $this->generator->lastBookableDate()) as $d) {
            if ($d['free'] > 0) {
                return $d['date'];
            }
        }
        return null;
    }

    /**
     * N вільних слотів, найближчих за часом до $around (для відповіді "слот зайнятий").
     *
     * @return Slot[]
     */
    public function nearestSlots(string $doctorId, string $serviceId, int $around, int $n = 3): array
    {
        $candidates = [];
        $after = 0;
        $d = max(Tz::date($around), $this->generator->today());
        $last = $this->generator->lastBookableDate();
        for (; $d <= $last && $after < $n; $d = Tz::addDays($d, 1)) {
            foreach ($this->slots($doctorId, $serviceId, $d) as $s) {
                $candidates[] = $s;
                if ($s->start > $around) {
                    $after++;
                }
            }
        }
        usort($candidates, static fn(Slot $a, Slot $b) => abs($a->start - $around) <=> abs($b->start - $around));
        $out = array_slice($candidates, 0, $n);
        usort($out, static fn(Slot $a, Slot $b) => $a->start <=> $b->start);
        return $out;
    }
}
