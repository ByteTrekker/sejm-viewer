<?php

declare(strict_types=1);

namespace Milczenie\Domain;

/**
 * Rozklada podpis pod odpowiedzia na FUNKCJE i NAZWISKO.
 *
 * API podaje autora odpowiedzi jednym polem tekstowym, wypelnianym przez ludzi
 * w kancelariach resortow, wiec jeden czlowiek wystepuje jako "Sekretarz stanu
 * Jan Kowalski", "sekretarz stanu w Ministerstwie X - Jan Kowalski" i "Z
 * upowaznienia MINISTRA X Jan Kowalski SEKRETARZ STANU". Bez rozlozenia tego na
 * czesci nie da sie ani policzyc, ile razy ktos podpisal, ani zbudowac profilu.
 *
 * Regula jest celowo waska, a to, czego nie rozpoznaje, jest raportowane, nie
 * zgadywane: podpis bez nazwiska ("Minister sprawiedliwosci") zwraca null i
 * strona podaje takie przypadki liczba, zamiast wpisywac je do rankingu.
 */
final class ReplySignatory
{
    /**
     * Slowa, ktore nigdy nie sa czescia nazwiska.
     *
     * Wystarczaja rdzenie: porownujemy po sprowadzeniu do malych liter, wiec
     * "PODSEKRETARZ" i "Podsekretarz" trafiaja w ten sam wpis.
     */
    /** Myslnik jako osobny token oddziela funkcje od nazwiska. */
    private const DASH = '-';

    private const OFFICE_WORDS = [
        'minister', 'ministra', 'ministrowie', 'ministerstwie', 'ministerstwa',
        'podsekretarz', 'sekretarz', 'stanu', 'stan',
        'prezes', 'prezesa', 'wiceprezes', 'szef', 'szefa', 'kierownik',
        'pelnomocnik', 'pełnomocnik', 'zastepca', 'zastępca', 'glowny', 'główny',
        'inspektor', 'komendant', 'dyrektor', 'generalny', 'generalna',
        'upowaznienia', 'upoważnienia', 'rady', 'ministrow', 'ministrów',
        'kancelarii', 'urzedu', 'urzędu', 'panstwa', 'państwa', 'rzeczypospolitej',
        'polskiej', 'pani', 'pan', 'koordynator', 'czlonek', 'członek',
    ];

    /**
     * @return array{funkcja: string|null, nazwisko: string|null, klucz: string|null}
     */
    public function parse(string $author): array
    {
        $tokens = $this->tokenize($author);

        // Myslnik rozdziela funkcje od nazwiska, ale bywa go wiecej niz jeden:
        // "minister - czlonek Rady Ministrow - Maciej Berek". Liczy sie OSTATNI,
        // bo nazwisko stoi na koncu. Tniemy po tokenach, nie po znakach: przy
        // liczeniu przesuniec w tekscie wynik nie zmienial sie od przesuniecia
        // o jeden, bo i tak przycinalismy spacje - kod udawal precyzje, ktorej
        // nie mial.
        $dash = array_keys($tokens, self::DASH, true);

        if ($dash !== []) {
            $last = $dash[count($dash) - 1];
            $name = array_slice($tokens, $last + 1);
            $role = array_slice($tokens, 0, $last);

            if ($this->looksLikeName($name)) {
                return $this->result($role, $name);
            }
        }

        $found = $this->trailingName($tokens);

        if ($found === null) {
            return self::nothing();
        }

        // Funkcja to wszystko poza nazwiskiem - wycinamy je po POZYCJI, ktora
        // zwraca wyszukiwanie, a nie szukajac tekstu w tekscie: to samo slowo
        // wystepujace wczesniej (np. w "Kancelarii Prezesa") tez by wtedy znikalo.
        [$at, $name] = $found;
        $role = array_merge(array_slice($tokens, 0, $at), array_slice($tokens, $at + count($name)));

        return $this->result($role, $name);
    }

    /**
     * Dzieli podpis na tokeny, po drodze prostujac to, co wpisano recznie.
     *
     * Myslnik jako osobny token, bo to on rozdziela funkcje od nazwiska; pauza
     * i polkreslnik znacza to samo co myslnik, a bez ich ujednolicenia "Henryka
     * Moscicka-Dendys" z pauza rozpadala sie na dwa tokeny. Sklejone wyrazy
     * ("stanuTomasz") tniemy na granicy mala-wielka litera: polskie nazwisko nie
     * ma wielkiej litery w srodku wyrazu poza mysnikiem, ktorego nie ruszamy.
     *
     * @return list<string>
     */
    private function tokenize(string $author): array
    {
        $normalized = strtr($author, ['–' => '-', '—' => '-', '‑' => '-', '−' => '-']);
        $normalized = preg_replace('/(\p{Ll})(\p{Lu})/u', '$1 $2', $normalized) ?? $normalized;

        // PREG_SPLIT_NO_EMPTY daje juz liste bez dziur, wiec nie ma czego indeksowac
        // od nowa ani odsiewac - kazde dodatkowe opakowanie bylo tu ozdoba.
        $tokens = preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY);

