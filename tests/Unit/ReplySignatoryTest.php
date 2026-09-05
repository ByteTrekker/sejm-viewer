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
        // Funkcja sklada sie z obu stron nazwiska, nie tylko z tej przed nim.
        self::assertSame('z upoważnienia ministra edukacji narodowej podsekretarz stanu', $r['funkcja']);
    }

    public function test_office_word_written_in_capitals_is_still_an_office_word(): void
    {
        $r = $this->parser->parse('MINISTER Jan Kowalski');

        self::assertSame('Jan Kowalski', $r['nazwisko']);
        self::assertSame('minister', $r['funkcja']);
    }

    /**
     * Kancelarie pisza wielkimi literami funkcje, nigdy nazwisk. Dopuszczenie
     * ich jako nazwisk robilo z "SEKRETARZ STANU" osobe.
     */
    public function test_all_capitals_is_never_a_name(): void
    {
        self::assertNull($this->parser->parse('JAN KOWALSKI SEKRETARZ')['nazwisko']);
    }

    /**
     * Inicjal to za malo, zeby uznac token za imie - i to jest znana granica
     * reguly, a nie przeoczenie: "J Kowalski" wypada jako nierozpoznany.
     */
    public function test_single_letter_is_not_enough_for_a_name(): void
    {
        self::assertNull($this->parser->parse('Sekretarz stanu J Kowalski')['nazwisko']);
    }

    public function test_token_with_a_digit_is_not_a_name(): void
    {
        self::assertNull($this->parser->parse('Sekretarz stanu Jan K0walski')['nazwisko']);
    }

    /**
     * Nazwisko bierzemy z KONCA, wiec to samo slowo wystepujace wczesniej
     * zostaje w funkcji. Przy wycinaniu tekstu z tekstu znikaloby oba razy.
     */
    public function test_word_repeated_earlier_stays_in_the_function(): void
    {
        $r = $this->parser->parse('Pełnomocnik Kowalski do spraw wsi Jan Kowalski');

        self::assertSame('Jan Kowalski', $r['nazwisko']);
        self::assertSame('pełnomocnik kowalski do spraw wsi', $r['funkcja']);
    }

    /**
     * Po myslniku moze stac imie, drugie imie i nazwisko, ale nie cztery slowa -
     * wtedy to juz nie nazwisko, tylko nierozpoznana funkcja, i wracamy do
     * przegladania tokenow.
     */
    public function test_too_many_words_after_the_dash_fall_back_to_the_token_scan(): void
    {
        $r = $this->parser->parse('minister - do spraw rolnictwa Jan Kowalski');

        self::assertSame('Jan Kowalski', $r['nazwisko']);
    }

    public function test_punctuation_around_a_token_does_not_break_the_name(): void
    {
        self::assertSame('Jan Kowalski', $this->parser->parse('Sekretarz stanu, Jan Kowalski.')['nazwisko']);
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

    /**
     * Slowo urzedowe stojace NA KONCU: gdyby porownanie z lista nie sprowadzalo
     * polskich liter do malych, "Pelnomocnik" trafilby do nazwiska.
     */
    public function test_office_word_after_the_name_is_not_part_of_it(): void
    {
        $r = $this->parser->parse('Jan Kowalski Pełnomocnik');

        self::assertSame('Jan Kowalski', $r['nazwisko']);
        self::assertSame('pełnomocnik', $r['funkcja']);
    }

    public function test_function_is_lowercased_including_polish_letters(): void
    {
        self::assertSame('minister finansów', $this->parser->parse('MINISTER FINANSÓW Jan Kowalski')['funkcja']);
    }

    /**
     * Dwuliterowe nazwisko jest nazwiskiem. Przy progu "dwa znaki to za malo"
     * caly podpis wypadal jako nierozpoznany.
     */
    public function test_two_letter_surname_is_a_name(): void
    {
        self::assertSame('Ola Li', $this->parser->parse('Sekretarz stanu Ola Li')['nazwisko']);
    }

    /**
     * Dlugosc liczona w znakach, nie bajtach: "Ż" to jeden znak i dwa bajty,
     * wiec przy liczeniu bajtow przechodzilo jako czlon nazwiska.
     */
    public function test_single_multibyte_letter_is_not_a_name_token(): void
    {
        self::assertNull($this->parser->parse('Jan Ż Kowalski')['nazwisko']);
    }

    public function test_token_starting_with_a_digit_is_not_a_name(): void
    {
        self::assertNull($this->parser->parse('Sekretarz stanu 2Kowalski Nowak')['nazwisko']);
    }

    /**
     * Po myslniku moga stac trzy czlony (imie, drugie imie, nazwisko), ale nie
     * cztery - wtedy wracamy do przegladania tokenow i bierzemy dwa ostatnie.
     */
    public function test_three_words_after_the_dash_are_a_name(): void
    {
        self::assertSame('Anna Maria Kowalska', $this->parser->parse('minister - Anna Maria Kowalska')['nazwisko']);
    }

    public function test_four_words_after_the_dash_are_not(): void
    {
        self::assertSame('Nowak Kowalska', $this->parser->parse('minister - Anna Maria Nowak Kowalska')['nazwisko']);
    }

    public function test_lowercase_words_after_the_dash_are_not_a_name(): void
    {
        self::assertNull($this->parser->parse('minister - do spraw')['nazwisko']);
    }

    /**
     * Ta sama para slow wystepujaca dwa razy: nazwisko bierzemy z konca, wiec
     * pierwsze wystapienie zostaje w funkcji.
     */
    public function test_name_repeated_twice_is_taken_from_the_end(): void
    {
        $r = $this->parser->parse('Jan Kowalski do spraw wsi Jan Kowalski');

        self::assertSame('Jan Kowalski', $r['nazwisko']);
        self::assertSame('jan kowalski do spraw wsi', $r['funkcja']);
    }

    public function test_key_has_no_trailing_separator(): void
    {
        self::assertSame('jan-kowalski', $this->parser->key('Jan Kowalski!'));
    }

    public function test_key_folds_case_and_diacritics_but_not_different_people(): void
    {
        self::assertSame('jan-zolna', $this->parser->key('Jan Żółna'));
        self::assertNotSame($this->parser->key('Jan Kowalski'), $this->parser->key('Anna Kowalski'));
    }

    /**
     * Kazdy polski znak diakrytyczny z osobna: pominiecie jednego sklejaloby
     * dwie rozne osoby albo rozdzielalo jedna na dwa profile.
     */
    public function test_key_folds_every_polish_diacritic(): void
    {
        self::assertSame('acelnoszz', $this->parser->key('ąćęłńóśźż'));
        self::assertSame('acelnoszz', $this->parser->key('ĄĆĘŁŃÓŚŹŻ'));
    }
}
