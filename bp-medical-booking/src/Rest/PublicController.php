<?php
declare(strict_types=1);

namespace BPMedical\Booking\Rest;

use BPMedical\Booking\Booking\SlotUnavailable;
use BPMedical\Booking\Booking\ValidationError;
use BPMedical\Booking\Core\Attribution;
use BPMedical\Booking\Core\Ics;
use BPMedical\Booking\Core\Ids;
use BPMedical\Booking\Core\Phone;
use BPMedical\Booking\Core\Slot;
use BPMedical\Booking\Core\Tz;
use BPMedical\Booking\Cron\Jobs;
use BPMedical\Booking\Options;
use BPMedical\Booking\Plugin;
use BPMedical\Booking\Security\RateLimiter;
use BPMedical\Booking\Security\Turnstile;
use BPMedical\Booking\Storage\LockTimeout;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Публічний API віджета. Віддає ТІЛЬКИ вільні слоти й довідники, ніколи не віддає
 * назви чи описи подій з календаря.
 */
final class PublicController
{
    private const HOLD_RATE = 30;   // holds / год / IP
    private const MIN_FILL_SECONDS = 3;

    private Plugin $p;

    public function __construct(Plugin $p)
    {
        $this->p = $p;
    }

    public function register(): void
    {
        $ns = Plugin::REST_NS;
        $public = ['permission_callback' => '__return_true'];
        register_rest_route($ns, '/catalog', $public + ['methods' => 'GET', 'callback' => [$this, 'catalog']]);
        register_rest_route($ns, '/nearest', $public + ['methods' => 'GET', 'callback' => [$this, 'nearest']]);
        register_rest_route($ns, '/availability', $public + ['methods' => 'GET', 'callback' => [$this, 'availability']]);
        register_rest_route($ns, '/slots', $public + ['methods' => 'GET', 'callback' => [$this, 'slots']]);
        register_rest_route($ns, '/holds', $public + ['methods' => 'POST', 'callback' => [$this, 'hold']]);
        register_rest_route($ns, '/bookings', $public + ['methods' => 'POST', 'callback' => [$this, 'book']]);
        register_rest_route($ns, '/callback', $public + ['methods' => 'POST', 'callback' => [$this, 'callback']]);
        register_rest_route($ns, '/ics/(?P<lead>BP-[0-9A-Z]{8})', $public + ['methods' => 'GET', 'callback' => [$this, 'ics']]);
        register_rest_route($ns, '/calendar/webhook', $public + ['methods' => 'POST', 'callback' => [$this, 'webhook']]);
    }

    // --- Довідники ----------------------------------------------------------------------

    public function catalog(): WP_REST_Response
    {
        return $this->cached('catalog', 300, function () {
            $c = $this->p->catalog();
            $cfg = $this->p->config();
            $doctors = [];
            foreach ($c->doctors() as $d) {
                if (!$d['bookable']) {
                    continue;
                }
                $services = array_map(static fn($s) => [
                    'id' => $s['id'],
                    'name' => $s['name'],
                    'duration_min' => $s['duration_min'],
                    'price_from' => $s['price_from'] ?? null,
                    'prep_note_url' => $s['prep_note_url'] ?? null,
                ], $c->servicesForDoctor($d['id']));
                $doctors[] = [
                    'id' => $d['id'],
                    'slug' => $d['slug'],
                    'full_name' => $d['full_name'],
                    'position' => $d['position'],
                    'photo_url' => $d['photo_url'],
                    'profile_url' => $d['profile_url'],
                    'specialties' => array_values((array) $d['specialties']),
                    'services' => $services,
                ];
            }
            $specialties = [];
            foreach ($c->specialties() as $s) {
                $specialties[] = [
                    'id' => $s['id'],
                    'name' => $s['name'],
                    'slug' => $s['slug'] ?? $s['id'],
                    'parent_id' => $s['parent_id'] ?? null,
                    'is_child' => !empty($s['is_child']),
                    'doctor_ids' => array_map(static fn($d) => $d['id'], $c->bookableDoctorsForSpecialty((string) $s['id'])),
                ];
            }
            $locations = array_values(array_map(static fn($l) => [
                'id' => $l['id'],
                'name' => $l['name'] ?? '',
                'address' => $l['address'] ?? '',
                'phone' => $l['phone'] ?? '',
                'phone_display' => $l['phone_display'] ?? ($l['phone'] ?? ''),
            ], $c->locations()));
            return [
                'specialties' => $specialties,
                'doctors' => $doctors,
                'locations' => $locations,
                'settings' => [
                    'privacy_url' => $cfg->get('privacy_url'),
                    'horizon_days' => $cfg->int('booking_horizon_days'),
                    'hold_minutes' => $cfg->int('hold_minutes'),
                    'callback_phone' => $cfg->get('callback_phone'),
                    'turnstile_site_key' => Turnstile::enabled((string) $cfg->get('turnstile_site_key')) ? $cfg->get('turnstile_site_key') : '',
                    'today' => Tz::date(time()),
                ],
            ];
        });
    }

