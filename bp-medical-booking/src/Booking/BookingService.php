<?php
declare(strict_types=1);

namespace BPMedical\Booking\Booking;

use BPMedical\Booking\Calendar\CalendarClient;
use BPMedical\Booking\Core\Attribution;
use BPMedical\Booking\Core\AvailabilityService;
use BPMedical\Booking\Core\BookingStatus;
use BPMedical\Booking\Core\CalendarEvent;
use BPMedical\Booking\Core\Catalog;
use BPMedical\Booking\Core\Clock;
use BPMedical\Booking\Core\Config;
use BPMedical\Booking\Core\EventClassifier;
use BPMedical\Booking\Core\Ids;
use BPMedical\Booking\Core\Phone;
use BPMedical\Booking\Core\Slot;
use BPMedical\Booking\Core\Tz;
use BPMedical\Booking\Storage\BookingRepository;
use BPMedical\Booking\Storage\Connection;
use BPMedical\Booking\Storage\EventCacheRepository;
use BPMedical\Booking\Storage\HoldRepository;

/**
 * Створення запису:
 *  1. hold на hold_minutes (під локом лікаря);
 *  2. submit: лок лікаря → свіжий events.list на день слота → перевірка слота
 *     (події + чужі holds + активні записи) → запис у БД → подія в календарі → зняття hold;
 *  3. сповіщення адміністраторам (поза локом).
 */
final class BookingService
{
    private const LOCK_TIMEOUT = 15;
    public const UNCONFIRMED_NOTE = 'Не підтверджено: зателефонувати пацієнту';
    public const CONFIRMED_NOTE = 'Підтверджено адміністратором';

    private Connection $db;
    private Catalog $catalog;
    private Config $config;
    private AvailabilityService $availability;
    private HoldRepository $holds;
    private BookingRepository $bookings;
    private EventCacheRepository $events;
    private EventClassifier $classifier;
    private CalendarClient $calendar;
    private CalendarRefresher $refresher;
    private Notifier $notifier;
    private Clock $clock;
    private Logger $log;

    public function __construct(
        Connection $db,
        Catalog $catalog,
        Config $config,
        AvailabilityService $availability,
        HoldRepository $holds,
        BookingRepository $bookings,
        EventCacheRepository $events,
        EventClassifier $classifier,
        CalendarClient $calendar,
        CalendarRefresher $refresher,
        Notifier $notifier,
        Clock $clock,
        Logger $log
    ) {
        $this->db = $db;
        $this->catalog = $catalog;
        $this->config = $config;
        $this->availability = $availability;
        $this->holds = $holds;
        $this->bookings = $bookings;
        $this->events = $events;
        $this->classifier = $classifier;
        $this->calendar = $calendar;
        $this->refresher = $refresher;
        $this->notifier = $notifier;
        $this->clock = $clock;
        $this->log = $log;
    }

    /**
     * @return array{token:string, expires_at:int, slot:Slot}
     */
    public function createHold(string $doctorId, string $serviceId, int $start): array
    {
        $this->assertBookable($doctorId, $serviceId);
        return $this->db->withLock('bpmb_doc_' . $doctorId, self::LOCK_TIMEOUT, function () use ($doctorId, $serviceId, $start) {
            $slot = $this->availability->findSlot($doctorId, $serviceId, $start);
            if ($slot === null) {
                throw new SlotUnavailable($this->availability->nearestSlots($doctorId, $serviceId, $start));
            }
            $now = $this->clock->now();
            $token = Ids::token();
            $expires = $now + $this->config->int('hold_minutes') * 60;
            $this->holds->create($token, $doctorId, $serviceId, $slot->locationId, $slot->start, $slot->blockEnd, $expires, $now);
            return ['token' => $token, 'expires_at' => $expires, 'slot' => $slot];
        });
    }

