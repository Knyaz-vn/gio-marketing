<?php
declare(strict_types=1);

namespace BPMedical\Booking\Rest;

use BPMedical\Booking\Admin\Roles;
use BPMedical\Booking\Booking\ValidationError;
use BPMedical\Booking\Core\BookingStatus;
use BPMedical\Booking\Core\Catalog;
use BPMedical\Booking\Core\Config;
use BPMedical\Booking\Core\EventClassifier;
use BPMedical\Booking\Core\Phone;
use BPMedical\Booking\Core\Tz;
use BPMedical\Booking\Options;
use BPMedical\Booking\Plugin;
use BPMedical\Booking\Storage\CallbackRepository;
use WP_REST_Request;
use WP_REST_Response;

/** REST для адмінки (cookie-авторизація WordPress + X-WP-Nonce). */
final class AdminController
{
    private Plugin $p;

    public function __construct(Plugin $p)
    {
        $this->p = $p;
    }

    public function register(): void
    {
        $ns = Plugin::REST_NS . '/admin';
        $staff = static fn() => current_user_can(Roles::CAP_BOOKINGS);
        $admin = static fn() => current_user_can(Roles::CAP_SETTINGS);

        register_rest_route($ns, '/bookings', ['methods' => 'GET', 'callback' => [$this, 'bookings'], 'permission_callback' => $staff]);
        register_rest_route($ns, '/bookings/(?P<id>\d+)/status', ['methods' => 'POST', 'callback' => [$this, 'bookingStatus'], 'permission_callback' => $staff]);
        register_rest_route($ns, '/callbacks', ['methods' => 'GET', 'callback' => [$this, 'callbacks'], 'permission_callback' => $staff]);
        register_rest_route($ns, '/callbacks/(?P<id>\d+)/status', ['methods' => 'POST', 'callback' => [$this, 'callbackStatus'], 'permission_callback' => $staff]);
        register_rest_route($ns, '/diagnostics', ['methods' => 'GET', 'callback' => [$this, 'diagnostics'], 'permission_callback' => $staff]);
        register_rest_route($ns, '/resync', ['methods' => 'POST', 'callback' => [$this, 'resync'], 'permission_callback' => $staff]);
        register_rest_route($ns, '/doctors', [
            ['methods' => 'GET', 'callback' => [$this, 'doctors'], 'permission_callback' => $staff],
            ['methods' => 'POST', 'callback' => [$this, 'saveDoctor'], 'permission_callback' => $admin],
        ]);
        register_rest_route($ns, '/doctors/(?P<id>[a-z0-9-]+)', ['methods' => 'DELETE', 'callback' => [$this, 'deleteDoctor'], 'permission_callback' => $admin]);
        register_rest_route($ns, '/settings', [
            ['methods' => 'GET', 'callback' => [$this, 'settings'], 'permission_callback' => $admin],
            ['methods' => 'POST', 'callback' => [$this, 'saveSettings'], 'permission_callback' => $admin],
        ]);
    }

    // --- Заявки ----------------------------------------------------------------------------

