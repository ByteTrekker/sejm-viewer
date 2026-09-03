<?php

declare(strict_types=1);

/**
 * ETL: frekwencja poslow (z flaga usprawiedliwienia) -> SQLite.
 *
 * Jedno zadanie na posla, wiec kadencja to ok. 460 zadan - kilkadziesiat sekund.
 * Wymaga wczesniejszego uruchomienia bin/fetch.php (lista poslow).
 *
 * Uzycie:
 *   php bin/fetch-attendance.php --term=10
 *   php bin/fetch-attendance.php --term=7,8,9,10
 */

require __DIR__ . '/bootstrap.php';

use Milczenie\Console\Options;
use Milczenie\Import\AttendanceImporter;
use Milczenie\Sejm\SejmApiClient;
use Milczenie\Storage\Database;

$options = Options::fromGetopt(['term::', 'db::']);
$terms = $options->commaListOfInt('term', [10]);
$dbPath = $options->string('db', __DIR__ . '/../var/sejm.sqlite');

$log = static fn (string $m): int|false => fwrite(STDERR, $m . PHP_EOL);

$startedAt = microtime(true);
$db = Database::open($dbPath);
$importer = new AttendanceImporter(new SejmApiClient(logger: $log(...)), $db, $log(...));

$total = 0;
foreach ($terms as $term) {
    $log(sprintf('Frekwencja kadencji %d...', $term));
    $total += $importer->import($term);
}

$db->setMeta('attendance_fetched_at', (new DateTimeImmutable())->format(DateTimeInterface::ATOM));

$log(sprintf('Gotowe: %d dni posiedzen w %.1fs', $total, microtime(true) - $startedAt));
