<?php
declare(strict_types=1);

namespace BPMedical\Booking\Core;

/**
 * Рекламна атрибуція заявки. Віджет збирає значення з URL і first-party cookie,
 * сервер лише очищує їх і зберігає.
 */
final class Attribution
{
    public const FIELDS = [
        'gclid', 'gbraid', 'wbraid', 'fbclid',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        'landing_page', 'referrer',
    ];

    /**
     * @param mixed $input
     * @return array<string, string|null>
     */
    public static function sanitize($input): array
    {
        $in = is_array($input) ? $input : [];
        $out = [];
        foreach (self::FIELDS as $f) {
            $v = isset($in[$f]) && is_scalar($in[$f]) ? trim((string) $in[$f]) : '';
            $v = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $v);
            $max = in_array($f, ['landing_page', 'referrer'], true) ? 1000 : 255;
            if (in_array($f, ['landing_page', 'referrer'], true) && $v !== '' && !preg_match('#^https?://#i', $v)) {
                $v = '';
            }
            if (in_array($f, ['gclid', 'gbraid', 'wbraid', 'fbclid'], true) && !preg_match('/^[A-Za-z0-9._\-~]*$/', $v)) {
                $v = '';
            }
            $out[$f] = $v === '' ? null : mb_substr($v, 0, $max);
        }
        return $out;
    }
}
