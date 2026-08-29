<?php

declare(strict_types=1);

/**
 * Raport: SQLite -> public/data.json + public/index.html (dashboard offline).
 *
 * Domyslnie buduje wszystkie kadencje obecne w bazie. Kazda kadencja dostaje
 * wlasna date odciecia: zamknieta - dzien jej konca, trwajaca - dzien dzisiejszy.
 * Bez tego porownanie kadencji zamknietej z trwajaca jest bez sensu, bo
 * w trwajacej czesc pytan wciaz biegnie w terminie.
 *
 * Uzycie:
 *   php bin/build.php
 *   php bin/build.php --term=9,10
 *   php bin/build.php --term=10 --snapshot=2025-01-01   # wymusza date odciecia
 */

require __DIR__ . '/bootstrap.php';

use Milczenie\Console\Options;
use Milczenie\Domain\RecipientNormalizer;
use Milczenie\Report\AbsenceBuilder;
use Milczenie\Report\MemberBuilder;
use Milczenie\Report\ProcessBuilder;
use Milczenie\Report\RankingBuilder;
use Milczenie\Storage\Database;

$options = Options::fromGetopt(['term::', 'db::', 'out::', 'snapshot::']);

$dbPath = $options->string('db', __DIR__ . '/../var/sejm.sqlite');
$outDir = rtrim($options->string('out', __DIR__ . '/../public'), '/');
$snapshotOption = $options->nullableString('snapshot');
$forcedSnapshot = $snapshotOption === null ? null : new DateTimeImmutable($snapshotOption);

$db = Database::open($dbPath);
$today = new DateTimeImmutable('today');

$available = $db->fetchInts('SELECT DISTINCT term FROM question ORDER BY term');

$requested = $options->commaListOfInt('term', $available);
$terms = array_values(array_intersect($available, $requested));

if ($terms === []) {
    fwrite(STDERR, 'Brak danych w bazie. Uruchom najpierw bin/fetch.php' . PHP_EOL);
    exit(1);
}

$termMeta = [];
foreach ($db->fetchAll('SELECT num, date_from, date_to FROM term') as $row) {
    $termMeta[(int) $row['num']] = $row;
}

$payload = ['kadencje' => [], 'raporty' => []];

foreach ($terms as $term) {
    $info = $termMeta[$term] ?? ['date_from' => null, 'date_to' => null];
    $endsAt = $info['date_to'];
    $closed = $endsAt !== null && $endsAt < $today->format('Y-m-d');
    $snapshot = $forcedSnapshot ?? ($closed ? new DateTimeImmutable((string) $endsAt) : $today);

    // Normalizator per kadencja - nazwy resortow nie sa porownywalne miedzy kadencjami,
    // wiec wspoldzielenie slownika etykiet tylko by je pomieszalo.
    $report = (new RankingBuilder($db, new RecipientNormalizer(), $snapshot))->build($term);
    $report['droga'] = (new ProcessBuilder($db))->build($term);
    $report += (new MemberBuilder($db, $snapshot))->build($term);
    // Glosowania sa pobierane osobnym ETL-em, wiec dla kadencji bez nich sekcja znika,
    // zamiast pokazywac pusty ranking.
    $absences = (new AbsenceBuilder($db))->build($term);
    $report['nieobecnosci'] = $absences;
    $report['meta']['zamknieta'] = $closed;
    $report['meta']['od'] = $info['date_from'];
    $report['meta']['do'] = $endsAt;

    $ranked = array_values(array_filter($report['ministerstwa'], static fn (array $m): bool => $m['w_rankingu']));
    $sum = static fn (string $key): int => array_sum(array_column($report['ministerstwa'], $key));

    $decided = $sum('rozstrzygniete');
    $failed = $sum('po_terminie') + $sum('bez_odpowiedzi_po_terminie');
    $forwarded = $sum('skierowane');
    $undated = $sum('odpowiedzi_bez_daty');

    // API zwraca daty odpowiedzi jako wartownik "0000-12-30" dla calej kadencji VII.
    // Bez daty nie da sie orzec o terminowosci - taka kadencja jest w bazie i w liczbach
    // ogolnych, ale wypada z rankingu, zamiast pokazywac fikcyjna punktualnosc.
    $measurable = $forwarded > 0 && $undated / $forwarded < 0.2;
    $report['meta']['mierzalna'] = $measurable;
    $report['meta']['odpowiedzi_bez_daty'] = $undated;

    $payload['kadencje'][] = [
        'numer' => $term,
        'od' => $info['date_from'],
        'do' => $endsAt,
        'zamknieta' => $closed,
        'odciecie' => $snapshot->format('Y-m-d'),
        'pytania' => $forwarded,
        'mierzalna' => $measurable,
        'odpowiedzi_bez_daty' => $undated,
        'rozstrzygniete' => $decided,
        'udzial_po_terminie' => $decided > 0 ? round($failed / $decided, 4) : 0.0,
        'bez_odpowiedzi' => $sum('bez_odpowiedzi_po_terminie'),
        'adresatow_w_rankingu' => count($ranked),
        // null, gdy dla kadencji nie pobrano glosowan - wykres porownawczy ma wtedy
        // pokazac brak danych, a nie zero.
        'nieobecnosci_udzial' => $absences['udzial_ogolem'] ?? null,
        'nieobecnosci_glosowan' => $absences['glosowan'] ?? null,
    ];
    $payload['raporty'][$term] = $report;

    fwrite(STDERR, sprintf(
        'Kadencja %2d (%s%s, odciecie %s): %s pytan, po terminie %s, bez odpowiedzi %d, adresatow w rankingu %d%s',
        $term,
        $report['meta']['od'] ?? '?',
        $closed ? ' - ' . $endsAt : ' - trwa',
        $snapshot->format('Y-m-d'),
        number_format($sum('skierowane'), 0, ',', ' '),
        $measurable ? sprintf('%.1f%%', 100 * ($decided > 0 ? $failed / $decided : 0)) : sprintf('NIEMIERZALNE (%d odpowiedzi bez daty)', $undated),
        $sum('bez_odpowiedzi_po_terminie'),
        count($ranked),
        PHP_EOL,
    ));
}

$measurableTerms = array_column(array_filter($payload['kadencje'], static fn (array $k): bool => $k['mierzalna']), 'numer');
$payload['domyslna_kadencja'] = $measurableTerms === [] ? max($terms) : max($measurableTerms);
$payload['pobrano'] = $db->getMeta('fetched_at');

$json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
file_put_contents($outDir . '/data.json', $json . PHP_EOL);

$template = file_get_contents($outDir . '/template.html');
if ($template === false) {
    throw new RuntimeException('Brak public/template.html');
}

// Dane wstrzykujemy inline - dashboard ma dzialac z file:// i jako pojedynczy plik.
file_put_contents($outDir . '/index.html', str_replace('/*__DATA__*/null', $json, $template));

fwrite(STDERR, sprintf('Zapisano %s/index.html (%s KB)%s', $outDir, number_format(strlen($json) / 1024, 0), PHP_EOL));
