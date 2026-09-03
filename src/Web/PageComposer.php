<?php

declare(strict_types=1);

namespace Milczenie\Web;

/**
 * Sklada strone z szablonu i czesci wspolnych.
 *
 * Kazda funkcja ma wlasna strone, ale arkusz stylow, rdzen JS i nawigacja sa jedne.
 * Zanim to powstalo, dwa szablony niosly 173 z 183 linii identycznego stylu
 * i komplet tych samych helperow - kazda poprawka wymagala dwoch edycji.
 *
 * Znaczniki w szablonie:
 *   <!--@style-->        arkusz stylow
 *   <!--@nav-->          nawigacja z zaznaczona biezaca strona
 *   /*@core* /           rdzen JS (wewnatrz bloku script, po definicji DATA)
 *   /*__DATA__* /null    dane strony
 *   {{tekst po polsku}}  ciag do przetlumaczenia
 *
 * Tlumaczenia dzialaja w konwencji "domyslny jezyk jest kluczem": w szablonie stoi
 * polski tekst w podwojnych nawiasach, a slownik jezyka mapuje go na obcy. Nie ma
 * wiec kluczy do wymyslania i nie da sie odwolac do nieistniejacego ciagu - a brak
 * tlumaczenia jest widoczny, bo zostaje polski tekst i raport go wymienia.
 */
final class PageComposer
{
    /**
     * Slownik na instancje, nie na proces: statyczny cache oddawal drugiej
     * instancji slownik pierwszej, wiec walidacja tresci tlumaczen wykonywala
     * sie tylko raz na uruchomienie.
     *
     * @var array<string, array<string, string>>
     */
    private array $dictionaries = [];

    /**
     * Ciagi bez tlumaczenia, zebrane przy renderowaniu - build je raportuje.
     *
     * @var array<string, array<string, true>>
     */
    private array $missing = [];

    public function __construct(private readonly string $publicDir)
    {
    }

    /**
     * @return array<string, list<string>> jezyk => brakujace ciagi
     */
    public function missingTranslations(): array
    {
        return array_map(static fn (array $set): array => array_keys($set), $this->missing);
    }

    /**
     * @param array<string, mixed> $data
     * @param string $prefix przedrostek dla odnosnikow, gdy strona lezy w podkatalogu
     *                       (profile poslow siedza w public/posel/, wiec potrzebuja '../')
     */
    /**
     * @param array<string, mixed> $data
     * @param string $self sciezka tej strony wzgledem katalogu jezyka ("posel/10-1.html"),
     *                     potrzebna, by przelacznik jezyka prowadzil do TEGO dokumentu,
     *                     a nie na strone glowna drugiej wersji
     */
    public function render(
        string $template,
        string $page,
        array $data,
        string $prefix = '',
        string $lang = 'pl',
        string|null $self = null,
    ): string {
        $html = $this->read('pages/' . $template);

        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $html = str_replace('<!--@style-->', "<style>\n" . $this->read('partials/style.css') . '</style>', $html);
        $html = str_replace('<!--@nav-->', $this->nav($page, $prefix, $lang, $self ?? $page . '.html'), $html);
        $html = str_replace('/*@core*/', $this->read('partials/core.js'), $html);
        $html = str_replace('/*__DATA__*/null', $json, $html);

        if (str_contains($html, '/*__DATA__*/') || str_contains($html, '<!--@')) {
            throw new \RuntimeException(sprintf('Szablon %s ma niewypelnione znaczniki.', $template));
        }

        return $this->translate($html, $lang);
    }

    /**
     * Podmienia {{...}} na tlumaczenie albo - gdy go nie ma - na polski oryginal,
     * zapisujac brak do raportu.
     */
    /**
     * Polski zapis liczby na angielski: "1 234,5" -> "1,234.5".
     *
     * Kolejnosc podmian ma znaczenie - najpierw separator dziesietny idzie na
     * znacznik tymczasowy, inaczej wstawione przecinki tysiecy zostalyby uznane
     * za przecinki dziesietne.
     */
    private static function localizeNumber(string $number): string
    {
        $number = str_replace(',', "\x00", $number);
        $number = str_replace([' ', "\u{a0}", "\u{202f}"], ',', $number);

        return str_replace("\x00", '.', $number);
    }

