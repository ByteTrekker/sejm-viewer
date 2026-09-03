<?php

declare(strict_types=1);

namespace Milczenie\Import;

use Milczenie\Sejm\SejmApiClient;
use Milczenie\Storage\Database;

/**
 * Glosowania imienne. Kazde glosowanie to osobne zadanie do API (lista posiedzenia
 * podaje tylko naglowki, bez glosow poszczegolnych poslow), wiec przy ~2,4 tys.
 * glosowan kadencji X pobieramy je rownolegle.
 */
final class VotingImporter
{
    public function __construct(
        private readonly SejmApiClient $api,
        private readonly Database $db,
        private readonly \Closure $logger,
    ) {
    }

    /**
     * @param bool $refresh true = pobierz ponownie takze to, co juz jest w bazie
     */
    public function import(int $term, bool $refresh = false): int
    {
        $paths = $this->votingPaths($term, $refresh);
        $this->log(sprintf('  kadencja %d: %d glosowan do pobrania', $term, count($paths)));

        if ($paths === []) {
            return 0;
        }

        $votingStmt = $this->db->pdo->prepare(
            'INSERT INTO voting (term, sitting, number, date, title, topic, kind, total_voted)
             VALUES (:term, :sitting, :number, :date, :title, :topic, :kind, :total_voted)
             ON CONFLICT (term, sitting, number) DO UPDATE SET
                 date = excluded.date, title = excluded.title, topic = excluded.topic,
                 kind = excluded.kind, total_voted = excluded.total_voted',
        );
        $voteStmt = $this->db->pdo->prepare(
            'INSERT INTO vote (term, sitting, number, mp_id, club, vote)
             VALUES (:term, :sitting, :number, :mp_id, :club, :vote)
             ON CONFLICT (term, sitting, number, mp_id) DO UPDATE SET
                 club = excluded.club, vote = excluded.vote',
        );

        $imported = 0;
        $malformed = 0;
        $this->db->pdo->beginTransaction();

        foreach ($this->api->fetchMany($paths) as $voting) {
            /** @var array<string, mixed> $voting */
            $sitting = (int) ($voting['sitting'] ?? 0);
            $number = (int) ($voting['votingNumber'] ?? 0);
            if ($sitting === 0 || $number === 0) {
                $malformed++;
                continue;
            }

            $votingStmt->execute([
                'term' => $term,
                'sitting' => $sitting,
                'number' => $number,
                'date' => isset($voting['date']) ? substr((string) $voting['date'], 0, 10) : null,
                'title' => isset($voting['title']) ? (string) $voting['title'] : null,
                'topic' => isset($voting['topic']) ? (string) $voting['topic'] : null,
                'kind' => isset($voting['kind']) ? (string) $voting['kind'] : null,
                'total_voted' => isset($voting['totalVoted']) ? (int) $voting['totalVoted'] : null,
            ]);

            foreach ((array) ($voting['votes'] ?? []) as $voteRaw) {
                if (!is_array($voteRaw) || !isset($voteRaw['MP'])) {
                    continue;
                }

                $voteStmt->execute([
                    'term' => $term,
                    'sitting' => $sitting,
                    'number' => $number,
                    'mp_id' => (int) $voteRaw['MP'],
                    'club' => isset($voteRaw['club']) ? (string) $voteRaw['club'] : null,
                    'vote' => (string) ($voteRaw['vote'] ?? 'UNKNOWN'),
                ]);
            }

            $imported++;

            if ($imported % 200 === 0) {
                $this->db->pdo->commit();
                $this->db->pdo->beginTransaction();
                $this->log(sprintf('  ... %d / %d', $imported, count($paths)));
            }
        }

        $this->db->pdo->commit();

        // Niekompletny import ma byc widoczny od razu, a nie odkryty tydzien pozniej
        // przy porownywaniu rankingu z API. Import jest idempotentny, wiec zalecenie
        // jest zawsze to samo: uruchomic ponownie te sama kadencje.
        $missing = count($paths) - $imported;
        if ($missing > 0) {
            $this->log(sprintf(
                '  UWAGA: pobrano %d z %d glosowan (brakuje %d: %d bledow sieci, %d rekordow bez identyfikatora).',
                $imported,
                count($paths),
                $missing,
                $this->api->lastFailureCount(),
                $malformed,
            ));
            $this->log(sprintf('  Import jest idempotentny - uruchom ponownie: php bin/fetch-votings.php --term=%d', $term));
        }

        return $imported;
    }

    /**
     * @return list<string>
     */
    private function votingPaths(int $term, bool $refresh = false): array
    {
        $sittings = $this->api->fetchProceedings($term);
        $headerPaths = array_map(
            static fn (int $n): string => sprintf('/sejm/term%d/votings/%d', $term, $n),
            $sittings,
        );

        $paths = [];
        foreach ($this->api->fetchMany($headerPaths) as $headers) {
            foreach ($headers as $header) {
                if (!is_array($header)) {
                    continue;
                }

                $sitting = (int) ($header['sitting'] ?? 0);
                $number = (int) ($header['votingNumber'] ?? 0);
                if ($sitting > 0 && $number > 0) {
                    $paths[] = sprintf('/sejm/term%d/votings/%d/%d', $term, $sitting, $number);
                }
            }
        }

        if ($refresh) {
            return $paths;
        }

        // Glosowanie raz zapisane juz sie nie zmieni - posiedzenie jest zamkniete.
        // Pomijanie ich zamienia cotygodniowe odswiezenie z 4,5 tys. zadan w kilkadziesiat.
        $stored = [];
        foreach ($this->db->fetchAll('SELECT sitting, number FROM voting WHERE term = :term', ['term' => $term]) as $row) {
            $stored[$row['sitting'] . ':' . $row['number']] = true;
        }

        $fresh = array_values(array_filter(
            $paths,
            static function (string $path) use ($stored, $term): bool {
                if (preg_match(sprintf('#/term%d/votings/(\d+)/(\d+)$#', $term), $path, $m) !== 1) {
                    return true;
                }

                return !isset($stored[$m[1] . ':' . $m[2]]);
            },
        ));

        $skipped = count($paths) - count($fresh);
        if ($skipped > 0) {
            $this->log(sprintf('  pominieto %d glosowan juz zapisanych (--refresh pobiera je ponownie)', $skipped));
        }

        return $fresh;
    }

    private function log(string $message): void
    {
        ($this->logger)($message);
    }
}
