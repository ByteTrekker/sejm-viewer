<?php

declare(strict_types=1);

/**
 * Raport vacatio legis: SQLite -> public/vacatio.json + public/vacatio.html.
 *
 * Uzycie:
 *   php bin/build-vacatio.php
 *   php bin/build-vacatio.php --from=2020 --to=2026
 */

require __DIR__ . '/bootstrap.php';

use Milczenie\Console\Options;
use Milczenie\Domain\IssuerNormalizer;
use Milczenie\Domain\LegalSource;
use Milczenie\Domain\TechnicalActClassifier;
use Milczenie\Report\VacatioBuilder;
use Milczenie\Storage\Database;
use Milczenie\Web\PageComposer;

$options = Options::fromGetopt(['from::', 'to::', 'db::', 'out::', 'exclude-technical', 'name::', 'lang::', 'templates::', 'style-overlay::']);

// Wariant "merytoryczny" odsiewa akty, ktore nie nakladaja obowiazkow na obywateli
// (obszary Natura 2000, pelnomocnicy rzadu, wybory przedterminowe). Powstaje jako
// OSOBNA strona - wersja pelna zostaje nietknieta, zeby dalo sie je porownac.
$excludeTechnical = $options->has('exclude-technical');
$name = $options->string('name', $excludeTechnical ? 'vacatio-merytoryczne' : 'vacatio');

$dbPath = $options->string('db', __DIR__ . '/../var/sejm.sqlite');
$outDir = rtrim($options->string('out', __DIR__ . '/../public'), '/');

$db = Database::open($dbPath);

$bounds = $db->fetchRow('SELECT MIN(year) AS lo, MAX(year) AS hi FROM act');
if ($bounds === null || $bounds['lo'] === null) {
    fwrite(STDERR, 'Brak aktow w bazie. Uruchom najpierw bin/fetch-acts.php' . PHP_EOL);
    exit(1);
}

$from = $options->int('from', (int) $bounds['lo']);
$to = $options->int('to', (int) $bounds['hi']);

$report = (new VacatioBuilder(
    $db,
    new IssuerNormalizer(),
    $excludeTechnical ? new TechnicalActClassifier() : null,
))->build($from, $to);

// Obie wersje (pelna i bez aktow technicznych) powstaja z jednego szablonu
// i roznia sie wylacznie zbiorem danych.
$report['podstawy'] = LegalSource::all();

// Wersja polska idzie do public/, kazda inna do podkatalogu jezyka. Dane zostaja
// te same - roznica jest wylacznie w warstwie tekstu, wiec raport liczy sie raz.
$composer = new PageComposer(
    rtrim($options->string('templates', __DIR__ . '/../public'), '/'),
    $options->nullableString('style-overlay'),
);
foreach ($options->commaList('lang', ['pl', 'en']) as $lang) {
    $dir = $lang === 'pl' ? $outDir : $outDir . '/' . $lang;
    if (!is_dir($dir)) {
        mkdir($dir, 0o755, true);
    }

    file_put_contents(
        sprintf('%s/%s.html', $dir, $name),
        $composer->render('vacatio.html', 'vacatio', $report, '', $lang, $name . '.html'),
    );
}

foreach ($composer->missingTranslations() as $lang => $strings) {
    fwrite(STDERR, sprintf('BRAK TLUMACZEN [%s]: %d%s', $lang, count($strings), PHP_EOL));
    foreach (array_slice($strings, 0, 25) as $string) {
        fwrite(STDERR, '   ' . $string . PHP_EOL);
    }
}

$ranked = array_values(array_filter($report['organy'], static fn (array $r): bool => $r['w_rankingu']));

fwrite(STDERR, sprintf(
    'Vacatio legis %d-%d [%s] | rozporzadzen: %s | organow w rankingu: %d/%d | bez daty wejscia: %d%s',
    $from,
    $to,
    $report['meta']['wariant'],
    number_format(array_sum(array_column($report['organy'], 'aktow')), 0, ',', ' '),
    count($ranked),
    count($report['organy']),
    $report['meta']['bez_daty_wejscia'],
    PHP_EOL,
));

if ($excludeTechnical) {
    fwrite(STDERR, sprintf('Odsiane jako techniczne: %d%s', $report['meta']['wykluczone_razem'], PHP_EOL));
    foreach ($report['meta']['wykluczone_techniczne'] as $category => $count) {
        fwrite(STDERR, sprintf('   %5d  %s%s', $count, $category, PHP_EOL));
    }
    fwrite(STDERR, PHP_EOL);
}

foreach (array_slice($ranked, 0, 10) as $i => $r) {
    fwrite(STDERR, sprintf(
        '%2d. %-42s pospiech %5.1f | ponizej 14 dni %5.1f%% | z dnia na dzien %5.1f%% | mediana %2sd | n=%d%s',
        $i + 1,
        mb_substr((string) $r['nazwa'], 0, 46),
        $r['wskaznik_pospiechu'],
        100 * $r['udzial_ponizej'],
        100 * $r['udzial_natychmiast'],
        $r['mediana_dni'] ?? '-',
        $r['aktow'],
        PHP_EOL,
    ));
}
