<?php
declare(strict_types=1);

namespace BPMedical\Booking\Notify;

use BPMedical\Booking\Booking\Notifier;
use BPMedical\Booking\Calendar\HttpTransport;

final class TelegramNotifier implements Notifier
{
    private string $token;
    /** @var string[] */
    private array $chatIds;
    private HttpTransport $http;

    /** @param string[] $chatIds */
    public function __construct(string $token, array $chatIds, HttpTransport $http)
    {
        $this->token = $token;
        $this->chatIds = $chatIds;
        $this->http = $http;
    }

    public function send(array $message): void
    {
        $text = '<b>' . self::esc((string) $message['title']) . "</b>\n" . implode("\n", array_map([self::class, 'esc'], (array) $message['lines']));
        foreach ($this->chatIds as $chat) {
            $res = $this->http->request('POST', 'https://api.telegram.org/bot' . $this->token . '/sendMessage', ['Content-Type' => 'application/json'], (string) json_encode([
                'chat_id' => $chat,
                'text' => $text,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ], JSON_UNESCAPED_UNICODE), 10);
            if ($res['status'] !== 200) {
                throw new \RuntimeException('Telegram HTTP ' . $res['status']);
            }
        }
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_NOQUOTES, 'UTF-8');
    }
}
