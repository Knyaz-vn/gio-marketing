<?php
declare(strict_types=1);

namespace BPMedical\Booking\Tests\Unit;

use BPMedical\Booking\Core\Config;
use BPMedical\Booking\Core\FixedClock;
use BPMedical\Booking\Core\Interval;
use BPMedical\Booking\Core\ScheduleResolver;
use BPMedical\Booking\Core\Slot;
use BPMedical\Booking\Core\SlotGenerator;
use BPMedical\Booking\Core\Tz;
use PHPUnit\Framework\TestCase;

final class SlotGeneratorTest extends TestCase
{
    // 2026-09-28 — понеділок; 2026-10-03 — субота; 2026-10-04 — неділя.
    private const MON = '2026-09-28';
    private const TUE = '2026-09-29';
    private const SAT = '2026-10-03';
    private const SUN = '2026-10-04';

    /** @return array<string, mixed> */
    private function doctor(array $extra = []): array
    {
        $schedule = [];
        for ($wd = 1; $wd <= 7; $wd++) {
            $schedule[] = ['weekday' => $wd, 'start' => '08:00', 'end' => '20:00', 'location_id' => 'loc_koriat'];
        }
        return $extra + ['id' => 'd1', 'schedule' => $schedule, 'schedule_overrides' => []];
    }

    private function gen(string $nowDate = '2026-09-27', string $nowTime = '07:00', array $cfg = []): SlotGenerator
    {
        $config = new Config($cfg);
        return new SlotGenerator($config, new ScheduleResolver($config), new FixedClock(Tz::at($nowDate, $nowTime)));
    }

    /** @param Slot[] $slots @return string[] */
    private static function times(array $slots): array
    {
        return array_map(static fn(Slot $s) => Tz::time($s->start), $slots);
    }

    public function testClinicHoursClipDoctorScheduleAndStepIs15(): void
    {
        $slots = $this->gen()->slotsForDate($this->doctor(), 30, 0, self::MON, []);
        $t = self::times($slots);
        self::assertSame('09:00', $t[0], 'графік лікаря 08:00 обрізається годинами клініки 09:00');
        self::assertSame('09:15', $t[1]);
        self::assertSame('17:30', end($t), 'останній слот закінчується о 18:00');
        self::assertCount(35, $t);
    }

    public function testSaturdayLastSlotEndsBy1500(): void
    {
        $slots = $this->gen()->slotsForDate($this->doctor(), 30, 0, self::SAT, []);
        $last = end($slots);
        self::assertSame('14:30', Tz::time($last->start));
        self::assertLessThanOrEqual(Tz::at(self::SAT, '15:00'), $last->blockEnd);

        $withBuffer = $this->gen()->slotsForDate($this->doctor(), 30, 10, self::SAT, []);
        $last = end($withBuffer);
        self::assertSame('14:15', Tz::time($last->start), '30 хв + 10 хв буфера → 14:15–14:55');
        self::assertLessThanOrEqual(Tz::at(self::SAT, '15:00'), $last->blockEnd);
    }

    public function testSundayAndHolidaysAreClosed(): void
    {
        self::assertSame([], $this->gen()->slotsForDate($this->doctor(), 30, 0, self::SUN, []));
        self::assertSame([], $this->gen('2026-09-27', '07:00', ['holidays' => [self::TUE]])->slotsForDate($this->doctor(), 30, 0, self::TUE, []));
    }

    public function testMinLeadTime(): void
    {
        // Зараз пн 09:40, min_lead 120 хв → найраніше 11:40 → перший слот 11:45.
        $slots = $this->gen(self::MON, '09:40')->slotsForDate($this->doctor(), 30, 0, self::MON, []);
        self::assertSame('11:45', self::times($slots)[0]);

        $slots = $this->gen(self::MON, '09:40', ['min_lead_minutes' => 0])->slotsForDate($this->doctor(), 30, 0, self::MON, []);
        self::assertSame('09:45', self::times($slots)[0]);
    }

