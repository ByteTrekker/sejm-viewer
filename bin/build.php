<?php

declare(strict_types=1);

/**
 * Raport: SQLite -> osobna strona na kazda funkcje.
 *
 * Kazda strona dostaje wylacznie swoj wycinek danych. Wczesniej jeden index.html
 * niosl komplet wszystkich funkcji i wazyl 1,2 MB - czytelnik zainteresowany
 * nieobecnosciami sciagal ranking resortow, serie szablonowe i listy pytan.
 *
 * Kazda kadencja liczona na wlasna date odciecia: zamknieta - dzien jej konca,
 * trwajaca - dzien dzisiejszy. Bez tego porownanie jest bez sensu.
 *
 * Uzycie:
 *   php bin/build.php
 *   php bin/build.php --term=9,10
 *   php bin/build.php --term=10 --snapshot=2025-01-01
 */

require __DIR__ . '/bootstrap.php';

use Milczenie\Console\Options;
use Milczenie\Domain\RecipientNormalizer;
use Milczenie\Report\AbsenceBuilder;
use Milczenie\Report\MemberBuilder;
use Milczenie\Report\ProcessBuilder;
use Milczenie\Report\RankingBuilder;
use Milczenie\Storage\Database;
use Milczenie\Web\PageComposer;

/** Wycinki danych per strona - klucze raportu, ktore dana strona faktycznie czyta. */
const PAGE_SLICES = [
    'interpelacje' => ['meta', 'ministerstwa', 'miesiace', 'kluby', 'najdluzej_bez_odpowiedzi', 'najdluzej_do_odpowiedzi'],
    'droga' => ['meta', 'droga'],
    'poslowie' => ['meta', 'poslowie', 'serie'],
    'nieobecnosci' => ['meta', 'nieobecnosci'],
];

$options = Options::fromGetopt(['term::', 'db::', 'out::', 'snapshot::']);

$dbPath = $options->string('db', __DIR__ . '/../var/sejm.sqlite');
$outDir = rtrim($options->string('out', __DIR__ . '/../public'), '/');
$snapshotOption = $options->nullableString('snapshot');
$forcedSnapshot = $snapshotOption === null ? null : new DateTimeImmutable($snapshotOption);

$db = Database::open($dbPath);
$today = new DateTimeImmutable('today');

$available = $db->fetchInts('SELECT DISTINCT term FROM question ORDER BY term');
$terms = array_values(array_intersect($available, $options->commaListOfInt('term', $available)));

if ($terms === []) {
    fwrite(STDERR, 'Brak danych w bazie. Uruchom najpierw bin/fetch.php' . PHP_EOL);
    exit(1);
}

$termMeta = [];
foreach ($db->fetchAll('SELECT num, date_from, date_to FROM term') as $row) {
    $termMeta[(int) $row['num']] = $row;
}

$kadencje = [];
$reports = [];

foreach ($terms as $term) {
    $info = $termMeta[$term] ?? ['date_from' => null, 'date_to' => null];
    $endsAt = $info['date_to'];
    $closed = $endsAt !== null && $endsAt < $today->format('Y-m-d');
    $snapshot = $forcedSnapshot ?? ($closed ? new DateTimeImmutable((string) $endsAt) : $today);

    // Normalizator per kadencja - nazwy resortow nie sa porownywalne miedzy kadencjami.
    $report = (new RankingBuilder($db, new RecipientNormalizer(), $snapshot))->build($term);
    $report['meta']['zamknieta'] = $closed;
    $report['meta']['od'] = $info['date_from'];
    $report['meta']['do'] = $endsAt;
    $report['droga'] = (new ProcessBuilder($db))->build($term);
    $report += (new MemberBuilder($db, $snapshot))->build($term);
    $report['nieobecnosci'] = (new AbsenceBuilder($db))->build($term);

    $ranked = array_values(array_filter($report['ministerstwa'], static fn (array $m): bool => $m['w_rankingu']));
    $sum = static fn (string $key): int => array_sum(array_column($report['ministerstwa'], $key));

    $decided = $sum('rozstrzygniete');
    $failed = $sum('po_terminie') + $sum('bez_odpowiedzi_po_terminie');
    $forwarded = $sum('skierowane');
    $undated = $sum('odpowiedzi_bez_daty');
    $measurable = $forwarded > 0 && $undated / $forwarded < 0.2;

    $report['meta']['mierzalna'] = $measurable;
    $report['meta']['odpowiedzi_bez_daty'] = $undated;

    $kadencje[] = [
        'numer' => $term,
        'od' => $info['date_from'],
        'do' => $endsAt,
        'zamknieta' => $closed,
        'odciecie' => $snapshot->format('Y-m-d'),
        'pytania' => $forwarded,
        'rozstrzygniete' => $decided,
        'udzial_po_terminie' => $decided > 0 ? round($failed / $decided, 4) : 0.0,
        'bez_odpowiedzi' => $sum('bez_odpowiedzi_po_terminie'),
        'adresatow_w_rankingu' => count($ranked),
        'mierzalna' => $measurable,
        'odpowiedzi_bez_daty' => $undated,
        'nieobecnosci_udzial' => $report['nieobecnosci']['udzial_ogolem'] ?? null,
        'nieobecnosci_glosowan' => $report['nieobecnosci']['glosowan'] ?? null,
    ];
    $reports[$term] = $report;

    fwrite(STDERR, sprintf(
        'Kadencja %2d (%s%s, odciecie %s): %s pytan, po terminie %s, nieobecnosci %s%s',
        $term,
        $info['date_from'] ?? '?',
        $closed ? ' - ' . $endsAt : ' - trwa',
        $snapshot->format('Y-m-d'),
        number_format($forwarded, 0, ',', ' '),
        $measurable ? sprintf('%.1f%%', 100 * ($decided > 0 ? $failed / $decided : 0)) : 'NIEMIERZALNE',
        $report['nieobecnosci'] === null ? 'brak glosowan' : sprintf('%.1f%%', 100 * $report['nieobecnosci']['udzial_ogolem']),
        PHP_EOL,
    ));
}

