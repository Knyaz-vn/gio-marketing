<?php
declare(strict_types=1);

namespace BPMedical\Booking\Calendar;

use BPMedical\Booking\Core\CalendarEvent;
use BPMedical\Booking\Core\Tz;

/**
 * Google Calendar API v3. Читання через events.list (НЕ freeBusy: потрібні назви подій,
 * щоб розділити зайнятість по лікарях у спільному календарі).
 */
final class GoogleCalendarClient implements CalendarClient
{
    private const BASE = 'https://www.googleapis.com/calendar/v3';

    private ServiceAccountAuth $auth;
    private HttpTransport $http;

    public function __construct(ServiceAccountAuth $auth, HttpTransport $http)
    {
        $this->auth = $auth;
        $this->http = $http;
    }

    public function supportsWatch(): bool
    {
        return true;
    }

    public function listEvents(string $calendarId, int $timeMin, int $timeMax): array
    {
        $events = [];
        $pageToken = null;
        $guard = 0;
        do {
            $q = [
                'singleEvents' => 'true',
                'orderBy' => 'startTime',
                'timeMin' => Tz::rfc3339($timeMin),
                'timeMax' => Tz::rfc3339($timeMax),
                'maxResults' => 2500,
                'timeZone' => 'Europe/Kyiv',
                'fields' => 'items(id,status,summary,start,end,transparency,extendedProperties/private),nextPageToken',
            ];
            if ($pageToken !== null) {
                $q['pageToken'] = $pageToken;
            }
            $data = $this->call('GET', '/calendars/' . rawurlencode($calendarId) . '/events?' . http_build_query($q));
            foreach ((array) ($data['items'] ?? []) as $item) {
                if (is_array($item)) {
                    $events[] = CalendarEvent::fromGoogle($calendarId, $item);
                }
            }
            $pageToken = isset($data['nextPageToken']) ? (string) $data['nextPageToken'] : null;
        } while ($pageToken !== null && ++$guard < 20);
        return $events;
    }

    public function insertEvent(string $calendarId, array $event): string
    {
        $data = $this->call('POST', '/calendars/' . rawurlencode($calendarId) . '/events', $event);
        if (empty($data['id'])) {
            throw new CalendarException('events.insert: немає id у відповіді');
        }
        return (string) $data['id'];
    }

    public function patchEvent(string $calendarId, string $eventId, array $patch): void
    {
        $this->call('PATCH', '/calendars/' . rawurlencode($calendarId) . '/events/' . rawurlencode($eventId), $patch);
    }

    public function deleteEvent(string $calendarId, string $eventId): void
    {
        try {
            $this->call('DELETE', '/calendars/' . rawurlencode($calendarId) . '/events/' . rawurlencode($eventId));
        } catch (CalendarException $e) {
            // 404/410: подію вже видалено вручну, це нормально.
            if (!preg_match('/HTTP (404|410)/', $e->getMessage())) {
                throw $e;
            }
        }
    }

    public function watch(string $calendarId, string $channelId, string $address, string $token, int $ttlSec): array
    {
        $data = $this->call('POST', '/calendars/' . rawurlencode($calendarId) . '/events/watch', [
            'id' => $channelId,
            'type' => 'web_hook',
            'address' => $address,
            'token' => $token,
            'params' => ['ttl' => (string) $ttlSec],
        ]);
        return [
            'id' => (string) ($data['id'] ?? $channelId),
            'resource_id' => (string) ($data['resourceId'] ?? ''),
            'expiration' => (int) floor(((int) ($data['expiration'] ?? 0)) / 1000),
        ];
    }

    public function stopChannel(string $channelId, string $resourceId): void
    {
        try {
            $this->call('POST', '/channels/stop', ['id' => $channelId, 'resourceId' => $resourceId]);
        } catch (CalendarException $e) {
            // канал міг уже протермінуватися
        }
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    private function call(string $method, string $path, ?array $body = null): array
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->auth->accessToken(),
            'Accept' => 'application/json',
        ];
        $payload = null;
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
            $payload = (string) json_encode($body, JSON_UNESCAPED_UNICODE);
        }
        $res = $this->http->request($method, self::BASE . $path, $headers, $payload);
        if ($res['status'] >= 300) {
            // Тіло помилки Google не містить даних подій, але обрізаємо про всяк випадок.
            throw new CalendarException("Google Calendar HTTP {$res['status']}: " . substr($res['body'], 0, 300));
        }
        if ($res['body'] === '') {
            return [];
        }
        $data = json_decode($res['body'], true);
        return is_array($data) ? $data : [];
    }
}
