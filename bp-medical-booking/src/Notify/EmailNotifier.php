<?php
declare(strict_types=1);

namespace BPMedical\Booking\Notify;

use BPMedical\Booking\Booking\Notifier;

final class EmailNotifier implements Notifier
{
    /** @var string[] */
    private array $emails;
    private string $adminUrl;

    /** @param string[] $emails */
    public function __construct(array $emails, string $adminUrl = '')
    {
        $this->emails = $emails;
        $this->adminUrl = $adminUrl;
    }

    public function send(array $message): void
    {
        if (!$this->emails) {
            return;
        }
        $body = implode("\n", (array) $message['lines']);
        $url = (string) ($message['admin_url'] ?? $this->adminUrl);
        if ($url !== '') {
            $body .= "\n\nАдмінка: " . $url;
        }
        wp_mail($this->emails, (string) $message['title'], $body, ['Content-Type: text/plain; charset=UTF-8']);
    }
}