$measurableTerms = array_column(array_filter($kadencje, static fn (array $k): bool => $k['mierzalna']), 'numer');
$defaultTerm = $measurableTerms === [] ? max($terms) : max($measurableTerms);
$fetchedAt = $db->getMeta('fetched_at');

$composer = new PageComposer($outDir);
$written = [];

foreach (PAGE_SLICES as $page => $keys) {
    $slices = [];
    foreach ($reports as $term => $report) {
        $slice = array_intersect_key($report, array_flip($keys));
        // Kadencja bez glosowan nie trafia na strone nieobecnosci - przelacznik
        // ma ja wyszarzyc, a nie pokazac pusta tabele.
        if ($page === 'nieobecnosci' && ($slice['nieobecnosci'] ?? null) === null) {
            continue;
        }
        $slices[$term] = $slice;
    }

    if ($slices === []) {
        fwrite(STDERR, sprintf('  pominieto %s.html - brak danych%s', $page, PHP_EOL));
        continue;
    }

    $default = isset($slices[$defaultTerm]) ? $defaultTerm : max(array_keys($slices));

    $html = $composer->render($page . '.html', $page, [
        'kadencje' => $kadencje,
        'domyslna_kadencja' => $default,
        'pobrano' => $fetchedAt,
        'raporty' => $slices,
    ]);

    file_put_contents($outDir . '/' . $page . '.html', $html);
    $written[$page] = strlen($html);
}

// --- strona startowa: liczby prowadzace do kazdej z funkcji ---
$latest = $reports[$defaultTerm];
$latestKadencja = null;
foreach ($kadencje as $k) {
    if ($k['numer'] === $defaultTerm) {
        $latestKadencja = $k;
    }
}

$vacatio = $db->fetchRow(
    <<<'SQL'
        SELECT COUNT(*) AS aktow,
               AVG(CASE WHEN julianday(entry_into_force) - julianday(promulgation) < 14 THEN 1.0 ELSE 0.0 END) AS ponizej
        FROM act
        WHERE type = 'Rozporządzenie' AND promulgation IS NOT NULL AND entry_into_force IS NOT NULL
        SQL,
);

$counts = static fn (string $sql): int => (int) ($db->fetchRow($sql)['n'] ?? 0);

$index = [
    'wygenerowano' => $today->format('Y-m-d'),
    'pobrano' => $fetchedAt,
    'skroty' => [
        'po_terminie' => $latestKadencja['udzial_po_terminie'] ?? 0.0,
        'pytania' => array_sum(array_column($kadencje, 'pytania')),
        'zjedzone' => $latest['droga']['kancelaria']['udzial_terminu_zjedzony'] ?? 0.0,
        'mediana' => $latest['droga']['kancelaria']['mediana_dni'] ?? 0,
        'milczacy' => count(array_filter($latest['poslowie'] ?? [], static fn (array $m): bool => $m['pytan'] === 0)),
        'absencja' => $latest['nieobecnosci']['udzial_ogolem'] ?? 0.0,
        'glosowan' => $counts('SELECT COUNT(*) AS n FROM voting'),
        'ponizej' => (float) ($vacatio['ponizej'] ?? 0),
        'aktow' => (int) ($vacatio['aktow'] ?? 0),
    ],
    'zrodla' => [
        ['zbior' => 'Interpelacje i zapytania', 'ile' => $counts('SELECT COUNT(*) AS n FROM question'), 'zakres' => 'kadencje VII–X, od 2011-11', 'odswiezanie' => 'tygodniowo (tylko kadencja X)'],
        ['zbior' => 'Odpowiedzi', 'ile' => $counts('SELECT COUNT(*) AS n FROM reply'), 'zakres' => 'jw.', 'odswiezanie' => 'tygodniowo (tylko kadencja X)'],
        ['zbior' => 'Głosowania imienne', 'ile' => $counts('SELECT COUNT(*) AS n FROM voting'), 'zakres' => 'kadencje VII–X', 'odswiezanie' => 'tygodniowo (nowe posiedzenia)'],
        ['zbior' => 'Głosy indywidualne', 'ile' => $counts('SELECT COUNT(*) AS n FROM vote'), 'zakres' => 'jw.', 'odswiezanie' => 'razem z głosowaniami'],
        ['zbior' => 'Akty Dziennika Ustaw', 'ile' => $counts('SELECT COUNT(*) AS n FROM act'), 'zakres' => '2015–2026', 'odswiezanie' => 'tygodniowo (bieżący rocznik)'],
        ['zbior' => 'Posłowie', 'ile' => $counts('SELECT COUNT(*) AS n FROM mp'), 'zakres' => '4 kadencje', 'odswiezanie' => 'miesięcznie'],
    ],
];

$html = $composer->render('index.html', 'index', $index);
file_put_contents($outDir . '/index.html', $html);
$written['index'] = strlen($html);

fwrite(STDERR, PHP_EOL . 'Strony:' . PHP_EOL);
foreach ($written as $page => $bytes) {
    fwrite(STDERR, sprintf('  %-16s %6s KB%s', $page . '.html', number_format($bytes / 1024, 0), PHP_EOL));
}
