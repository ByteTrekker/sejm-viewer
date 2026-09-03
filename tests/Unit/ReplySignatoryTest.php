<?php

declare(strict_types=1);

namespace Milczenie\Tests\Unit;

use Milczenie\Domain\ReplySignatory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Kazdy przypadek pochodzi z prawdziwego pola "author" w API - to nie sa
 * wymyslone warianty, tylko te, na ktorych regula sie kolejno wykladala.
 */
#[CoversClass(ReplySignatory::class)]
final class ReplySignatoryTest extends TestCase
{
    private ReplySignatory $parser;

    protected function setUp(): void
    {
        $this->parser = new ReplySignatory();
    }

    public function test_function_before_the_name_is_the_common_form(): void
    {
        $r = $this->parser->parse('Podsekretarz stanu Hanna Majszczyk');

        self::assertSame('podsekretarz stanu', $r['funkcja']);
        self::assertSame('Hanna Majszczyk', $r['nazwisko']);
        self::assertSame('hanna-majszczyk', $r['klucz']);
    }

    public function test_dash_separates_function_from_name(): void
    {
        $r = $this->parser->parse('minister rodziny, pracy i polityki społecznej - Agnieszka Dziemianowicz-Bąk');

        self::assertSame('Agnieszka Dziemianowicz-Bąk', $r['nazwisko']);
    }

    public function test_last_dash_wins_when_the_function_contains_one(): void
    {
        $r = $this->parser->parse('minister - członek Rady Ministrów - Maciej Berek');

        self::assertSame('Maciej Berek', $r['nazwisko']);
        self::assertSame('minister - członek rady ministrów', $r['funkcja']);
    }

    public function test_function_written_after_the_name_is_stripped(): void
    {
        $r = $this->parser->parse('Z upoważnienia MINISTRA EDUKACJI NARODOWEJ Joanna Berdzik PODSEKRETARZ STANU');

        self::assertSame('Joanna Berdzik', $r['nazwisko']);
    }

    public function test_missing_space_between_function_and_name_is_repaired(): void
    {
        $r = $this->parser->parse('Podsekretarz stanuTomasz Szubiela');

        self::assertSame('Tomasz Szubiela', $r['nazwisko']);
    }

    /**
     * Slowo urzedowe w dopelniaczu jest pisane wielka litera i wchodziloby do
     * nazwiska, gdyby brac trzy czlony zamiast dwoch.
     */
    public function test_office_words_do_not_leak_into_the_name(): void
    {
        $r = $this->parser->parse('prezes Urzędu Ochrony Konkurencji i Konsumentów Tomasz Chróstny');

        self::assertSame('Tomasz Chróstny', $r['nazwisko']);
    }

    public function test_en_dash_in_a_surname_is_normalised(): void
    {
        $r = $this->parser->parse('Podsekretarz stanu Henryka Mościcka–Dendys');

        self::assertSame('Henryka Mościcka-Dendys', $r['nazwisko']);
        self::assertSame('henryka-moscicka-dendys', $r['klucz']);
    }

    public function test_signature_without_a_person_returns_nothing(): void
    {
        $r = $this->parser->parse('Minister sprawiedliwości');

        self::assertNull($r['nazwisko']);
        self::assertNull($r['klucz']);
        self::assertNull($r['funkcja']);
    }

    public function test_bare_name_has_no_function(): void
    {
        $r = $this->parser->parse('Marcin Romanowski');

        self::assertSame('Marcin Romanowski', $r['nazwisko']);
        self::assertNull($r['funkcja']);
    }

    public function test_empty_input_returns_nothing(): void
    {
        self::assertNull($this->parser->parse('   ')['nazwisko']);
    }

    public function test_key_folds_case_and_diacritics_but_not_different_people(): void
    {
        self::assertSame('jan-zolna', $this->parser->key('Jan Żółna'));
        self::assertNotSame($this->parser->key('Jan Kowalski'), $this->parser->key('Anna Kowalski'));
    }
}
