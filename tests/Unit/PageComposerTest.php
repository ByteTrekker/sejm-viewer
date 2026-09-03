<?php

declare(strict_types=1);

namespace Milczenie\Tests\Unit;

use Milczenie\Web\PageComposer;
use PHPUnit\Framework\TestCase;

/**
 * Warstwa tlumaczen: podmiana jest tekstowa, wiec regula bezpieczenstwa
 * i regula formatu liczby sa tu wazniejsze od samego skladania stron.
 */
// Bez #[CoversClass]: pokrycie i mutacje obejmuja warstwe czysta (src/Domain,
// src/Console), a PageComposer czyta pliki, wiec do niej nie nalezy. Deklaracja
// pokrycia klasy spoza <source> jest ostrzezeniem PHPUnit, a ostrzezenie jest
// w tym projekcie bledem. Poszerzenie zakresu mutacji to osobna decyzja.
final class PageComposerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/composer-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/pages', 0o775, true);
        mkdir($this->dir . '/partials/i18n', 0o775, true);

        file_put_contents($this->dir . '/partials/style.css', '');
        file_put_contents($this->dir . '/partials/core.js', '');
        file_put_contents(
            $this->dir . '/partials/nav.html',
            '<nav class="pages"><a href="index.html" data-page="index">Start</a>'
            . '<div class="langs" id="langs"></div></nav>',
        );
    }

    protected function tearDown(): void
    {
        foreach (['pages', 'partials/i18n', 'partials'] as $sub) {
            foreach (glob($this->dir . '/' . $sub . '/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }

        foreach (['pages', 'partials/i18n', 'partials', ''] as $sub) {
            @rmdir(rtrim($this->dir . '/' . $sub, '/'));
        }
    }

    /**
     * @param array<string, string> $dictionary
     */
    private function compose(
        string $template,
        array $dictionary,
        string $lang = 'en',
        string|null $self = null,
        string $prefix = '',
    ): string {
        file_put_contents($this->dir . '/pages/t.html', $template);
        file_put_contents(
            $this->dir . '/partials/i18n/en.json',
            json_encode($dictionary, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );

        return (new PageComposer($this->dir))
            ->render('t.html', 'index', [], $prefix, $lang, $self);
    }

    public function test_marked_string_is_replaced_from_the_dictionary(): void
    {
        $html = $this->compose('<h1>{{Kadencja}}</h1><!--@nav-->', ['Kadencja' => 'Term']);

        self::assertStringContainsString('<h1>Term</h1>', $html);
    }

    public function test_polish_build_only_strips_the_markers(): void
    {
        $html = $this->compose('<h1>{{Kadencja}}</h1><!--@nav-->', ['Kadencja' => 'Term'], 'pl');

        self::assertStringContainsString('<h1>Kadencja</h1>', $html);
    }

    public function test_missing_translation_falls_back_to_polish_and_is_reported(): void
    {
        file_put_contents($this->dir . '/pages/t.html', '<h1>{{Kadencja}}</h1><!--@nav-->');
        file_put_contents($this->dir . '/partials/i18n/en.json', '{}');

        $composer = new PageComposer($this->dir);
        $html = $composer->render('t.html', 'index', [], '', 'en');

        self::assertStringContainsString('<h1>Kadencja</h1>', $html);
        self::assertSame(['en' => ['Kadencja']], $composer->missingTranslations());
    }

    /**
     * Angielski zapisuje 1,234.5 tam, gdzie polski 1 234,5. Raport powstaje raz
     * dla obu wersji, wiec liczba idzie znacznikiem i tlumaczy sie regula.
     */
    public function test_numeric_marker_switches_separators_for_english(): void
    {
        $html = $this->compose('<p>{{#1 234,5}} {{#0,25%}} {{#21}}</p><!--@nav-->', []);

        self::assertStringContainsString('<p>1,234.5 0.25% 21</p>', $html);
    }

    public function test_numeric_marker_keeps_polish_separators_in_polish(): void
    {
        $html = $this->compose('<p>{{#1 234,5}}</p><!--@nav-->', [], 'pl');

        self::assertStringContainsString('<p>1 234,5</p>', $html);
    }

    public function test_numeric_marker_is_not_reported_as_a_missing_translation(): void
    {
        file_put_contents($this->dir . '/pages/t.html', '<p>{{#1 234}}</p><!--@nav-->');
        file_put_contents($this->dir . '/partials/i18n/en.json', '{}');

        $composer = new PageComposer($this->dir);
        $composer->render('t.html', 'index', [], '', 'en');

        self::assertSame([], $composer->missingTranslations());
    }

    /**
     * Tlumaczenie ląduje w środku literalu JS. Apostrof w "members' and other
     * bills" zamykal literal i wywalal caly skrypt strony angielskiej.
     */
    public function test_translation_with_an_apostrophe_is_rejected(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->compose('<p>{{projekty}}</p><!--@nav-->', ['projekty' => "members' bills"]);
    }

    public function test_translation_with_a_quote_or_backslash_is_rejected(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->compose('<p>{{projekty}}</p><!--@nav-->', ['projekty' => 'a "quoted" label']);
    }

    public function test_language_switch_points_at_the_same_document(): void
    {
        $pl = $this->compose('<!--@nav-->', ['x' => 'y'], 'pl', 'interpelacje.html');
        $en = $this->compose('<!--@nav-->', ['x' => 'y'], 'en', 'interpelacje.html');

        self::assertStringContainsString('href="en/interpelacje.html"', $pl);
        self::assertStringContainsString('href="../interpelacje.html"', $en);
    }

    /**
     * Strona lezaca glebiej cofa sie do katalogu jezyka, a dalej sciezka jest
     * identyczna w obu drzewach - inaczej przelacznik z profilu ladowal na
     * stronie glownej drugiej wersji.
     */
    public function test_language_switch_from_a_subdirectory_keeps_the_document(): void
    {
        $pl = $this->compose('<!--@nav-->', ['x' => 'y'], 'pl', 'posel/10-1.html', '../');
        $en = $this->compose('<!--@nav-->', ['x' => 'y'], 'en', 'posel/10-1.html', '../');

        self::assertStringContainsString('href="../en/posel/10-1.html"', $pl);
        self::assertStringContainsString('href="../../posel/10-1.html"', $en);
    }
}
