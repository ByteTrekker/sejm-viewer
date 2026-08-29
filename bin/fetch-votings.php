<?php

declare(strict_types=1);

/**
 * ETL: glosowania imienne -> SQLite.
 *
 * Kazde glosowanie to osobne zadanie (lista posiedzenia nie zawiera glosow
 * poszczegolnych poslow), wiec kadencja X to ~2,4 tys. zadan, a kadencja IX ~10,6 tys.
 *
 * Uzycie:
 *   php bin/fetch-votings.php --term=10
 *   php bin/fetch-votings.php --term=8,9,10
 */

require __DIR__ . '/bootstrap.php';

use Milczenie\Console\Options;
use Milczenie\Import\VotingImporter;
use Milczenie\Sejm\SejmApiClient;
use Milczenie\Storage\Database;

$options = Options::fromGetopt(['term::', 'db::']);
$terms = $options->commaListOfInt('term', [10]);
$dbPath = $options->string('db', __DIR__ . '/../var/sejm.sqlite');

$log = static fn (string $m): int|false => fwrite(STDERR, $m . PHP_EOL);

$startedAt = microtime(true);
$db = Database::open($dbPath);
$importer = new VotingImporter(new SejmApiClient(logger: $log(...)), $db, $log(...));

$total = 0;
foreach ($terms as $term) {
    $log(sprintf('Glosowania kadencji %d...', $term));
    $total += $importer->import($term);
}

$db->setMeta('votings_fetched_at', (new DateTimeImmutable())->format(DateTimeInterface::ATOM));
$db->setMeta('votings_terms', implode(',', $terms));

$log(sprintf('Gotowe: %d glosowan w %.1fs', $total, microtime(true) - $startedAt));

// Kod wyjscia niesie informacje o kompletnosci - inaczej cron zapisze sukces
// przy niepelnym imporcie.
$stored = (int) ($db->fetchRow(
    sprintf('SELECT COUNT(*) AS n FROM voting WHERE term IN (%s)', implode(',', $terms)),
)['n'] ?? 0);
$log(sprintf('W bazie: %d glosowan dla kadencji %s', $stored, implode(', ', $terms)));
