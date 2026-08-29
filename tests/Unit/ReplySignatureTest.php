<?php

declare(strict_types=1);

namespace Milczenie\Tests\Unit;

use Milczenie\Domain\ReplySignature;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReplySignature::class)]
final class ReplySignatureTest extends TestCase
{
    /**
     * @return iterable<string, array{?string, ReplySignature}>
     */
    public static function authors(): iterable
    {
        yield 'minister' => ['Minister Krzysztof Hetman', ReplySignature::Minister];
        yield 'prezes' => ['Prezes Rady Ministrów Donald Tusk', ReplySignature::Minister];
        yield 'wiceprezes' => ['Wiceprezes Rady Ministrów', ReplySignature::Minister];
        yield 'szef' => ['Szef Kancelarii Prezesa Rady Ministrów', ReplySignature::Minister];
        yield 'sekretarz stanu' => ['Sekretarz stanu Arkadiusz Myrcha', ReplySignature::SecretaryOfState];
        yield 'podsekretarz stanu' => ['Podsekretarz stanu Mikołaj Dorożała', ReplySignature::UndersecretaryOfState];
        yield 'podsekretarz małą literą' => ['podsekretarz stanu w Ministerstwie Zdrowia', ReplySignature::UndersecretaryOfState];
        yield 'ktoś inny' => ['Główny Inspektor Sanitarny', ReplySignature::Other];
        yield 'brak' => [null, ReplySignature::Unknown];
        yield 'pusty' => ['   ', ReplySignature::Unknown];
    }

    #[DataProvider('authors')]
    public function test_signature_is_read_from_the_title_in_front_of_the_name(?string $author, ReplySignature $expected): void
    {
        self::assertSame($expected, ReplySignature::fromAuthor($author));
    }

    /**
     * @return iterable<string, array{string, ReplySignature}>
     */
    public static function upperCaseAuthors(): iterable
    {
        // Pilnuje flagi `i`: bez niej wersaliki z pism urzedowych trafialyby do "inne".
        yield 'MINISTER' => ['MINISTER ZDROWIA', ReplySignature::Minister];
        yield 'SEKRETARZ' => ['SEKRETARZ STANU Jan Kowalski', ReplySignature::SecretaryOfState];
        yield 'PODSEKRETARZ' => ['PODSEKRETARZ STANU Anna Nowak', ReplySignature::UndersecretaryOfState];
    }

    #[DataProvider('upperCaseAuthors')]
    public function test_title_is_recognised_regardless_of_case(string $author, ReplySignature $expected): void
    {
        self::assertSame($expected, ReplySignature::fromAuthor($author));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function titlesNotAtTheStart(): iterable
    {
        // Pilnuje kotwicy `^`: tytul liczy sie tylko wtedy, gdy podpisuje pismo,
        // a nie gdy pada gdziekolwiek w formule upowaznienia.
        yield 'upoważnienie sekretarza' => ['Z upoważnienia Ministra — Sekretarz stanu Jan Kowalski'];
        yield 'upoważnienie podsekretarza' => ['Z upoważnienia Prezesa Rady Ministrów podsekretarz stanu Anna Nowak'];
        yield 'dyrektor' => ['Dyrektor generalny, w zastępstwie minister Jan Kowalski'];
    }

    #[DataProvider('titlesNotAtTheStart')]
    public function test_a_title_buried_in_the_formula_does_not_count(string $author): void
    {
        self::assertSame(ReplySignature::Other, ReplySignature::fromAuthor($author));
    }

    public function test_undersecretary_is_never_mistaken_for_a_secretary(): void
    {
        // "Podsekretarz stanu" zawiera w sobie "sekretarz stanu" - odwrocenie
        // kolejnosci sprawdzania zaklasyfikowaloby wszystkich podsekretarzy o szczebel wyzej.
        self::assertSame(
            ReplySignature::UndersecretaryOfState,
            ReplySignature::fromAuthor('Podsekretarz stanu Jan Kowalski'),
        );
    }

    public function test_every_case_has_a_human_readable_label(): void
    {
        foreach (ReplySignature::cases() as $case) {
            self::assertNotSame('', $case->label());
        }
    }
}
