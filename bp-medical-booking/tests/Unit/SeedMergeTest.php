<?php
declare(strict_types=1);

namespace BPMedical\Booking\Tests\Unit;

use BPMedical\Booking\Options;
use PHPUnit\Framework\TestCase;

final class SeedMergeTest extends TestCase
{
    public function testMergeKeepsAdminEditsAndAddsNewData(): void
    {
        $current = [
            ['id' => 'gribanova', 'slug' => 'gribanova-anastasiya', 'photo_url' => '', 'profile_url' => 'https://bpmedical.com.ua/doctors-2/gribanova-anastasiya/',
             'aliases' => ['Грибанова', 'Грибанової-власний'], 'bookable' => false, 'schedule' => [['weekday' => 2, 'start' => '10:00', 'end' => '14:00', 'location_id' => 'loc_koriat']]],
            ['id' => 'duda', 'slug' => 'dyda', 'photo_url' => 'https://example.com/my.jpg', 'profile_url' => 'https://example.com/duda/'],
            ['id' => 'bazhenova', 'slug' => 'bazhenova-iryna', 'photo_url' => '', 'profile_url' => '', '_todo' => 'x'],
        ];
        $seed = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/data/doctors.json'), true);
        $out = Options::mergeDoctors($current, $seed);
        $by = array_column($out, null, 'id');

        self::assertStringContainsString('photo_2025-05-19', $by['gribanova']['photo_url'], 'порожнє фото заповнене');
        self::assertSame('https://bpmedical.com.ua/doctors/gribanova-anastasiya/', $by['gribanova']['profile_url'], 'старе /doctors-2/ посилання оновлене');
        self::assertSame(['Грибанова', 'Грибанової-власний'], $by['gribanova']['aliases'], 'aliases адміністратора не змінюються');
        self::assertFalse($by['gribanova']['bookable'], 'bookable не змінюється');
        self::assertCount(1, $by['gribanova']['schedule'], 'графік не змінюється');

        self::assertSame('https://example.com/my.jpg', $by['duda']['photo_url'], 'власне фото лишається');
        self::assertSame('https://example.com/duda/', $by['duda']['profile_url'], 'власне посилання лишається');

        self::assertSame('bajenova-dermatolog', $by['bazhenova']['slug']);
        self::assertArrayNotHasKey('_todo', $by['bazhenova']);
        self::assertArrayHasKey('kachula', $by, 'нові лікарі додані');
        self::assertCount(count($seed), $out);
    }
}