    public function bookings(WP_REST_Request $r): WP_REST_Response
    {
        $f = [
            'status' => (string) $r->get_param('status'),
            'doctor_id' => (string) $r->get_param('doctor'),
            'phone' => (string) $r->get_param('phone'),
        ];
        foreach (['from', 'to'] as $k) {
            $d = (string) $r->get_param($k);
            if (Tz::isValidDate($d)) {
                $f[$k] = $k === 'from' ? Tz::dayStart($d) : Tz::dayStart(Tz::addDays($d, 1));
            }
        }
        $page = max(1, (int) $r->get_param('page'));
        $res = $this->p->bookingRepository()->search($f, 50, ($page - 1) * 50);
        $c = $this->p->catalog();
        $items = array_map(function (array $b) use ($c) {
            $doctor = $c->doctor((string) $b['doctor_id']);
            $service = $c->service((string) $b['service_id']);
            $loc = $c->location((string) $b['location_id']);
            return [
                'id' => (int) $b['id'],
                'lead_id' => $b['lead_id'],
                'status' => $b['status'],
                'doctor' => $doctor['full_name'] ?? $b['doctor_id'],
                'doctor_id' => $b['doctor_id'],
                'service' => $service['name'] ?? $b['service_id'],
                'location' => $loc['name'] ?? $b['location_id'],
                'date' => Tz::date((int) $b['start_ts']),
                'time' => Tz::time((int) $b['start_ts']),
                'patient_name' => $b['patient_name'],
                'phone' => $b['phone'] ? Phone::pretty((string) $b['phone']) : null,
                'comment' => $b['comment'],
                'child_age' => $b['child_age'],
                'source' => implode(' / ', array_filter([$b['utm_source'], $b['utm_medium'], $b['utm_campaign']])),
                'click_ids' => array_keys(array_filter(['gclid' => $b['gclid'], 'gbraid' => $b['gbraid'], 'wbraid' => $b['wbraid'], 'fbclid' => $b['fbclid']])),
                'landing_page' => $b['landing_page'],
                'entry' => $b['entry'],
                'sync_error' => $b['sync_error'],
                'created_at' => Tz::local((int) $b['created_at'])->format('Y-m-d H:i'),
                'anonymized' => !empty($b['anonymized_at']),
            ];
        }, $res['items']);
        return new WP_REST_Response(['items' => $items, 'total' => $res['total'], 'page' => $page, 'per_page' => 50]);
    }

    public function bookingStatus(WP_REST_Request $r): WP_REST_Response
    {
        try {
            $b = $this->p->bookingService()->changeStatus((int) $r->get_param('id'), (string) $r->get_param('status'));
        } catch (ValidationError $e) {
            return new WP_REST_Response(['message' => $e->getMessage(), 'fields' => $e->fields], 422);
        } catch (\OutOfBoundsException $e) {
            return new WP_REST_Response(['message' => $e->getMessage()], 404);
        }
        Options::bumpCache();
        return new WP_REST_Response(['id' => (int) $b['id'], 'status' => $b['status'], 'sync_error' => $b['sync_error'] ?? null]);
    }

    public function callbacks(WP_REST_Request $r): WP_REST_Response
    {
        $page = max(1, (int) $r->get_param('page'));
        $res = $this->p->callbackRepository()->search(['status' => (string) $r->get_param('status'), 'phone' => (string) $r->get_param('phone')], 50, ($page - 1) * 50);
        $c = $this->p->catalog();
        $items = array_map(static function (array $x) use ($c) {
            $doctor = $x['doctor_id'] ? $c->doctor((string) $x['doctor_id']) : null;
            $spec = $x['specialty_id'] ? $c->findSpecialty((string) $x['specialty_id']) : null;
            return [
                'id' => (int) $x['id'],
                'lead_id' => $x['lead_id'],
                'status' => $x['status'],
                'name' => $x['name'],
                'phone' => $x['phone'] ? Phone::pretty((string) $x['phone']) : null,
                'comment' => $x['comment'],
                'doctor' => $doctor['full_name'] ?? null,
                'specialty' => $spec['name'] ?? null,
                'source' => implode(' / ', array_filter([$x['utm_source'], $x['utm_medium'], $x['utm_campaign']])),
                'created_at' => Tz::local((int) $x['created_at'])->format('Y-m-d H:i'),
            ];
        }, $res['items']);
        return new WP_REST_Response(['items' => $items, 'total' => $res['total'], 'page' => $page, 'per_page' => 50]);
    }

    public function callbackStatus(WP_REST_Request $r): WP_REST_Response
    {
        $status = (string) $r->get_param('status');
        if (!in_array($status, CallbackRepository::STATUSES, true)) {
            return new WP_REST_Response(['message' => 'Невідомий статус'], 422);
        }
        $this->p->callbackRepository()->update((int) $r->get_param('id'), ['status' => $status, 'updated_at' => time()]);
        return new WP_REST_Response(['id' => (int) $r->get_param('id'), 'status' => $status]);
    }

