<?php

declare(strict_types=1);

/**
 * ETL: ELI (Dziennik Ustaw / Monitor Polski) -> SQLite.
 *
 * Data wejscia w zycie jest wylacznie w detalu aktu, wiec pobieramy je pojedynczo -
 * rownolegle, bo inaczej rocznik trwa kilka minut zamiast kilkunastu sekund.
 *
 * Uzycie:
 *   php bin/fetch-acts.php --from=2015 --to=2026
 *   php bin/fetch-acts.php --from=2024 --to=2024 --types=Ustawa
 */

require __DIR__ . '/bootstrap.php';

use Milczenie\Console\Options;
use Milczenie\Domain\IssuerNormalizer;
use Milczenie\Import\ActImporter;
use Milczenie\Sejm\SejmApiClient;
use Milczenie\Storage\Database;

$options = Options::fromGetopt(['from::', 'to::', 'publisher::', 'types::', 'db::', 'refresh']);

$from = $options->int('from', 2015);
$to = $options->int('to', (int) date('Y'));
$publisher = $options->string('publisher', 'DU');
$types = $options->commaList('types', ActImporter::TYPES);
$dbPath = $options->string('db', __DIR__ . '/../var/sejm.sqlite');

$log = static fn (string $m): int|false => fwrite(STDERR, $m . PHP_EOL);

$startedAt = microtime(true);
$db = Database::open($dbPath);
$importer = new ActImporter(new SejmApiClient(logger: $log(...)), $db, new IssuerNormalizer(), $log(...));

$total = 0;
foreach (range($from, $to) as $year) {
    $total += $importer->import($publisher, $year, $types, $options->has('refresh'));
}

$db->setMeta('acts_fetched_at', (new DateTimeImmutable())->format(DateTimeInterface::ATOM));
$db->setMeta('acts_range', sprintf('%s %d-%d', $publisher, $from, $to));

$log(sprintf('Gotowe: %d aktow w %.1fs', $total, microtime(true) - $startedAt));
