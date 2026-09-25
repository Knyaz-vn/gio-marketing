<?php
declare(strict_types=1);

namespace BPMedical\Booking\Calendar;

final class WpHttpTransport implements HttpTransport
{
    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 15): array
    {
        $args = ['method' => $method, 'headers' => $headers, 'timeout' => $timeout];
        if ($body !== null) {
            $args['body'] = $body;
        }
        $res = wp_remote_request($url, $args);
        if (is_wp_error($res)) {
            throw new CalendarException('HTTP error: ' . $res->get_error_message());
        }
        return ['status' => (int) wp_remote_retrieve_response_code($res), 'body' => (string) wp_remote_retrieve_body($res)];
    }
}
