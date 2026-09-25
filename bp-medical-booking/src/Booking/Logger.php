<?php
declare(strict_types=1);

namespace BPMedical\Booking\Booking;

use BPMedical\Booking\Core\Phone;

/** Логер, що ніколи не пише телефони у відкритому вигляді. */
class Logger
{
    /** @var callable(string): void */
    private $sink;

    public function __construct(?callable $sink = null)
    {
        $this->sink = $sink ?? static function (string $line): void {
            error_log($line);
        };
    }

    /** @param array<string, mixed> $context */
    public function log(string $level, string $message, array $context = []): void
    {
        $line = '[bp-booking] ' . strtoupper($level) . ' ' . $message;
        if ($context) {
            $line .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE);
        }
        ($this->sink)(Phone::maskInText($line));
    }

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }
}
