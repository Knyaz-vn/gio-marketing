<?php
declare(strict_types=1);

namespace BPMedical\Booking\Booking;

final class ValidationError extends \InvalidArgumentException
{
    /** @var array<string, string> поле => повідомлення */
    public array $fields;

    /** @param array<string, string> $fields */
    public function __construct(array $fields)
    {
        parent::__construct('Перевірте правильність заповнення форми.');
        $this->fields = $fields;
    }
}
