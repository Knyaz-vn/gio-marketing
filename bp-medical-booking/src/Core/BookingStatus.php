<?php
declare(strict_types=1);

namespace BPMedical\Booking\Core;

final class BookingStatus
{
    public const PENDING = 'pending';
    public const CONFIRMED = 'confirmed';
    public const CANCELLED = 'cancelled';
    public const NO_SHOW = 'no_show';
    public const VISITED = 'visited';

    public const ALL = [self::PENDING, self::CONFIRMED, self::CANCELLED, self::NO_SHOW, self::VISITED];

    /** Статуси, що займають час лікаря. */
    public const ACTIVE = [self::PENDING, self::CONFIRMED];

    public const LABELS = [
        self::PENDING => 'Очікує підтвердження',
        self::CONFIRMED => 'Підтверджено',
        self::CANCELLED => 'Скасовано',
        self::NO_SHOW => 'Не прийшов',
        self::VISITED => 'Відвідав',
    ];
}