    /**
     * @param array<string, mixed> $input
     * @return array{booking: array<string, mixed>, slot: Slot}
     */
    public function submit(array $input): array
    {
        $doctorId = (string) ($input['doctor_id'] ?? '');
        $serviceId = (string) ($input['service_id'] ?? '');
        $start = (int) ($input['start'] ?? 0);
        $this->assertBookable($doctorId, $serviceId);
        $specialtyId = $this->resolveSpecialty($doctorId, isset($input['specialty_id']) ? (string) $input['specialty_id'] : null);
        $patient = $this->validatePatient($input, $specialtyId);
        $attribution = Attribution::sanitize($input['attribution'] ?? []);
        $holdToken = isset($input['hold_token']) && is_string($input['hold_token']) && $input['hold_token'] !== '' ? $input['hold_token'] : null;

        $result = $this->db->withLock('bpmb_doc_' . $doctorId, self::LOCK_TIMEOUT, function () use (
            $doctorId, $serviceId, $start, $specialtyId, $patient, $attribution, $holdToken, $input
        ) {
            $syncErrors = [];
            // Повторна перевірка в реальному часі: свіжий events.list на добу слота.
            try {
                $this->refresher->refreshWindow(Tz::dayStart(Tz::date($start)), Tz::dayStart(Tz::addDays(Tz::date($start), 1)));
            } catch (\Throwable $e) {
                $syncErrors[] = 'Календар недоступний під час перевірки, перевірте слот вручну';
                $this->log->error('refreshWindow failed', ['error' => $e->getMessage()]);
            }

            $slot = $this->availability->findSlot($doctorId, $serviceId, $start, $holdToken);
            if ($slot === null) {
                throw new SlotUnavailable($this->availability->nearestSlots($doctorId, $serviceId, $start));
            }

            $now = $this->clock->now();
            $leadId = Ids::lead();
            $calendarId = $this->config->calendarForLocation($slot->locationId);
            $row = [
                'lead_id' => $leadId,
                'doctor_id' => $doctorId,
                'service_id' => $serviceId,
                'specialty_id' => $specialtyId,
                'location_id' => $slot->locationId,
                'calendar_id' => $calendarId,
                'start_ts' => $slot->start,
                'end_ts' => $slot->end,
                'block_end_ts' => $slot->blockEnd,
                'status' => BookingStatus::PENDING,
                'patient_name' => $patient['name'],
                'phone' => $patient['phone'],
                'phone_hash' => $this->config->get('store_phone_hash') ? Phone::hash($patient['phone']) : null,
                'comment' => $patient['comment'],
                'child_age' => $patient['child_age'],
                'entry' => isset($input['entry']) ? substr(preg_replace('/[^a-z_]/', '', (string) $input['entry']) ?? '', 0, 30) : null,
                'created_at' => $now,
                'updated_at' => $now,
            ] + $attribution;
            $id = $this->bookings->insert($row);
            $row['id'] = $id;

            if ($calendarId !== null) {
                try {
                    $eventId = $this->calendar->insertEvent($calendarId, $this->eventResource($row));
                    $row['gcal_event_id'] = $eventId;
                    $this->bookings->update($id, ['gcal_event_id' => $eventId]);
                    $this->events->upsertOne(
                        new CalendarEvent($calendarId, $eventId, $this->eventSummary($row), $slot->start, $slot->end),
                        $this->classifier,
                        $now
                    );
                } catch (\Throwable $e) {
                    $syncErrors[] = 'Не вдалося створити подію в календарі: додайте запис вручну';
                    $this->log->error('insertEvent failed', ['lead' => $leadId, 'error' => $e->getMessage()]);
                }
            } else {
                $syncErrors[] = 'Не налаштовано календар для локації';
            }
            if ($syncErrors) {
                $row['sync_error'] = implode('; ', $syncErrors);
                $this->bookings->update($id, ['sync_error' => $row['sync_error']]);
            }
            if ($holdToken !== null) {
                $this->holds->delete($holdToken);
            }
            return ['booking' => $row, 'slot' => $slot];
        });

        try {
            $this->notifier->send($this->notifyPayload($result['booking']));
        } catch (\Throwable $e) {
            $this->log->error('notify failed', ['lead' => $result['booking']['lead_id'], 'error' => $e->getMessage()]);
        }
        $this->log->info('booking created', ['lead' => $result['booking']['lead_id'], 'phone' => $result['booking']['phone']]);
        return $result;
    }

