<?php
declare(strict_types=1);

namespace BPMedical\Booking\Core;

final class Phone
{
    /**
     * Нормалізує український номер у E.164 (+380XXXXXXXXX). Повертає null, якщо невалідний.
     * Приймає: +380671234567, 380671234567, 0671234567, 67 123 45 67, (067) 123-45-67.
     */
    public static function normalizeUa(string $raw): ?string
    {
        $d = (string) preg_replace('/\D+/', '', $raw);
        if (strlen($d) === 12 && strpos($d, '380') === 0) {
            $d = substr($d, 3);
        } elseif (strlen($d) === 11 && strpos($d, '80') === 0) {
            $d = substr($d, 2);
        } elseif (strlen($d) === 10 && $d[0] === '0') {
            $d = substr($d, 1);
        }
        if (strlen($d) !== 9 || $d[0] === '0') {
            return null;
        }
        // Коди мобільних операторів і міських мереж починаються з 3-9.
        if (!preg_match('/^[3-9]\d{8}$/', $d)) {
            return null;
        }
        return '+380' . $d;
    }

    /** +380671234545 → +38067***45 (для логів). */
    public static function mask(string $phone): string
    {
        $d = (string) preg_replace('/\D+/', '', $phone);
        if (strlen($d) < 7) {
            return '***';
        }
        return '+' . substr($d, 0, 5) . '***' . substr($d, -2);
    }

    /** Маскує всі схожі на телефон послідовності в довільному тексті. */
    public static function maskInText(string $text): string
    {
        return (string) preg_replace_callback(
            '/\+?\d[\d\s\-()]{8,}\d/',
            static fn(array $m) => strlen((string) preg_replace('/\D/', '', $m[0])) >= 9 ? self::mask($m[0]) : $m[0],
            $text
        );
    }

    /** SHA-256 від E.164 (для Enhanced Conversions for Leads). */
    public static function hash(string $e164): string
    {
        return hash('sha256', $e164);
    }

    /** Форматування для людей: +380 67 123 45 67. */
    public static function pretty(string $e164): string
    {
        if (!preg_match('/^\+380(\d{2})(\d{3})(\d{2})(\d{2})$/', $e164, $m)) {
            return $e164;
        }
        return "+380 {$m[1]} {$m[2]} {$m[3]} {$m[4]}";
    }
}
