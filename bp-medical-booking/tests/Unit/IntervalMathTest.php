<?php
declare(strict_types=1);

namespace BPMedical\Booking\Tests\Unit;

use BPMedical\Booking\Core\Interval;
use BPMedical\Booking\Core\IntervalMath;
use PHPUnit\Framework\TestCase;

final class IntervalMathTest extends TestCase
{
    public function testSubtractKeepsLocationAndSplits(): void
    {
        $r = IntervalMath::subtract([new Interval(0, 100, 'L')], [new Interval(20, 30), new Interval(25, 40), new Interval(90, 200)]);
        self::assertCount(2, $r);
        self::assertSame([0, 20, 'L'], [$r[0]->start, $r[0]->end, $r[0]->locationId]);
        self::assertSame([40, 90, 'L'], [$r[1]->start, $r[1]->end, $r[1]->locationId]);
    }

    public function testMergeAdjacent(): void
    {
        $r = IntervalMath::merge([new Interval(10, 20), new Interval(0, 10), new Interval(30, 40)]);
        self::assertSame([[0, 20], [30, 40]], array_map(static fn($i) => [$i->start, $i->end], $r));
    }
}