    private function translate(string $html, string $lang): string
    {
        $dictionary = $lang === 'pl' ? [] : $this->dictionary($lang);

        return (string) preg_replace_callback(
            '/\{\{(.+?)\}\}/su',
            function (array $m) use ($dictionary, $lang): string {
                $source = $m[1];

                // Liczba zapisana przez PHP w polskiej konwencji. Nie ma jej w slowniku,
                // bo tlumaczy sie regula, nie wpisem: angielski uzywa przecinka na tysiace
                // i kropki na czesci dziesietne, dokladnie odwrotnie niz polski.
                if (str_starts_with($source, '#')) {
                    $number = substr($source, 1);

                    return $lang === 'pl' ? $number : self::localizeNumber($number);
                }

                if ($lang === 'pl') {
                    return $source;
                }

                if (isset($dictionary[$source])) {
                    return $dictionary[$source];
                }

                $this->missing[$lang][$source] = true;

                return $source;
            },
            $html,
        );
    }

    /**
     * @return array<string, string>
     */
    private function dictionary(string $lang): array
    {
        if (!isset($this->dictionaries[$lang])) {
            $decoded = json_decode($this->read('partials/i18n/' . $lang . '.json'), true, 512, JSON_THROW_ON_ERROR);
            /** @var array<string, string> $map */
            $map = is_array($decoded) ? $decoded : [];

            // Tlumaczenie podstawia sie tekstowo, takze w srodku literalu JS
            // i w srodku wstrzyknietego JSON-a. Apostrof, cudzyslow i odwrotny
            // ukosnik rozerwaly kiedys caly skrypt strony angielskiej: literal
            // 'members' and other bills' konczyl sie na pierwszym apostrofie
            // i wywalal cala strone. Znaki typograficzne (’ ” “) sa bezpieczne
            // i poprawniejsze, wiec wymagamy ich zamiast blokowania przy uzyciu.
            foreach ($map as $source => $target) {
                if (preg_match('/[\'"\\\\]/', $target) === 1) {
                    throw new \RuntimeException(sprintf(
                        'Tlumaczenie [%s] zawiera \' " lub \\ - uzyj znakow typograficznych (’ ” “): %s',
                        $lang . ':' . $source,
                        $target,
                    ));
                }
            }

            $this->dictionaries[$lang] = $map;
        }

        return $this->dictionaries[$lang];
    }

    private function nav(string $page, string $prefix, string $lang, string $self): string
    {
        $nav = $this->read('partials/nav.html');

        $nav = (string) preg_replace(
            '/(<a href="[^"]+" data-page="' . preg_quote($page, '/') . '")/',
            '$1 aria-current="page"',
            $nav,
        );

        // Przelacznik jezyka prowadzi do TEGO SAMEGO dokumentu w drugiej wersji, nie na
        // strone glowna: z profilu posla ma sie przejsc na ten sam profil. Polska wersja
        // lezy w katalogu glownym, angielska w /en/, wiec $prefix cofa do katalogu jezyka,
        // a dalej sciezka jest identyczna w obu drzewach.
        $other = $lang === 'pl' ? $prefix . 'en/' . $self : $prefix . '../' . $self;

        $langs = $lang === 'pl'
            ? '<span aria-current="page">Polski</span><a href="' . $other . '" hreflang="en" lang="en">English</a>'
            : '<a href="' . $other . '" hreflang="pl" lang="pl">Polski</a><span aria-current="page">English</span>';

        if ($prefix !== '') {
            // Odnosniki w nawigacji sa wzgledne wobec katalogu jezyka, wiec strona
            // lezaca glebiej musi dostac przedrostek - inaczej kazdy link z profilu
            // posla prowadzi do nieistniejacego posel/index.html. Przelacznik jezyka
            // wstawiamy PO tej podmianie, bo jego adresy maja przedrostek juz wliczony.
            $nav = (string) preg_replace('/<a href="(?!https?:)/', '<a href="' . $prefix, $nav);
        }

        return str_replace(
            '<div class="langs" id="langs"></div>',
            '<div class="langs">' . $langs . '</div>',
            $nav,
        );
    }

    private function read(string $relative): string
    {
        $path = $this->publicDir . '/' . $relative;
        $content = file_get_contents($path);

        if ($content === false) {
            throw new \RuntimeException('Brak pliku ' . $path);
        }

        return $content;
    }
}
