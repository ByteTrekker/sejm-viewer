<?php

declare(strict_types=1);

namespace Milczenie\Tests\Unit;

use Milczenie\Domain\RecipientNormalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RecipientNormalizer::class)]
final class RecipientNormalizerTest extends TestCase
{
    public function test_case_and_whitespace_do_not_create_separate_addressees(): void
    {
        $n = new RecipientNormalizer();

        self::assertSame(
            $n->normalize('minister zdrowia'),
            $n->normalize('  Minister   Zdrowia  '),
        );
    }

    public function test_renamed_ministry_folds_into_its_current_name(): void
    {
        $n = new RecipientNormalizer();

        self::assertSame(
            $n->normalize('minister finansów i gospodarki'),
            $n->normalize('minister finansów'),
        );
    }

    public function test_renamed_ministry_is_displayed_under_the_current_name(): void
    {
        $n = new RecipientNormalizer();

        // Kolejnosc ma znaczenie: nazwa historyczna pojawia sie pierwsza w danych,
        // ale etykieta ma pochodzic z nazwy biezacej, inaczej resort wyswietla sie wstecz.
        $key = $n->normalize('minister finansów');
        $n->normalize('minister finansów i gospodarki');

        self::assertSame('Minister finansów i gospodarki', $n->displayName($key));
    }

    public function test_unrelated_ministries_stay_separate(): void
    {
        $n = new RecipientNormalizer();

        self::assertNotSame(
            $n->normalize('minister zdrowia'),
            $n->normalize('minister sprawiedliwości'),
        );
    }

    public function test_display_name_starts_with_a_capital_letter(): void
    {
        $n = new RecipientNormalizer();
        $key = $n->normalize('minister obrony narodowej');

        self::assertSame('Minister obrony narodowej', $n->displayName($key));
    }

    public function test_unknown_key_falls_back_to_the_key_itself(): void
    {
        self::assertSame('cokolwiek', (new RecipientNormalizer())->displayName('cokolwiek'));
    }
}
