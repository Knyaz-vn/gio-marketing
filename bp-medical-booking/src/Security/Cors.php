<?php
declare(strict_types=1);

namespace BPMedical\Booking\Security;

/**
 * CORS для /wp-json/bp-booking/*: лише домени зі списку cors_origins (bpmedical.com.ua + staging).
 * Для інших маршрутів WordPress поведінка не змінюється.
 */
final class Cors
{
    /** @param string[] $origins */
    public static function register(array $origins): void
    {
        add_filter('rest_pre_serve_request', static function ($served, $result, $request) use ($origins) {
            if (!$request instanceof \WP_REST_Request || strpos($request->get_route(), '/bp-booking/') !== 0) {
                return $served;
            }
            $origin = get_http_origin();
            header_remove('Access-Control-Allow-Origin');
            header_remove('Access-Control-Allow-Credentials');
            $own = untrailingslashit(home_url());
            $allowed = array_map('untrailingslashit', array_merge($origins, [$own]));
            if ($origin && in_array(untrailingslashit($origin), $allowed, true)) {
                header('Access-Control-Allow-Origin: ' . $origin);
                header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
                header('Access-Control-Allow-Headers: Content-Type, X-WP-Nonce');
                header('Vary: Origin');
            }
            return $served;
        }, 20, 3);
    }
}
