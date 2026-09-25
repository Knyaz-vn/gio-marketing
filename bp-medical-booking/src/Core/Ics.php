<?php
declare(strict_types=1);

namespace BPMedical\Booking\Core;

/** Генерація .ics (RFC 5545) для пацієнта. */
final class Ics
{
    public static function build(
        string $uid,
        int $start,
        int $end,
        string $summary,
        string $location,
        string $description,
        int $now
    ): string {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//BP Medical//Online Booking//UK',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:' . self::escape($uid) . '@bpmedical.com.ua',
            'DTSTAMP:' . gmdate('Ymd\THis\Z', $now),
            'DTSTART:' . gmdate('Ymd\THis\Z', $start),
            'DTEND:' . gmdate('Ymd\THis\Z', $end),
            'SUMMARY:' . self::escape($summary),
            'LOCATION:' . self::escape($location),
            'DESCRIPTION:' . self::escape($description),
            'STATUS:TENTATIVE',
            'BEGIN:VALARM',
            'TRIGGER:-PT2H',
            'ACTION:DISPLAY',
            'DESCRIPTION:' . self::escape($summary),
            'END:VALARM',
            'END:VEVENT',
            'END:VCALENDAR',
        ];
        return implode("\r\n", array_map([self::class, 'fold'], $lines)) . "\r\n";
    }

    private static function escape(string $s): string
    {
        return str_replace(["\\", ';', ',', "\r\n", "\n"], ["\\\\", '\;', '\,', '\n', '\n'], $s);
    }

    /** Перенос рядків > 75 октетів (без розриву UTF-8 символів). */
    private static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }
        $out = '';
        $cur = '';
        foreach (preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
            if (strlen($cur) + strlen($ch) > 74) {
                $out .= $cur . "\r\n ";
                $cur = '';
            }
            $cur .= $ch;
        }
        return $out . $cur;
    }
}