    /**
     * Зміна статусу з адмінки. При cancelled подію видаляємо або позначаємо "СКАСОВАНО" + transparent.
     *
     * @return array<string, mixed>
     */
    public function changeStatus(int $id, string $status): array
    {
        if (!in_array($status, BookingStatus::ALL, true)) {
            throw new ValidationError(['status' => 'Невідомий статус']);
        }
        $b = $this->bookings->find($id);
        if ($b === null) {
            throw new \OutOfBoundsException('Заявку не знайдено');
        }
        $prev = (string) $b['status'];
        $now = $this->clock->now();
        $update = ['status' => $status, 'updated_at' => $now];
        $calId = (string) ($b['calendar_id'] ?? '');
        $evId = (string) ($b['gcal_event_id'] ?? '');

        try {
            if ($status === BookingStatus::CANCELLED && $prev !== BookingStatus::CANCELLED && $calId !== '' && $evId !== '') {
                if ($this->config->get('cancel_mode') === 'delete') {
                    $this->calendar->deleteEvent($calId, $evId);
                    $update['gcal_event_id'] = null;
                } else {
                    $this->calendar->patchEvent($calId, $evId, [
                        'summary' => 'СКАСОВАНО | ' . $this->eventSummary($b),
                        'transparency' => 'transparent',
                    ]);
                }
                $this->events->deleteEvent($calId, $evId);
            } elseif (in_array($status, BookingStatus::ACTIVE, true) && $prev === BookingStatus::CANCELLED && $calId !== '') {
                // Відновлення скасованого запису.
                $b['status'] = $status;
                if ($evId !== '') {
                    $this->calendar->patchEvent($calId, $evId, ['summary' => $this->eventSummary($b), 'transparency' => 'opaque', 'description' => $this->eventDescription($b)]);
                } else {
                    $update['gcal_event_id'] = $this->calendar->insertEvent($calId, $this->eventResource($b));
                }
            } elseif ($status === BookingStatus::CONFIRMED && $prev === BookingStatus::PENDING && $calId !== '' && $evId !== '') {
                $b['status'] = $status;
                $this->calendar->patchEvent($calId, $evId, ['description' => $this->eventDescription($b)]);
            }
        } catch (\Throwable $e) {
            $update['sync_error'] = 'Календар: ' . substr($e->getMessage(), 0, 200);
            $this->log->error('status calendar sync failed', ['lead' => $b['lead_id'], 'error' => $e->getMessage()]);
        }
        $this->bookings->update($id, $update);
        return (array) $this->bookings->find($id);
    }

    /** @param array<string, mixed> $b */
    public function eventSummary(array $b): string
    {
        $doctor = $this->catalog->doctor((string) $b['doctor_id']);
        $service = $this->catalog->service((string) $b['service_id']);
        return sprintf('%s | ОНЛАЙН | %s', $doctor['surname'] ?? $b['doctor_id'], $service['name'] ?? $b['service_id']);
    }

