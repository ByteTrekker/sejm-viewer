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

use Milczenie\Domain\IssuerNormalizer;
use Milczenie\Import\ActImporter;
use Milczenie\Sejm\SejmApiClient;
use Milczenie\Storage\Database;

$options = getopt('', ['from::', 'to::', 'publisher::', 'types::', 'db::']);

$from = (int) ($options['from'] ?? 2015);
$to = (int) ($options['to'] ?? (int) date('Y'));
$publisher = (string) ($options['publisher'] ?? 'DU');
$types = isset($options['types']) ? explode(',', (string) $options['types']) : ActImporter::TYPES;
$dbPath = (string) ($options['db'] ?? __DIR__ . '/../var/sejm.sqlite');

$log = static fn (string $m): int|false => fwrite(STDERR, $m . PHP_EOL);

$startedAt = microtime(true);
$db = Database::open($dbPath);
$importer = new ActImporter(new SejmApiClient(logger: $log(...)), $db, new IssuerNormalizer(), $log(...));

$total = 0;
foreach (range($from, $to) as $year) {
    $total += $importer->import($publisher, $year, $types);
}

$db->setMeta('acts_fetched_at', (new DateTimeImmutable())->format(DateTimeInterface::ATOM));
$db->setMeta('acts_range', sprintf('%s %d-%d', $publisher, $from, $to));

$log(sprintf('Gotowe: %d aktow w %.1fs', $total, microtime(true) - $startedAt));