    /** Найближча вільна дата для карток лікарів (послуга за замовчуванням). */
    public function nearest(WP_REST_Request $r): WP_REST_Response
    {
        $ids = array_slice(array_filter(explode(',', (string) $r->get_param('doctors'))), 0, 30);
        sort($ids);
        return $this->cached('nearest_' . md5(implode(',', $ids)), 60, function () use ($ids) {
            $out = [];
            foreach ($ids as $id) {
                $d = $this->p->catalog()->doctor($id);
                if ($d === null || !$d['bookable']) {
                    continue;
                }
                $services = $this->p->catalog()->servicesForDoctor($id);
                $out[$id] = $services ? $this->p->availability()->nearestDate($id, (string) $services[0]['id']) : null;
            }
            return ['nearest' => (object) $out];
        });
    }

    public function availability(WP_REST_Request $r): WP_REST_Response
    {
        [$doctorId, $serviceId, $err] = $this->doctorService($r);
        if ($err) {
            return $err;
        }
        $today = Tz::date(time());
        $from = (string) ($r->get_param('from') ?: $today);
        $to = (string) ($r->get_param('to') ?: Tz::addDays($today, $this->p->config()->int('booking_horizon_days')));
        if (!Tz::isValidDate($from) || !Tz::isValidDate($to) || Tz::daysBetween($from, $to) > 62) {
            return $this->error('bad_request', 'Некоректний діапазон дат', 400);
        }
        return $this->cached("avail_{$doctorId}_{$serviceId}_{$from}_{$to}", 30, function () use ($doctorId, $serviceId, $from, $to, $today) {
            $gen = $this->p->availability()->generator();
            return [
                'doctor_id' => $doctorId,
                'service_id' => $serviceId,
                'today' => $today,
                'last_date' => $gen->lastBookableDate(),
                'days' => $this->p->availability()->days($doctorId, $serviceId, $from, $to),
            ];
        });
    }

    public function slots(WP_REST_Request $r): WP_REST_Response
    {
        [$doctorId, $serviceId, $err] = $this->doctorService($r);
        if ($err) {
            return $err;
        }
        $date = (string) $r->get_param('date');
        if (!Tz::isValidDate($date)) {
            return $this->error('bad_request', 'Некоректна дата', 400);
        }
        $slots = $this->p->availability()->slots($doctorId, $serviceId, $date);
        return $this->ok([
            'date' => $date,
            'slots' => array_map(static fn(Slot $s) => $s->toArray(), $slots),
        ], 200, false);
    }

    // --- Запис ---------------------------------------------------------------------------

    public function hold(WP_REST_Request $r): WP_REST_Response
    {
        if (!RateLimiter::hit('hold', RateLimiter::clientIp(), self::HOLD_RATE)) {
            return $this->error('rate_limited', 'Забагато спроб. Спробуйте пізніше або зателефонуйте нам.', 429);
        }
        [$doctorId, $serviceId, $err] = $this->doctorService($r);
        if ($err) {
            return $err;
        }
        $start = self::parseStart($r->get_param('start'));
        if ($start === null) {
            return $this->error('bad_request', 'Некоректний час', 400);
        }
        try {
            $h = $this->p->bookingService()->createHold($doctorId, $serviceId, $start);
            Options::bumpCache();
            return $this->ok(['hold_token' => $h['token'], 'expires_at' => $h['expires_at'], 'slot' => $h['slot']->toArray()], 201, false);
        } catch (SlotUnavailable $e) {
            return $this->slotUnavailable($e);
        } catch (ValidationError $e) {
            return $this->validation($e);
        } catch (LockTimeout $e) {
            return $this->error('busy', 'Сервер зайнятий, спробуйте ще раз', 503);
        }
    }