    /** Опис події: без скарг і діагнозів (коментар пацієнта в календар не потрапляє). @param array<string, mixed> $b */
    public function eventDescription(array $b): string
    {
        $lines = [
            'Пацієнт: ' . $b['patient_name'],
            'Телефон: ' . Phone::pretty((string) $b['phone']),
        ];
        if (!empty($b['child_age'])) {
            $lines[] = 'Вік дитини: ' . $b['child_age'] . ' (записує законний представник)';
        }
        $lines[] = ($b['status'] ?? BookingStatus::PENDING) === BookingStatus::PENDING ? self::UNCONFIRMED_NOTE : self::CONFIRMED_NOTE;
        $lines[] = 'ID заявки: ' . $b['lead_id'];
        $lines[] = 'Джерело: онлайн-запис на сайті';
        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $b
     * @return array<string, mixed>
     */
    public function eventResource(array $b): array
    {
        $location = $this->catalog->location((string) $b['location_id']);
        $res = [
            'summary' => $this->eventSummary($b),
            'description' => $this->eventDescription($b),
            'start' => ['dateTime' => Tz::rfc3339Local((int) $b['start_ts']), 'timeZone' => 'Europe/Kyiv'],
            'end' => ['dateTime' => Tz::rfc3339Local((int) $b['end_ts']), 'timeZone' => 'Europe/Kyiv'],
            'extendedProperties' => ['private' => ['bpmb_lead' => (string) $b['lead_id']]],
        ];
        if ($location) {
            $res['location'] = (string) ($location['address'] ?? '');
        }
        $color = (string) $this->config->get('online_color_id');
        if ($color !== '') {
            $res['colorId'] = $color;
        }
        return $res;
    }

    /**
     * Повідомлення для адміністраторів (Telegram / email).
     *
     * @param array<string, mixed> $b
     * @return array<string, mixed>
     */
    public function notifyPayload(array $b): array
    {
        $doctor = $this->catalog->doctor((string) $b['doctor_id']);
        $service = $this->catalog->service((string) $b['service_id']);
        $location = $this->catalog->location((string) $b['location_id']);
        $source = array_filter([
            $b['utm_source'] ?? null,
            $b['utm_medium'] ?? null,
            $b['utm_campaign'] ?? null,
        ]);
        $clickIds = array_filter([
            !empty($b['gclid']) ? 'gclid' : null,
            !empty($b['gbraid']) ? 'gbraid' : null,
            !empty($b['wbraid']) ? 'wbraid' : null,
            !empty($b['fbclid']) ? 'fbclid' : null,
        ]);
        return [
            'type' => 'booking',
            'title' => 'Нова онлайн-заявка ' . $b['lead_id'],
            'lines' => array_values(array_filter([
                'Лікар: ' . ($doctor['full_name'] ?? $b['doctor_id']),
                'Послуга: ' . ($service['name'] ?? $b['service_id']),
                'Дата: ' . Tz::local((int) $b['start_ts'])->format('d.m.Y') . ' (' . self::weekdayName((int) Tz::local((int) $b['start_ts'])->format('N')) . ')',
                'Час: ' . Tz::time((int) $b['start_ts']) . '–' . Tz::time((int) $b['end_ts']),
                'Адреса: ' . ($location['address'] ?? '—'),
                "Ім'я: " . $b['patient_name'],
                'Телефон: ' . Phone::pretty((string) $b['phone']),
                !empty($b['child_age']) ? 'Вік дитини: ' . $b['child_age'] : null,
                !empty($b['comment']) ? 'Коментар: ' . $b['comment'] : null,
                'Джерело: ' . ($source ? implode(' / ', $source) : ($b['referrer'] ?? 'прямий / невідомо')) . ($clickIds ? ' [' . implode(', ', $clickIds) . ']' : ''),
                !empty($b['landing_page']) ? 'Сторінка входу: ' . $b['landing_page'] : null,
                !empty($b['sync_error']) ? '⚠️ ' . $b['sync_error'] : null,
                'Статус: не підтверджено, зателефонуйте пацієнту',
            ])),
        ];
    }

    /** @return array{name:string, phone:string, comment:?string, child_age:?string} */
    public function validatePatient(array $input, ?string $specialtyId): array
    {
        $errors = [];
        $name = trim((string) preg_replace('/\s+/u', ' ', (string) ($input['name'] ?? '')));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 100 || !preg_match("/^[\\p{L}' ’ʼ\\-.]+$/u", $name)) {
            $errors['name'] = "Вкажіть ім'я (лише літери)";
        }
        $phone = Phone::normalizeUa((string) ($input['phone'] ?? ''));
        if ($phone === null) {
            $errors['phone'] = 'Вкажіть коректний номер телефону у форматі +380 XX XXX XX XX';
        }
        if (empty($input['consent'])) {
            $errors['consent'] = 'Потрібна згода на обробку персональних даних';
        }
        $comment = trim((string) ($input['comment'] ?? ''));
        if (mb_strlen($comment) > 500) {
            $errors['comment'] = 'Коментар до 500 символів';
        }
        $childAge = null;
        if ($this->catalog->isChildSpecialty($specialtyId)) {
            $age = trim((string) ($input['child_age'] ?? ''));
            if ($age === '' || !preg_match('/^\d{1,2}$/', $age) || (int) $age > 17) {
                $errors['child_age'] = 'Вкажіть вік дитини (0–17 років)';
            } else {
                $childAge = $age;
            }
        }
        if ($errors) {
            throw new ValidationError($errors);
        }
        return ['name' => $name, 'phone' => (string) $phone, 'comment' => $comment !== '' ? $comment : null, 'child_age' => $childAge];
    }

    private function assertBookable(string $doctorId, string $serviceId): void
    {
        $doctor = $this->catalog->doctor($doctorId);
        if ($doctor === null || !$doctor['bookable']) {
            throw new ValidationError(['doctor_id' => 'Онлайн-запис до цього лікаря недоступний']);
        }
        if (!$this->catalog->doctorOffersService($doctorId, $serviceId)) {
            throw new ValidationError(['service_id' => 'Послуга недоступна для цього лікаря']);
        }
    }

    private function resolveSpecialty(string $doctorId, ?string $specialtyId): ?string
    {
        $doctor = $this->catalog->doctor($doctorId);
        $specs = (array) ($doctor['specialties'] ?? []);
        if ($specialtyId !== null && $specialtyId !== '') {
            $s = $this->catalog->findSpecialty($specialtyId);
            if ($s !== null) {
                $sid = (string) $s['id'];
                if (in_array($sid, $specs, true)) {
                    return $sid;
                }
                // Батьківська спеціальність (напр. "онколог" для хіміотерапевта).
                foreach ($specs as $ds) {
                    $child = $this->catalog->findSpecialty((string) $ds);
                    if ($child && ($child['parent_id'] ?? null) === $sid) {
                        return $sid;
                    }
                }
            }
        }
        return $specs ? (string) $specs[0] : null;
    }

    private static function weekdayName(int $n): string
    {
        return ['', 'пн', 'вт', 'ср', 'чт', 'пт', 'сб', 'нд'][$n] ?? '';
    }
}
