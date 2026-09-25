<?php
declare(strict_types=1);

namespace BPMedical\Booking\Calendar;

use BPMedical\Booking\Core\CalendarEvent;
use BPMedical\Booking\Core\Tz;

/**
 * Mock Google Calendar: події зберігаються в JSON-файлі у форматі Google API.
 * Для локального запуску без ключів і для тестів (безпечний між процесами завдяки flock).
 */
final class MockCalendarClient implements CalendarClient
{
    private string $file;

    public function __construct(string $file)
    {
        $this->file = $file;
    }

    public function supportsWatch(): bool
    {
        return false;
    }

    public function listEvents(string $calendarId, int $timeMin, int $timeMax): array
    {
        $data = $this->read();
        $out = [];
        foreach ((array) ($data[$calendarId] ?? []) as $item) {
            $e = CalendarEvent::fromGoogle($calendarId, $item);
            if ($e->end > $timeMin && $e->start < $timeMax) {
                $out[] = $e;
            }
        }
        usort($out, static fn(CalendarEvent $a, CalendarEvent $b) => $a->start <=> $b->start);
        return $out;
    }

    public function insertEvent(string $calendarId, array $event): string
    {
        $id = 'mock' . bin2hex(random_bytes(8));
        $this->mutate(static function (array $data) use ($calendarId, $event, $id): array {
            $event['id'] = $id;
            $event += ['status' => 'confirmed'];
            $data[$calendarId][$id] = $event;
            return $data;
        });
        return $id;
    }

    public function patchEvent(string $calendarId, string $eventId, array $patch): void
    {
        $this->mutate(static function (array $data) use ($calendarId, $eventId, $patch): array {
            if (!isset($data[$calendarId][$eventId])) {
                throw new CalendarException('Google Calendar HTTP 404: not found');
            }
            $data[$calendarId][$eventId] = array_replace($data[$calendarId][$eventId], $patch);
            return $data;
        });
    }

    public function deleteEvent(string $calendarId, string $eventId): void
    {
        $this->mutate(static function (array $data) use ($calendarId, $eventId): array {
            unset($data[$calendarId][$eventId]);
            return $data;
        });
    }

    public function watch(string $calendarId, string $channelId, string $address, string $token, int $ttlSec): array
    {
        throw new CalendarException('Mock calendar не підтримує push-сповіщення');
    }

    public function stopChannel(string $channelId, string $resourceId): void
    {
    }

    /** Додає подію (для тестів і демо). */
    public function addTimed(string $calendarId, string $summary, int $start, int $end, array $extra = []): string
    {
        return $this->insertEvent($calendarId, $extra + [
            'summary' => $summary,
            'start' => ['dateTime' => Tz::rfc3339Local($start)],
            'end' => ['dateTime' => Tz::rfc3339Local($end)],
        ]);
    }

    public function addAllDay(string $calendarId, string $summary, string $fromYmd, string $toYmdExclusive): string
    {
        return $this->insertEvent($calendarId, [
            'summary' => $summary,
            'start' => ['date' => $fromYmd],
            'end' => ['date' => $toYmdExclusive],
        ]);
    }

    /** @return array<string, array<string, array<string, mixed>>> */
    public function dump(): array
    {
        return $this->read();
    }

    /**
     * Демо-дані на найближчі 2 тижні: звичайні записи, операція з двома лікарями, відпустка,
     * нерозпізнана подія, збіг прізвища пацієнта, transparent і скасована події.
     *
     * @param array<int, array{id:string, location_id:?string}> $calendars
     */
    public function seedDemo(array $calendars, int $now): void
    {
        if (is_file($this->file) && filesize($this->file) > 2) {
            return;
        }
        $koriat = $calendars[0]['id'] ?? 'mock-koriat';
        $strilets = $calendars[1]['id'] ?? $koriat;
        $today = Tz::date($now);
        for ($i = 0; $i < 14; $i++) {
            $d = Tz::addDays($today, $i);
            $wd = Tz::weekday($d);
            if ($wd === 7) {
                continue;
            }
            $this->addTimed($koriat, 'Грибанова | консультація | Іваненко О.', Tz::at($d, '10:00'), Tz::at($d, '10:30'));
            $this->addTimed($koriat, 'Цимбалюк | повторна | Савчук', Tz::at($d, '12:00'), Tz::at($d, '12:20'));
            if ($wd <= 5) {
                $this->addTimed($strilets, 'Біктіміров + Машевський | операція | Петренко', Tz::at($d, '09:00'), Tz::at($d, '12:00'));
                $this->addTimed($koriat, 'Мусієнко | УЗД | Король Ірина', Tz::at($d, '14:00'), Tz::at($d, '14:30'));
            }
            if ($i % 3 === 0) {
                $this->addTimed($koriat, 'Нарада адміністраторів', Tz::at($d, '17:00'), Tz::at($d, '17:30'));
                $this->addTimed($koriat, 'Дерев’янко | консультація | Бондар', Tz::at($d, '11:00'), Tz::at($d, '12:00'));
            }
            if ($i === 2) {
                $this->addTimed($koriat, 'Машевська | скасовано', Tz::at($d, '15:00'), Tz::at($d, '16:00'), ['status' => 'cancelled']);
                $this->addTimed($koriat, 'Дуда | нагадування', Tz::at($d, '09:00'), Tz::at($d, '18:00'), ['transparency' => 'transparent']);
            }
        }
        $this->addAllDay($koriat, 'Машевська відпустка', Tz::addDays($today, 5), Tz::addDays($today, 8));
    }

    /** @return array<string, array<string, array<string, mixed>>> */
    private function read(): array
    {
        if (!is_file($this->file)) {
            return [];
        }
        $fh = fopen($this->file, 'r');
        if ($fh === false) {
            return [];
        }
        flock($fh, LOCK_SH);
        $raw = stream_get_contents($fh);
        flock($fh, LOCK_UN);
        fclose($fh);
        $data = json_decode((string) $raw, true);
        return is_array($data) ? $data : [];
    }

    private function mutate(callable $fn): void
    {
        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        $fh = fopen($this->file, 'c+');
        if ($fh === false) {
            throw new CalendarException('Mock calendar: не вдалося відкрити ' . $this->file);
        }
        flock($fh, LOCK_EX);
        $raw = stream_get_contents($fh);
        $data = json_decode((string) $raw, true);
        $data = $fn(is_array($data) ? $data : []);
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        fflush($fh);
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}
