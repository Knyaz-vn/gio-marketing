<?php
declare(strict_types=1);

namespace BPMedical\Booking\Tests\Integration;

use BPMedical\Booking\Core\Tz;
use BPMedical\Booking\Tests\Support\TestStack;
use PHPUnit\Framework\TestCase;

/**
 * Два паралельні сабміти на один слот (окремі PHP-процеси, спільні SQLite + mock-календар)
 * → успішний лише один, другий отримує "слот зайнятий" і альтернативи.
 */
final class DoubleBookingTest extends TestCase
{
    public function testParallelSubmitsOnSameSlotOnlyOneSucceeds(): void
    {
        $dir = TestStack::tmpDir('race');
        $stack = new TestStack($dir);
        $start = self::futureWeekdayAt('10:00');
        self::assertNotNull($stack->availability->findSlot('gribanova', 'consult_primary', $start), 'слот вільний до тесту');

        $barrier = microtime(true) + 1.0;
        $procs = [];
        foreach (['Олена', 'Ірина'] as $name) {
            $cmd = [PHP_BINARY, __DIR__ . '/submit-worker.php', $dir, 'gribanova', 'consult_primary', (string) $start, (string) $barrier, $name];
            $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($p);
            $procs[] = [$p, $pipes];
        }
        $results = [];
        foreach ($procs as [$p, $pipes]) {
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            proc_close($p);
            $decoded = json_decode((string) $out, true);
            self::assertIsArray($decoded, "worker output: $out $err");
            $results[] = $decoded;
        }

        $ok = array_values(array_filter($results, static fn($r) => $r['ok'] === true));
        $fail = array_values(array_filter($results, static fn($r) => $r['ok'] === false));
        self::assertCount(1, $ok, 'рівно один успішний запис: ' . json_encode($results));
        self::assertCount(1, $fail);
        self::assertSame('slot_unavailable', $fail[0]['error']);
        self::assertSame(3, $fail[0]['alternatives'], '3 найближчі вільні слоти');

        // У календарі рівно одна онлайн-подія на цей час, у БД рівно один запис.
        $online = 0;
        foreach ($stack->calendar->dump() as $events) {
            foreach ($events as $e) {
                if (strpos((string) $e['summary'], 'ОНЛАЙН') !== false) {
                    $online++;
                }
            }
        }
        self::assertSame(1, $online);
        self::assertSame(1, $stack->bookingRepo->search([])['total']);
        self::assertNull($stack->availability->findSlot('gribanova', 'consult_primary', $start), 'слот тепер зайнятий');
    }

    /** Найближчий робочий день (Пн–Пт) щонайменше через 2 дні. */
    public static function futureWeekdayAt(string $hm): int
    {
        $d = Tz::addDays(Tz::date(time()), 2);
        while (Tz::weekday($d) > 5) {
            $d = Tz::addDays($d, 1);
        }
        return Tz::at($d, $hm);
    }
}
