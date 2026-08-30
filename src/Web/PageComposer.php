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
     */
    public function render(string $template, string $page, array $data): string
    {
        $html = $this->read('pages/' . $template);

        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $html = str_replace('<!--@style-->', "<style>\n" . $this->read('partials/style.css') . '</style>', $html);
        $html = str_replace('<!--@nav-->', $this->nav($page), $html);
        $html = str_replace('/*@core*/', $this->read('partials/core.js'), $html);
        $html = str_replace('/*__DATA__*/null', $json, $html);

        if (str_contains($html, '/*__DATA__*/') || str_contains($html, '<!--@')) {
            throw new \RuntimeException(sprintf('Szablon %s ma niewypelnione znaczniki.', $template));
        }

        return $html;
    }

    private function nav(string $page): string
    {
        $nav = $this->read('partials/nav.html');

        return (string) preg_replace(
            '/(<a href="[^"]+" data-page="' . preg_quote($page, '/') . '")/',
            '$1 aria-current="page"',
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
