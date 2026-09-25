<?php
declare(strict_types=1);

namespace BPMedical\Booking\Security;

/** Ковзне вікно на transients: не більше N дій за годину з одного IP (IP зберігається лише як хеш). */
final class RateLimiter
{
    public static function hit(string $bucket, string $ip, int $limit, int $window = 3600): bool
    {
        if ($limit <= 0) {
            return true;
        }
        $key = 'bpmb_rl_' . $bucket . '_' . substr(hash('sha256', $ip . wp_salt('nonce')), 0, 24);
        $now = time();
        $hits = array_values(array_filter((array) get_transient($key), static fn($t) => (int) $t > $now - $window));
        if (count($hits) >= $limit) {
            return false;
        }
        $hits[] = $now;
        set_transient($key, $hits, $window);
        return true;
    }

    public static function clientIp(): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        if (defined('BPMB_TRUST_CLOUDFLARE_IP') && BPMB_TRUST_CLOUDFLARE_IP && !empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $ip = (string) $_SERVER['HTTP_CF_CONNECTING_IP'];
        }
        /** Фільтр для нестандартних проксі. */
        return (string) apply_filters('bpmb_client_ip', $ip);
    }
}
