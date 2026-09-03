<?php

declare(strict_types=1);

/**
 * ETL: konta spolecznosciowe poslow z Wikidaty -> SQLite.
 *
 * API Sejmu nie podaje zadnych kont. Wikidata jest zrodlem spolecznosciowym:
 * niekompletnym i niegwarantowanym - dlatego kazdy rekord niesie identyfikator
 * encji, a nazwiska wskazujace na wiecej niz jedna osobe sa odrzucane.
 *
 * Uzycie:
 *   php bin/fetch-social.php --term=10
 *   php bin/fetch-social.php --term=7,8,9,10
 */

require __DIR__ . '/bootstrap.php';

use Milczenie\Console\Options;
use Milczenie\Import\SocialImporter;
use Milczenie\Storage\Database;
use Milczenie\Wikidata\WikidataClient;

$options = Options::fromGetopt(['term::', 'db::']);
$terms = $options->commaListOfInt('term', [10]);
$dbPath = $options->string('db', __DIR__ . '/../var/sejm.sqlite');

$log = static fn (string $m): int|false => fwrite(STDERR, $m . PHP_EOL);

$db = Database::open($dbPath);
$log('Pobieranie kont z Wikidaty...');
$result = (new SocialImporter(new WikidataClient(logger: $log(...)), $db, $log(...)))->import($terms);

$db->setMeta('social_fetched_at', (new DateTimeImmutable())->format(DateTimeInterface::ATOM));

$log(sprintf('Gotowe: %d kont dla %d poslow', $result['konta'], $result['poslow']));
