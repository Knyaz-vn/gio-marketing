<?php
declare(strict_types=1);

namespace BPMedical\Booking\Storage;

/**
 * Мінімальна абстракція БД. SQL пишеться з плейсхолдерами "?" у переносимому підмножині
 * (MySQL у WordPress, SQLite у тестах).
 */
interface Connection
{
    /** Повне ім'я таблиці з префіксом, напр. wp_bpmb_bookings. */
    public function table(string $name): string;

    /** @param list<mixed> $params */
    public function execute(string $sql, array $params = []): int;

    /**
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array;

    /**
     * @param list<mixed> $params
     * @return array<string, mixed>|null
     */
    public function fetchRow(string $sql, array $params = []): ?array;

    /** @param array<string, mixed> $data */
    public function insert(string $table, array $data): int;

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     */
    public function update(string $table, array $data, array $where): int;

    /**
     * Виконує $fn під іменованим ексклюзивним локом (між процесами/запитами).
     * Кидає LockTimeout, якщо лок не отримано за $timeoutSec.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function withLock(string $name, int $timeoutSec, callable $fn);
}
