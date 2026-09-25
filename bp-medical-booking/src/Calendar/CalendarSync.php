<?php
declare(strict_types=1);

namespace BPMedical\Booking\Calendar;

use BPMedical\Booking\Booking\CalendarRefresher;
use BPMedical\Booking\Booking\Logger;
use BPMedical\Booking\Core\Clock;
use BPMedical\Booking\Core\Config;
use BPMedical\Booking\Core\EventClassifier;
use BPMedical\Booking\Core\Tz;
use BPMedical\Booking\Storage\BookingRepository;
use BPMedical\Booking\Storage\EventCacheRepository;

/**
 * Синхронізація кешу подій: повне читання вікна [сьогодні, сьогодні + horizon + 1] для всіх календарів.
 * Запускається cron-ом кожні 2 хв і за push-сповіщенням events.watch.
 */
final class CalendarSync implements CalendarRefresher
{
    private Config $config;
    private CalendarClient $client;
    private EventCacheRepository $events;
    private EventClassifier $classifier;
    private Clock $clock;
    private Logger $log;
    private ?BookingRepository $bookings;

    public function __construct(
        Config $config,
        CalendarClient $client,
        EventCacheRepository $events,
        EventClassifier $classifier,
        Clock $clock,
        Logger $log,
        ?BookingRepository $bookings = null
    ) {
        $this->config = $config;
        $this->client = $client;
        $this->events = $events;
        $this->classifier = $classifier;
        $this->clock = $clock;
        $this->log = $log;
        $this->bookings = $bookings;
    }

    /**
     * @return array{ok:bool, calendars: list<array{id:string, name:string, events:int, error:?string}>, at:int}
     */
    public function syncAll(): array
    {
        $now = $this->clock->now();
        $today = Tz::date($now);
        $from = Tz::dayStart($today);
        $to = Tz::dayStart(Tz::addDays($today, $this->config->int('booking_horizon_days') + 2));
        $report = ['ok' => true, 'calendars' => [], 'at' => $now];
        foreach ($this->config->calendars() as $cal) {
            try {
                $list = $this->client->listEvents($cal['id'], $from, $to);
                $this->followMovedBookings($list);
                $n = $this->events->replaceWindow($cal['id'], $from, $to, $list, $this->classifier, $now);
                $report['calendars'][] = ['id' => $cal['id'], 'name' => $cal['name'], 'events' => $n, 'error' => null];
            } catch (\Throwable $e) {
                $report['ok'] = false;
                $report['calendars'][] = ['id' => $cal['id'], 'name' => $cal['name'], 'events' => 0, 'error' => substr($e->getMessage(), 0, 300)];
                $this->log->error('sync failed', ['calendar' => $cal['name'], 'error' => $e->getMessage()]);
            }
        }
        $this->events->purgeEndedBefore($from - 86400);
        return $report;
    }

    public function refreshWindow(int $from, int $to): void
    {
        $now = $this->clock->now();
        $errors = [];
        foreach ($this->config->calendars() as $cal) {
            try {
                $list = $this->client->listEvents($cal['id'], $from, $to);
                $this->followMovedBookings($list);
                $this->events->replaceWindow($cal['id'], $from, $to, $list, $this->classifier, $now);
            } catch (\Throwable $e) {
                $errors[] = $cal['name'] . ': ' . $e->getMessage();
            }
        }
        if ($errors) {
            throw new CalendarException(implode('; ', $errors));
        }
    }

    /** @param \BPMedical\Booking\Core\CalendarEvent[] $list */
    private function followMovedBookings(array $list): void
    {
        if ($this->bookings === null) {
            return;
        }
        foreach ($list as $e) {
            if ($e->leadId !== null && !$e->allDay && !$e->isIgnored() && $this->bookings->moveByLead($e->leadId, $e->start, $e->end) > 0) {
                $this->log->info('online booking moved in calendar', ['lead' => $e->leadId]);
            }
        }
    }
}
