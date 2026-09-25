<?php
declare(strict_types=1);

namespace BPMedical\Booking\Core;

final class MatchResult
{
    /** @var string[] лікарі, час яких блокує подія (з урахуванням match_mode) */
    public array $doctorIds;
    /** @var string[] усі лікарі, знайдені будь-де в назві */
    public array $anywhereIds;
    /** @var string[] лікарі, знайдені в "лікарській" позиції (перше слово / перший сегмент) */
    public array $prefixIds;
    /** @var string[] знайдені не на початку: кандидати в "можливі хибні збіги" */
    public array $suspiciousIds;

    /**
     * @param string[] $doctorIds
     * @param string[] $anywhereIds
     * @param string[] $prefixIds
     */
    public function __construct(array $doctorIds, array $anywhereIds, array $prefixIds)
    {
        $this->doctorIds = array_values(array_unique($doctorIds));
        $this->anywhereIds = array_values(array_unique($anywhereIds));
        $this->prefixIds = array_values(array_unique($prefixIds));
        $this->suspiciousIds = array_values(array_diff($this->anywhereIds, $this->prefixIds));
    }

    public function isEmpty(): bool
    {
        return !$this->anywhereIds;
    }
}
