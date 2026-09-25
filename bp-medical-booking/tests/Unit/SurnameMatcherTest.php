<?php
declare(strict_types=1);

namespace BPMedical\Booking\Tests\Unit;

use BPMedical\Booking\Core\CalendarEvent;
use BPMedical\Booking\Core\EventClassifier;
use BPMedical\Booking\Core\Normalizer;
use BPMedical\Booking\Core\SurnameMatcher;
use BPMedical\Booking\Core\Tz;
use PHPUnit\Framework\TestCase;

final class SurnameMatcherTest extends TestCase
{
    private const ALIASES = [
        'mashevska' => ['Машевська', 'Машевської', 'Машевській', 'Машевську', 'Машевською'],
        'mashevskyi' => ['Машевський', 'Машевського', 'Машевському', 'Машевським'],
        'derevianko' => ["Дерев'янко", 'Деревянко'],
        'biktimirov' => ['Біктіміров', 'Біктімірова'],
        'korol' => ['Король'],
        'musiienko' => ['Мусієнко'],
        'duda' => ['Дуда', 'Дуди'],
        'gribanova' => ['Грибанова'],
    ];

    private function anywhere(): SurnameMatcher
    {
        return new SurnameMatcher(self::ALIASES, SurnameMatcher::MODE_ANYWHERE);
    }

    private function prefix(): SurnameMatcher
    {
        return new SurnameMatcher(self::ALIASES, SurnameMatcher::MODE_PREFIX);
    }

    // --- 1. Нормалізація ----------------------------------------------------------------

    public function testNormalizerLowercasesUnifiesApostrophesAndSpaces(): void
    {
        self::assertSame("дерев'янко | прийом", Normalizer::normalize("  ДЕРЕВ’ЯНКО   |\tприйом "));
        self::assertSame("дерев'янко", Normalizer::normalize('Деревʼянко'));
        self::assertSame("дерев'янко", Normalizer::normalize('Дерев`янко'));
    }

    public function testNormalizerReplacesLatinHomoglyphsOnlyInsideCyrillicWords(): void
    {
        // "Бiктiмiров" з латинськими i; "Mусієнко" з латинською M; "KOPOЛЬ" з латинськими K, O, P.
        self::assertSame('біктіміров', Normalizer::normalize('Бiктiмiров'));
        self::assertSame('мусієнко', Normalizer::normalize('Mусієнко'));
        self::assertSame('король', Normalizer::normalize('KOPOЛЬ'));
        // Чисто латинські слова не чіпаємо.
        self::assertSame('online mri', Normalizer::normalize('Online MRI'));
    }

    // --- 2. Лише цілі слова ---------------------------------------------------------------

    public function testMashevskaAndMashevskyiAreDifferentDoctors(): void
    {
        $m = $this->anywhere();
        self::assertSame(['mashevska'], $m->match('Машевська | консультація | Іваненко')->doctorIds);
        self::assertSame(['mashevskyi'], $m->match('Машевський | наркоз | Іваненко')->doctorIds);
    }

    public function testSubstringDoesNotMatch(): void
    {
        $m = $this->anywhere();
        self::assertSame([], $m->match('Машевськ | ?')->doctorIds, 'обрізане прізвище не блокує нікого');
        self::assertSame([], $m->match('Машевськ. консультація')->doctorIds);
        self::assertSame([], $m->match('Дудка | прийом')->doctorIds, 'Дудка ≠ Дуда');
        self::assertSame([], $m->match('Королько | прийом')->doctorIds, 'Королько ≠ Король');
        self::assertSame([], $m->match('Машевськаія')->doctorIds);
    }

    public function testDeclinedFormsFromAliases(): void
    {
        $m = $this->anywhere();
        self::assertSame(['mashevska'], $m->match('Консультація у Машевської')->doctorIds);
        self::assertSame(['mashevskyi'], $m->match('Наркоз: Машевського')->doctorIds);
    }

    // --- 3. Апострофи й латиниця ---------------------------------------------------------

    /** @dataProvider derevianko */
    public function testDereviankoApostropheVariants(string $summary): void
    {
        self::assertSame(['derevianko'], $this->anywhere()->match($summary)->doctorIds);
    }

    /** @return array<string, array{string}> */
    public static function derevianko(): array
    {
        return [
            'ascii' => ["Дерев'янко | консультація"],
            'right quote' => ['Дерев’янко | консультація'],
            'modifier letter' => ['Деревʼянко | консультація'],
            'backtick' => ['Дерев`янко | консультація'],
            'no apostrophe (alias)' => ['Деревянко | консультація'],
            'upper case' => ["ДЕРЕВ'ЯНКО | КОНСУЛЬТАЦІЯ"],
            'in quotes' => ["'Дерев'янко' консультація"],
        ];
    }

