<?php
declare(strict_types=1);

namespace BPMedical\Booking\Admin;

use BPMedical\Booking\Core\Tz;
use BPMedical\Booking\Plugin;

/**
 * Експорт заявок у CSV (admin-post.php?action=bpmb_export).
 *  - format=generic: усі поля заявки з атрибуцією;
 *  - format=google_ads: офлайн-конверсії Google Ads (GCLID / GBRAID / WBRAID + хеш телефону для
 *    Enhanced Conversions for Leads), за замовчуванням лише status=visited.
 */
final class Export
{
    private Plugin $p;

    public function __construct(Plugin $p)
    {
        $this->p = $p;
    }

    public function register(): void
    {
        add_action('admin_post_bpmb_export', [$this, 'handle']);
    }

    public function handle(): void
    {
        if (!current_user_can(Roles::CAP_BOOKINGS)) {
            wp_die('Недостатньо прав', 403);
        }
        check_admin_referer('bpmb_export');
        $format = ($_GET['format'] ?? 'generic') === 'google_ads' ? 'google_ads' : 'generic';
        $status = sanitize_key((string) ($_GET['status'] ?? ($format === 'google_ads' ? 'visited' : '')));
        $f = ['status' => $status];
        foreach (['from', 'to'] as $k) {
            $d = (string) ($_GET[$k] ?? '');
            if (Tz::isValidDate($d)) {
                $f[$k] = $k === 'from' ? Tz::dayStart($d) : Tz::dayStart(Tz::addDays($d, 1));
            }
        }
        $rows = $this->p->bookingRepository()->search($f, 100000, 0)['items'];

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="bp-booking-' . $format . '-' . gmdate('Ymd-His') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // BOM для Excel
        if ($format === 'google_ads') {
            $this->googleAds($out, $rows);
        } else {
            $this->generic($out, $rows);
        }
        fclose($out);
        exit;
    }

    /** @param resource $out @param list<array<string, mixed>> $rows */
    private function generic($out, array $rows): void
    {
        $c = $this->p->catalog();
        fputcsv($out, ['lead_id', 'created_at', 'status', 'doctor', 'specialty', 'service', 'location', 'date', 'time', 'patient_name', 'phone', 'child_age', 'comment',
            'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'gbraid', 'wbraid', 'fbclid', 'landing_page', 'referrer', 'entry']);
        foreach ($rows as $b) {
            fputcsv($out, array_map([self::class, 'cell'], [
                $b['lead_id'], Tz::local((int) $b['created_at'])->format('Y-m-d H:i'), $b['status'],
                $c->doctor((string) $b['doctor_id'])['full_name'] ?? $b['doctor_id'],
                $b['specialty_id'] ? ($c->findSpecialty((string) $b['specialty_id'])['name'] ?? $b['specialty_id']) : '',
                $c->service((string) $b['service_id'])['name'] ?? $b['service_id'],
                $c->location((string) $b['location_id'])['name'] ?? $b['location_id'],
                Tz::date((int) $b['start_ts']), Tz::time((int) $b['start_ts']),
                $b['patient_name'], $b['phone'], $b['child_age'], $b['comment'],
                $b['utm_source'], $b['utm_medium'], $b['utm_campaign'], $b['utm_term'], $b['utm_content'],
                $b['gclid'], $b['gbraid'], $b['wbraid'], $b['fbclid'], $b['landing_page'], $b['referrer'], $b['entry'],
            ]));
        }
    }

    /** @param resource $out @param list<array<string, mixed>> $rows */
    private function googleAds($out, array $rows): void
    {
        $name = (string) $this->p->config()->get('ads_conversion_name');
        fputcsv($out, ['Parameters:TimeZone=Europe/Kyiv']);
        fputcsv($out, ['Google Click ID', 'GBRAID', 'WBRAID', 'Phone Number', 'Conversion Name', 'Conversion Time', 'Conversion Value', 'Conversion Currency', 'Order ID']);
        foreach ($rows as $b) {
            if (empty($b['gclid']) && empty($b['gbraid']) && empty($b['wbraid']) && empty($b['phone_hash'])) {
                continue;
            }
            $service = $this->p->catalog()->service((string) $b['service_id']);
            fputcsv($out, array_map([self::class, 'cell'], [
                $b['gclid'], $b['gbraid'], $b['wbraid'], $b['phone_hash'], $name,
                Tz::local((int) $b['start_ts'])->format('Y-m-d H:i:s'),
                isset($service['price_from']) && $service['price_from'] !== null ? (string) $service['price_from'] : '',
                'UAH', $b['lead_id'],
            ]));
        }
    }

    /** Захист від CSV-injection у Excel. @param mixed $v */
    public static function cell($v): string
    {
        $s = (string) ($v ?? '');
        if ($s !== '' && (in_array($s[0], ['=', '@', "\t", "\r"], true) || (in_array($s[0], ['+', '-'], true) && !preg_match('/^[+-]?[\d\s]+$/', $s)))) {
            return "'" . $s;
        }
        return $s;
    }
}
