<?php
declare(strict_types=1);

namespace BPMedical\Booking\Tests\Integration;

use BPMedical\Booking\Booking\SlotUnavailable;
use BPMedical\Booking\Booking\ValidationError;
use BPMedical\Booking\Core\BookingStatus;
use BPMedical\Booking\Core\Tz;
use BPMedical\Booking\Tests\Support\TestStack;
use PHPUnit\Framework\TestCase;

final class BookingFlowTest extends TestCase
{
    private TestStack $s;
    private int $start;

    protected function setUp(): void
    {
        $this->s = new TestStack(TestStack::tmpDir('flow'));
        $this->start = DoubleBookingTest::futureWeekdayAt('11:00');
    }

    public function testHoldBlocksSlotForOthersButNotForOwner(): void
    {
        $hold = $this->s->bookings->createHold('gribanova', 'consult_primary', $this->start);
        self::assertSame(7 * 60, $hold['expires_at'] - time(), 'hold на 7 хв');

        self::assertNull($this->s->availability->findSlot('gribanova', 'consult_primary', $this->start), 'інші не бачать слот');
        self::assertNotNull($this->s->availability->findSlot('gribanova', 'consult_primary', $this->start, $hold['token']), 'власник бачить');

        try {
            $this->s->bookings->createHold('gribanova', 'consult_primary', $this->start);
            self::fail('другий hold на той самий слот');
        } catch (SlotUnavailable $e) {
            self::assertCount(3, $e->alternatives);
        }

        $r = $this->s->bookings->submit(TestStack::patient([
            'doctor_id' => 'gribanova', 'service_id' => 'consult_primary', 'start' => $this->start, 'hold_token' => $hold['token'],
        ]));
        self::assertSame(BookingStatus::PENDING, $r['booking']['status']);
        self::assertNull($this->s->holds->find($hold['token']), 'hold знято після запису');
    }

    public function testCalendarEventFormatWithoutComplaints(): void
    {
        $r = $this->s->bookings->submit(TestStack::patient([
            'doctor_id' => 'gribanova', 'service_id' => 'consult_primary', 'start' => $this->start,
            'comment' => 'болить груди, підозра на пухлину',
        ]));
        $b = $r['booking'];
        $event = $this->s->calendar->dump()[$b['calendar_id']][$b['gcal_event_id']];
        self::assertSame('Грибанова | ОНЛАЙН | Первинна консультація', $event['summary']);
        self::assertStringContainsString('Олена', $event['description']);
        self::assertStringContainsString('+380 67 123 45 67', $event['description']);
        self::assertStringContainsString('Не підтверджено: зателефонувати пацієнту', $event['description']);
        self::assertStringContainsString($b['lead_id'], $event['description']);
        self::assertStringNotContainsString('пухлин', $event['description'], 'без скарг і діагнозів');
        self::assertSame('9', $event['colorId']);
        self::assertSame('mock-koriat', $b['calendar_id'], 'календар локації loc_koriat');

        // Подія з прізвищем першим словом розпізнається навіть у режимі prefix.
        self::assertSame(['gribanova'], (new \BPMedical\Booking\Core\SurnameMatcher($this->s->catalog->aliasesByDoctor(), 'prefix'))->match($event['summary'])->doctorIds);

        self::assertCount(1, $this->s->notifier->sent);
        $text = implode("\n", $this->s->notifier->sent[0]['lines']);
        self::assertStringContainsString('google / cpc', $text);
        self::assertStringContainsString('Коріатовичів', $text);
        foreach ($this->s->logLines as $l) {
            self::assertStringNotContainsString('1234567', $l, 'телефон у логах маскується');
        }
    }

    public function testEventInCalendarWithDoctorSurnameBlocksSlotOnSubmit(): void
    {
        // Адміністратор щойно вручну записав пацієнта (кеш ще не синхронізувався).
        $this->s->calendar->addTimed('mock-strilets', 'Грибанова | огляд | Петренко', $this->start, $this->start + 1800);
        self::assertNotNull($this->s->availability->findSlot('gribanova', 'consult_primary', $this->start), 'кеш ще не знає про подію');
        $this->expectException(SlotUnavailable::class);
        $this->s->bookings->submit(TestStack::patient(['doctor_id' => 'gribanova', 'service_id' => 'consult_primary', 'start' => $this->start]));
    }

