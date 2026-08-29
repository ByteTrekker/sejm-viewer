<?php

declare(strict_types=1);

namespace Milczenie\Tests\Unit;

use Milczenie\Domain\IssuerNormalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(IssuerNormalizer::class)]
final class IssuerNormalizerTest extends TestCase
{
    public function test_eli_abbreviation_expands_to_a_readable_minister(): void
    {
        $n = new IssuerNormalizer();
        $key = $n->normalize('MIN. SPRAW WEWNĘTRZNYCH I ADMINISTRACJI');

        self::assertSame('minister spraw wewnetrznych i administracji', $key);
        self::assertSame('Minister spraw wewnętrznych i administracji', $n->displayName($key));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function namedBodies(): iterable
    {
        // Kazda pozycja slownika etykiet osobno - usuniecie ktorejkolwiek ma dac czerwony test.
        yield 'premier' => ['PREZ. RADY MINISTRÓW', 'Prezes Rady Ministrów'];
        yield 'rząd' => ['RADA MINISTRÓW', 'Rada Ministrów'];
        yield 'prezydent' => ['PREZYDENT RZECZYPOSPOLITEJ POLSKIEJ', 'Prezydent RP'];
        yield 'Sejm' => ['SEJM', 'Sejm RP'];
        yield 'brak organu' => ['nieznany', 'Organ nieokreślony w API'];
    }

    #[DataProvider('namedBodies')]
    public function test_known_bodies_get_their_proper_names_not_lowercased_slugs(string $raw, string $expected): void
    {
        $n = new IssuerNormalizer();

        self::assertSame($expected, $n->displayName($n->normalize($raw)));
    }

    public function test_key_strips_padding_diacritics_and_case(): void
    {
        // Asercja na dokladna wartosc wykrywa usuniecie trim() i zamiane mb_strtolower().
        $n = new IssuerNormalizer();

        self::assertSame('minister srodowiska', $n->normalize('  MIN.  ŚRODOWISKA '));
        self::assertSame('szef kancelarii prezesa rady ministrow', $n->normalize('SZEF KANCELARII PREZESA RADY MINISTRÓW'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function diacritics(): iterable
    {
        yield 'ą' => ['MIN. Ą', 'minister a'];
        yield 'ć' => ['MIN. Ć', 'minister c'];
        yield 'ę' => ['MIN. Ę', 'minister e'];
        yield 'ł' => ['MIN. Ł', 'minister l'];
        yield 'ń' => ['MIN. Ń', 'minister n'];
        yield 'ó' => ['MIN. Ó', 'minister o'];
        yield 'ś' => ['MIN. Ś', 'minister s'];
        yield 'ź' => ['MIN. Ź', 'minister z'];
        yield 'ż' => ['MIN. Ż', 'minister z'];
    }

    #[DataProvider('diacritics')]
    public function test_every_polish_letter_has_an_ascii_counterpart(string $raw, string $expected): void
    {
        self::assertSame($expected, (new IssuerNormalizer())->normalize($raw));
    }

    public function test_display_name_trims_padding_before_matching_the_ministry_prefix(): void
    {
        // Bez trim() w prettify padding zjadlby przedrostek "min. " i organ
        // wyswietlilby sie jako "Min. zdrowia" zamiast "Minister zdrowia".
        $n = new IssuerNormalizer();

        self::assertSame('Minister zdrowia', $n->displayName($n->normalize('  MIN.  ZDROWIA  ')));
    }

    public function test_first_spelling_wins_and_later_ones_do_not_overwrite_it(): void
    {
        // Pilnuje `??=`: dwa zapisy daja ten sam klucz (diakrytyki sa usuwane w slug),
        // ale rozne etykiety - wiec zwykle przypisanie podmieniloby nazwe na gorsza.
        $n = new IssuerNormalizer();

        $key = $n->normalize('MIN. ŚRODOWISKA');
        $n->normalize('MIN. SRODOWISKA');

        self::assertSame('minister srodowiska', $key);
        self::assertSame('Minister środowiska', $n->displayName($key));
    }

    public function test_display_name_capitalises_a_multibyte_first_letter(): void
    {
        $n = new IssuerNormalizer();

        self::assertSame('Śląski wojewoda', $n->displayName($n->normalize('ŚLĄSKI WOJEWODA')));
    }

    public function test_unknown_key_falls_back_to_the_key_itself(): void
    {
        self::assertSame('cokolwiek', (new IssuerNormalizer())->displayName('cokolwiek'));
    }

    public function test_ministries_are_never_merged_across_years(): void
    {
        // Swiadoma decyzja: kompetencje wedrowaly miedzy urzedami, wiec sklejenie
        // tych dwoch nazw sugerowaloby ciaglosc, ktorej nie bylo.
        $n = new IssuerNormalizer();

        self::assertNotSame(
            $n->normalize('MIN. FINANSÓW'),
            $n->normalize('MIN. ROZWOJU I FINANSÓW'),
        );
    }

}
