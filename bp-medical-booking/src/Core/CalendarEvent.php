<?php
declare(strict_types=1);

namespace BPMedical\Booking\Core;

/**
 * Подія Google Calendar у внутрішньому форматі. Summary є внутрішніми даними:
 * воно ніколи не повертається через публічний API.
 */
final class CalendarEvent
{
    public string $calendarId;
    public string $id;
    public string $summary;
    public string $status;        // confirmed | tentative | cancelled
    public string $transparency;  // opaque | transparent
    public bool $allDay;
    public int $start;            // UTC timestamp (для all-day: 00:00 Kyiv першого дня)
    public int $end;              // UTC timestamp (для all-day: 00:00 Kyiv дня після останнього)
    public ?string $description;
    /** ID онлайн-заявки (extendedProperties.private.bpmb_lead), якщо подію створив сайт */
    public ?string $leadId = null;

    public function __construct(
        string $calendarId,
        string $id,
        string $summary,
        int $start,
        int $end,
        bool $allDay = false,
        string $status = 'confirmed',
        string $transparency = 'opaque',
        ?string $description = null
    ) {
        $this->calendarId = $calendarId;
        $this->id = $id;
        $this->summary = $summary;
        $this->start = $start;
        $this->end = $end;
        $this->allDay = $allDay;
        $this->status = $status;
        $this->transparency = $transparency;
        $this->description = $description;
    }

    /**
     * Створює з ресурсу Google Calendar API (events.list item).
     *
     * @param array<string, mixed> $item
     */
    public static function fromGoogle(string $calendarId, array $item): self
    {
        $start = $item['start'] ?? [];
        $end = $item['end'] ?? [];
        $allDay = isset($start['date']);
        if ($allDay) {
            $s = Tz::dayStart((string) $start['date']);
            $e = isset($end['date']) ? Tz::dayStart((string) $end['date']) : $s + 86400;
        } else {
            $s = isset($start['dateTime']) ? (int) strtotime((string) $start['dateTime']) : 0;
            $e = isset($end['dateTime']) ? (int) strtotime((string) $end['dateTime']) : $s;
        }
        $event = new self(
            $calendarId,
            (string) ($item['id'] ?? ''),
            (string) ($item['summary'] ?? ''),
            $s,
            $e,
            $allDay,
            (string) ($item['status'] ?? 'confirmed'),
            (string) ($item['transparency'] ?? 'opaque'),
            isset($item['description']) ? (string) $item['description'] : null
        );
        $lead = $item['extendedProperties']['private']['bpmb_lead'] ?? null;
        $event->leadId = is_string($lead) && $lead !== '' ? $lead : null;
        return $event;
    }

    public function isIgnored(): bool
    {
        return $this->status === 'cancelled' || $this->transparency === 'transparent';
    }
}
