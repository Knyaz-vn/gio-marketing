<?php
declare(strict_types=1);

namespace BPMedical\Booking\Core;

/**
 * Визначає, яких лікарів стосується назва події календаря.
 *
 * Правила:
 *  1. Назва й aliases нормалізуються (Normalizer).
 *  2. Порівняння лише цілими словами: "Машевськ" не збігається ні з "Машевська", ні з "Машевський".
 *  3. Кожен лікар має список aliases (відмінки, варіанти написання); alias може складатися з кількох слів.
 *  4. match_mode:
 *     - "anywhere": лікар блокується, якщо alias знайдено будь-де;
 *     - "prefix":   лише в "лікарській" позиції: якщо назва містить роздільник (| або " - "),
 *                   враховуються всі прізвища лікарів у першому сегменті, за умови що сегмент
 *                   починається з прізвища лікаря; без роздільника лише перше слово назви.
 *  5. Одна подія може блокувати кількох лікарів.
 */
final class SurnameMatcher
{
    public const MODE_ANYWHERE = 'anywhere';
    public const MODE_PREFIX = 'prefix';

    private const SEGMENT_SEPARATOR = '/\s*(?:\||\s[-–—]\s)\s*/u';

    private string $mode;
    /** @var array<string, list<array{0:string,1:string[]}>> перше слово alias → [[doctorId, tokens]] */
    private array $index = [];

    /**
     * @param array<string, string[]> $aliasesByDoctor doctorId => [прізвище, alias1, ...]
     */
    public function __construct(array $aliasesByDoctor, string $mode = self::MODE_ANYWHERE)
    {
        if ($mode !== self::MODE_ANYWHERE && $mode !== self::MODE_PREFIX) {
            throw new \InvalidArgumentException("Unknown match_mode: $mode");
        }
        $this->mode = $mode;
        foreach ($aliasesByDoctor as $doctorId => $aliases) {
            foreach ($aliases as $alias) {
                $tokens = Normalizer::tokens(Normalizer::normalize((string) $alias));
                if (!$tokens) {
                    continue;
                }
                $this->index[$tokens[0]][] = [(string) $doctorId, $tokens];
            }
        }
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function match(string $summary): MatchResult
    {
        $normalized = Normalizer::normalize($summary);
        $tokens = Normalizer::tokens($normalized);

        $anywhere = [];
        foreach ($this->findAll($tokens) as [$doctorId]) {
            $anywhere[] = $doctorId;
        }

        $prefix = [];
        $segments = preg_split(self::SEGMENT_SEPARATOR, $normalized, 2);
        if ($segments !== false && count($segments) > 1) {
            $first = Normalizer::tokens($segments[0]);
            $found = $this->findAll($first);
            if ($found && $found[0][1] === 0) {
                foreach ($found as [$doctorId]) {
                    $prefix[] = $doctorId;
                }
            }
        } else {
            foreach ($this->findAll($tokens) as [$doctorId, $pos]) {
                if ($pos === 0) {
                    $prefix[] = $doctorId;
                }
            }
        }

        $blocking = $this->mode === self::MODE_PREFIX ? $prefix : $anywhere;
        return new MatchResult($blocking, $anywhere, $prefix);
    }

    /**
     * @param string[] $tokens
     * @return list<array{0:string,1:int}> [doctorId, позиція першого слова], відсортовано за позицією
     */
    private function findAll(array $tokens): array
    {
        $found = [];
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            foreach ($this->index[$tokens[$i]] ?? [] as [$doctorId, $aliasTokens]) {
                $len = count($aliasTokens);
                if ($i + $len > $n) {
                    continue;
                }
                if (array_slice($tokens, $i, $len) === $aliasTokens) {
                    $found[] = [$doctorId, $i];
                }
            }
        }
        return $found;
    }
}
