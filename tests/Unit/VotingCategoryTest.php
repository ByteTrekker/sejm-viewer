<?php

declare(strict_types=1);

namespace Milczenie\Tests\Unit;

use Milczenie\Domain\VotingCategory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(VotingCategory::class)]
final class VotingCategoryTest extends TestCase
{
    /**
     * @return iterable<string, array{string, VotingCategory}>
     */
    public static function titles(): iterable
    {
        yield 'wniosek formalny' => ['Wniosek formalny o przerwę w obradach', VotingCategory::FormalMotion];
        yield 'reasumpcja' => ['Wniosek o reasumpcję głosowania nr 12', VotingCategory::FormalMotion];
        yield 'wybór marszałka' => ['Wybór Marszałka Sejmu RP', VotingCategory::Appointment];
        yield 'kandydatura' => ['Głosowanie nad kandydaturą Posła Krzysztofa Bosaka', VotingCategory::Appointment];
        yield 'immunitet' => ['Wniosek o wyrażenie zgody na pociągnięcie do odpowiedzialności', VotingCategory::Appointment];
        yield 'wotum nieufności' => ['Wniosek o wotum nieufności wobec Ministra Zdrowia', VotingCategory::ConfidenceVote];
        yield 'uchwała Senatu' => ['Pkt. 16 Sprawozdanie Komisji o uchwale Senatu w sprawie ustawy o VAT', VotingCategory::SenateAmendment];
        yield 'ratyfikacja' => ['Sprawozdanie Komisji o rządowym projekcie ustawy o ratyfikacji Umowy', VotingCategory::Ratification];
        yield 'budżet' => ['Pkt. 1 Sprawozdanie Komisji o rządowym projekcie ustawy budżetowej na rok 2025', VotingCategory::Budget];
        yield 'projekt rządowy' => ['Sprawozdanie Komisji o rządowym projekcie ustawy o zmianie ustawy o VAT', VotingCategory::GovernmentBill];
        yield 'projekt poselski' => ['Sprawozdanie Komisji o poselskim projekcie ustawy o zmianie Kodeksu pracy', VotingCategory::OtherBill];
        yield 'projekt obywatelski' => ['Sprawozdanie Komisji o obywatelskim projekcie ustawy', VotingCategory::OtherBill];
        yield 'nierozpoznane' => ['Pkt. 9 Sprawozdanie Komisji o sprawozdaniu z działalności NBP', VotingCategory::Other];
    }

    #[DataProvider('titles')]
    public function test_category_is_read_from_the_title(string $title, VotingCategory $expected): void
    {
        self::assertSame($expected, VotingCategory::fromTitle($title));
    }

    public function test_earlier_rule_wins_when_two_match(): void
    {
        // Poprawki Senatu do ustawy budzetowej to etap senacki, nie "budzet" -
        // odwrocenie kolejnosci regul przesunieloby setki glosowan miedzy koszykami.
        self::assertSame(
            VotingCategory::SenateAmendment,
            VotingCategory::fromTitle('Sprawozdanie Komisji o uchwale Senatu w sprawie ustawy budżetowej'),
        );
    }

    public function test_ratification_of_a_government_bill_is_a_ratification(): void
    {
        self::assertSame(
            VotingCategory::Ratification,
            VotingCategory::fromTitle('Sprawozdanie Komisji o rządowym projekcie ustawy o ratyfikacji Konwencji'),
        );
    }

    public function test_topic_is_searched_alongside_the_title(): void
    {
        self::assertSame(
            VotingCategory::Appointment,
            VotingCategory::fromTitle('Pkt. 2 Głosowanie', 'Wybór Wicemarszałka Sejmu RP'),
        );
    }

    public function test_missing_text_falls_into_the_residual_bucket(): void
    {
        self::assertSame(VotingCategory::Other, VotingCategory::fromTitle(null, null));
        self::assertSame(VotingCategory::Other, VotingCategory::fromTitle('   ', ''));
    }
}
