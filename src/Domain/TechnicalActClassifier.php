<?php

declare(strict_types=1);

namespace Milczenie\Domain;

/**
 * Rozpoznaje rozporzadzenia, ktore nie naklada ja obowiazkow na obywateli ani firmy -
 * a wiec takie, dla ktorych krotkie vacatio legis nikogo nie zaskakuje.
 *
 * Reguly sa celowo waskie i jawne. Ryzyko takiego filtra jest oczywiste: zbyt szeroki
 * usuwa niewygodne przypadki i poprawia wynik rzadu. Dlatego:
 *  - NIE wykluczamy rozporzadzen zmieniajacych (45% zbioru) - nowelizacja bywa bardzo
 *    merytoryczna i wykluczenie jej wypatroszyloby analize,
 *  - NIE wykluczamy aktow "technicznych z nazwy", jesli dotykaja praw i obowiazkow
 *    (stawki, wzory wnioskow, ograniczenia epidemiczne),
 *  - kazdy wykluczony akt ma przypisana kategorie, a strona pokazuje ich liczby,
 *    zeby czytelnik mogl filtr zakwestionowac.
 */
final class TechnicalActClassifier
{
    /**
     * Kategoria => wyrazenie regularne na tytule aktu.
     *
     * @var array<string, string>
     */
    private const RULES = [
        'obszary ochrony przyrody i pomniki historii'
            => '/specjalnego obszaru ochrony|obszaru specjalnej ochrony|rezerwatu przyrody|uznania za pomnik/iu',
        'organizacja rządu i urzędów'
            => '/szczegółowego zakresu działania (ministra|sekretarza|podsekretarza)|(ustanowienia|zniesienia) [Pp]ełnomocnika|nadania statutu|utworzenia (ministerstwa|urzędu)|przekształcenia ministerstwa/iu',
        'wybory przedterminowe i uzupełniające'
            => '/przedterminowych wyborów|wyborów przedterminowych|wyborów uzupełniających|ponownych wyborów|referendum gminnym/iu',
        'osobowość prawna jednostek kościelnych'
            => '/nadania osobowości prawnej/iu',
        'odznaczenia, ordery i odznaki'
            => '/\b(odznak|odznaki|odznaką|orderów|orderu|medalu|medali)\b/iu',
        'nazwy i granice jednostek terytorialnych'
            => '/ustalenia granic|nadania (nazwy|statusu) miast|zmiany (nazwy|granic) (gmin|miast)|ustalenia,? zmiany i znoszenia urzędowych nazw/iu',
        'sprostowania i uchylenia'
            => '/uchylające rozporządzenie|w sprawie sprostowania/iu',
        // Dotyczy wylacznie ustaw: zgoda na ratyfikacje to akt formalny,
        // ktory nie tworzy obowiazkow bezposrednio stosowalnych.
        'zgoda na ratyfikację umowy międzynarodowej'
            => '/^Ustawa .* o ratyfikacji|o wypowiedzeniu (Porozumienia|Umowy|Konwencji)/iu',
    ];

    public function categorize(string $title): ?string
    {
        foreach (self::RULES as $category => $pattern) {
            if (preg_match($pattern, $title) === 1) {
                return $category;
            }
        }

        return null;
    }

    public function isTechnical(string $title): bool
    {
        return $this->categorize($title) !== null;
    }
}
