<?php
declare(strict_types=1);

namespace BPMedical\Booking\Core;

/**
 * Застосовує SurnameMatcher до подій календаря і формує:
 *  - блокування лікарів (doctorId => Interval[]);
 *  - діагностику: нерозпізнані події, можливі хибні збіги, події з кількома лікарями.
 */
final class EventClassifier
{
    public const FLAG_IGNORED = 'ignored';
    public const FLAG_UNRECOGNIZED = 'unrecognized';
    public const FLAG_SUSPICIOUS = 'suspicious';
    public const FLAG_MULTI = 'multi';
    public const FLAG_ALL_DAY = 'all_day';

    private SurnameMatcher $matcher;

    public function __construct(SurnameMatcher $matcher)
    {
        $this->matcher = $matcher;
    }

    /**
     * @return array{doctor_ids: string[], suspicious_ids: string[], flags: string[]}
     */
    public function classify(CalendarEvent $event): array
    {
        if ($event->isIgnored()) {
            return ['doctor_ids' => [], 'suspicious_ids' => [], 'flags' => [self::FLAG_IGNORED]];
        }
        $m = $this->matcher->match($event->summary);
        $flags = [];
        if ($m->isEmpty()) {
            $flags[] = self::FLAG_UNRECOGNIZED;
        }
        if ($m->suspiciousIds) {
            $flags[] = self::FLAG_SUSPICIOUS;
        }
        if (count($m->doctorIds) > 1) {
            $flags[] = self::FLAG_MULTI;
        }
        if ($event->allDay) {
            $flags[] = self::FLAG_ALL_DAY;
        }
        return ['doctor_ids' => $m->doctorIds, 'suspicious_ids' => $m->suspiciousIds, 'flags' => $flags];
    }

    /**
     * Будує зайнятість по лікарях. All-day подія блокує лікаря на всі дні [start, end).
     *
     * @param CalendarEvent[] $events
     * @return array<string, Interval[]>
     */
    public function busyByDoctor(array $events): array
    {
        $busy = [];
        foreach ($events as $e) {
            $c = $this->classify($e);
            foreach ($c['doctor_ids'] as $doctorId) {
                $busy[$doctorId][] = new Interval($e->start, $e->end);
            }
        }
        return $busy;
    }
}
