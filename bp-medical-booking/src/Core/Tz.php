<?php
declare(strict_types=1);

namespace BPMedical\Booking\Core;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Таймзона клініки. Усі розрахунки робляться в Europe/Kyiv, зберігання в UTC (unix timestamp).
 */
final class Tz
{
    private static ?DateTimeZone $kyiv = null;
    private static ?DateTimeZone $utc = null;

    public static function kyiv(): DateTimeZone
    {
        if (self::$kyiv === null) {
            try {
                self::$kyiv = new DateTimeZone('Europe/Kyiv');
            } catch (\Exception $e) {
                // Старі tzdata (до 2022b) знають лише Europe/Kiev.
                self::$kyiv = new DateTimeZone('Europe/Kiev');
            }
        }
        return self::$kyiv;
    }

    public static function utc(): DateTimeZone
    {
        if (self::$utc === null) {
            self::$utc = new DateTimeZone('UTC');
        }
        return self::$utc;
    }

    /** Початок доби (00:00 Kyiv) для дати Y-m-d, як unix timestamp. */
    public static function dayStart(string $ymd): int
    {
        return self::at($ymd, '00:00');
    }

    /** Timestamp для дати Y-m-d і часу H:i за Києвом. */
    public static function at(string $ymd, string $hm): int
    {
        if ($hm === '24:00') {
            return self::at(self::addDays($ymd, 1), '00:00');
        }
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $ymd . ' ' . $hm, self::kyiv());
        if ($dt === false) {
            throw new \InvalidArgumentException("Bad date/time: $ymd $hm");
        }
        return $dt->getTimestamp();
    }

    public static function date(int $ts): string
    {
        return self::local($ts)->format('Y-m-d');
    }

    public static function time(int $ts): string
    {
        return self::local($ts)->format('H:i');
    }

    public static function local(int $ts): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . $ts))->setTimezone(self::kyiv());
    }

    /** ISO-день тижня 1 (Пн) .. 7 (Нд). */
    public static function weekday(string $ymd): int
    {
        return (int) self::local(self::dayStart($ymd) + 12 * 3600)->format('N');
    }

    public static function addDays(string $ymd, int $days): string
    {
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd, self::kyiv());
        if ($dt === false) {
            throw new \InvalidArgumentException("Bad date: $ymd");
        }
        return $dt->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
    }

    public static function daysBetween(string $fromYmd, string $toYmd): int
    {
        $a = DateTimeImmutable::createFromFormat('!Y-m-d', $fromYmd, self::utc());
        $b = DateTimeImmutable::createFromFormat('!Y-m-d', $toYmd, self::utc());
        return (int) round(($b->getTimestamp() - $a->getTimestamp()) / 86400);
    }

    public static function isValidDate(string $ymd): bool
    {
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd, self::utc());
        return $dt !== false && $dt->format('Y-m-d') === $ymd;
    }

    public static function isValidTime(string $hm): bool
    {
        return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hm) || $hm === '24:00';
    }

    /** RFC3339 у UTC для Google API. */
    public static function rfc3339(int $ts): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $ts);
    }

    /** RFC3339 у Київському часі (для подій, які створюємо). */
    public static function rfc3339Local(int $ts): string
    {
        return self::local($ts)->format(DATE_RFC3339);
    }

    /** Хвилини з початку доби для "H:i" ("24:00" = 1440). */
    public static function minutes(string $hm): int
    {
        [$h, $m] = array_map('intval', explode(':', $hm));
        return $h * 60 + $m;
    }
}
