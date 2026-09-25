<?php
declare(strict_types=1);

namespace BPMedical\Booking\Booking;

/** Свіже читання календарів (events.list) на вузьке вікно перед створенням запису. */
interface CalendarRefresher
{
    /** Оновлює кеш подій усіх календарів на [from, to). Кидає виняток, якщо календар недоступний. */
    public function refreshWindow(int $from, int $to): void;
}
