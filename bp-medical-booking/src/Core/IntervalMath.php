<?php
declare(strict_types=1);

namespace BPMedical\Booking\Core;

final class IntervalMath
{
    /**
     * Віднімає зайняті інтервали від робочих. Локація робочого інтервалу зберігається.
     *
     * @param Interval[] $base
     * @param Interval[] $busy
     * @return Interval[]
     */
    public static function subtract(array $base, array $busy): array
    {
        $busy = self::merge($busy);
        $out = [];
        foreach ($base as $b) {
            $pieces = [new Interval($b->start, $b->end, $b->locationId)];
            foreach ($busy as $x) {
                $next = [];
                foreach ($pieces as $p) {
                    if (!$p->overlaps($x->start, $x->end)) {
                        $next[] = $p;
                        continue;
                    }
                    if ($p->start < $x->start) {
                        $next[] = new Interval($p->start, $x->start, $p->locationId);
                    }
                    if ($x->end < $p->end) {
                        $next[] = new Interval($x->end, $p->end, $p->locationId);
                    }
                }
                $pieces = $next;
                if (!$pieces) {
                    break;
                }
            }
            foreach ($pieces as $p) {
                if ($p->length() > 0) {
                    $out[] = $p;
                }
            }
        }
        usort($out, static fn(Interval $a, Interval $b) => $a->start <=> $b->start);
        return $out;
    }

    /**
     * Об'єднує інтервали, що перетинаються або стикуються (локацію ігнорує).
     *
     * @param Interval[] $items
     * @return Interval[]
     */
    public static function merge(array $items): array
    {
        $items = array_values(array_filter($items, static fn(Interval $i) => $i->end > $i->start));
        usort($items, static fn(Interval $a, Interval $b) => $a->start <=> $b->start);
        $out = [];
        foreach ($items as $i) {
            $last = $out ? $out[count($out) - 1] : null;
            if ($last !== null && $i->start <= $last->end) {
                $last->end = max($last->end, $i->end);
            } else {
                $out[] = new Interval($i->start, $i->end, $i->locationId);
            }
        }
        return $out;
    }

    /**
     * Обрізає інтервали за межами [start, end).
     *
     * @param Interval[] $items
     * @return Interval[]
     */
    public static function clip(array $items, int $start, int $end): array
    {
        $out = [];
        foreach ($items as $i) {
            $s = max($i->start, $start);
            $e = min($i->end, $end);
            if ($e > $s) {
                $out[] = new Interval($s, $e, $i->locationId);
            }
        }
        return $out;
    }
}
