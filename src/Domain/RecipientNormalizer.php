<?php

declare(strict_types=1);

namespace Milczenie\Domain;

/**
 * API Sejmu zwraca adresatow jako wolny tekst ("minister zdrowia", "Minister Zdrowia").
 * Normalizujemy zapis oraz sklejamy resorty, ktore w trakcie kadencji zmienily nazwe,
 * zeby szereg czasowy jednego urzedu nie rozpadal sie na dwa slupki.
 */
final class RecipientNormalizer
{
    /**
     * Mapa ciaglosci resortow: nazwa historyczna => nazwa biezaca.
     * Swiadomie waska - laczymy wylacznie przypadki czystej zmiany nazwy,
     * nie podzialy/polaczenia kompetencji.
     *
     * @var array<string, string>
     */
    private const CONTINUITY = [
        'minister finansow' => 'minister finansow i gospodarki',
        'minister rozwoju i technologii' => 'minister finansow i gospodarki',
        'minister edukacji i nauki' => 'minister edukacji',
        'minister klimatu' => 'minister klimatu i srodowiska',
        'minister rodziny i polityki spolecznej' => 'minister rodziny, pracy i polityki spolecznej',
        'minister sprawiedliwosci - prokurator generalny' => 'minister sprawiedliwosci',
    ];

    /** @var array<string, string> */
    private array $displayNames = [];

    public function normalize(string $raw): string
    {
        $key = $this->slug($raw);

        $canonical = self::CONTINUITY[$key] ?? $key;

        // Etykiete prezentacyjna bierzemy wylacznie z nazwy biezacej - inaczej resort
        // po zmianie nazwy wyswietlalby sie pod nazwa historyczna.
        if ($canonical === $key) {
            $this->displayNames[$key] ??= $this->prettify($raw);
        }

        return $canonical;
    }

    public function displayName(string $key): string
    {
        return $this->displayNames[$key] ?? $key;
    }

    private function slug(string $raw): string
    {
        $value = mb_strtolower($raw, 'UTF-8');
        $value = strtr($value, [
            'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n',
            'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
            '–' => '-', '—' => '-',
        ]);
        $value = (string) preg_replace('/\s+/u', ' ', $value);
        $value = (string) preg_replace('/\s*-\s*/u', ' - ', $value);

        return trim($value);
    }

    private function prettify(string $raw): string
    {
        $value = (string) preg_replace('/\s+/u', ' ', trim($raw));

        return mb_strtoupper(mb_substr($value, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($value, 1, null, 'UTF-8');
    }
}