    public function testCalendarEventsAreSubtracted(): void
    {
        $busy = [
            new Interval(Tz::at(self::MON, '10:00'), Tz::at(self::MON, '10:30')),
            new Interval(Tz::at(self::MON, '10:20'), Tz::at(self::MON, '11:05')), // перетин подій
        ];
        $t = self::times($this->gen()->slotsForDate($this->doctor(), 30, 0, self::MON, $busy));
        self::assertContains('09:30', $t, 'слот 09:30–10:00 стикується з подією і доступний');
        self::assertNotContains('09:45', $t);
        self::assertNotContains('10:00', $t);
        self::assertNotContains('10:45', $t);
        self::assertNotContains('11:00', $t, '11:00 перетинається з подією до 11:05');
        self::assertContains('11:15', $t, 'після 11:05 наступний крок сітки 11:15');
    }

    public function testBufferAfterMustFitBeforeNextEvent(): void
    {
        $busy = [new Interval(Tz::at(self::MON, '11:00'), Tz::at(self::MON, '12:00'))];
        $slots = $this->gen()->slotsForDate($this->doctor(), 30, 15, self::MON, $busy);
        $t = self::times($slots);
        self::assertContains('10:15', $t, '10:15 + 30 + 15 = 11:00');
        self::assertNotContains('10:30', $t, '10:30 + 30 + 15 = 11:15 > 11:00');
        $first = $slots[0];
        self::assertSame(30 * 60, $first->end - $first->start, 'сам прийом без буфера');
        self::assertSame(45 * 60, $first->blockEnd - $first->start, 'зайнятість з буфером');
    }

    public function testOverrideDayOff(): void
    {
        $doctor = $this->doctor(['schedule_overrides' => [['date' => self::TUE, 'start' => null, 'end' => null, 'location_id' => null]]]);
        self::assertSame([], $this->gen()->slotsForDate($doctor, 30, 0, self::TUE, []));
        self::assertNotEmpty($this->gen()->slotsForDate($doctor, 30, 0, self::MON, []), 'інші дні не зачеплені');
    }

    public function testOverrideReplacesScheduleAndLocation(): void
    {
        $doctor = $this->doctor(['schedule_overrides' => [['date' => self::TUE, 'start' => '12:00', 'end' => '13:00', 'location_id' => 'loc_strilets']]]);
        $slots = $this->gen()->slotsForDate($doctor, 30, 0, self::TUE, []);
        self::assertSame(['12:00', '12:15', '12:30'], self::times($slots));
        self::assertSame('loc_strilets', $slots[0]->locationId);
    }

    public function testMultipleIntervalsAndLocationsPerDay(): void
    {
        $doctor = ['id' => 'd', 'schedule' => [
            ['weekday' => 1, 'start' => '09:00', 'end' => '10:00', 'location_id' => 'loc_koriat'],
            ['weekday' => 1, 'start' => '14:00', 'end' => '15:00', 'location_id' => 'loc_strilets'],
        ]];
        $slots = $this->gen()->slotsForDate($doctor, 30, 0, self::MON, []);
        self::assertSame(['09:00', '09:15', '09:30', '14:00', '14:15', '14:30'], self::times($slots));
        self::assertSame('loc_koriat', $slots[0]->locationId);
        self::assertSame('loc_strilets', $slots[3]->locationId);
    }

    public function testAllDayEventBlocksWholeDay(): void
    {
        $busy = [new Interval(Tz::dayStart(self::MON), Tz::dayStart(self::TUE))];
        self::assertSame([], $this->gen()->slotsForDate($this->doctor(), 30, 0, self::MON, $busy));
    }

    public function testHorizon(): void
    {
        $gen = $this->gen('2026-09-27', '07:00', ['booking_horizon_days' => 30]);
        self::assertNotEmpty($gen->slotsForDate($this->doctor(), 30, 0, '2026-10-27', []));
        self::assertSame([], $gen->slotsForDate($this->doctor(), 30, 0, '2026-10-28', []));
        self::assertSame([], $gen->slotsForDate($this->doctor(), 30, 0, '2026-09-26', []), 'минуле');
    }
}