    public function book(WP_REST_Request $r): WP_REST_Response
    {
        $in = (array) $r->get_json_params();
        $ip = RateLimiter::clientIp();
        if ($this->isSpam($in)) {
            // Не підказуємо боту, що його розпізнано.
            return $this->ok(['lead_id' => Ids::lead()], 201, false);
        }
        if (!RateLimiter::hit('booking', $ip, $this->p->config()->int('rate_limit_per_hour'))) {
            return $this->error('rate_limited', 'Забагато заявок з вашої мережі. Зателефонуйте нам, будь ласка.', 429);
        }
        if (!$this->turnstileOk($in, $ip)) {
            return $this->error('captcha', 'Підтвердіть, що ви не робот', 400);
        }
        $start = self::parseStart($in['start'] ?? null);
        if ($start === null) {
            return $this->error('bad_request', 'Некоректний час', 400);
        }
        $in['start'] = $start;
        $in['doctor_id'] = (string) ($in['doctor'] ?? $in['doctor_id'] ?? '');
        $in['service_id'] = (string) ($in['service'] ?? $in['service_id'] ?? '');
        $in['specialty_id'] = isset($in['specialty']) ? (string) $in['specialty'] : ($in['specialty_id'] ?? null);

        try {
            $res = $this->p->bookingService()->submit($in);
        } catch (SlotUnavailable $e) {
            return $this->slotUnavailable($e);
        } catch (ValidationError $e) {
            return $this->validation($e);
        } catch (LockTimeout $e) {
            return $this->error('busy', 'Сервер зайнятий, спробуйте ще раз', 503);
        }
        Options::bumpCache();

        $b = $res['booking'];
        $c = $this->p->catalog();
        $doctor = $c->doctor((string) $b['doctor_id']);
        $service = $c->service((string) $b['service_id']);
        $location = $c->location((string) $b['location_id']);
        return $this->ok([
            'lead_id' => $b['lead_id'],
            'doctor' => ['id' => $b['doctor_id'], 'full_name' => $doctor['full_name'] ?? ''],
            'service' => ['id' => $b['service_id'], 'name' => $service['name'] ?? ''],
            'specialty_id' => $b['specialty_id'],
            'location' => $location ? ['id' => $location['id'], 'name' => $location['name'] ?? '', 'address' => $location['address'] ?? '', 'phone_display' => $location['phone_display'] ?? ''] : null,
            'date' => Tz::date((int) $b['start_ts']),
            'time' => Tz::time((int) $b['start_ts']),
            'ics_url' => add_query_arg('t', $this->p->icsToken((string) $b['lead_id']), rest_url(Plugin::REST_NS . '/ics/' . $b['lead_id'])),
            'prep_note_url' => $service['prep_note_url'] ?? null,
        ], 201, false);
    }

