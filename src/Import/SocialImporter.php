<?php

declare(strict_types=1);

namespace Milczenie\Import;

use Milczenie\Storage\Database;
use Milczenie\Wikidata\WikidataClient;

/**
 * Dopasowanie kont spolecznosciowych z Wikidaty do poslow.
 *
 * Dopasowujemy po PELNYM imieniu i nazwisku, bo nic lepszego nie ma - a to znaczy,
 * ze trzeba byc ostroznym. Przypisanie konta niewlasciwej osobie jest gorsze niz
 * brak konta, wiec:
 *  - dopasowanie musi byc dokladne po normalizacji spacji i wielkosci liter,
 *  - nazwisko wskazujace na WIECEJ NIZ JEDNA encje Wikidaty jest odrzucane w calosci,
 *  - kazdy rekord niesie identyfikator encji, zeby dalo sie go sprawdzic u zrodla,
 *  - liczba dopasowanych i odrzuconych jest raportowana, nie ukrywana.
 */
final class SocialImporter
{
    public function __construct(
        private readonly WikidataClient $wikidata,
        private readonly Database $db,
        private readonly \Closure $logger,
    ) {
    }

    /**
     * @param list<int> $terms
     * @return array{konta: int, poslow: int, niejednoznaczne: int, bez_konta: int}
     */
    public function import(array $terms): array
    {
        $accounts = $this->wikidata->politiciansWithAccounts();

        /** @var array<string, array<string, true>> $entitiesByName */
        $entitiesByName = [];
        foreach ($accounts as $row) {
            $entitiesByName[$this->key($row['name'])][$row['qid']] = true;
        }

        // Nazwisko wskazujace na kilka encji odrzucamy w calosci - lepiej nie pokazac
        // konta niz pokazac cudze.
        $ambiguous = array_keys(array_filter($entitiesByName, static fn (array $q): bool => count($q) > 1));
        $ambiguousSet = array_flip($ambiguous);

        /** @var array<string, list<array{qid: string, platform: string, handle: string}>> $byName */
        $byName = [];
        foreach ($accounts as $row) {
            $key = $this->key($row['name']);
            if (isset($ambiguousSet[$key])) {
                continue;
            }

            $byName[$key][] = ['qid' => $row['qid'], 'platform' => $row['platform'], 'handle' => $row['handle']];
        }

        $stmt = $this->db->pdo->prepare(
            'INSERT INTO mp_social (term, mp_id, platform, handle, qid) VALUES (:term, :mp_id, :platform, :handle, :qid)
             ON CONFLICT (term, mp_id, platform, handle) DO UPDATE SET qid = excluded.qid',
        );

        $stored = 0;
        $matched = 0;
        $unmatched = 0;
        $ambiguousHits = 0;

        $this->db->pdo->beginTransaction();

        foreach ($terms as $term) {
            foreach ($this->db->fetchAll('SELECT id, name FROM mp WHERE term = :term', ['term' => $term]) as $mp) {
                $key = $this->key((string) $mp['name']);

                if (isset($ambiguousSet[$key])) {
                    $ambiguousHits++;
                    continue;
                }

                if (!isset($byName[$key])) {
                    $unmatched++;
                    continue;
                }

                $matched++;
                foreach ($byName[$key] as $account) {
                    $stmt->execute([
                        'term' => $term,
                        'mp_id' => (int) $mp['id'],
                        'platform' => $account['platform'],
                        'handle' => $account['handle'],
                        'qid' => $account['qid'],
                    ]);
                    $stored++;
                }
            }
        }

        $this->db->pdo->commit();

        $this->log(sprintf(
            '  dopasowano %d poslow (%d kont), odrzucono %d niejednoznacznych, bez konta %d',
            $matched,
            $stored,
            $ambiguousHits,
            $unmatched,
        ));

        return ['konta' => $stored, 'poslow' => $matched, 'niejednoznaczne' => $ambiguousHits, 'bez_konta' => $unmatched];
    }

    private function key(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)), 'UTF-8');
    }

    private function log(string $message): void
    {
        ($this->logger)($message);
    }
}
