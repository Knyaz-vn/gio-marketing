<?php
declare(strict_types=1);

namespace BPMedical\Booking\Booking;

interface Notifier
{
    /** @param array<string, mixed> $message структуроване повідомлення (див. BookingService::notifyPayload) */
    public function send(array $message): void;
}
