<?php
declare(strict_types=1);

namespace BPMedical\Booking\Storage;

use PDO;

/**
 * PDO-реалізація (SQLite для тестів і локального запуску; Postgres/MySQL теж працюватимуть).
 * Лок: flock на файлі в $lockDir, працює між процесами.
 */
final class PdoConnection implements Connection
{
    private PDO $pdo;
    private string $prefix;
    private string $lockDir;

    public function __construct(PDO $pdo, string $prefix, string $lockDir)
    {
        $this->pdo = $pdo;
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->prefix = $prefix;
        $this->lockDir = $lockDir;
    }

    public static function sqlite(string $file, string $lockDir, string $prefix = 'wp_'): self
    {
        $pdo = new PDO('sqlite:' . $file);
        $pdo->setAttribute(PDO::ATTR_TIMEOUT, 10);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA busy_timeout=10000');
        return new self($pdo, $prefix, $lockDir);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function table(string $name): string
    {
        return $this->prefix . $name;
    }

    public function execute(string $sql, array $params = []): int
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public function fetchRow(string $sql, array $params = []): ?array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    public function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(',', $cols),
            implode(',', array_fill(0, count($cols), '?'))
        );
        $this->execute($sql, array_values($data));
        return (int) $this->pdo->lastInsertId();
    }

    public function update(string $table, array $data, array $where): int
    {
        $set = implode(',', array_map(static fn($c) => "$c = ?", array_keys($data)));
        $cond = implode(' AND ', array_map(static fn($c) => "$c = ?", array_keys($where)));
        return $this->execute("UPDATE $table SET $set WHERE $cond", array_merge(array_values($data), array_values($where)));
    }

    public function withLock(string $name, int $timeoutSec, callable $fn)
    {
        if (!is_dir($this->lockDir)) {
            @mkdir($this->lockDir, 0777, true);
        }
        $path = $this->lockDir . '/' . preg_replace('/[^a-z0-9_.-]/i', '_', $name) . '.lock';
        $fh = fopen($path, 'c');
        if ($fh === false) {
            throw new LockTimeout("Cannot open lock file $path");
        }
        $deadline = microtime(true) + $timeoutSec;
        while (!flock($fh, LOCK_EX | LOCK_NB)) {
            if (microtime(true) > $deadline) {
                fclose($fh);
                throw new LockTimeout("Lock timeout: $name");
            }
            usleep(20000);
        }
        try {
            return $fn();
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }
}