    public function testLatinIInsideCyrillicSurname(): void
    {
        $r = $this->anywhere()->match('Бiктiмiров | операція');
        self::assertSame(['biktimirov'], $r->doctorIds);
        $r = $this->anywhere()->match('Mусiєнко | УЗД');
        self::assertSame(['musiienko'], $r->doctorIds);
    }

    // --- 4. Режим prefix: прізвище пацієнта = прізвище лікаря ---------------------------

    public function testPatientSurnameEqualsDoctorSurnameInPrefixMode(): void
    {
        $summary = 'Мусієнко | УЗД | Король Ірина';
        self::assertSame(['musiienko'], $this->prefix()->match($summary)->doctorIds, 'prefix: пацієнтка Король не блокує лікаря Король');

        $any = $this->anywhere()->match($summary);
        self::assertEqualsCanonicalizing(['musiienko', 'korol'], $any->doctorIds, 'anywhere блокує обох');
        self::assertSame(['korol'], $any->suspiciousIds, 'але Король потрапляє у "можливі хибні збіги"');
    }

    public function testPrefixWithoutSeparatorUsesFirstWordOnly(): void
    {
        $p = $this->prefix();
        self::assertSame(['korol'], $p->match('Король консультація Іваненко')->doctorIds);
        self::assertSame([], $p->match('Консультація Король')->doctorIds);
        self::assertSame(['gribanova'], $p->match('Грибанова - УЗД - Дуда')->doctorIds, 'роздільник " - " теж підтримується');
    }

    public function testPrefixRequiresFirstSegmentToStartWithDoctor(): void
    {
        self::assertSame([], $this->prefix()->match('Операція Біктіміров | Петренко')->doctorIds);
    }

    // --- 5. Кілька прізвищ в одній події -------------------------------------------------

    public function testTwoDoctorsInOneEvent(): void
    {
        $summary = 'Біктіміров + Машевський | операція | Петренко';
        self::assertEqualsCanonicalizing(['biktimirov', 'mashevskyi'], $this->anywhere()->match($summary)->doctorIds);
        self::assertEqualsCanonicalizing(['biktimirov', 'mashevskyi'], $this->prefix()->match($summary)->doctorIds);
        self::assertSame([], $this->prefix()->match($summary)->suspiciousIds);
    }

    public function testNoDoctorFound(): void
    {
        $r = $this->anywhere()->match('Нарада адміністраторів');
        self::assertTrue($r->isEmpty());
    }

    // --- EventClassifier: all-day, transparent, cancelled, діагностика --------------------

    public function testAllDayEventBlocksWholeDays(): void
    {
        $c = new EventClassifier($this->anywhere());
        $e = CalendarEvent::fromGoogle('cal', [
            'id' => 'x', 'summary' => 'Машевська відпустка',
            'start' => ['date' => '2026-10-05'], 'end' => ['date' => '2026-10-08'],
        ]);
        self::assertTrue($e->allDay);
        $busy = $c->busyByDoctor([$e]);
        self::assertArrayHasKey('mashevska', $busy);
        self::assertArrayNotHasKey('mashevskyi', $busy);
        self::assertSame(Tz::at('2026-10-05', '00:00'), $busy['mashevska'][0]->start);
        self::assertSame(Tz::at('2026-10-08', '00:00'), $busy['mashevska'][0]->end);
        self::assertContains(EventClassifier::FLAG_ALL_DAY, $c->classify($e)['flags']);
    }

    public function testTransparentAndCancelledAreIgnored(): void
    {
        $c = new EventClassifier($this->anywhere());
        $t = new CalendarEvent('cal', 'a', 'Дуда | нагадування', 0, 3600, false, 'confirmed', 'transparent');
        $x = new CalendarEvent('cal', 'b', 'Дуда | прийом', 0, 3600, false, 'cancelled');
        self::assertSame([], $c->busyByDoctor([$t, $x]));
        self::assertSame([EventClassifier::FLAG_IGNORED], $c->classify($t)['flags']);
    }

    public function testDiagnosticsFlags(): void
    {
        $c = new EventClassifier($this->anywhere());
        $unrec = $c->classify(new CalendarEvent('cal', 'a', 'Нарада', 0, 1));
        self::assertContains(EventClassifier::FLAG_UNRECOGNIZED, $unrec['flags']);

        $multi = $c->classify(new CalendarEvent('cal', 'b', 'Біктіміров + Машевський | операція', 0, 1));
        self::assertContains(EventClassifier::FLAG_MULTI, $multi['flags']);

        $susp = $c->classify(new CalendarEvent('cal', 'c', 'Мусієнко | УЗД | Король', 0, 1));
        self::assertContains(EventClassifier::FLAG_SUSPICIOUS, $susp['flags']);
    }
}
