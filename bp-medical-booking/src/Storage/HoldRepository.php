<?php
declare(strict_types=1);

namespace BPMedical\Booking\Storage;

use BPMedical\Booking\Core\Interval;

/** Тимчасові утримання слота (7 хв), захист від подвійного запису під час заповнення форми. */
final class HoldRepository
{
    private Connection $db;
    private string $t;

    public function __construct(Connection $db)
    {
        $this->db = $db;
        $this->t = $db->table('bpmb_holds');
    }

    public function create(string $token, string $doctorId, string $serviceId, string $locationId, int $start, int $end, int $expires, int $now): void
    {
        $this->db->insert($this->t, [
            'token' => $token,
            'doctor_id' => $doctorId,
            'service_id' => $serviceId,
            'location_id' => $locationId,
            'start_ts' => $start,
            'end_ts' => $end,
            'expires_ts' => $expires,
            'created_at' => $now,
        ]);
    }

    /** @return array<string, mixed>|null */
    public function find(string $token): ?array
    {
        return $this->db->fetchRow("SELECT * FROM {$this->t} WHERE token = ?", [$token]);
    }

    /** @return Interval[] */
    public function busyIntervals(string $doctorId, int $from, int $to, int $now, ?string $excludeToken = null): array
    {
        $rows = $this->db->fetchAll(
            "SELECT token, start_ts, end_ts FROM {$this->t} WHERE doctor_id = ? AND expires_ts > ? AND end_ts > ? AND start_ts < ?",
            [$doctorId, $now, $from, $to]
        );
        $out = [];
        foreach ($rows as $r) {
            if ($excludeToken !== null && hash_equals((string) $r['token'], $excludeToken)) {
                continue;
            }
            $out[] = new Interval((int) $r['start_ts'], (int) $r['end_ts']);
        }
        return $out;
    }

    public function delete(string $token): void
    {
        $this->db->execute("DELETE FROM {$this->t} WHERE token = ?", [$token]);
    }

    public function purgeExpired(int $now): int
    {
        return $this->db->execute("DELETE FROM {$this->t} WHERE expires_ts < ?", [$now - 3600]);
    }
}
