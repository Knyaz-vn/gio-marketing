<?php
declare(strict_types=1);

namespace BPMedical\Booking\Storage;

/**
 * Опис таблиць. Генерує DDL для MySQL (dbDelta) і SQLite (тести). Усі часи зберігаються
 * як unix timestamp (UTC).
 */
final class Schema
{
    public const VERSION = '1.0.0';

    private const ATTRIBUTION = [
        'gclid' => 'str:255', 'gbraid' => 'str:255', 'wbraid' => 'str:255', 'fbclid' => 'str:255',
        'utm_source' => 'str:255', 'utm_medium' => 'str:255', 'utm_campaign' => 'str:255',
        'utm_term' => 'str:255', 'utm_content' => 'str:255',
        'landing_page' => 'text', 'referrer' => 'text',
    ];

    /** @return array<string, array{columns: array<string,string>, keys: list<array{0:string,1:string,2:string[]}>}> */
    public static function tables(): array
    {
        return [
            'bpmb_events' => [
                'columns' => [
                    'id' => 'pk',
                    'calendar_id' => 'str:190',
                    'event_id' => 'str:190',
                    'summary' => 'text',
                    'start_ts' => 'int',
                    'end_ts' => 'int',
                    'all_day' => 'int',
                    'status' => 'str:20',
                    'transparency' => 'str:20',
                    'doctor_ids' => 'str:255',
                    'suspicious_ids' => 'str:255',
                    'flags' => 'str:100',
                    'synced_at' => 'int',
                ],
                'keys' => [
                    ['unique', 'cal_event', ['calendar_id(90)', 'event_id(90)']],
                    ['index', 'span', ['start_ts', 'end_ts']],
                ],
            ],
            'bpmb_holds' => [
                'columns' => [
                    'id' => 'pk',
                    'token' => 'str:64',
                    'doctor_id' => 'str:64',
                    'service_id' => 'str:64',
                    'location_id' => 'str:64',
                    'start_ts' => 'int',
                    'end_ts' => 'int',
                    'expires_ts' => 'int',
                    'created_at' => 'int',
                ],
                'keys' => [
                    ['unique', 'token', ['token']],
                    ['index', 'doctor_span', ['doctor_id', 'start_ts']],
                ],
            ],
            'bpmb_bookings' => [
                'columns' => [
                    'id' => 'pk',
                    'lead_id' => 'str:20',
                    'doctor_id' => 'str:64',
                    'service_id' => 'str:64',
                    'specialty_id' => 'str:64',
                    'location_id' => 'str:64',
                    'calendar_id' => 'str:190',
                    'gcal_event_id' => 'str:190',
                    'start_ts' => 'int',
                    'end_ts' => 'int',
                    'block_end_ts' => 'int',
                    'status' => 'str:20',
                    'patient_name' => 'str:200',
                    'phone' => 'str:20',
                    'phone_hash' => 'str:64',
                    'comment' => 'text',
                    'child_age' => 'str:20',
                ] + self::ATTRIBUTION + [
                    'entry' => 'str:30',
                    'sync_error' => 'text',
                    'created_at' => 'int',
                    'updated_at' => 'int',
                    'anonymized_at' => 'int',
                ],
                'keys' => [
                    ['unique', 'lead_id', ['lead_id']],
                    ['index', 'doctor_span', ['doctor_id', 'start_ts']],
                    ['index', 'status', ['status']],
                    ['index', 'phone', ['phone']],
                    ['index', 'created_at', ['created_at']],
                ],
            ],
            'bpmb_callbacks' => [
                'columns' => [
                    'id' => 'pk',
                    'lead_id' => 'str:20',
                    'name' => 'str:200',
                    'phone' => 'str:20',
                    'phone_hash' => 'str:64',
                    'comment' => 'text',
                    'doctor_id' => 'str:64',
                    'specialty_id' => 'str:64',
                    'status' => 'str:20',
                ] + self::ATTRIBUTION + [
                    'entry' => 'str:30',
                    'created_at' => 'int',
                    'updated_at' => 'int',
                    'anonymized_at' => 'int',
                ],
                'keys' => [
                    ['index', 'status', ['status']],
                    ['index', 'phone', ['phone']],
                    ['index', 'created_at', ['created_at']],
                ],
            ],
        ];
    }

    /** @return string[] SQL для dbDelta (формат, який розуміє dbDelta: два пробіли після PRIMARY KEY). */
    public static function mysql(string $prefix, string $charsetCollate): array
    {
        $out = [];
        foreach (self::tables() as $name => $t) {
            $lines = [];
            foreach ($t['columns'] as $col => $type) {
                $lines[] = "$col " . self::mysqlType($type);
            }
            $lines[] = 'PRIMARY KEY  (id)';
            foreach ($t['keys'] as [$kind, $kname, $cols]) {
                $lines[] = ($kind === 'unique' ? 'UNIQUE KEY' : 'KEY') . " $kname (" . implode(',', $cols) . ')';
            }
            $out[] = "CREATE TABLE {$prefix}{$name} (\n" . implode(",\n", $lines) . "\n) $charsetCollate;";
        }
        return $out;
    }

    /** @return string[] */
    public static function sqlite(string $prefix): array
    {
        $out = [];
        foreach (self::tables() as $name => $t) {
            $lines = [];
            foreach ($t['columns'] as $col => $type) {
                $lines[] = "$col " . self::sqliteType($type);
            }
            $out[] = "CREATE TABLE IF NOT EXISTS {$prefix}{$name} (" . implode(', ', $lines) . ')';
            foreach ($t['keys'] as [$kind, $kname, $cols]) {
                $cols = array_map(static fn($c) => preg_replace('/\(\d+\)$/', '', $c), $cols);
                $out[] = 'CREATE ' . ($kind === 'unique' ? 'UNIQUE ' : '') . "INDEX IF NOT EXISTS {$prefix}{$name}_{$kname} ON {$prefix}{$name} (" . implode(',', $cols) . ')';
            }
        }
        return $out;
    }

    private static function mysqlType(string $t): string
    {
        if ($t === 'pk') {
            return 'bigint(20) unsigned NOT NULL AUTO_INCREMENT';
        }
        if ($t === 'int') {
            return 'bigint(20) NULL DEFAULT NULL';
        }
        if ($t === 'text') {
            return 'text NULL';
        }
        return 'varchar(' . substr($t, 4) . ') NULL DEFAULT NULL';
    }

    private static function sqliteType(string $t): string
    {
        if ($t === 'pk') {
            return 'INTEGER PRIMARY KEY AUTOINCREMENT';
        }
        return $t === 'int' ? 'INTEGER NULL' : 'TEXT NULL';
    }
}
