<?php
declare(strict_types=1);

namespace BPMedical\Booking\Storage;

final class CallbackRepository
{
    public const STATUSES = ['new', 'done', 'spam'];

    private Connection $db;
    private string $t;

    public function __construct(Connection $db)
    {
        $this->db = $db;
        $this->t = $db->table('bpmb_callbacks');
    }

    /** @param array<string, mixed> $row */
    public function insert(array $row): int
    {
        return $this->db->insert($this->t, $row);
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->db->update($this->t, $data, ['id' => $id]);
    }

    /**
     * @param array{status?:string, phone?:string} $f
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function search(array $f, int $limit = 50, int $offset = 0): array
    {
        $cond = [];
        $params = [];
        if (!empty($f['status'])) {
            $cond[] = 'status = ?';
            $params[] = (string) $f['status'];
        }
        if (!empty($f['phone'])) {
            $cond[] = 'phone LIKE ?';
            $params[] = '%' . preg_replace('/\D/', '', (string) $f['phone']) . '%';
        }
        $where = $cond ? 'WHERE ' . implode(' AND ', $cond) : '';
        $total = (int) ($this->db->fetchRow("SELECT COUNT(*) AS n FROM {$this->t} $where", $params)['n'] ?? 0);
        $items = $this->db->fetchAll(
            "SELECT * FROM {$this->t} $where ORDER BY created_at DESC LIMIT " . max(1, $limit) . ' OFFSET ' . max(0, $offset),
            $params
        );
        return ['items' => $items, 'total' => $total];
    }

    public function anonymizeBefore(int $before, int $now): int
    {
        return $this->db->execute(
            "UPDATE {$this->t} SET name = ?, phone = NULL, phone_hash = NULL, comment = NULL, anonymized_at = ?
             WHERE created_at < ? AND anonymized_at IS NULL",
            ['[анонімізовано]', $now, $before]
        );
    }
}
