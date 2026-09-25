<?php
declare(strict_types=1);

namespace BPMedical\Booking\Storage;

/**
 * Реалізація через $wpdb (MySQL/MariaDB). Лок: GET_LOCK / RELEASE_LOCK. Він діє між
 * PHP-воркерами і не блокує таблиці.
 */
final class WpdbConnection implements Connection
{
    /** @var \wpdb */
    private $wpdb;

    /** @param \wpdb $wpdb */
    public function __construct($wpdb)
    {
        $this->wpdb = $wpdb;
    }

    public function table(string $name): string
    {
        return $this->wpdb->prefix . $name;
    }

    /** @param list<mixed> $params */
    private function prepare(string $sql, array $params): string
    {
        if (!$params) {
            return $sql;
        }
        $sql = str_replace('%', '%%', $sql);
        $values = [];
        $i = 0;
        $sql = (string) preg_replace_callback('/\?/', function () use (&$i, $params, &$values) {
            $v = $params[$i++] ?? null;
            if ($v === null) {
                return 'NULL';
            }
            if (is_bool($v)) {
                $values[] = (int) $v;
                return '%d';
            }
            if (is_int($v)) {
                $values[] = $v;
                return '%d';
            }
            if (is_float($v)) {
                $values[] = $v;
                return '%f';
            }
            $values[] = (string) $v;
            return '%s';
        }, $sql);
        return $values ? (string) $this->wpdb->prepare($sql, $values) : str_replace('%%', '%', $sql);
    }

    public function execute(string $sql, array $params = []): int
    {
        $r = $this->wpdb->query($this->prepare($sql, $params));
        if ($r === false) {
            throw new \RuntimeException('DB error: ' . $this->wpdb->last_error);
        }
        return (int) $r;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $rows = $this->wpdb->get_results($this->prepare($sql, $params), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    public function fetchRow(string $sql, array $params = []): ?array
    {
        $row = $this->wpdb->get_row($this->prepare($sql, $params), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    public function insert(string $table, array $data): int
    {
        if ($this->wpdb->insert($table, $data) === false) {
            throw new \RuntimeException('DB insert error: ' . $this->wpdb->last_error);
        }
        return (int) $this->wpdb->insert_id;
    }

    public function update(string $table, array $data, array $where): int
    {
        $r = $this->wpdb->update($table, $data, $where);
        if ($r === false) {
            throw new \RuntimeException('DB update error: ' . $this->wpdb->last_error);
        }
        return (int) $r;
    }

    public function withLock(string $name, int $timeoutSec, callable $fn)
    {
        $lock = substr($this->wpdb->prefix . $name, 0, 64);
        $got = $this->wpdb->get_var($this->wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock, $timeoutSec));
        if ((string) $got !== '1') {
            throw new LockTimeout("Lock timeout: $name");
        }
        try {
            return $fn();
        } finally {
            $this->wpdb->query($this->wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }
}
