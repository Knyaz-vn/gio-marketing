<?php
declare(strict_types=1);

namespace BPMedical\Booking\Core;

/**
 * Нормалізація тексту назви події та прізвищ для надійного порівняння.
 *
 *  - lower-case (mb);
 *  - усі варіанти апострофа (' ’ ʼ ` ‘ ′ ´) → ASCII ';
 *  - латинські двійники (a c e i o p x y k m t b h, ï) усередині слів, що містять кирилицю, → кирилиця;
 *  - прибирання зайвих пробілів.
 */
final class Normalizer
{
    private const APOSTROPHES = ['’', 'ʼ', '`', '‘', '′', '´', 'ʹ', '＇'];

    /** Після lower-case. Велика B/H стає b/h і теж мапиться на в/н. */
    private const HOMOGLYPHS = [
        'a' => 'а', 'c' => 'с', 'e' => 'е', 'i' => 'і', 'o' => 'о', 'p' => 'р',
        'x' => 'х', 'y' => 'у', 'k' => 'к', 'm' => 'м', 't' => 'т', 'b' => 'в',
        'h' => 'н', 'ï' => 'ї',
    ];

    public static function normalize(string $text): string
    {
        $text = str_replace(self::APOSTROPHES, "'", $text);
        $text = mb_strtolower($text, 'UTF-8');
        $text = (string) preg_replace_callback(
            "/[\\p{L}\\p{M}']+/u",
            static function (array $m): string {
                $word = $m[0];
                if (preg_match('/\p{Cyrillic}/u', $word)) {
                    return strtr($word, self::HOMOGLYPHS);
                }
                return $word;
            },
            $text
        );
        $text = (string) preg_replace('/\s+/u', ' ', $text);
        return trim($text);
    }

    /**
     * Розбиває нормалізований текст на слова. Апостроф усередині слова лишається (дерев'янко),
     * по краях (лапки) відкидається. Дефіс, цифри, розділові знаки є межами слів.
     *
     * @return string[]
     */
    public static function tokens(string $normalized): array
    {
        if (!preg_match_all("/[\\p{L}\\p{M}']+/u", $normalized, $m)) {
            return [];
        }
        $out = [];
        foreach ($m[0] as $w) {
            $w = trim($w, "'");
            if ($w !== '') {
                $out[] = $w;
            }
        }
        return $out;
    }
}