    public function callback(WP_REST_Request $r): WP_REST_Response
    {
        $in = (array) $r->get_json_params();
        $ip = RateLimiter::clientIp();
        if ($this->isSpam($in)) {
            return $this->ok(['ok' => true], 201, false);
        }
        if (!RateLimiter::hit('callback', $ip, $this->p->config()->int('rate_limit_per_hour'))) {
            return $this->error('rate_limited', 'Забагато заявок з вашої мережі. Зателефонуйте нам, будь ласка.', 429);
        }
        if (!$this->turnstileOk($in, $ip)) {
            return $this->error('captcha', 'Підтвердіть, що ви не робот', 400);
        }
        $errors = [];
        $name = trim((string) ($in['name'] ?? ''));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
            $errors['name'] = "Вкажіть ім'я";
        }
        $phone = Phone::normalizeUa((string) ($in['phone'] ?? ''));
        if ($phone === null) {
            $errors['phone'] = 'Вкажіть коректний номер телефону';
        }
        if (empty($in['consent'])) {
            $errors['consent'] = 'Потрібна згода на обробку персональних даних';
        }
        $comment = mb_substr(trim((string) ($in['comment'] ?? '')), 0, 500);
        if ($errors) {
            return $this->validation(new ValidationError($errors));
        }
        $c = $this->p->catalog();
        $doctor = isset($in['doctor']) ? $c->findDoctor((string) $in['doctor']) : null;
        $spec = isset($in['specialty']) ? $c->findSpecialty((string) $in['specialty']) : null;
        $now = time();
        $leadId = Ids::lead('CB');
        $row = [
            'lead_id' => $leadId,
            'name' => sanitize_text_field($name),
            'phone' => $phone,
            'phone_hash' => $this->p->config()->get('store_phone_hash') ? Phone::hash((string) $phone) : null,
            'comment' => $comment !== '' ? sanitize_textarea_field($comment) : null,
            'doctor_id' => $doctor['id'] ?? null,
            'specialty_id' => $spec['id'] ?? null,
            'status' => 'new',
            'entry' => isset($in['entry']) ? substr((string) preg_replace('/[^a-z_]/', '', (string) $in['entry']), 0, 30) : null,
            'created_at' => $now,
            'updated_at' => $now,
        ] + Attribution::sanitize($in['attribution'] ?? []);
        $this->p->callbackRepository()->insert($row);

