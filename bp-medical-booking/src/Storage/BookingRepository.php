<?php
declare(strict_types=1);

namespace BPMedical\Booking\Storage;

use BPMedical\Booking\Core\BookingStatus;
use BPMedical\Booking\Core\Interval;

final class BookingRepository
{
    private Connection $db;
    private string $t;

    public function __construct(Connection $db)
    {
        $this->db = $db;
        $this->t = $db->table('bpmb_bookings');
    }

    /** @param array<string, mixed> $row */
    public function insert(array $row): int
    {
        return $this->db->insert($this->t, $row);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->fetchRow("SELECT * FROM {$this->t} WHERE id = ?", [$id]);
    }

    /** @return array<string, mixed>|null */
    public function findByLead(string $leadId): ?array
    {
        return $this->db->fetchRow("SELECT * FROM {$this->t} WHERE lead_id = ?", [$leadId]);
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->db->update($this->t, $data, ['id' => $id]);
    }

    /** Активні записи (pending/confirmed) займають час разом із буфером. @return Interval[] */
    public function busyIntervals(string $doctorId, int $from, int $to): array
    {
        $rows = $this->db->fetchAll(
            "SELECT start_ts, block_end_ts FROM {$this->t} WHERE doctor_id = ? AND status IN (?, ?) AND block_end_ts > ? AND start_ts < ?",
            [$doctorId, BookingStatus::PENDING, BookingStatus::CONFIRMED, $from, $to]
        );
        return array_map(static fn($r) => new Interval((int) $r['start_ts'], (int) $r['block_end_ts']), $rows);
    }

    /**
     * Адміністратор переніс онлайн-подію в календарі: переносимо й заявку (буфер зберігається).
     */
    public function moveByLead(string $leadId, int $start, int $end): int
    {
        // block_end_ts першим: MySQL обчислює присвоєння зліва направо.
        return $this->db->execute(
            "UPDATE {$this->t} SET block_end_ts = ? + (block_end_ts - end_ts), start_ts = ?, end_ts = ?
             WHERE lead_id = ? AND status IN (?, ?) AND (start_ts <> ? OR end_ts <> ?)",
            [$end, $start, $end, $leadId, BookingStatus::PENDING, BookingStatus::CONFIRMED, $start, $end]
        );
    }

    /**
     * @param array{status?:string, doctor_id?:string, phone?:string, from?:int, to?:int, created_from?:int, created_to?:int} $f
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function search(array $f, int $limit = 50, int $offset = 0): array
    {
        [$where, $params] = $this->where($f);
        $total = (int) ($this->db->fetchRow("SELECT COUNT(*) AS n FROM {$this->t} $where", $params)['n'] ?? 0);
        $items = $this->db->fetchAll(
            "SELECT * FROM {$this->t} $where ORDER BY start_ts DESC LIMIT " . max(1, $limit) . ' OFFSET ' . max(0, $offset),
            $params
        );
        return ['items' => $items, 'total' => $total];
    }

    /**
     * @param array<string, mixed> $f
     * @return array{0:string,1:list<mixed>}
     */
    private function where(array $f): array
    {
        $cond = [];
        $params = [];
        if (!empty($f['status'])) {
            $cond[] = 'status = ?';
            $params[] = (string) $f['status'];
        }
        if (!empty($f['doctor_id'])) {
            $cond[] = 'doctor_id = ?';
            $params[] = (string) $f['doctor_id'];
        }
        if (!empty($f['phone'])) {
            $cond[] = 'phone LIKE ?';
            $params[] = '%' . preg_replace('/\D/', '', (string) $f['phone']) . '%';
        }
        foreach (['from' => 'start_ts >= ?', 'to' => 'start_ts < ?', 'created_from' => 'created_at >= ?', 'created_to' => 'created_at < ?'] as $k => $sql) {
            if (isset($f[$k]) && $f[$k] !== '') {
                $cond[] = $sql;
                $params[] = (int) $f[$k];
            }
        }
        return [$cond ? 'WHERE ' . implode(' AND ', $cond) : '', $params];
    }

    /**
     * Анонімізація записів, старших за $before (за created_at).
     */
    public function anonymizeBefore(int $before, int $now): int
    {
        return $this->db->execute(
            "UPDATE {$this->t} SET patient_name = ?, phone = NULL, phone_hash = NULL, comment = NULL, child_age = NULL, anonymized_at = ?
             WHERE created_at < ? AND anonymized_at IS NULL",
            ['[анонімізовано]', $now, $before]
        );
    }
}
