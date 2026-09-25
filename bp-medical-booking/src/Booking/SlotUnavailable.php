<?php
declare(strict_types=1);

namespace BPMedical\Booking\Booking;

use BPMedical\Booking\Core\Slot;

final class SlotUnavailable extends \RuntimeException
{
    /** @var Slot[] */
    public array $alternatives;

    /** @param Slot[] $alternatives */
    public function __construct(array $alternatives, string $message = 'Цей час щойно зайняли. Оберіть інший.')
    {
        parent::__construct($message);
        $this->alternatives = $alternatives;
    }
}
