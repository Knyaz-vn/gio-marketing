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
    public const SEED_VERSION_OPTION = 'bpmb_seed_version';
    /** Збільшується, коли в data/*.json з'являються нові лікарі / фото / спеціальності. */
    public const SEED_VERSION = 3;

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

    /**
     * Доповнює вже збережені довідники новими даними з data/*.json, не затираючи правок адміністратора:
     *  - нові лікарі й спеціальності додаються;
     *  - у наявних лікарів заповнюється порожнє фото, а старі посилання /doctors-2/... замінюються на актуальні;
     *  - графіки, aliases, послуги, bookable не змінюються.
     */
    public static function syncSeed(): void
    {
        if ((int) get_option(self::SEED_VERSION_OPTION, 0) >= self::SEED_VERSION) {
            return;
        }
        if (is_array(get_option(self::DOCTORS, null))) {
            update_option(self::DOCTORS, self::mergeDoctors(self::get(self::DOCTORS), self::seedValue(self::DOCTORS)), true);
        }
        if (is_array(get_option(self::SPECIALTIES, null))) {
            $specs = self::get(self::SPECIALTIES);
            $ids = array_column($specs, 'id');
            foreach (self::seedValue(self::SPECIALTIES) as $s) {
                if (!in_array($s['id'], $ids, true)) {
                    $specs[] = $s;
                }
            }
            update_option(self::SPECIALTIES, $specs, true);
        }
        if ((int) get_option(self::SEED_VERSION_OPTION, 0) < 3 && is_array(get_option(self::SETTINGS, null))) {
            // v3: старі форми й попап Popup Maker лишаються як були; запис лише через окремі кнопки.
            $settings = self::get(self::SETTINGS);
            $settings['intercept_popup_id'] = '';
            update_option(self::SETTINGS, $settings, true);
        }
        update_option(self::SEED_VERSION_OPTION, self::SEED_VERSION, true);
        self::bumpCache();
    }

    /**
     * @param list<array<string, mixed>> $current
     * @param list<array<string, mixed>> $seed
     * @return list<array<string, mixed>>
     */
    public static function mergeDoctors(array $current, array $seed): array
    {
        $index = [];
        foreach ($current as $i => $d) {
            $index[(string) ($d['id'] ?? '')] = $i;
        }
        foreach ($seed as $s) {
            $id = (string) $s['id'];
            if (!isset($index[$id])) {
                $current[] = $s;
                continue;
            }
            $d = &$current[$index[$id]];
            if (empty($d['photo_url']) && !empty($s['photo_url'])) {
                $d['photo_url'] = $s['photo_url'];
            }
            $url = (string) ($d['profile_url'] ?? '');
            $path = (string) parse_url($url, PHP_URL_PATH);
            if ($url === '' || (strpos($url, 'bpmedical.com.ua') !== false && strpos($path, '/doctors/') !== 0)) {
                $d['profile_url'] = $s['profile_url'];
            }
            if (($d['slug'] ?? '') === 'bazhenova-iryna') {
                $d['slug'] = $s['slug'];
            }
            unset($d['_todo'], $d);
        }
        return array_values($current);
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