        if ($tokens === false) {
            return [];
        }

        // Interpunkcja odpada juz tutaj, a nie dopiero przy sprawdzaniu tokena:
        // inaczej "Jan Kowalski." wchodzil do wyniku z kropka i dawal inny klucz
        // niz "Jan Kowalski", czyli dwa profile jednej osoby.
        $clean = [];
        foreach ($tokens as $token) {
            $token = trim($token, '.,;:()');

            if ($token !== '') {
                $clean[] = $token;
            }
        }

        return $clean;
    }

    /**
     * Nazwisko to OSTATNI ciag dwoch lub trzech slow, ktore wygladaja jak imie
     * i nazwisko. "Ostatni", bo w formie "Z upowaznienia MINISTRA X Jan Kowalski
     * SEKRETARZ STANU" funkcja stoi po obu stronach nazwiska.
     *
     * @param list<string> $tokens
     *
     * @return array{int, list<string>}|null pozycja poczatku nazwiska i jego czlony
     */
    private function trailingName(array $tokens): array|null
    {
        $runs = [];
        $current = [];
        $start = 0;

        foreach ($tokens as $i => $token) {
            if ($this->isNameToken($token)) {
                if ($current === []) {
                    $start = $i;
                }

                $current[] = $token;
                continue;
            }

            if (count($current) >= 2) {
                $runs[] = [$start, $current];
            }

            $current = [];
        }

        if (count($current) >= 2) {
            $runs[] = [$start, $current];
        }

        if ($runs === []) {
            return null;
        }

        [$at, $last] = $runs[count($runs) - 1];

        // Dwa ostatnie czlony, nie trzy. Nierozpoznane slowo urzedowe w dopelniaczu
        // ("prezes Urzedu Ochrony Konkurencji i Konsumentow Tomasz Chrostny") jest
        // pisane wielka litera i wchodzi do ciagu; przy trzech czlonach powstawal
        // z tego nieistniejacy "Konsumentow Tomasz Chrostny". Zgubienie drugiego
        // imienia jest kosmetyczne, wymyslenie osoby - nie.
        $name = array_slice($last, -2);

        return [$at + count($last) - count($name), $name];
    }

    private function isNameToken(string $bare): bool
    {
        if (mb_strlen($bare) < 2) {
            return false;
        }

        if (in_array(mb_strtolower($bare), self::OFFICE_WORDS, true)) {
            return false;
        }

        // Wielka litera na poczatku, ale nie CALY wyraz wielkimi: kancelarie
        // pisza tak funkcje ("SEKRETARZ STANU"), nigdy nazwisk.
        return preg_match('/^\p{Lu}[\p{L}\'-]*$/u', $bare) === 1
            && mb_strtoupper($bare) !== $bare;
    }

    /**
     * @param list<string> $tokens
     */
    private function looksLikeName(array $tokens): bool
    {
        if (count($tokens) < 2 || count($tokens) > 3) {
            return false;
        }

        foreach ($tokens as $token) {
            if (!$this->isNameToken($token)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string> $role
     * @param list<string> $name
     *
     * @return array{funkcja: string|null, nazwisko: string, klucz: string}
     */
    private function result(array $role, array $name): array
    {
        $nazwisko = implode(' ', $name);

        return [
            'funkcja' => $role === [] ? null : mb_strtolower(implode(' ', $role)),
            'nazwisko' => $nazwisko,
            'klucz' => $this->key($nazwisko),
        ];
    }

    /**
     * Klucz laczy warianty zapisu tej samej osoby: rozne wielkosci liter
     * i ogonki. Nie laczy roznych osob o tym samym nazwisku - imie wchodzi
     * do klucza wlasnie po to.
     */
    public function key(string $name): string
    {
        $ascii = strtr(mb_strtolower($name), [
            'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n',
            'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
        ]);

        return trim(preg_replace('/[^a-z]+/', '-', $ascii) ?? '', '-');
    }

    /**
     * @return array{funkcja: null, nazwisko: null, klucz: null}
     */
    private static function nothing(): array
    {
        return ['funkcja' => null, 'nazwisko' => null, 'klucz' => null];
    }
}
