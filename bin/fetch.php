<?php

declare(strict_types=1);

/**
 * ETL: api.sejm.gov.pl -> SQLite.
 *
 * Uzycie:
 *   php bin/fetch.php                      # kadencja 10, interpelacje + zapytania
 *   php bin/fetch.php --term=9,10          # dwie kadencje (porownanie rzadow)
 *   php bin/fetch.php --kind=interpelacja  # tylko interpelacje
 */

require __DIR__ . '/bootstrap.php';

use Milczenie\Console\Options;
use Milczenie\Domain\QuestionKind;
use Milczenie\Domain\RecipientNormalizer;
use Milczenie\Import\QuestionImporter;
use Milczenie\Sejm\SejmApiClient;
use Milczenie\Storage\Database;

$options = Options::fromGetopt(['term::', 'kind::', 'db::', 'skip-mp']);

$terms = $options->commaListOfInt('term', [10]);
$kinds = array_map(
    static fn (string $k): QuestionKind => QuestionKind::from($k),
    $options->commaList('kind', ['interpelacja', 'zapytanie']),
);
$dbPath = $options->string('db', __DIR__ . '/../var/sejm.sqlite');

$log = static function (string $message): void {
    fwrite(STDERR, $message . PHP_EOL);
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

    foreach ($kinds as $kind) {
        $log(sprintf('Import: kadencja %d, %s', $term, $kind->value));
        $importer->import($term, $kind);
    }
}

$db->setMeta('fetched_at', (new DateTimeImmutable())->format(DateTimeInterface::ATOM));
$db->setMeta('terms', implode(',', $terms));

$log(sprintf('Gotowe w %.1fs -> %s', microtime(true) - $startedAt, realpath($dbPath) ?: $dbPath));
