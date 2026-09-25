<?php
declare(strict_types=1);

namespace BPMedical\Booking\Booking;

final class NullNotifier implements Notifier
{
    /** @var list<array<string, mixed>> */
    public array $sent = [];

    public function send(array $message): void
    {
        $this->sent[] = $message;
    }
}