        $lines = array_values(array_filter([
            "Ім'я: " . $row['name'],
            'Телефон: ' . Phone::pretty((string) $phone),
            $doctor ? 'Лікар: ' . $doctor['full_name'] : null,
            $spec ? 'Спеціальність: ' . $spec['name'] : null,
            $row['comment'] ? 'Коментар: ' . $row['comment'] : null,
            'Джерело: ' . (implode(' / ', array_filter([$row['utm_source'], $row['utm_medium'], $row['utm_campaign']])) ?: ($row['referrer'] ?? 'прямий / невідомо')),
        ]));
        try {
            $this->p->notifier()->send(['type' => 'callback', 'title' => 'Замовлено зворотний дзвінок ' . $leadId, 'lines' => $lines, 'admin_url' => admin_url('admin.php?page=bp-booking#callbacks')]);
        } catch (\Throwable $e) {
            $this->p->logger()->error('callback notify failed', ['error' => $e->getMessage()]);
        }
        return $this->ok(['ok' => true, 'lead_id' => $leadId], 201, false);
    }

    /** @return WP_REST_Response|void */
    public function ics(WP_REST_Request $r)
    {
        $lead = (string) $r->get_param('lead');
        $token = (string) $r->get_param('t');
        if (!hash_equals($this->p->icsToken($lead), $token)) {
            return $this->error('not_found', 'Не знайдено', 404);
        }
        $b = $this->p->bookingRepository()->findByLead($lead);
        if ($b === null || $b['status'] === 'cancelled') {
            return $this->error('not_found', 'Не знайдено', 404);
        }
        $c = $this->p->catalog();
        $doctor = $c->doctor((string) $b['doctor_id']);
        $service = $c->service((string) $b['service_id']);
        $location = $c->location((string) $b['location_id']);
        $ics = Ics::build(
            (string) $b['lead_id'],
            (int) $b['start_ts'],
            (int) $b['end_ts'],
            'BP Medical: ' . ($service['name'] ?? 'прийом') . ' — ' . ($doctor['full_name'] ?? ''),
            'BP Medical, ' . ($location['address'] ?? ''),
            'Заявка ' . $b['lead_id'] . '. Адміністратор зателефонує для підтвердження. Тел.: ' . ($location['phone_display'] ?? ''),
            time()
        );
        nocache_headers();
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="bpmedical-' . $b['lead_id'] . '.ics"');
        echo $ics; // phpcs:ignore WordPress.Security.EscapeOutput
        exit;
    }

    /** Push-сповіщення Google Calendar: перевірка каналу і позачергова синхронізація. */
    public function webhook(WP_REST_Request $r): WP_REST_Response
    {
        $channel = (string) $r->get_header('x_goog_channel_id');
        $token = (string) $r->get_header('x_goog_channel_token');
        if (!$this->p->watchManager()->verify($channel, $token)) {
            return new WP_REST_Response(null, 403);
        }
        // "sync" = підтвердження створення каналу; решта означає, що в календарі щось змінилося.
        if ((string) $r->get_header('x_goog_resource_state') !== 'sync' && !wp_next_scheduled(Jobs::SYNC_NOW)) {
            wp_schedule_single_event(time(), Jobs::SYNC_NOW);
            spawn_cron();
        }
        return new WP_REST_Response(null, 200);
    }

    // --- Допоміжне ---------------------------------------------------------------------------

    /** @return array{0:string, 1:string, 2:?WP_REST_Response} */
    private function doctorService(WP_REST_Request $r): array
    {
        $c = $this->p->catalog();
        $doctor = $c->findDoctor((string) $r->get_param('doctor'));
        if ($doctor === null || !$doctor['bookable']) {
            return ['', '', $this->error('not_found', 'Лікаря не знайдено або онлайн-запис недоступний', 404)];
        }
        $serviceId = (string) $r->get_param('service');
        if ($serviceId === '') {
            $services = $c->servicesForDoctor($doctor['id']);
            $serviceId = $services ? (string) $services[0]['id'] : '';
        }
        if (!$c->doctorOffersService($doctor['id'], $serviceId)) {
            return ['', '', $this->error('not_found', 'Послуга недоступна', 404)];
        }
        return [(string) $doctor['id'], $serviceId, null];
    }

    /** @param mixed $v */
    private static function parseStart($v): ?int
    {
        if (is_numeric($v)) {
            return (int) $v;
        }
        if (!is_string($v) || $v === '') {
            return null;
        }
        $ts = strtotime($v);
        return $ts === false ? null : $ts;
    }

    /** @param array<string, mixed> $in */
    private function isSpam(array $in): bool
    {
        if (!empty($in['website'])) {
            return true; // honeypot
        }
        $started = isset($in['form_started_at']) ? (int) $in['form_started_at'] : 0;
        return $started > 0 && (int) floor(microtime(true) * 1000) - $started < self::MIN_FILL_SECONDS * 1000;
    }

    /** @param array<string, mixed> $in */
    private function turnstileOk(array $in, string $ip): bool
    {
        $siteKey = (string) $this->p->config()->get('turnstile_site_key');
        if (!Turnstile::enabled($siteKey)) {
            return true;
        }
        return Turnstile::verify((string) ($in['turnstile_token'] ?? ''), $ip);
    }

    private function slotUnavailable(SlotUnavailable $e): WP_REST_Response
    {
        return $this->ok([
            'code' => 'slot_unavailable',
            'message' => $e->getMessage(),
            'alternatives' => array_map(static fn(Slot $s) => $s->toArray(), $e->alternatives),
        ], 409, false);
    }

    private function validation(ValidationError $e): WP_REST_Response
    {
        return $this->ok(['code' => 'validation', 'message' => $e->getMessage(), 'fields' => $e->fields], 422, false);
    }

    private function error(string $code, string $message, int $status): WP_REST_Response
    {
        return $this->ok(['code' => $code, 'message' => $message], $status, false);
    }

    /** @param array<string, mixed> $data */
    private function ok(array $data, int $status = 200, bool $cacheable = false): WP_REST_Response
    {
        $res = new WP_REST_Response($data, $status);
        $res->header('Cache-Control', $cacheable ? 'public, max-age=30' : 'no-store');
        return $res;
    }

    /** Кеш у transients з версією, що інвалідується при синхронізації/записі/зміні налаштувань. */
    private function cached(string $key, int $ttl, callable $fn): WP_REST_Response
    {
        $tkey = 'bpmb_c' . Options::cacheVersion() . '_' . substr(md5($key), 0, 16);
        $data = get_transient($tkey);
        if (!is_array($data)) {
            $data = $fn();
            set_transient($tkey, $data, $ttl);
        }
        return $this->ok($data, 200, false);
    }
}
