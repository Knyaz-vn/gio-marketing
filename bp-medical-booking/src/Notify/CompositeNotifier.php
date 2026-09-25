<?php
declare(strict_types=1);

namespace BPMedical\Booking\Notify;

use BPMedical\Booking\Booking\Logger;
use BPMedical\Booking\Booking\Notifier;

/** Надсилає в усі канали; помилка одного каналу не зупиняє інші. */
final class CompositeNotifier implements Notifier
{
    /** @var Notifier[] */
    private array $channels;
    private Logger $log;

    /** @param Notifier[] $channels */
    public function __construct(array $channels, Logger $log)
    {
        $this->channels = $channels;
        $this->log = $log;
    }

    public function send(array $message): void
    {
        foreach ($this->channels as $c) {
            try {
                $c->send($message);
            } catch (\Throwable $e) {
                $this->log->error('notifier ' . get_class($c) . ' failed', ['error' => $e->getMessage()]);
            }
        }
    }
}
