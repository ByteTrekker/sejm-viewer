<?php

declare(strict_types=1);

namespace Milczenie\Import;

use Milczenie\Domain\IssuerNormalizer;
use Milczenie\Sejm\SejmApiClient;
use Milczenie\Storage\Database;

final class ActImporter
{
    /**
     * Interesuja nas akty tworzace prawo powszechnie obowiazujace. Obwieszczenia
     * (najliczniejszy typ w Dz.U.) to teksty jednolite - nie wprowadzaja nowych obowiazkow,
     * wiec vacatio legis nie ma dla nich sensu.
     */
    public const TYPES = ['Ustawa', 'Rozporządzenie'];

    public function __construct(
        private readonly SejmApiClient $api,
        private readonly Database $db,
        private readonly IssuerNormalizer $normalizer,
        private readonly \Closure $logger,
    ) {
    }

    /**
     * @param list<string> $types
     */
    public function import(string $publisher, int $year, array $types = self::TYPES, bool $refresh = false): int
    {
        $index = $this->api->fetchActsIndex($publisher, $year);
        $refs = [];

        foreach ($index as $item) {
            if (!in_array((string) ($item['type'] ?? ''), $types, true)) {
                continue;
            }

            $refs[] = ['publisher' => $publisher, 'year' => $year, 'pos' => (int) $item['pos']];
        }

        if (!$refresh) {
            // Akt raz ogloszony nie zmienia daty wejscia w zycie. Przy cotygodniowym
            // odswiezaniu biezacego rocznika liczy sie wylacznie to, co doszlo.
            $stored = [];
            foreach ($this->db->fetchAll(
                'SELECT pos FROM act WHERE publisher = :publisher AND year = :year',
                ['publisher' => $publisher, 'year' => $year],
            ) as $row) {
                $stored[(int) $row['pos']] = true;
            }

            $all = count($refs);
            $refs = array_values(array_filter($refs, static fn (array $r): bool => !isset($stored[$r['pos']])));

            if ($all !== count($refs)) {
                $this->log(sprintf('  pominieto %d aktow juz zapisanych (--refresh pobiera je ponownie)', $all - count($refs)));
            }
        }

        $this->log(sprintf('  %s %d: %d aktow do pobrania (z %d w roczniku)', $publisher, $year, count($refs), count($index)));

        if ($refs === []) {
            return 0;
        }

        $actStmt = $this->db->pdo->prepare(
            'INSERT INTO act (eli, publisher, year, pos, type, title, announcement_date, promulgation,
                              entry_into_force, in_force, status, display_address)
             VALUES (:eli, :publisher, :year, :pos, :type, :title, :announcement_date, :promulgation,
                     :entry_into_force, :in_force, :status, :display_address)
             ON CONFLICT (eli) DO UPDATE SET
                 type = excluded.type, title = excluded.title,
                 announcement_date = excluded.announcement_date, promulgation = excluded.promulgation,
                 entry_into_force = excluded.entry_into_force, in_force = excluded.in_force,
                 status = excluded.status, display_address = excluded.display_address',
        );
        $issuerStmt = $this->db->pdo->prepare(
            'INSERT INTO act_issuer (eli, issuer_raw, issuer_key) VALUES (:eli, :raw, :key)
             ON CONFLICT (eli, issuer_raw) DO UPDATE SET issuer_key = excluded.issuer_key',
        );

        $imported = 0;
        $this->db->pdo->beginTransaction();

        foreach ($this->api->fetchActDetails($refs) as $act) {
            $eli = (string) ($act['ELI'] ?? '');
            if ($eli === '') {
                continue;
            }

            $actStmt->execute([
                'eli' => $eli,
                'publisher' => (string) ($act['publisher'] ?? $publisher),
                'year' => (int) ($act['year'] ?? $year),
                'pos' => (int) ($act['pos'] ?? 0),
                'type' => (string) ($act['type'] ?? ''),
                'title' => (string) ($act['title'] ?? ''),
                'announcement_date' => $this->date($act['announcementDate'] ?? null),
                'promulgation' => $this->date($act['promulgation'] ?? null),
                'entry_into_force' => $this->date($act['entryIntoForce'] ?? null),
                'in_force' => isset($act['inForce']) ? (string) $act['inForce'] : null,
                'status' => isset($act['status']) ? (string) $act['status'] : null,
                'display_address' => isset($act['displayAddress']) ? (string) $act['displayAddress'] : null,
            ]);

            foreach ($this->issuers($act) as $issuer) {
                $issuerStmt->execute([
                    'eli' => $eli,
                    'raw' => $issuer,
                    'key' => $this->normalizer->normalize($issuer),
                ]);
            }

            $imported++;

            if ($imported % 500 === 0) {
                $this->db->pdo->commit();
                $this->db->pdo->beginTransaction();
                $this->log(sprintf('  ... %d / %d', $imported, count($refs)));
            }
        }

        $this->db->pdo->commit();

        return $imported;
    }

    /**
     * releasedBy bywa lista stringow albo lista obiektow {name: ...} - zaleznie od aktu.
     *
     * @param array<string, mixed> $act
     * @return list<string>
     */
    private function issuers(array $act): array
    {
        $out = [];

        foreach ((array) ($act['releasedBy'] ?? []) as $entry) {
            $name = is_array($entry) ? (string) ($entry['name'] ?? '') : (string) $entry;
            $name = trim($name);

            if ($name !== '') {
                $out[] = $name;
            }
        }

        return $out;
    }

    private function date(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $date = substr($value, 0, 10);

        return $date >= '1900-01-01' ? $date : null;
    }

    private function log(string $message): void
    {
        ($this->logger)($message);
    }
}
