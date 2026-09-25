<?php
declare(strict_types=1);

namespace BPMedical\Booking\Calendar;

interface HttpTransport
{
    /**
     * @param array<string, string> $headers
     * @return array{status:int, body:string}
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 15): array;
}
