<?php
declare(strict_types=1);

namespace BPMedical\Booking\Storage;

use BPMedical\Booking\Core\CalendarEvent;
use BPMedical\Booking\Core\EventClassifier;
use BPMedical\Booking\Core\Interval;

/**
 * Кеш подій календаря з результатом класифікації (doctor_ids).
 * summary зберігається лише для діагностики в адмінці, у публічний API не потрапляє.
 */
final class EventCacheRepository
{
    private Connection $db;
    private string $t;

    public function __construct(Connection $db)
    {
        $this->db = $db;
        $this->t = $db->table('bpmb_events');
    }

    /**
     * Синхронізує вікно [from, to) одного календаря з актуальним списком подій.
     *
     * @param CalendarEvent[] $events
     */
    public function replaceWindow(string $calendarId, int $from, int $to, array $events, EventClassifier $classifier, int $now): int
    {
        $stamp = $now * 1000 + random_int(0, 999);
        $count = 0;
        foreach ($events as $e) {
            $c = $classifier->classify($e);
            if (in_array(EventClassifier::FLAG_IGNORED, $c['flags'], true)) {
                continue;
            }
            $row = [
                'summary' => $e->summary,
                'start_ts' => $e->start,
                'end_ts' => $e->end,
                'all_day' => $e->allDay ? 1 : 0,
                'status' => $e->status,
                'transparency' => $e->transparency,
                'doctor_ids' => self::idList($c['doctor_ids']),
                'suspicious_ids' => self::idList($c['suspicious_ids']),
                'flags' => implode(',', $c['flags']),
                'synced_at' => $stamp,
            ];
            $existing = $this->db->fetchRow("SELECT id FROM {$this->t} WHERE calendar_id = ? AND event_id = ?", [$calendarId, $e->id]);
            if ($existing) {
                $this->db->update($this->t, $row, ['id' => (int) $existing['id']]);
            } else {
                $this->db->insert($this->t, $row + ['calendar_id' => $calendarId, 'event_id' => $e->id]);
            }
            $count++;
        }
        // Події з вікна, яких більше немає в календарі (видалені / скасовані / стали transparent).
        $this->db->execute(
            "DELETE FROM {$this->t} WHERE calendar_id = ? AND end_ts > ? AND start_ts < ? AND (synced_at IS NULL OR synced_at <> ?)",
            [$calendarId, $from, $to, $stamp]
        );
        return $count;
    }

    /** Додає/оновлює одну подію (наприклад, щойно створений онлайн-запис). */
    public function upsertOne(CalendarEvent $e, EventClassifier $classifier, int $now): void
    {
        $c = $classifier->classify($e);
        $existing = $this->db->fetchRow("SELECT id FROM {$this->t} WHERE calendar_id = ? AND event_id = ?", [$e->calendarId, $e->id]);
        if (in_array(EventClassifier::FLAG_IGNORED, $c['flags'], true)) {
            if ($existing) {
                $this->db->execute("DELETE FROM {$this->t} WHERE id = ?", [(int) $existing['id']]);
            }
            return;
        }
        $row = [
            'summary' => $e->summary,
            'start_ts' => $e->start,
            'end_ts' => $e->end,
            'all_day' => $e->allDay ? 1 : 0,
            'status' => $e->status,
            'transparency' => $e->transparency,
            'doctor_ids' => self::idList($c['doctor_ids']),
            'suspicious_ids' => self::idList($c['suspicious_ids']),
            'flags' => implode(',', $c['flags']),
            'synced_at' => $now * 1000,
        ];
        if ($existing) {
            $this->db->update($this->t, $row, ['id' => (int) $existing['id']]);
        } else {
            $this->db->insert($this->t, $row + ['calendar_id' => $e->calendarId, 'event_id' => $e->id]);
        }
    }

    public function deleteEvent(string $calendarId, string $eventId): void
    {
        $this->db->execute("DELETE FROM {$this->t} WHERE calendar_id = ? AND event_id = ?", [$calendarId, $eventId]);
    }

    /** @return Interval[] */
    public function busyIntervals(string $doctorId, int $from, int $to): array
    {
        $rows = $this->db->fetchAll(
            "SELECT start_ts, end_ts FROM {$this->t} WHERE doctor_ids LIKE ? AND end_ts > ? AND start_ts < ?",
            ['%,' . $doctorId . ',%', $from, $to]
        );
        return array_map(static fn($r) => new Interval((int) $r['start_ts'], (int) $r['end_ts']), $rows);
    }

    /**
     * Перекласифікація всього кешу після зміни лікарів, aliases або match_mode.
     */
    public function reclassifyAll(EventClassifier $classifier): int
    {
        $rows = $this->db->fetchAll("SELECT id, calendar_id, event_id, summary, start_ts, end_ts, all_day, status, transparency FROM {$this->t}");
        foreach ($rows as $r) {
            $e = self::rowToEvent($r);
            $c = $classifier->classify($e);
            $this->db->update($this->t, [
                'doctor_ids' => self::idList($c['doctor_ids']),
                'suspicious_ids' => self::idList($c['suspicious_ids']),
                'flags' => implode(',', $c['flags']),
            ], ['id' => (int) $r['id']]);
        }
        return count($rows);
    }

    /**
     * Діагностика для адмінки.
     *
     * @return list<array<string, mixed>>
     */
    public function withFlag(string $flag, int $from, int $to, int $limit = 300): array
    {
        return $this->db->fetchAll(
            "SELECT calendar_id, event_id, summary, start_ts, end_ts, all_day, doctor_ids, suspicious_ids, flags
             FROM {$this->t} WHERE flags LIKE ? AND end_ts > ? AND start_ts < ? ORDER BY start_ts LIMIT " . (int) $limit,
            ['%' . $flag . '%', $from, $to]
        );
    }

    /** @return array{total:int, last_synced:int} */
    public function stats(): array
    {
        $r = $this->db->fetchRow("SELECT COUNT(*) AS n, MAX(synced_at) AS s FROM {$this->t}");
        return ['total' => (int) ($r['n'] ?? 0), 'last_synced' => (int) floor(((int) ($r['s'] ?? 0)) / 1000)];
    }

    public function purgeEndedBefore(int $ts): int
    {
        return $this->db->execute("DELETE FROM {$this->t} WHERE end_ts < ?", [$ts]);
    }

    /** @param array<string, mixed> $r */
    public static function rowToEvent(array $r): CalendarEvent
    {
        return new CalendarEvent(
            (string) $r['calendar_id'],
            (string) $r['event_id'],
            (string) $r['summary'],
            (int) $r['start_ts'],
            (int) $r['end_ts'],
            (bool) $r['all_day'],
            (string) ($r['status'] ?? 'confirmed'),
            (string) ($r['transparency'] ?? 'opaque')
        );
    }

    /** @param string[] $ids */
    private static function idList(array $ids): string
    {
        return $ids ? ',' . implode(',', $ids) . ',' : '';
    }
}
