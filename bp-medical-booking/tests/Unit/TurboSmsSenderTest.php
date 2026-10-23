<?php
declare(strict_types=1);

namespace BPMedical\Booking\Tests\Unit;

use BPMedical\Booking\Calendar\HttpTransport;
use BPMedical\Booking\Notify\TurboSmsSender;
use PHPUnit\Framework\TestCase;

final class TurboSmsSenderTest extends TestCase
{
    private function transport(int $status, array $body, ?array &$captured): HttpTransport
    {
        return new class ($status, $body, $captured) implements HttpTransport {
            private int $status;
            private array $body;
            private $captured;

            public function __construct(int $status, array $body, ?array &$captured)
            {
                $this->status = $status;
                $this->body = $body;
                $this->captured = &$captured;
            }

            public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 15): array
            {
                $this->captured = compact('method', 'url', 'headers', 'body');
                return ['status' => $this->status, 'body' => (string) json_encode($this->body)];
            }
        };
    }

    public function testSendsRequestInTurboSmsFormat(): void
    {
        $captured = null;
        $sender = new TurboSmsSender('TOKEN', 'BPMedical', $this->transport(200, [
            'response_code' => 800,
            'response_status' => 'SUCCESS_MESSAGE_ACCEPTED',
            'response_result' => [['phone' => '380671234567', 'response_code' => 0, 'message_id' => 'f83f8868-5e46-c6cf-e4fb-615e5a293754', 'response_status' => 'OK']],
        ], $captured));
        self::assertSame('f83f8868-5e46-c6cf-e4fb-615e5a293754', $sender->send('+380671234567', 'Запис підтверджено'));
        self::assertSame('POST', $captured['method']);
        self::assertSame('https://api.turbosms.ua/message/send.json', $captured['url']);
        self::assertSame('Bearer TOKEN', $captured['headers']['Authorization']);
        self::assertSame(
            ['recipients' => ['380671234567'], 'sms' => ['sender' => 'BPMedical', 'text' => 'Запис підтверджено']],
            json_decode((string) $captured['body'], true)
        );
    }

    public function testRejectedMessageThrows(): void
    {
        $captured = null;
        $sender = new TurboSmsSender('TOKEN', 'BPMedical', $this->transport(200, [
            'response_code' => 207,
            'response_status' => 'NOT_ENOUGH_CREDITS',
            'response_result' => null,
        ], $captured));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('NOT_ENOUGH_CREDITS');
        $sender->send('+380671234567', 'x');
    }

    public function testRejectedRecipientThrows(): void
    {
        $captured = null;
        $sender = new TurboSmsSender('TOKEN', 'BPMedical', $this->transport(200, [
            'response_code' => 802,
            'response_status' => 'SUCCESS_MESSAGE_PARTIAL_ACCEPTED',
            'response_result' => [['phone' => '380671234567', 'response_code' => 406, 'message_id' => null, 'response_status' => 'NOT_ALLOWED_RECIPIENT_COUNTRY']],
        ], $captured));
        $this->expectException(\RuntimeException::class);
        $sender->send('+380671234567', 'x');
    }

    public function testHttpErrorThrows(): void
    {
        $captured = null;
        $sender = new TurboSmsSender('BAD', 'BPMedical', $this->transport(401, [], $captured));
        $this->expectException(\RuntimeException::class);
        $sender->send('+380671234567', 'x');
    }
}