    public function testSyncBlocksOnlyMatchedDoctor(): void
    {
        $this->s->calendar->addTimed('mock-koriat', 'Машевська | консультація', $this->start, $this->start + 3600);
        $this->s->sync->syncAll();
        self::assertNull($this->s->availability->findSlot('mashevska', 'consult_primary', $this->start));
        // Машевський не bookable, тож перевіряємо іншого лікаря: його не зачеплено.
        self::assertNotNull($this->s->availability->findSlot('gribanova', 'consult_primary', $this->start));
        self::assertSame([], $this->s->events->busyIntervals('mashevskyi', $this->start - 1, $this->start + 3600));
    }

    public function testValidation(): void
    {
        try {
            $this->s->bookings->submit(['doctor_id' => 'gribanova', 'service_id' => 'consult_primary', 'start' => $this->start, 'name' => '', 'phone' => '123']);
            self::fail();
        } catch (ValidationError $e) {
            self::assertArrayHasKey('name', $e->fields);
            self::assertArrayHasKey('phone', $e->fields);
            self::assertArrayHasKey('consent', $e->fields);
        }
    }

    public function testChildSpecialtyRequiresChildAge(): void
    {
        try {
            $this->s->bookings->submit(TestStack::patient(['doctor_id' => 'korol', 'service_id' => 'consult_primary', 'start' => $this->start]));
            self::fail();
        } catch (ValidationError $e) {
            self::assertArrayHasKey('child_age', $e->fields);
        }
        $r = $this->s->bookings->submit(TestStack::patient(['doctor_id' => 'korol', 'service_id' => 'consult_primary', 'start' => $this->start, 'child_age' => '7']));
        self::assertSame('7', $r['booking']['child_age']);
    }

    public function testNonBookableDoctorRejected(): void
    {
        $this->expectException(ValidationError::class);
        $this->s->bookings->createHold('mashevskyi', 'consult_primary', $this->start);
    }

    public function testCancelMarksEventTransparentAndFreesSlot(): void
    {
        $r = $this->s->bookings->submit(TestStack::patient(['doctor_id' => 'gribanova', 'service_id' => 'consult_primary', 'start' => $this->start]));
        $b = $this->s->bookings->changeStatus((int) $r['booking']['id'], BookingStatus::CANCELLED);
        self::assertSame(BookingStatus::CANCELLED, $b['status']);
        $event = $this->s->calendar->dump()[$b['calendar_id']][$b['gcal_event_id']];
        self::assertStringStartsWith('СКАСОВАНО | ', $event['summary']);
        self::assertSame('transparent', $event['transparency']);
        $this->s->sync->syncAll();
        self::assertNotNull($this->s->availability->findSlot('gribanova', 'consult_primary', $this->start), 'слот знову вільний');
    }

    public function testConfirmUpdatesDescription(): void
    {
        $r = $this->s->bookings->submit(TestStack::patient(['doctor_id' => 'gribanova', 'service_id' => 'consult_primary', 'start' => $this->start]));
        $b = $this->s->bookings->changeStatus((int) $r['booking']['id'], BookingStatus::CONFIRMED);
        $event = $this->s->calendar->dump()[$b['calendar_id']][$b['gcal_event_id']];
        self::assertStringContainsString('Підтверджено адміністратором', $event['description']);
    }

    public function testAdminMovesOnlineEventInCalendar(): void
    {
        $r = $this->s->bookings->submit(TestStack::patient(['doctor_id' => 'gribanova', 'service_id' => 'consult_primary', 'start' => $this->start]));
        $b = $r['booking'];
        $newStart = $this->start + 3 * 3600;
        $this->s->calendar->patchEvent($b['calendar_id'], $b['gcal_event_id'], [
            'start' => ['dateTime' => Tz::rfc3339Local($newStart)],
            'end' => ['dateTime' => Tz::rfc3339Local($newStart + 1800)],
        ]);
        $this->s->sync->syncAll();
        $moved = $this->s->bookingRepo->findByLead($b['lead_id']);
        self::assertSame($newStart, (int) $moved['start_ts']);
        self::assertNotNull($this->s->availability->findSlot('gribanova', 'consult_primary', $this->start), 'старий час звільнився');
        self::assertNull($this->s->availability->findSlot('gribanova', 'consult_primary', $newStart), 'новий час зайнятий');
    }

