<?php

declare(strict_types=1);

/**
 * Warianty interfejsu: te same strony, ten sam komplet danych, inny arkusz.
 *
 * Kierunek wizualny wybiera sie patrzac na prawdziwe liczby, a nie na makiete
 * z tekstem zastepczym: to, czy dany uklad znosi tabele 460 wierszy i hemicykl
 * o 460 kolkach, widac dopiero na danych. Kazdy wariant powstaje wiec jako
 * pelny, przeklikiwalny serwis w osobnym katalogu.
 *
 * Uzycie:
 *   php bin/build-variants.php
 *   php bin/build-variants.php --term=10 --only=gesty
 */

require __DIR__ . '/bootstrap.php';

use Milczenie\Console\Options;

$options = Options::fromGetopt(['term::', 'db::', 'out::', 'only::', 'lang::']);

/**
 * @var array<string, array{nazwa: string, zalozenie: string, koszt: string}>
 */
const VARIANTS = [
    'redakcyjny' => [
        'nazwa' => 'Redakcyjny',
        'zalozenie' => 'Odbiorca czyta, a nie analizuje. Szeryfy w nagłówkach, węższa kolumna, '
            . 'linie zamiast pudełek — rozkładówka gazety z danymi, nie panel administracyjny.',
        'koszt' => 'Mniej danych na ekran niż w wariancie gęstym; szeryfy gorzej znoszą małe rozmiary.',
    ],
    'gesty' => [
        'nazwa' => 'Gęsty',
        'zalozenie' => 'Dziennikarz przegląda 460 wierszy i szuka anomalii. Ciaśniejszy rytm, '
            . 'liczby monospace, zero zaokrągleń — na ekranie mieści się około dwa razy tyle danych.',
        'koszt' => 'Czyta się gorzej na telefonie i onieśmiela czytelnika, który wszedł z linku.',
    ],
    'obywatelski' => [
        'nazwa' => 'Obywatelski',
        'zalozenie' => 'Czytelnik wchodzi z telefonu i ma piętnaście sekund. Duża typografia, '
            . 'dużo powietrza, duże cele dotykowe, liczba i zdanie przy niej zamiast tabeli.',
        'koszt' => 'Mniej danych na ekran, więcej przewijania przy porównywaniu wielu pozycji.',
    ],
];

$root = dirname(__DIR__);
$outRoot = rtrim($options->string('out', $root . '/public/warianty'), '/');
$only = $options->nullableString('only');
$term = $options->string('term', '10');
$lang = $options->string('lang', 'pl');

// array_values, bo array_intersect zachowuje klucze wejscia - a przy --only=gesty
// zostaje klucz 1 i lista przestaje byc lista.
$chosen = $only === null
    ? array_keys(VARIANTS)
    : array_values(array_intersect(explode(',', $only), array_keys(VARIANTS)));

if ($chosen === []) {
    fwrite(STDERR, 'Nieznany wariant. Dostepne: ' . implode(', ', array_keys(VARIANTS)) . PHP_EOL);
    exit(1);
}

foreach ($chosen as $slug) {
    $dir = $outRoot . '/' . $slug;
    fwrite(STDERR, sprintf('--- wariant %s -> %s%s', $slug, $dir, PHP_EOL));

    $command = sprintf(
        'php %s --out=%s --templates=%s --term=%s --lang=%s --profile-votes=0 --style-overlay=%s 2>&1',
        escapeshellarg($root . '/bin/build.php'),
        escapeshellarg($dir),
        escapeshellarg($root . '/public'),
        escapeshellarg($term),
        escapeshellarg($lang),
        escapeshellarg('partials/warianty/' . $slug . '.css'),
    );

    exec($command, $output, $code);

    if ($code !== 0) {
        fwrite(STDERR, implode(PHP_EOL, $output) . PHP_EOL);
        fwrite(STDERR, 'Budowanie wariantu ' . $slug . ' nie powiodlo sie.' . PHP_EOL);
        exit($code);
    }

    $output = [];
}

