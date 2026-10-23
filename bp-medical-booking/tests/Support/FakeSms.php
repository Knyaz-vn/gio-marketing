<?php
declare(strict_types=1);

namespace BPMedical\Booking\Tests\Support;

use BPMedical\Booking\Notify\SmsSender;

final class FakeSms implements SmsSender
{
    /** @var list<array{0:string,1:string}> */
    public array $sent = [];
    public ?string $fail = null;

    public function send(string $e164, string $text): string
    {
        if ($this->fail !== null) {
            throw new \RuntimeException($this->fail);
        }
        $this->sent[] = [$e164, $text];
        return 'msg-' . count($this->sent);
    }
}
