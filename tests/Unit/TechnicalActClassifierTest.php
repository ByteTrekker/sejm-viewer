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
        yield 'wypowiedzenie porozumienia' => [
            'Ustawa z dnia 5 grudnia 2024 r. o wypowiedzeniu Porozumienia o zdolności prawnej',
            'zgoda na ratyfikację umowy międzynarodowej',
        ];
        yield 'osobowość prawna parafii' => [
            'Rozporządzenie Ministra Administracji i Cyfryzacji w sprawie nadania osobowości prawnej Parafii Świętego Jana',
            'osobowość prawna jednostek kościelnych',
        ];
        yield 'odznaka' => [
            'Rozporządzenie Ministra Zdrowia w sprawie odznaki "Dawca Przeszczepu"',
            'odznaczenia, ordery i odznaki',
        ];
        yield 'granice miast' => [
            'Rozporządzenie Rady Ministrów w sprawie ustalenia granic niektórych gmin i miast',
            'nazwy i granice jednostek terytorialnych',
        ];
        yield 'uchylenie' => [
            'Rozporządzenie Rady Ministrów uchylające rozporządzenie w sprawie utworzenia gminy',
            'sprostowania i uchylenia',
        ];
        yield 'sprostowanie' => [
            'Obwieszczenie Prezesa Rady Ministrów w sprawie sprostowania błędu',
            'sprostowania i uchylenia',
        ];
        yield 'szczegółowy zakres działania ministra' => [
            'Rozporządzenie Prezesa Rady Ministrów w sprawie szczegółowego zakresu działania Ministra Cyfryzacji',
            'organizacja rządu i urzędów',
        ];
        yield 'nadanie statutu' => [
            'Rozporządzenie Prezesa Rady Ministrów w sprawie nadania statutu Urzędowi Ochrony Konkurencji i Konsumentów',
            'organizacja rządu i urzędów',
        ];
        yield 'rezerwat przyrody' => [
            'Rozporządzenie Ministra Klimatu i Środowiska w sprawie rezerwatu przyrody Las Bielański',
            'obszary ochrony przyrody i pomniki historii',
        ];
        yield 'wybory uzupełniające' => [
            'Rozporządzenie Prezesa Rady Ministrów w sprawie wyborów uzupełniających do Senatu',
            'wybory przedterminowe i uzupełniające',
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
        yield 'ustawa merytoryczna, nie ratyfikacyjna' => ['Ustawa z dnia 5 grudnia 2024 r. o zmianie ustawy o podatku dochodowym od osób fizycznych'];
        yield 'zakres działania nie-ministra' => ['Rozporządzenie Ministra Zdrowia w sprawie szczegółowego zakresu danych przekazywanych do rejestru'];
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

    public function test_first_matching_rule_wins_so_a_category_is_never_ambiguous(): void
    {
        // Akt pasujacy do dwoch regul ma dostac te wczesniejsza, a nie losowa -
        // inaczej liczniki w sekcji "co odsialismy" nie sumowalyby sie do calosci.
        $title = 'Rozporządzenie Prezydenta w sprawie uznania za pomnik historii oraz nadania statutu muzeum';

        self::assertSame(
            'obszary ochrony przyrody i pomniki historii',
            (new TechnicalActClassifier())->categorize($title),
        );
    }
}