file_put_contents($outRoot . '/index.html', renderIndex($chosen));

fwrite(STDERR, sprintf('Gotowe: %d wariantow. Porownanie: %s/index.html%s', count($chosen), $outRoot, PHP_EOL));

/**
 * Strona porownawcza. Celowo bez stylu wariantow - ma byc neutralna ramka,
 * w ktorej porownuje sie trzy kierunki, a nie czwartym kierunkiem.
 *
 * @param list<string> $slugs
 */
function renderIndex(array $slugs): string
{
    $rows = '';
    foreach ($slugs as $slug) {
        $v = VARIANTS[$slug];
        $rows .= sprintf(
            '<article><h2><a href="%1$s/index.html">%2$s</a></h2>'
            . '<p class="why">%3$s</p><p class="cost"><b>Koszt:</b> %4$s</p>'
            . '<p class="links">Zobacz na stronach: '
            . '<a href="%1$s/index.html">start</a> · '
            . '<a href="%1$s/sklad.html">skład Sejmu (499 wierszy)</a> · '
            . '<a href="%1$s/mandaty.html">rozkład mandatów (hemicykl)</a> · '
            . '<a href="%1$s/interpelacje.html">ranking milczenia</a> · '
            . '<a href="%1$s/rzad.html">skład rządu</a></p></article>',
            htmlspecialchars($slug, ENT_QUOTES),
            htmlspecialchars($v['nazwa'], ENT_QUOTES),
            htmlspecialchars($v['zalozenie'], ENT_QUOTES),
            htmlspecialchars($v['koszt'], ENT_QUOTES),
        );
    }

    return <<<HTML
        <!doctype html>
        <html lang="pl">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Warianty interfejsu — sejm-viewer</title>
        <style>
          :root { color-scheme: light dark; --bg: #f6f5f2; --fg: #14140f; --dim: #52514e; --line: #dedcd5; }
          @media (prefers-color-scheme: dark) { :root { --bg: #131312; --fg: #fff; --dim: #c3c2b7; --line: #343431; } }
          body { margin: 0; background: var(--bg); color: var(--fg); font: 16px/1.6 ui-sans-serif, -apple-system, "Segoe UI", Roboto, sans-serif; }
          main { max-width: 780px; margin: 0 auto; padding: 48px 20px 96px; }
          h1 { font-size: 34px; line-height: 1.15; margin: 0 0 8px; }
          .lead { color: var(--dim); max-width: 60ch; margin: 0 0 8px; }
          article { border-top: 1px solid var(--line); padding: 24px 0 4px; }
          h2 { font-size: 22px; margin: 0 0 6px; }
          a { color: inherit; }
          .why { margin: 0 0 8px; }
          .cost, .links { color: var(--dim); font-size: 14.5px; margin: 0 0 8px; }
          footer { border-top: 1px solid var(--line); margin-top: 32px; padding-top: 16px; color: var(--dim); font-size: 14px; }
        </style>
        </head>
        <body><main>
        <h1>Warianty interfejsu</h1>
        <p class="lead">Trzy kierunki wizualne, każdy zbudowany na komplecie prawdziwych danych kadencji X.
        Wszystkie trzy to <b>nakładki na jeden arkusz bazowy</b>, więc różnią się tylko tym, co dany kierunek
        naprawdę zmienia — układ stron, treść i liczby są identyczne.</p>
        <p class="lead">Każdy wariant ma podane założenie <b>i koszt</b>. Kierunek bez kosztu to zwykle kierunek,
        którego nie przemyślano do końca.</p>
        {$rows}
        <footer>Wersja podstawowa jest <a href="../index.html">o katalog wyżej</a>.
        Warianty są generowane przez <code>make warianty</code> i nie wchodzą do repozytorium.
        Powstają dla <b>kadencji X i tylko po polsku</b> — przełącznik kadencji pokaże w nich jedną pozycję,
        bo budowanie kompletu cztery razy zajmowałoby kilkanaście minut, a o wyglądzie to nie rozstrzyga.</footer>
        </main></body></html>
        HTML;
}
