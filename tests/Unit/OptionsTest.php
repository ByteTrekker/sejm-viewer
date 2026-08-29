<?php

declare(strict_types=1);

namespace Milczenie\Tests\Unit;

use Milczenie\Console\Options;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Options::class)]
final class OptionsTest extends TestCase
{
    public function test_missing_option_falls_back_to_the_default(): void
    {
        $o = new Options([]);

        self::assertSame('public', $o->string('out', 'public'));
        self::assertSame(2015, $o->int('from', 2015));
        self::assertNull($o->nullableString('snapshot'));
        self::assertSame([10], $o->commaListOfInt('term', [10]));
    }

    public function test_repeated_flag_takes_the_last_occurrence(): void
    {
        // getopt zwraca dla powtorzonej flagi liste; rzutowanie jej na string
        // dawalo doslownie "Array" i cichy blad w parametrach raportu.
        $o = new Options(['term' => ['9', '10']]);

        self::assertSame('10', $o->nullableString('term'));
        self::assertSame(10, $o->int('term', 7));
    }

    public function test_valueless_flag_is_detected_although_getopt_returns_false(): void
    {
        $o = new Options(['skip-mp' => false]);

        self::assertTrue($o->has('skip-mp'));
        self::assertFalse($o->has('exclude-technical'));
        // Sama obecnosc flagi nie jest wartoscia tekstowa.
        self::assertNull($o->nullableString('skip-mp'));
    }

    public function test_comma_list_is_split_and_trimmed(): void
    {
        $o = new Options(['kind' => ' interpelacja , zapytanie ']);

        self::assertSame(['interpelacja', 'zapytanie'], $o->commaList('kind', []));
    }

    public function test_comma_list_drops_empty_items_and_reindexes(): void
    {
        // assertSame porownuje takze klucze: bez array_values lista mialaby
        // dziury po odfiltrowanych elementach i rozjechalaby sie przy iteracji.
        $o = new Options(['term' => '7,,9']);

        self::assertSame(['7', '9'], $o->commaList('term', []));
        self::assertSame([7, 9], $o->commaListOfInt('term', []));
    }

    public function test_single_item_list_stays_a_one_element_list(): void
    {
        $o = new Options(['term' => '10']);

        self::assertSame(['10'], $o->commaList('term', []));
        self::assertSame([10], $o->commaListOfInt('term', []));
    }

    public function test_comma_list_falls_back_when_the_option_is_absent(): void
    {
        $o = new Options([]);

        self::assertSame(['interpelacja'], $o->commaList('kind', ['interpelacja']));
    }

    public function test_int_parses_a_numeric_string(): void
    {
        $o = new Options(['from' => '2015']);

        self::assertSame(2015, $o->int('from', 1999));
    }

    public function test_comma_list_of_only_separators_falls_back_to_the_default(): void
    {
        $o = new Options(['term' => ',,']);

        self::assertSame([7, 8], $o->commaListOfInt('term', [7, 8]));
    }

    public function test_empty_string_is_treated_as_absent(): void
    {
        $o = new Options(['db' => '']);

        self::assertSame('var/sejm.sqlite', $o->string('db', 'var/sejm.sqlite'));
    }

    public function test_empty_repeated_flag_falls_back_to_the_default(): void
    {
        $o = new Options(['term' => []]);

        self::assertSame(10, $o->int('term', 10));
    }
}
