<?php
declare(strict_types=1);

namespace BPMedical\Booking\Core;

/**
 * Налаштування модуля (не секрети: ключі service account, токени, секрети Turnstile
 * читаються з оточення, див. Plugin::secret()).
 */
final class Config
{
    public const DEFAULTS = [
        'calendars' => [],                 // [{id, name, location_id|null}]
        'match_mode' => 'anywhere',        // anywhere | prefix
        'min_lead_minutes' => 120,
        'booking_horizon_days' => 30,
        'slot_step_min' => 15,
        'hold_minutes' => 7,
        'clinic_hours' => [
            '1' => ['09:00', '18:00'],
            '2' => ['09:00', '18:00'],
            '3' => ['09:00', '18:00'],
            '4' => ['09:00', '18:00'],
            '5' => ['09:00', '18:00'],
            '6' => ['09:00', '15:00'],
            '7' => null,
        ],
        'holidays' => [],                  // ["2026-12-25", ...]
        'online_color_id' => '9',          // Google Calendar colorId для онлайн-записів
        'cancel_mode' => 'mark',           // mark (СКАСОВАНО + transparent) | delete
        'telegram_chat_id' => '',
        'notify_emails' => [],
        'retention_days' => 365,
        'rate_limit_per_hour' => 5,
        'turnstile_site_key' => '',
        'cors_origins' => ['https://bpmedical.com.ua', 'https://www.bpmedical.com.ua'],
        'privacy_url' => 'https://bpmedical.com.ua/polityka-konfidentsiynosti/',
        'load_globally' => true,           // підключати віджет на всіх сторінках (модалка + UTM)
        'intercept_popup_id' => '13597',   // перехоплювати тригери Popup Maker #popmake-N ('' = ні)
        'store_phone_hash' => true,        // SHA-256(E.164) для Enhanced Conversions for Leads
        'ads_conversion_name' => 'Online booking visit',
        'calendar_mode' => 'auto',         // auto | google | mock
        'sync_interval_min' => 2,
        'callback_phone' => '+380662119922',
    ];

    /** @var array<string, mixed> */
    private array $data;

    /** @param array<string, mixed> $data */
    public function __construct(array $data = [])
    {
        $this->data = array_replace(self::DEFAULTS, $data);
    }

    /** @return mixed */
    public function get(string $key)
    {
        return $this->data[$key] ?? (self::DEFAULTS[$key] ?? null);
    }

    public function int(string $key): int
    {
        return (int) $this->get($key);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->data;
    }

    /** @return list<array{id:string,name:string,location_id:?string}> */
    public function calendars(): array
    {
        $out = [];
        foreach ((array) $this->get('calendars') as $c) {
            if (!is_array($c) || empty($c['id'])) {
                continue;
            }
            $out[] = [
                'id' => (string) $c['id'],
                'name' => (string) ($c['name'] ?? $c['id']),
                'location_id' => isset($c['location_id']) && $c['location_id'] !== '' ? (string) $c['location_id'] : null,
            ];
        }
        return $out;
    }

    /** Календар, у який пишемо онлайн-запис для локації. */
    public function calendarForLocation(?string $locationId): ?string
    {
        $cals = $this->calendars();
        foreach ($cals as $c) {
            if ($locationId !== null && $c['location_id'] === $locationId) {
                return $c['id'];
            }
        }
        foreach ($cals as $c) {
            if ($c['location_id'] === null) {
                return $c['id'];
            }
        }
        return $cals[0]['id'] ?? null;
    }

    /** @return array{0:string,1:string}|null */
    public function clinicHours(int $weekday): ?array
    {
        $h = ((array) $this->get('clinic_hours'))[(string) $weekday] ?? null;
        if (!is_array($h) || count($h) < 2) {
            return null;
        }
        return [(string) $h[0], (string) $h[1]];
    }

    public function isHoliday(string $ymd): bool
    {
        return in_array($ymd, (array) $this->get('holidays'), true);
    }

    /**
     * Валідація налаштувань з адмінки.
     *
     * @param array<string, mixed> $in
     * @return array{0: array<string, mixed>, 1: string[]} [очищені дані, помилки]
     */
    public static function sanitize(array $in): array
    {
        $errors = [];
        $out = [];
        foreach (self::DEFAULTS as $key => $def) {
            if (!array_key_exists($key, $in)) {
                continue;
            }
            $v = $in[$key];
            if (is_int($def)) {
                $v = (int) $v;
                if ($v < 0) {
                    $errors[] = "$key: має бути ≥ 0";
                    continue;
                }
            } elseif (is_bool($def)) {
                $v = (bool) $v;
            } elseif (is_string($def)) {
                $v = trim((string) $v);
            } elseif (is_array($def) && !is_array($v)) {
                $errors[] = "$key: очікується масив";
                continue;
            }
            $out[$key] = $v;
        }
        if (isset($out['match_mode']) && !in_array($out['match_mode'], ['anywhere', 'prefix'], true)) {
            $errors[] = 'match_mode: anywhere або prefix';
        }
        if (isset($out['cancel_mode']) && !in_array($out['cancel_mode'], ['mark', 'delete'], true)) {
            $errors[] = 'cancel_mode: mark або delete';
        }
        if (isset($out['calendar_mode']) && !in_array($out['calendar_mode'], ['auto', 'google', 'mock'], true)) {
            $errors[] = 'calendar_mode: auto, google або mock';
        }
        if (isset($out['slot_step_min']) && ($out['slot_step_min'] < 5 || $out['slot_step_min'] > 120)) {
            $errors[] = 'slot_step_min: від 5 до 120';
        }
        if (isset($out['holidays'])) {
            foreach ($out['holidays'] as $d) {
                if (!Tz::isValidDate((string) $d)) {
                    $errors[] = "holidays: некоректна дата $d";
                }
            }
        }
        if (isset($out['clinic_hours'])) {
            foreach ($out['clinic_hours'] as $wd => $h) {
                if ($h !== null && (!is_array($h) || count($h) !== 2 || !Tz::isValidTime((string) $h[0]) || !Tz::isValidTime((string) $h[1]))) {
                    $errors[] = "clinic_hours[$wd]: очікується [\"09:00\",\"18:00\"] або null";
                }
            }
        }
        return [$out, $errors];
    }
}
