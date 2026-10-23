<?php
declare(strict_types=1);

namespace BPMedical\Booking\Notify;

use BPMedical\Booking\Calendar\HttpTransport;

/**
 * TurboSMS HTTP API (https://turbosms.ua/api.html): POST https://api.turbosms.ua/message/send.json,
 * авторизація Bearer-токеном. Ім'я відправника (alpha name) має бути зареєстроване в кабінеті TurboSMS.
 */
final class TurboSmsSender implements SmsSender
{
    private const URL = 'https://api.turbosms.ua/message/send.json';

    private string $token;
    private string $sender;
    private HttpTransport $http;

    public function __construct(string $token, string $sender, HttpTransport $http)
    {
        $this->token = $token;
        $this->sender = $sender;
        $this->http = $http;
    }

    public function send(string $e164, string $text): string
    {
        $res = $this->http->request('POST', self::URL, [
            'Authorization' => 'Bearer ' . $this->token,
            'Content-Type' => 'application/json',
        ], (string) json_encode([
            'recipients' => [ltrim($e164, '+')],
            'sms' => ['sender' => $this->sender, 'text' => $text],
        ], JSON_UNESCAPED_UNICODE), 15);

        $data = json_decode($res['body'], true);
        if ($res['status'] !== 200 || !is_array($data)) {
            throw new \RuntimeException('TurboSMS HTTP ' . $res['status']);
        }
        // Загальний код і код для конкретного номера: 0 або 800–899 означає, що повідомлення прийнято.
        $item = $data['response_result'][0] ?? null;
        $code = is_array($item) && isset($item['response_code']) ? (int) $item['response_code'] : (int) ($data['response_code'] ?? -1);
        if (!self::accepted((int) ($data['response_code'] ?? -1)) || !self::accepted($code)) {
            $status = is_array($item) && !empty($item['response_status']) ? $item['response_status'] : ($data['response_status'] ?? 'unknown');
            throw new \RuntimeException('TurboSMS: ' . $status . ' (' . $code . ')');
        }
        return is_array($item) ? (string) ($item['message_id'] ?? '') : '';
    }

    private static function accepted(int $code): bool
    {
        return $code === 0 || ($code >= 800 && $code < 900);
    }
}
