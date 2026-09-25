<?php
declare(strict_types=1);

namespace BPMedical\Booking\Core;

/** Джерело поточного часу (підміняється в тестах). */
interface Clock
{
    public function now(): int;
}
