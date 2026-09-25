<?php
declare(strict_types=1);

namespace BPMedical\Booking\Calendar;

use BPMedical\Booking\Core\CalendarEvent;

interface CalendarClient
{
    /**
     * events.list (singleEvents=true, orderBy=startTime) з урахуванням пагінації.
     *
     * @return CalendarEvent[]
     */
    public function listEvents(string $calendarId, int $timeMin, int $timeMax): array;

    /**
     * @param array<string, mixed> $event ресурс події у форматі Google Calendar API
     * @return string id створеної події
     */
    public function insertEvent(string $calendarId, array $event): string;

    /** @param array<string, mixed> $patch */
    public function patchEvent(string $calendarId, string $eventId, array $patch): void;

    public function deleteEvent(string $calendarId, string $eventId): void;

    /**
     * events.watch: push-канал.
     *
     * @return array{id:string, resource_id:string, expiration:int}
     */
    public function watch(string $calendarId, string $channelId, string $address, string $token, int $ttlSec): array;

    public function stopChannel(string $channelId, string $resourceId): void;

    public function supportsWatch(): bool;
}
