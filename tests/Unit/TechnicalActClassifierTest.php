<?php

declare(strict_types=1);

namespace Milczenie\Tests\Unit;

use Milczenie\Domain\TechnicalActClassifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(TechnicalActClassifier::class)]
final class TechnicalActClassifierTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function technicalActs(): iterable
    {
        yield 'obszar Natura 2000' => [
            'Rozporządzenie Ministra Klimatu i Środowiska w sprawie specjalnego obszaru ochrony siedlisk Dolina Wisły',
            'obszary ochrony przyrody i pomniki historii',
        ];
        yield 'pomnik historii' => [
            'Rozporządzenie Prezydenta Rzeczypospolitej Polskiej w sprawie uznania za pomnik historii Zamku w Malborku',
            'obszary ochrony przyrody i pomniki historii',
        ];
        yield 'pełnomocnik rządu' => [
            'Rozporządzenie Rady Ministrów w sprawie ustanowienia Pełnomocnika Rządu do Spraw Cyberbezpieczeństwa',
            'organizacja rządu i urzędów',
        ];
        yield 'wybory przedterminowe' => [
            'Rozporządzenie Prezesa Rady Ministrów w sprawie przedterminowych wyborów wójta gminy Sękowa',
            'wybory przedterminowe i uzupełniające',
        ];
        yield 'ratyfikacja umowy' => [
            'Ustawa z dnia 5 grudnia 2024 r. o ratyfikacji Umowy między Rzecząpospolitą Polską a Republiką Czeską',
            'zgoda na ratyfikację umowy międzynarodowej',
        ];
    }

    #[DataProvider('technicalActs')]
    public function test_administrative_acts_are_recognised_with_their_category(string $title, string $category): void
    {
        self::assertSame($category, (new TechnicalActClassifier())->categorize($title));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function substantiveActs(): iterable
    {
        // Te trzy przypadki decyduja o wiarygodnosci filtra: gdyby ktorykolwiek
        // wypadl ze zbioru, ranking dalby sie dowolnie nagiac.
        yield 'nowelizacja' => ['Rozporządzenie Ministra Zdrowia zmieniające rozporządzenie w sprawie świadczeń gwarantowanych'];
        yield 'stawki' => ['Rozporządzenie Ministra Finansów w sprawie stawek podatku akcyzowego'];
        yield 'ograniczenia epidemiczne' => ['Rozporządzenie Rady Ministrów w sprawie ustanowienia określonych ograniczeń, nakazów i zakazów w związku z wystąpieniem stanu epidemii'];
        yield 'wzór wniosku' => ['Rozporządzenie Ministra Rodziny w sprawie wzoru wniosku o świadczenie wychowawcze'];
        yield 'tryb sprostowania jest aktem merytorycznym' => ['Rozporządzenie Ministra Pracy i Polityki Społecznej w sprawie trybu i sposobu sprostowania świadectwa pracy'];
    }

    #[DataProvider('substantiveActs')]
    public function test_substantive_acts_are_never_filtered_out(string $title): void
    {
        $classifier = new TechnicalActClassifier();

        self::assertNull($classifier->categorize($title));
        self::assertFalse($classifier->isTechnical($title));
    }

    public function test_is_technical_agrees_with_categorize(): void
    {
        $classifier = new TechnicalActClassifier();

        self::assertTrue($classifier->isTechnical('Rozporządzenie w sprawie nadania osobowości prawnej Parafii Świętego Jana'));
    }
}
