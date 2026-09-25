<?php
declare(strict_types=1);

namespace BPMedical\Booking\Security;

/** Cloudflare Turnstile (опційно): вмикається, якщо задано site key у налаштуваннях і BPMB_TURNSTILE_SECRET. */
final class Turnstile
{
    public static function enabled(string $siteKey): bool
    {
        return $siteKey !== '' && Secrets::get('BPMB_TURNSTILE_SECRET') !== null;
    }

    public static function verify(string $token, string $ip): bool
    {
        if ($token === '') {
            return false;
        }
        $res = wp_remote_post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
            'timeout' => 8,
            'body' => ['secret' => Secrets::get('BPMB_TURNSTILE_SECRET'), 'response' => $token, 'remoteip' => $ip],
        ]);
        if (is_wp_error($res)) {
            return false;
        }
        $data = json_decode((string) wp_remote_retrieve_body($res), true);
        return !empty($data['success']);
    }
}