    public function testConfirmSendsSmsOnceWhenEnabled(): void
    {
        $s = new TestStack(TestStack::tmpDir('sms'), null, ['sms_enabled' => true]);
        $r = $s->bookings->submit(TestStack::patient(['doctor_id' => 'gribanova', 'service_id' => 'consult_primary', 'start' => $this->start]));
        self::assertSame([], $s->sms->sent, 'при створенні заявки SMS не надсилається');

        $b = $s->bookings->changeStatus((int) $r['booking']['id'], BookingStatus::CONFIRMED);
        self::assertCount(1, $s->sms->sent);
        [$phone, $text] = $s->sms->sent[0];
        self::assertSame('+380671234567', $phone);
        self::assertStringContainsString('Олена, ваш запис підтверджено: ' . Tz::local($this->start)->format('d.m'), $text);
        self::assertStringContainsString('о 11:00, лікар Грибанова А.С., вул. Князів Коріатовичів, 168А. Тел. 066-211-99-22', $text);
        self::assertSame('надіслано #msg-1', $b['sms_status']);

        // Повторне підтвердження (pending → confirmed ще раз) не дублює SMS.
        $s->bookings->changeStatus((int) $b['id'], BookingStatus::PENDING);
        $s->bookings->changeStatus((int) $b['id'], BookingStatus::CONFIRMED);
        self::assertCount(1, $s->sms->sent);
    }

    public function testSmsFailureDoesNotBlockConfirmation(): void
    {
        $s = new TestStack(TestStack::tmpDir('smsfail'), null, ['sms_enabled' => true]);
        $s->sms->fail = 'TurboSMS: NOT_ENOUGH_CREDITS (502)';
        $r = $s->bookings->submit(TestStack::patient(['doctor_id' => 'gribanova', 'service_id' => 'consult_primary', 'start' => $this->start]));
        $b = $s->bookings->changeStatus((int) $r['booking']['id'], BookingStatus::CONFIRMED);
        self::assertSame(BookingStatus::CONFIRMED, $b['status']);
        self::assertSame('помилка: TurboSMS: NOT_ENOUGH_CREDITS (502)', $b['sms_status']);
        self::assertEmpty($b['sms_sent_at'], 'після помилки можна спробувати ще раз');
    }

    public function testSmsDisabledByDefault(): void
    {
        $r = $this->s->bookings->submit(TestStack::patient(['doctor_id' => 'gribanova', 'service_id' => 'consult_primary', 'start' => $this->start]));
        $this->s->bookings->changeStatus((int) $r['booking']['id'], BookingStatus::CONFIRMED);
        self::assertSame([], $this->s->sms->sent);
    }

    public function testSlotsRangeMatchesPerDaySlots(): void
    {
        $this->s->calendar->addTimed('mock-koriat', 'Грибанова | огляд | Петренко', $this->start, $this->start + 3600);
        $this->s->sync->syncAll();
        $from = Tz::date($this->start);
        $to = Tz::addDays($from, 6);
        $range = $this->s->availability->slotsRange('gribanova', 'consult_primary', $from, $to);
        self::assertNotEmpty($range);
        for ($d = $from; $d <= $to; $d = Tz::addDays($d, 1)) {
            $perDay = array_map(static fn($x) => $x->start, $this->s->availability->slots('gribanova', 'consult_primary', $d));
            $inRange = array_map(static fn($x) => $x->start, $range[$d] ?? []);
            self::assertSame($perDay, $inRange, "день $d");
        }
        $times = array_map(static fn($x) => Tz::time($x->start), $range[$from]);
        self::assertNotContains('11:00', $times, 'подія з прізвищем блокує час і в діапазоні');
        self::assertNotContains('11:30', $times);
    }

    public function testDaysApiCountsAndNoLeakOfSummaries(): void
    {
        $this->s->calendar->addAllDay('mock-koriat', 'Грибанова відпустка', Tz::date($this->start), Tz::addDays(Tz::date($this->start), 1));
        $this->s->sync->syncAll();
        $days = $this->s->availability->days('gribanova', 'consult_primary', Tz::date($this->start), Tz::date($this->start));
        self::assertSame([['date' => Tz::date($this->start), 'free' => 0]], $days);
        self::assertStringNotContainsString('відпустка', json_encode($days, JSON_UNESCAPED_UNICODE));
    }
}
