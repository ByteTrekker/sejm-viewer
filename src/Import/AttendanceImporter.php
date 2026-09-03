<?php

declare(strict_types=1);

namespace Milczenie\Import;

use Milczenie\Sejm\SejmApiClient;
use Milczenie\Storage\Database;

/**
 * Frekwencja posla dzien po dniu wraz z informacja, czy nieobecnosc byla
 * usprawiedliwiona.
 *
 * To jedyne miejsce w API, ktore rozroznia nieobecnosc usprawiedliwiona od
 * nieusprawiedliwionej - same glosowania podaja wylacznie fakt ("ABSENT").
 * Flaga `absenceExcuse` jest przypisana do DNIA POSIEDZENIA, nie do pojedynczego
 * glosowania: usprawiedliwiony jest caly dzien nieobecnosci.
 */
final class AttendanceImporter
{
    public function __construct(
        private readonly SejmApiClient $api,
        private readonly Database $db,
        private readonly \Closure $logger,
    ) {
    }

    public function import(int $term): int
    {
        $ids = $this->db->fetchInts('SELECT id FROM mp WHERE term = :term ORDER BY id', ['term' => $term]);
        if ($ids === []) {
            $this->log(sprintf('  kadencja %d: brak poslow w bazie - najpierw bin/fetch.php', $term));

            return 0;
        }

        $paths = array_map(
            static fn (int $id): string => sprintf('/sejm/term%d/MP/%d/votings/stats', $term, $id),
            $ids,
        );
        $this->log(sprintf('  kadencja %d: %d poslow', $term, count($paths)));

        $stmt = $this->db->pdo->prepare(
            'INSERT INTO mp_attendance (term, mp_id, sitting, date, num_votings, num_voted, num_missed, excused)
             VALUES (:term, :mp_id, :sitting, :date, :num_votings, :num_voted, :num_missed, :excused)
             ON CONFLICT (term, mp_id, sitting, date) DO UPDATE SET
                 num_votings = excluded.num_votings, num_voted = excluded.num_voted,
                 num_missed = excluded.num_missed, excused = excluded.excused',
        );

        $days = 0;
        $done = 0;
        $this->db->pdo->beginTransaction();

        foreach ($this->api->fetchManyWithPaths($paths) as $item) {
            // Odpowiedz nie niesie identyfikatora posla - bierzemy go ze sciezki.
            if (preg_match('#/MP/(\d+)/votings/stats#', $item['path'], $m) !== 1) {
                continue;
            }

            $mpId = (int) $m[1];
            $done++;

            foreach ($item['data'] as $row) {
                if (!is_array($row) || !isset($row['date'], $row['sitting'])) {
                    continue;
                }

                $stmt->execute([
                    'term' => $term,
                    'mp_id' => $mpId,
                    'sitting' => (int) $row['sitting'],
                    'date' => substr((string) $row['date'], 0, 10),
                    'num_votings' => (int) ($row['numVotings'] ?? 0),
                    'num_voted' => (int) ($row['numVoted'] ?? 0),
                    'num_missed' => (int) ($row['numMissed'] ?? 0),
                    'excused' => ($row['absenceExcuse'] ?? false) ? 1 : 0,
                ]);
                $days++;
            }

            if ($done % 100 === 0) {
                $this->db->pdo->commit();
                $this->db->pdo->beginTransaction();
                $this->log(sprintf('  ... %d / %d posłów', $done, count($paths)));
            }
        }

        $this->db->pdo->commit();

        $missing = count($paths) - $done;
        if ($missing > 0) {
            $this->log(sprintf(
                '  UWAGA: pobrano statystyki %d z %d poslow (brakuje %d, bledow sieci: %d).',
                $done,
                count($paths),
                $missing,
                $this->api->lastFailureCount(),
            ));
            $this->log(sprintf('  Import jest idempotentny - uruchom ponownie: php bin/fetch-attendance.php --term=%d', $term));
        }

        return $days;
    }

    private function log(string $message): void
    {
        ($this->logger)($message);
    }
}
