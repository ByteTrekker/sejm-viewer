<?php

declare(strict_types=1);

namespace Milczenie\Tests\Unit;

use Milczenie\Domain\RecipientNormalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(RecipientNormalizer::class)]
final class RecipientNormalizerTest extends TestCase
{
    public function test_key_is_lowercase_without_diacritics_and_without_padding(): void
    {
        // Asercja na dokladna wartosc, a nie na rownosc dwoch wywolan: tylko taka
        // wykrywa usuniecie trim() albo zamiane mb_strtolower() na strtolower().
        $n = new RecipientNormalizer();

        self::assertSame('minister zdrowia', $n->normalize('  Minister   Zdrowia  '));
        self::assertSame('minister srodowiska', $n->normalize('MINISTER ŚRODOWISKA'));
        self::assertSame('minister rolnictwa i rozwoju wsi', $n->normalize("Minister\tRolnictwa\ni Rozwoju Wsi"));
    }

    public function test_dash_variants_collapse_to_one_spaced_form(): void
    {
        $n = new RecipientNormalizer();

        self::assertSame('minister sprawiedliwosci', $n->normalize('minister sprawiedliwości – Prokurator Generalny'));
        self::assertSame('minister sprawiedliwosci', $n->normalize('minister sprawiedliwości - Prokurator Generalny'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function renamedMinistries(): iterable
    {
        // Kazda pozycja mapy ciaglosci osobno - usuniecie ktorejkolwiek ma dac czerwony test.
        yield 'finanse' => ['minister finansów', 'minister finansow i gospodarki'];
        yield 'rozwój i technologia' => ['minister rozwoju i technologii', 'minister finansow i gospodarki'];
        yield 'edukacja i nauka' => ['minister edukacji i nauki', 'minister edukacji'];
        yield 'klimat' => ['minister klimatu', 'minister klimatu i srodowiska'];
        yield 'rodzina' => ['minister rodziny i polityki społecznej', 'minister rodziny, pracy i polityki spolecznej'];
        yield 'sprawiedliwość z prokuratorem' => ['minister sprawiedliwości - Prokurator Generalny', 'minister sprawiedliwosci'];
    }

    #[DataProvider('renamedMinistries')]
    public function test_renamed_ministry_folds_into_its_current_name(string $historic, string $expected): void
    {
        self::assertSame($expected, (new RecipientNormalizer())->normalize($historic));
    }

    public function test_renamed_ministry_is_displayed_under_the_current_name(): void
    {
        $n = new RecipientNormalizer();

        // Kolejnosc ma znaczenie: nazwa historyczna pojawia sie w danych pierwsza,
        // ale etykieta ma pochodzic z nazwy biezacej, inaczej resort wyswietla sie wstecz.
        $key = $n->normalize('minister finansów');
        $n->normalize('minister finansów i gospodarki');

        self::assertSame('Minister finansów i gospodarki', $n->displayName($key));
    }

    public function test_first_spelling_wins_and_later_ones_do_not_overwrite_it(): void
    {
        // Pilnuje `??=`: podmiana na zwykle przypisanie sprawilaby, ze etykieta
        // zmienia sie przy kazdym kolejnym rekordzie z innym zapisem.
        $n = new RecipientNormalizer();

        $key = $n->normalize('minister obrony narodowej');
        $n->normalize('MINISTER OBRONY NARODOWEJ');

        self::assertSame('Minister obrony narodowej', $n->displayName($key));
    }

    public function test_display_name_capitalises_a_multibyte_first_letter(): void
    {
        $n = new RecipientNormalizer();
        $key = $n->normalize('ślusarz koronny');

        self::assertSame('Ślusarz koronny', $n->displayName($key));
    }

    public function test_display_name_keeps_diacritics_and_collapses_whitespace(): void
    {
        $n = new RecipientNormalizer();
        $key = $n->normalize('  minister  spraw   zagranicznych ');

        self::assertSame('Minister spraw zagranicznych', $n->displayName($key));
    }

    public function test_unrelated_ministries_stay_separate(): void
    {
        $n = new RecipientNormalizer();

        self::assertNotSame(
            $n->normalize('minister zdrowia'),
            $n->normalize('minister sprawiedliwości'),
        );
    }

    public function test_unknown_key_falls_back_to_the_key_itself(): void
    {
        self::assertSame('cokolwiek', (new RecipientNormalizer())->displayName('cokolwiek'));
    }
}
