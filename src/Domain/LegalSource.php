<?php

declare(strict_types=1);

namespace Milczenie\Domain;

/**
 * Podstawy prawne, na ktorych stoja rankingi - razem z odnosnikami do tekstu.
 *
 * Projekt powolywal sie na artykuly, nie dajac czytelnikowi zadnego sposobu, zeby je
 * sprawdzic. To jest ta sama zasada co przy pytaniach i aktach (N3): liczba prowadzi
 * do zrodla, a skoro prog ustawowy jest najwazniejsza liczba w calym projekcie, to on
 * tym bardziej.
 *
 * Linkujemy WYLACZNIE przez API ELI. ISAP bylby czytelniejszy dla czlowieka, ale stoi
 * za ochrona antybotowa i nie dalo sie sprawdzic, czy identyfikatory starszych aktow
 * (z numerem zeszytu) buduja sie tak samo jak nowych. Link niesprawdzony jest gorszy
 * niz link mniej wygodny.
 */
final class LegalSource
{
    /**
     * @var array<string, array{eli: string, tytul: string, adres: string}>
     */
    private const ACTS = [
        'regulamin' => [
            'eli' => 'MP/1992/185',
            'tytul' => 'Regulamin Sejmu Rzeczypospolitej Polskiej',
            'adres' => 'M.P. 1992 nr 26 poz. 185',
        ],
        'ogloszenia' => [
            'eli' => 'DU/2000/718',
            'tytul' => 'Ustawa o ogłaszaniu aktów normatywnych i niektórych innych aktów prawnych',
            'adres' => 'Dz.U. 2000 nr 62 poz. 718',
        ],
    ];

    /**
     * Konkretne przepisy, ktore projekt cytuje.
     *
     * @var array<string, array{akt: string, przepis: string, o_czym: string}>
     */
    private const CITATIONS = [
        'termin_interpelacje' => [
            'akt' => 'regulamin',
            'przepis' => 'art. 192 ust. 1',
            'o_czym' => '21 dni na odpowiedź na interpelację',
        ],
        'termin_zapytania' => [
            'akt' => 'regulamin',
            'przepis' => 'art. 195 ust. 1',
            'o_czym' => '21 dni na odpowiedź na zapytanie poselskie',
        ],
        'vacatio_standard' => [
            'akt' => 'ogloszenia',
            'przepis' => 'art. 4 ust. 1',
            'o_czym' => '14 dni między ogłoszeniem a wejściem w życie',
        ],
        'vacatio_wyjatek' => [
            'akt' => 'ogloszenia',
            'przepis' => 'art. 4 ust. 2',
            'o_czym' => 'skrócenie terminu w uzasadnionych przypadkach',
        ],
        'moc_wsteczna' => [
            'akt' => 'ogloszenia',
            'przepis' => 'art. 5',
            'o_czym' => 'wejście w życie z mocą wsteczną',
        ],
    ];

    /**
     * @return array<string, array{przepis: string, akt: string, adres: string, o_czym: string, url: string, url_pdf: string}>
     */
    public static function all(): array
    {
        $out = [];

        foreach (self::CITATIONS as $key => $citation) {
            $act = self::ACTS[$citation['akt']];

            $out[$key] = [
                'przepis' => $citation['przepis'],
                'akt' => $act['tytul'],
                'adres' => $act['adres'],
                'o_czym' => $citation['o_czym'],
                'url' => sprintf('https://api.sejm.gov.pl/eli/acts/%s/text.html', $act['eli']),
                'url_pdf' => sprintf('https://api.sejm.gov.pl/eli/acts/%s/text.pdf', $act['eli']),
            ];
        }

        return $out;
    }
}
