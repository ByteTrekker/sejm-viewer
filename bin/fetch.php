<?php

declare(strict_types=1);

/**
 * ETL: api.sejm.gov.pl -> SQLite.
 *
 * Uzycie:
 *   php bin/fetch.php                      # kadencja 10, interpelacje + zapytania
 *   php bin/fetch.php --term=9,10          # dwie kadencje (porownanie rzadow)
 *   php bin/fetch.php --kind=interpelacja  # tylko interpelacje
 *   php bin/fetch.php --since=auto         # tylko to, co zmienilo sie od ostatniego razu
 *   php bin/fetch.php --since=2026-08-01   # wlasny punkt odciecia
 *
 * Pelne pobranie kadencji to ok. 40 zadan; przyrostowe tygodniowe - jedno.
 */

require __DIR__ . '/bootstrap.php';

use Milczenie\Console\Options;
use Milczenie\Domain\QuestionKind;
use Milczenie\Domain\RecipientNormalizer;
use Milczenie\Import\QuestionImporter;
use Milczenie\Sejm\SejmApiClient;
use Milczenie\Storage\Database;

$options = Options::fromGetopt(['term::', 'kind::', 'db::', 'skip-mp', 'since::']);

$terms = $options->commaListOfInt('term', [10]);
$kinds = array_map(
    static fn (string $k): QuestionKind => QuestionKind::from($k),
    $options->commaList('kind', ['interpelacja', 'zapytanie']),
);
$dbPath = $options->string('db', __DIR__ . '/../var/sejm.sqlite');

$log = static function (string $message): void {
    fwrite(STDERR, $message . PHP_EOL);
};

/**
 * Punkt odciecia dla dociagania przyrostowego. API filtruje po dacie MODYFIKACJI,
 * wiec stare pytanie wraca w wynikach, gdy dojdzie do niego odpowiedz - to jest
 * dokladnie to, czego potrzebujemy.
 *
 * Cofamy sie o dobe od ostatniej znanej modyfikacji: rekord zapisany w trakcie
 * poprzedniego pobierania moglby inaczej wypasc miedzy dwoma przebiegami.
 */
$resolveSince = static function (?string $option, Database $db, int $term) use ($log): ?string {
    if ($option === null) {
        return null;
    }

    if ($option !== 'auto') {
        return $option;
    }

    $latest = $db->fetchRow(
        'SELECT MAX(last_modified) AS d FROM question WHERE term = :term',
        ['term' => $term],
    )['d'] ?? null;

    $latest ??= $db->getMeta('fetched_at');

    if ($latest === null) {
        $log('  --since=auto: brak punktu odniesienia, pobieram wszystko');

        return null;
    }

    return (new DateTimeImmutable(substr((string) $latest, 0, 10)))->modify('-1 day')->format('Y-m-d');
};

$startedAt = microtime(true);
$db = Database::open($dbPath);
$api = new SejmApiClient(logger: $log);
$importer = new QuestionImporter($api, $db, new RecipientNormalizer(), $log);

$log(sprintf('Metadane kadencji... %d', $importer->importTerms()));

foreach ($terms as $term) {
    if (!$options->has('skip-mp')) {
        $log(sprintf('Poslowie kadencji %d...', $term));
        $log(sprintf('  zapisano %d poslow', $importer->importMembers($term)));
    }

    $since = $resolveSince($options->nullableString('since'), $db, $term);

    foreach ($kinds as $kind) {
        $log(sprintf(
            'Import: kadencja %d, %s%s',
            $term,
            $kind->value,
            $since === null ? '' : ' (przyrostowo od ' . $since . ')',
        ));
        $importer->import($term, $kind, $since);
    }
}

$db->setMeta('fetched_at', (new DateTimeImmutable())->format(DateTimeInterface::ATOM));
$db->setMeta('terms', implode(',', $terms));

$log(sprintf('Gotowe w %.1fs -> %s', microtime(true) - $startedAt, realpath($dbPath) ?: $dbPath));