    // --- Діагностика календаря -----------------------------------------------------------

    public function diagnostics(): WP_REST_Response
    {
        $repo = $this->p->eventRepository();
        $from = Tz::dayStart(Tz::date(time()));
        $to = $from + ($this->p->config()->int('booking_horizon_days') + 2) * 86400;
        $c = $this->p->catalog();
        $calNames = [];
        foreach ($this->p->config()->calendars() as $cal) {
            $calNames[$cal['id']] = $cal['name'];
        }
        $fmt = static function (array $rows) use ($c, $calNames): array {
            return array_map(static function (array $e) use ($c, $calNames) {
                $names = static fn(string $list) => array_values(array_map(
                    static fn($id) => $c->doctor($id)['full_name'] ?? $id,
                    array_filter(explode(',', $list))
                ));
                return [
                    'calendar' => $calNames[$e['calendar_id']] ?? $e['calendar_id'],
                    'summary' => $e['summary'],
                    'date' => Tz::date((int) $e['start_ts']),
                    'time' => $e['all_day'] ? 'весь день' : Tz::time((int) $e['start_ts']) . '–' . Tz::time((int) $e['end_ts']),
                    'doctors' => $names((string) $e['doctor_ids']),
                    'suspicious' => $names((string) $e['suspicious_ids']),
                ];
            }, $rows);
        };
        return new WP_REST_Response([
            'mode' => $this->p->calendarMode(),
            'match_mode' => $this->p->config()->get('match_mode'),
            'sync' => get_option(Options::SYNC_REPORT, null),
            'watch' => array_map(static fn($w) => [
                'active' => !empty($w['id']) && empty($w['error']),
                'expires' => !empty($w['expiration']) ? Tz::local((int) $w['expiration'])->format('Y-m-d H:i') : null,
                'error' => $w['error'] ?? null,
            ], Options::get(Options::WATCH)),
            'webhook_url' => $this->p->webhookUrl(),
            'stats' => $repo->stats(),
            'unrecognized' => $fmt($repo->withFlag(EventClassifier::FLAG_UNRECOGNIZED, $from, $to)),
            'suspicious' => $fmt($repo->withFlag(EventClassifier::FLAG_SUSPICIOUS, $from, $to)),
            'multi' => $fmt($repo->withFlag(EventClassifier::FLAG_MULTI, $from, $to)),
        ]);
    }

    public function resync(): WP_REST_Response
    {
        $this->p->reclassify();
        $report = $this->p->runSync();
        $this->p->ensureWatch();
        return new WP_REST_Response($report);
    }

    // --- Лікарі ----------------------------------------------------------------------------

    public function doctors(): WP_REST_Response
    {
        return new WP_REST_Response([
            'doctors' => array_values(Options::get(Options::DOCTORS)),
            'specialties' => Options::get(Options::SPECIALTIES),
            'services' => Options::get(Options::SERVICES),
            'locations' => Options::get(Options::LOCATIONS),
            'can_edit' => current_user_can(Roles::CAP_SETTINGS),
        ]);
    }

    public function saveDoctor(WP_REST_Request $r): WP_REST_Response
    {
        $d = (array) $r->get_json_params();
        [$doctor, $errors] = $this->sanitizeDoctor($d);
        if ($errors) {
            return new WP_REST_Response(['message' => 'Помилки у даних лікаря', 'errors' => $errors], 422);
        }
        $all = Options::get(Options::DOCTORS);
        $found = false;
        foreach ($all as $i => $x) {
            if (($x['id'] ?? null) === $doctor['id']) {
                $all[$i] = $doctor;
                $found = true;
            }
        }
        if (!$found) {
            $all[] = $doctor;
        }
        Options::set(Options::DOCTORS, array_values($all));
        $this->p->reclassify();
        return new WP_REST_Response(['doctor' => $doctor]);
    }

