<?php

declare(strict_types=1);

namespace Milczenie\Tests\Unit;

use Milczenie\Domain\IssuerNormalizer;
use PHPUnit\Framework\Attributes\CoversClass;
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

    public function test_known_bodies_get_their_proper_names_not_lowercased_slugs(): void
    {
        $n = new IssuerNormalizer();

        self::assertSame('Prezes Rady Ministrów', $n->displayName($n->normalize('PREZ. RADY MINISTRÓW')));
        self::assertSame('Rada Ministrów', $n->displayName($n->normalize('RADA MINISTRÓW')));
        self::assertSame('Prezydent RP', $n->displayName($n->normalize('PREZYDENT RZECZYPOSPOLITEJ POLSKIEJ')));
        self::assertSame('Sejm RP', $n->displayName($n->normalize('SEJM')));
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

    public function test_missing_issuer_is_labelled_rather_than_shown_as_a_slug(): void
    {
        $n = new IssuerNormalizer();

        self::assertSame('Organ nieokreślony w API', $n->displayName($n->normalize('nieznany')));
    }
}
