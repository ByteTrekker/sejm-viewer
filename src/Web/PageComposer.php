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
 */
final class PageComposer
{
    public function __construct(private readonly string $publicDir)
    {
    }

    /**
     * @param array<string, mixed> $data
     * @param string $prefix przedrostek dla odnosnikow, gdy strona lezy w podkatalogu
     *                       (profile poslow siedza w public/posel/, wiec potrzebuja '../')
     */
    public function render(string $template, string $page, array $data, string $prefix = ''): string
    {
        $html = $this->read('pages/' . $template);

        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $html = str_replace('<!--@style-->', "<style>\n" . $this->read('partials/style.css') . '</style>', $html);
        $html = str_replace('<!--@nav-->', $this->nav($page, $prefix), $html);
        $html = str_replace('/*@core*/', $this->read('partials/core.js'), $html);
        $html = str_replace('/*__DATA__*/null', $json, $html);

        if (str_contains($html, '/*__DATA__*/') || str_contains($html, '<!--@')) {
            throw new \RuntimeException(sprintf('Szablon %s ma niewypelnione znaczniki.', $template));
        }

        return $html;
    }

    private function nav(string $page, string $prefix): string
    {
        $nav = $this->read('partials/nav.html');

        $nav = (string) preg_replace(
            '/(<a href="[^"]+" data-page="' . preg_quote($page, '/') . '")/',
            '$1 aria-current="page"',
            $nav,
        );

        if ($prefix === '') {
            return $nav;
        }

        // Odnosniki w nawigacji sa wzgledne wobec katalogu public/, wiec strona
        // lezaca glebiej musi dostac przedrostek - inaczej kazdy link z profilu
        // posla prowadzi do nieistniejacego posel/index.html.
        return (string) preg_replace('/<a href="(?!https?:)/', '<a href="' . $prefix, $nav);
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