    public function deleteDoctor(WP_REST_Request $r): WP_REST_Response
    {
        $id = (string) $r->get_param('id');
        $all = array_values(array_filter(Options::get(Options::DOCTORS), static fn($x) => ($x['id'] ?? null) !== $id));
        Options::set(Options::DOCTORS, $all);
        $this->p->reclassify();
        return new WP_REST_Response(['deleted' => $id]);
    }

    /**
     * @param array<string, mixed> $d
     * @return array{0: array<string, mixed>, 1: string[]}
     */
    private function sanitizeDoctor(array $d): array
    {
        $errors = [];
        $id = (string) ($d['id'] ?? '');
        if (!preg_match('/^[a-z0-9-]{2,40}$/', $id)) {
            $errors[] = 'id: латиниця, цифри, дефіс (2–40 символів)';
        }
        $surname = trim((string) ($d['surname'] ?? ''));
        if ($surname === '') {
            $errors[] = 'Прізвище обов\'язкове: за ним розпізнаються події календаря';
        }
        $locations = array_keys($this->p->catalog()->locations());
        $services = array_keys($this->p->catalog()->services());
        $specialties = array_keys($this->p->catalog()->specialties());

        $schedule = [];
        foreach ((array) ($d['schedule'] ?? []) as $i => $row) {
            $wd = (int) ($row['weekday'] ?? 0);
            $start = (string) ($row['start'] ?? '');
            $end = (string) ($row['end'] ?? '');
            $loc = (string) ($row['location_id'] ?? '');
            if ($wd < 1 || $wd > 7 || !Tz::isValidTime($start) || !Tz::isValidTime($end) || $start >= $end || !in_array($loc, $locations, true)) {
                $errors[] = 'Графік, рядок ' . ($i + 1) . ': перевірте день, час (початок < кінець) і адресу';
                continue;
            }
            $schedule[] = ['weekday' => $wd, 'start' => $start, 'end' => $end, 'location_id' => $loc];
        }
        $overrides = [];
        foreach ((array) ($d['schedule_overrides'] ?? []) as $i => $row) {
            $date = (string) ($row['date'] ?? '');
            $loc = $row['location_id'] ?? null;
            if (!Tz::isValidDate($date)) {
                $errors[] = 'Виняток, рядок ' . ($i + 1) . ': некоректна дата';
                continue;
            }
            if ($loc === null || $loc === '') {
                $overrides[] = ['date' => $date, 'start' => null, 'end' => null, 'location_id' => null];
                continue;
            }
            $start = (string) ($row['start'] ?? '');
            $end = (string) ($row['end'] ?? '');
            if (!Tz::isValidTime($start) || !Tz::isValidTime($end) || $start >= $end || !in_array($loc, $locations, true)) {
                $errors[] = 'Виняток ' . $date . ': перевірте час і адресу';
                continue;
            }
            $overrides[] = ['date' => $date, 'start' => $start, 'end' => $end, 'location_id' => (string) $loc];
        }
        $durations = [];
        foreach ((array) ($d['service_durations'] ?? []) as $sid => $v) {
            if (in_array($sid, $services, true) && is_array($v)) {
                $durations[$sid] = [
                    'duration_min' => max(5, min(480, (int) ($v['duration_min'] ?? 30))),
                    'buffer_after_min' => max(0, min(120, (int) ($v['buffer_after_min'] ?? 0))),
                ];
            }
        }
        $aliases = array_values(array_unique(array_filter(array_map(
            static fn($a) => trim(sanitize_text_field((string) $a)),
            is_array($d['aliases'] ?? null) ? $d['aliases'] : explode(',', (string) ($d['aliases'] ?? ''))
        ))));
        $url = static fn($u) => $u ? esc_url_raw((string) $u) : '';

        $doctor = Catalog::normalizeDoctor([
            'id' => $id,
            'slug' => sanitize_title((string) ($d['slug'] ?? $id)) ?: $id,
            'full_name' => sanitize_text_field((string) ($d['full_name'] ?? '')),
            'surname' => sanitize_text_field($surname),
            'position' => sanitize_text_field((string) ($d['position'] ?? '')),
            'aliases' => $aliases,
            'specialties' => array_values(array_intersect((array) ($d['specialties'] ?? []), $specialties)),
            'profile_url' => $url($d['profile_url'] ?? ''),
            'photo_url' => $url($d['photo_url'] ?? ''),
            'bookable' => !empty($d['bookable']),
            'schedule' => $schedule,
            'schedule_overrides' => $overrides,
            'default_service_ids' => array_values(array_intersect((array) ($d['default_service_ids'] ?? []), $services)),
            'service_durations' => $durations,
        ]);
        return [$doctor, $errors];
    }

