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

use Milczenie\Domain\IssuerNormalizer;
use Milczenie\Domain\TechnicalActClassifier;
use Milczenie\Report\VacatioBuilder;
use Milczenie\Storage\Database;

$options = getopt('', ['from::', 'to::', 'db::', 'out::', 'exclude-technical', 'name::']);

// Wariant "merytoryczny" odsiewa akty, ktore nie nakladaja obowiazkow na obywateli
// (obszary Natura 2000, pelnomocnicy rzadu, wybory przedterminowe). Powstaje jako
// OSOBNA strona - wersja pelna zostaje nietknieta, zeby dalo sie je porownac.
$excludeTechnical = isset($options['exclude-technical']);
$name = (string) ($options['name'] ?? ($excludeTechnical ? 'vacatio-merytoryczne' : 'vacatio'));

$dbPath = (string) ($options['db'] ?? __DIR__ . '/../var/sejm.sqlite');
$outDir = rtrim((string) ($options['out'] ?? __DIR__ . '/../public'), '/');

$db = Database::open($dbPath);

$bounds = $db->pdo->query('SELECT MIN(year) AS lo, MAX(year) AS hi FROM act')->fetch();
if (!is_array($bounds) || $bounds['lo'] === null) {
    fwrite(STDERR, 'Brak aktow w bazie. Uruchom najpierw bin/fetch-acts.php' . PHP_EOL);
    exit(1);
}

$from = (int) ($options['from'] ?? $bounds['lo']);
$to = (int) ($options['to'] ?? $bounds['hi']);

$report = (new VacatioBuilder(
    $db,
    new IssuerNormalizer(),
    $excludeTechnical ? new TechnicalActClassifier() : null,
))->build($from, $to);

$json = json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
file_put_contents(sprintf('%s/%s.json', $outDir, $name), $json . PHP_EOL);

$template = file_get_contents($outDir . '/template-vacatio.html');
if ($template === false) {
    throw new RuntimeException('Brak public/template-vacatio.html');
}
file_put_contents(sprintf('%s/%s.html', $outDir, $name), str_replace('/*__DATA__*/null', $json, $template));

$ranked = array_values(array_filter($report['organy'], static fn (array $r): bool => $r['w_rankingu']));

fwrite(STDERR, sprintf(
    "Vacatio legis %d-%d [%s] | rozporzadzen: %s | organow w rankingu: %d/%d | bez daty wejscia: %d%s",
    $from,
    $to,
    $report['meta']['wariant'],
    number_format(array_sum(array_column($report['organy'], 'aktow')), 0, ',', ' '),
    count($ranked),
    count($report['organy']),
    $report['meta']['bez_daty_wejscia'],
    PHP_EOL
));

if ($excludeTechnical) {
    fwrite(STDERR, sprintf("Odsiane jako techniczne: %d%s", $report['meta']['wykluczone_razem'], PHP_EOL));
    foreach ($report['meta']['wykluczone_techniczne'] as $category => $count) {
        fwrite(STDERR, sprintf("   %5d  %s%s", $count, $category, PHP_EOL));
    }
    fwrite(STDERR, PHP_EOL);
}

foreach (array_slice($ranked, 0, 10) as $i => $r) {
    fwrite(STDERR, sprintf(
        "%2d. %-42s pospiech %5.1f | ponizej 14 dni %5.1f%% | z dnia na dzien %5.1f%% | mediana %2sd | n=%d%s",
        $i + 1,
        mb_substr((string) $r['nazwa'], 0, 46),
        $r['wskaznik_pospiechu'],
        100 * $r['udzial_ponizej'],
        100 * $r['udzial_natychmiast'],
        $r['mediana_dni'] ?? '-',
        $r['aktow'],
        PHP_EOL
    ));
}
