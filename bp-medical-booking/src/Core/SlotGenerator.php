<?php
declare(strict_types=1);

namespace BPMedical\Booking\Core;

/**
 * Алгоритм вільних слотів:
 *  1. Робочі інтервали (schedule + overrides − свята ∩ години клініки).
 *  2. Мінус зайнятість (події календаря з прізвищем лікаря, holds, активні записи в БД).
 *  3. Нарізка кроком slot_step_min (сітка від 00:00) довжиною duration + buffer; слот має повністю
 *     вміщатися у вільний інтервал (тому в суботу останній слот закінчується не пізніше 15:00).
 *  4. Не раніше now + min_lead_minutes і не далі booking_horizon_days від сьогодні.
 */
final class SlotGenerator
{
    private Config $config;
    private ScheduleResolver $schedule;
    private Clock $clock;

    public function __construct(Config $config, ScheduleResolver $schedule, Clock $clock)
    {
        $this->config = $config;
        $this->schedule = $schedule;
        $this->clock = $clock;
    }

    public function today(): string
    {
        return Tz::date($this->clock->now());
    }

    public function lastBookableDate(): string
    {
        return Tz::addDays($this->today(), $this->config->int('booking_horizon_days'));
    }

    /**
     * @param array<string, mixed> $doctor
     * @param Interval[] $busy
     * @return Slot[]
     */
    public function slotsForDate(array $doctor, int $durationMin, int $bufferMin, string $ymd, array $busy): array
    {
        if ($ymd < $this->today() || $ymd > $this->lastBookableDate()) {
            return [];
        }
        $working = $this->schedule->workingIntervals($doctor, $ymd);
        if (!$working) {
            return [];
        }
        $free = IntervalMath::subtract($working, $busy);

        $step = max(1, $this->config->int('slot_step_min')) * 60;
        $len = ($durationMin + $bufferMin) * 60;
        $earliest = $this->clock->now() + $this->config->int('min_lead_minutes') * 60;
        $dayStart = Tz::dayStart($ymd);

        $slots = [];
        $seen = [];
        foreach ($free as $iv) {
            $t = $dayStart + (int) ceil(($iv->start - $dayStart) / $step) * $step;
            for (; $t + $len <= $iv->end; $t += $step) {
                if ($t < $earliest || isset($seen[$t])) {
                    continue;
                }
                $seen[$t] = true;
                $slots[] = new Slot($t, $t + $durationMin * 60, $t + $len, (string) $iv->locationId);
            }
        }
        usort($slots, static fn(Slot $a, Slot $b) => $a->start <=> $b->start);
        return $slots;
    }
}