    // --- Налаштування ---------------------------------------------------------------------

    public function settings(): WP_REST_Response
    {
        return new WP_REST_Response([
            'settings' => (new Config(Options::get(Options::SETTINGS)))->all(),
            'specialties' => Options::get(Options::SPECIALTIES),
            'services' => Options::get(Options::SERVICES),
            'locations' => Options::get(Options::LOCATIONS),
            'secrets' => [
                'google_service_account' => \BPMedical\Booking\Security\Secrets::googleServiceAccount() !== null,
                'google_client_email' => \BPMedical\Booking\Security\Secrets::googleServiceAccount()['client_email'] ?? null,
                'telegram_bot_token' => \BPMedical\Booking\Security\Secrets::get('BPMB_TELEGRAM_BOT_TOKEN') !== null,
                'turnstile_secret' => \BPMedical\Booking\Security\Secrets::get('BPMB_TURNSTILE_SECRET') !== null,
            ],
            'calendar_mode_effective' => $this->p->calendarMode(),
        ]);
    }

    public function saveSettings(WP_REST_Request $r): WP_REST_Response
    {
        $in = (array) $r->get_json_params();
        $errors = [];
        if (isset($in['settings']) && is_array($in['settings'])) {
            $s = $in['settings'];
            if (isset($s['calendars']) && is_array($s['calendars'])) {
                $s['calendars'] = array_values(array_filter(array_map(static fn($c) => [
                    'id' => trim(sanitize_text_field((string) ($c['id'] ?? ''))),
                    'name' => sanitize_text_field((string) ($c['name'] ?? '')),
                    'location_id' => !empty($c['location_id']) ? sanitize_key((string) $c['location_id']) : null,
                ], $s['calendars']), static fn($c) => $c['id'] !== ''));
            }
            if (isset($s['notify_emails']) && !is_array($s['notify_emails'])) {
                $s['notify_emails'] = array_values(array_filter(array_map('trim', explode(',', (string) $s['notify_emails'])), 'is_email'));
            }
            if (isset($s['holidays']) && !is_array($s['holidays'])) {
                $s['holidays'] = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', (string) $s['holidays']) ?: [])));
            }
            if (isset($s['cors_origins']) && !is_array($s['cors_origins'])) {
                $s['cors_origins'] = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', (string) $s['cors_origins']) ?: [])));
            }
            [$clean, $errs] = Config::sanitize($s);
            $errors = array_merge($errors, $errs);
            if (!$errs) {
                Options::set(Options::SETTINGS, array_replace(Options::get(Options::SETTINGS), $clean));
            }
        }
        foreach (['specialties' => Options::SPECIALTIES, 'services' => Options::SERVICES, 'locations' => Options::LOCATIONS] as $key => $opt) {
            if (!isset($in[$key])) {
                continue;
            }
            $list = $in[$key];
            if (!is_array($list) || array_filter($list, static fn($x) => !is_array($x) || empty($x['id']) || empty($x['name']))) {
                $errors[] = "$key: кожен елемент має містити id і name";
                continue;
            }
            Options::set($opt, array_values($list));
        }
        if ($errors) {
            return new WP_REST_Response(['message' => 'Не збережено', 'errors' => $errors], 422);
        }
        $this->p->reclassify();
        return $this->settings();
    }
}
