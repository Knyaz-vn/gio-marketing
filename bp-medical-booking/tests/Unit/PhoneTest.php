<?php
declare(strict_types=1);

namespace BPMedical\Booking\Tests\Unit;

use BPMedical\Booking\Booking\Logger;
use BPMedical\Booking\Core\Phone;
use PHPUnit\Framework\TestCase;

final class PhoneTest extends TestCase
{
    /** @dataProvider valid */
    public function testNormalize(string $raw): void
    {
        self::assertSame('+380671234545', Phone::normalizeUa($raw));
    }

    /** @return array<array{string}> */
    public static function valid(): array
    {
        return [['+380671234545'], ['380671234545'], ['0671234545'], ['(067) 123-45-45'], ['+38 067 123 45 45'], ['671234545']];
    }

    public function testInvalid(): void
    {
        self::assertNull(Phone::normalizeUa('12345'));
        self::assertNull(Phone::normalizeUa('+380021234545'));
        self::assertNull(Phone::normalizeUa('+4915112345678'));
    }

    public function testMaskForLogs(): void
    {
        self::assertSame('+38067***45', Phone::mask('+380671234545'));
        $logged = [];
        (new Logger(function (string $l) use (&$logged): void {
            $logged[] = $l;
        }))->info('booking', ['phone' => '+380671234545', 'text' => 'дзвонити на 067 123 45 45']);
        self::assertStringNotContainsString('1234545', $logged[0]);
        self::assertStringNotContainsString('123 45 45', $logged[0]);
        self::assertStringContainsString('+38067***45', $logged[0]);
    }

    public function testHashIsSha256OfE164(): void
    {
        self::assertSame(hash('sha256', '+380671234545'), Phone::hash('+380671234545'));
    }
}
