<?php
declare(strict_types=1);

namespace BPMedical\Booking\Core;

/**
 * Робочі інтервали лікаря на дату:
 * schedule (за днем тижня) або schedule_overrides (на конкретну дату), мінус свята, в межах годин клініки.
 */
final class ScheduleResolver
{
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * @param array<string, mixed> $doctor
     * @return Interval[]
     */
    public function workingIntervals(array $doctor, string $ymd): array
    {
        if ($this->config->isHoliday($ymd)) {
            return [];
        }
        $weekday = Tz::weekday($ymd);
        $clinic = $this->config->clinicHours($weekday);
        if ($clinic === null) {
            return [];
        }

        $rules = [];
        $overrides = array_values(array_filter(
            (array) ($doctor['schedule_overrides'] ?? []),
            static fn($o) => is_array($o) && ($o['date'] ?? null) === $ymd
        ));
        if ($overrides) {
            foreach ($overrides as $o) {
                if (!array_key_exists('location_id', $o) || $o['location_id'] === null || $o['location_id'] === '') {
                    return []; // override-вихідний
                }
                $rules[] = $o;
            }
        } else {
            foreach ((array) ($doctor['schedule'] ?? []) as $r) {
                if (is_array($r) && (int) ($r['weekday'] ?? 0) === $weekday) {
                    $rules[] = $r;
                }
            }
        }

        $out = [];
        foreach ($rules as $r) {
            $start = (string) ($r['start'] ?? '');
            $end = (string) ($r['end'] ?? '');
            if (!Tz::isValidTime($start) || !Tz::isValidTime($end)) {
                continue;
            }
            $s = Tz::at($ymd, $start);
            $e = Tz::at($ymd, $end);
            if ($e > $s) {
                $out[] = new Interval($s, $e, (string) $r['location_id']);
            }
        }
        return IntervalMath::clip($out, Tz::at($ymd, $clinic[0]), Tz::at($ymd, $clinic[1]));
    }
}
