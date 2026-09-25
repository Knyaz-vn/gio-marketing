<?php
declare(strict_types=1);

namespace BPMedical\Booking\Core;

final class Ids
{
    /** Crockford base32 без неоднозначних символів (I, L, O, U). */
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /** ID заявки для людей і аналітики, напр. BP-7K2M9QXA. */
    public static function lead(string $prefix = 'BP'): string
    {
        $s = '';
        $bytes = random_bytes(8);
        for ($i = 0; $i < 8; $i++) {
            $s .= self::ALPHABET[ord($bytes[$i]) % 32];
        }
        return $prefix . '-' . $s;
    }

    public static function token(): string
    {
        return bin2hex(random_bytes(16));
    }
}
