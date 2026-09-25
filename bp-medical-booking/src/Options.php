<?php
declare(strict_types=1);

namespace BPMedical\Booking;

/**
 * Зберігання налаштувань і довідників у wp_options (редагуються в адмінці).
 * Початкові значення беруться з data/*.json при активації.
 */
final class Options
{
    public const SETTINGS = 'bpmb_settings';
    public const DOCTORS = 'bpmb_doctors';
    public const SPECIALTIES = 'bpmb_specialties';
    public const SERVICES = 'bpmb_services';
    public const LOCATIONS = 'bpmb_locations';
    public const WATCH = 'bpmb_watch_state';
    public const SYNC_REPORT = 'bpmb_sync_report';
    public const CACHE_VER = 'bpmb_cache_ver';
    public const DB_VERSION = 'bpmb_db_version';

    private const SEED = [
        self::SETTINGS => 'settings.json',
        self::DOCTORS => 'doctors.json',
        self::SPECIALTIES => 'specialties.json',
        self::SERVICES => 'services.json',
        self::LOCATIONS => 'locations.json',
    ];

    /** @return array<mixed> */
    public static function get(string $name): array
    {
        $v = get_option($name, null);
        if (!is_array($v)) {
            $v = self::seedValue($name);
        }
        return $v;
    }

    /** @param array<mixed> $value */
    public static function set(string $name, array $value): void
    {
        update_option($name, $value, $name !== self::WATCH && $name !== self::SYNC_REPORT);
        self::bumpCache();
    }

    /** Записує початкові дані, якщо опцій ще немає (або примусово). */
    public static function seed(bool $overwrite = false): void
    {
        foreach (array_keys(self::SEED) as $name) {
            if ($overwrite || !is_array(get_option($name, null))) {
                update_option($name, self::seedValue($name), true);
            }
        }
        self::bumpCache();
    }

    /** @return array<mixed> */
    public static function seedValue(string $name): array
    {
        $file = self::SEED[$name] ?? null;
        if ($file === null) {
            return [];
        }
        $data = json_decode((string) file_get_contents(BPMB_DIR . '/data/' . $file), true);
        return is_array($data) ? $data : [];
    }

    /** Версія для інвалідації кешу публічних відповідей (transients). */
    public static function cacheVersion(): int
    {
        return (int) get_option(self::CACHE_VER, 1);
    }

    public static function bumpCache(): void
    {
        update_option(self::CACHE_VER, self::cacheVersion() + 1, true);
    }
}
