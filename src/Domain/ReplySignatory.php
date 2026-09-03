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
        // Polkreslnik, poltrafiony myslnik i pauza wygladaja tak samo, a znacza
        // to samo. Bez tego "Henryka Moscicka–Dendys" z pauza rozpadala sie na
        // dwa tokeny i podpis wypadal jako nierozpoznany.
        $author = strtr($author, ['–' => '-', '—' => '-', '‑' => '-', '−' => '-']);
        $author = trim(preg_replace('/\s+/u', ' ', $author) ?? '');

        if ($author === '') {
            return self::nothing();
        }

        // Myslnik rozdziela funkcje od nazwiska, ale bywa go wiecej niz jeden:
        // "minister - czlonek Rady Ministrow - Maciej Berek". Liczy sie OSTATNI,
        // bo nazwisko stoi na koncu.
        $dash = mb_strrpos($author, ' - ');
        if ($dash !== false) {
            $name = trim(mb_substr($author, $dash + 3));
            $role = trim(mb_substr($author, 0, $dash));

            if ($this->looksLikeName($name)) {
                return $this->result($role, $name);
            }
        }

        $tokens = $this->tokenize($author);
        $name = $this->trailingName($tokens);

        if ($name === null) {
            return self::nothing();
        }

        $role = trim(str_replace($name, '', implode(' ', $tokens)));
        $role = trim(preg_replace('/\s+/u', ' ', $role) ?? '');

        return $this->result($role, $name);
    }

    /**
     * Rozbija sklejone tokeny w rodzaju "stanuTomasz".
     *
     * Zrodlem jest pole wpisywane recznie, wiec brak spacji zdarza sie regularnie.
     * Ciecie na granicy mala->wielka litera jest bezpieczne, bo polskie nazwisko
     * nie ma wielkiej litery w srodku wyrazu poza mysnikiem, ktorego nie ruszamy.
     *
     * @return list<string>
     */
    private function tokenize(string $author): array
    {
        $split = preg_replace('/(\p{Ll})(\p{Lu})/u', '$1 $2', $author) ?? $author;

        return array_values(array_filter(preg_split('/\s+/u', $split) ?: []));
    }

    /**
     * Nazwisko to OSTATNI ciag dwoch lub trzech slow, ktore wygladaja jak imie
     * i nazwisko. "Ostatni", bo w formie "Z upowaznienia MINISTRA X Jan Kowalski
     * SEKRETARZ STANU" funkcja stoi po obu stronach nazwiska.
     *
     * @param list<string> $tokens
     */
    private function trailingName(array $tokens): string|null
    {
        $runs = [];
        $current = [];

        foreach ($tokens as $token) {
            if ($this->isNameToken($token)) {
                $current[] = $token;
                continue;
            }

            if (count($current) >= 2) {
                $runs[] = $current;
            }

            $current = [];
        }

        if (count($current) >= 2) {
            $runs[] = $current;
        }

        if ($runs === []) {
            return null;
        }

        $last = $runs[count($runs) - 1];

        // Dwa ostatnie czlony, nie trzy. Nierozpoznane slowo urzedowe w dopelniaczu
        // ("prezes Urzedu Ochrony Konkurencji i Konsumentow Tomasz Chrostny") jest
        // pisane wielka litera i wchodzi do ciagu; przy trzech czlonach powstawal
        // z tego nieistniejacy "Konsumentow Tomasz Chrostny". Zgubienie drugiego
        // imienia jest kosmetyczne, wymyslenie osoby - nie.
        return implode(' ', array_slice($last, -2));
    }

    private function isNameToken(string $token): bool
    {
        $bare = trim($token, '.,;:()');

        if ($bare === '' || mb_strlen($bare) < 2) {
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

    private function looksLikeName(string $candidate): bool
    {
        $tokens = $this->tokenize($candidate);

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
     * @return array{funkcja: string|null, nazwisko: string, klucz: string}
     */
    private function result(string $role, string $name): array
    {
        return [
            'funkcja' => $role === '' ? null : mb_strtolower($role),
            'nazwisko' => $name,
            'klucz' => $this->key($name),
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
