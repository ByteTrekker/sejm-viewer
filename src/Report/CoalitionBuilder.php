<?php

declare(strict_types=1);

namespace Milczenie\Report;

use Milczenie\Domain\VotingCategory;
use Milczenie\Storage\Database;

/**
 * Kto z kim glosuje: zgodnosc linii klubowych, para po parze.
 *
 * Zgodnosc liczymy na poziomie KLUBU, nie posla - dla kazdego glosowania
 * ustalamy linie kazdego klubu, a potem sprawdzamy, ile razy dwie linie byly
 * takie same. Dzieki temu liczba nie zalezy od liczebnosci klubow: klub
 * dwustuosobowy i pietnastoosobowy waza tyle samo.
 *
 * Wynik jest rozbity na rodzaje glosowan, bo jedna usredniona liczba zaciera cala
 * tresc: przy wnioskach formalnych zgadzaja sie niemal wszyscy, a przy ustawach
 * dopiero widac podzialy.
 */
final class CoalitionBuilder
{
    private const MIN_CLUB_VOTES = 10;

    /** Ponizej tylu wspolnych glosowan procent zgodnosci jest szumem. */
    private const MIN_SHARED = 50;

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function build(int $term): ?array
    {
        $lines = $this->clubLines($term);
        if ($lines === []) {
            return null;
        }

        $categories = $this->categories($term);

        /** @var array<string, array<string, array{wspolnych: int, zgodnych: int, wg_kategorii: array<string, array{wspolnych: int, zgodnych: int}>}>> $pairs */
        $pairs = [];
        $clubVotings = [];
        $categoryCounts = [];

        foreach ($lines as $key => $clubs) {
            $category = $categories[$key] ?? VotingCategory::Other->value;
            $categoryCounts[$category] = ($categoryCounts[$category] ?? 0) + 1;

            $names = array_keys($clubs);
            sort($names);

            foreach ($names as $name) {
                $clubVotings[$name] = ($clubVotings[$name] ?? 0) + 1;
            }

            for ($i = 0; $i < count($names); $i++) {
                for ($j = $i + 1; $j < count($names); $j++) {
                    $a = $names[$i];
                    $b = $names[$j];
                    $agree = $clubs[$a] === $clubs[$b] ? 1 : 0;

                    $pairs[$a][$b] ??= ['wspolnych' => 0, 'zgodnych' => 0, 'wg_kategorii' => []];
                    $pairs[$a][$b]['wspolnych']++;
                    $pairs[$a][$b]['zgodnych'] += $agree;

                    $pairs[$a][$b]['wg_kategorii'][$category] ??= ['wspolnych' => 0, 'zgodnych' => 0];
                    $pairs[$a][$b]['wg_kategorii'][$category]['wspolnych']++;
                    $pairs[$a][$b]['wg_kategorii'][$category]['zgodnych'] += $agree;
                }
            }
        }

        arsort($clubVotings);
        $ranked = array_keys(array_filter($clubVotings, static fn (int $n): bool => $n >= self::MIN_SHARED));

        $out = [];
        foreach ($pairs as $a => $others) {
            foreach ($others as $b => $stats) {
                if ($stats['wspolnych'] < self::MIN_SHARED) {
                    continue;
                }

                $byCategory = [];
                foreach ($stats['wg_kategorii'] as $category => $c) {
                    if ($c['wspolnych'] < self::MIN_SHARED) {
                        continue;
                    }

                    $byCategory[$category] = [
                        'wspolnych' => $c['wspolnych'],
                        'zgodnosc' => round($c['zgodnych'] / $c['wspolnych'], 4),
                    ];
                }

                $out[] = [
                    'a' => $a,
                    'b' => $b,
                    'wspolnych' => $stats['wspolnych'],
                    'zgodnych' => $stats['zgodnych'],
                    'zgodnosc' => round($stats['zgodnych'] / $stats['wspolnych'], 4),
                    'wg_kategorii' => $byCategory,
                ];
            }
        }

        usort($out, static fn (array $x, array $y): int => $y['zgodnosc'] <=> $x['zgodnosc']);

        arsort($categoryCounts);

        return [
            'glosowan' => count($lines),
            'min_wspolnych' => self::MIN_SHARED,
            'kluby' => $ranked,
            'pary' => $out,
            'kategorie' => array_map(
                static fn (string $name): array => ['nazwa' => $name, 'glosowan' => $categoryCounts[$name]],
                array_keys($categoryCounts),
            ),
        ];
    }

    /**
     * Linia kazdego klubu w kazdym glosowaniu. Niezrzeszeni odpadaja - nie sa
     * klubem i nie maja linii.
     *
     * @return array<string, array<string, string>> klucz "posiedzenie:numer" => klub => linia
     */
    private function clubLines(int $term): array
    {
        $sql = <<<'SQL'
            WITH counts AS (
                SELECT sitting, number, club, vote, COUNT(*) AS n
                FROM vote
                WHERE term = :term AND vote IN ('YES', 'NO', 'ABSTAIN')
                  AND club IS NOT NULL AND club NOT IN ('niez.', 'niezrz.', 'niezrzeszeni')
                GROUP BY sitting, number, club, vote
            ),
            line AS (
                SELECT sitting, number, club, vote AS linia,
                       SUM(n) OVER (PARTITION BY sitting, number, club) AS klub_n,
                       ROW_NUMBER() OVER (PARTITION BY sitting, number, club ORDER BY n DESC, vote) AS rn
                FROM counts
            )
            SELECT sitting, number, club, linia
            FROM line
            WHERE rn = 1 AND klub_n >= CAST(:minklub AS INTEGER)
            SQL;

        $out = [];
        foreach ($this->db->fetchAll($sql, ['term' => $term, 'minklub' => self::MIN_CLUB_VOTES]) as $row) {
            $out[$row['sitting'] . ':' . $row['number']][(string) $row['club']] = (string) $row['linia'];
        }

        // Glosowanie, w ktorym linie ma tylko jeden klub, nie mowi nic o zgodnosci.
        return array_filter($out, static fn (array $clubs): bool => count($clubs) > 1);
    }

    /**
     * @return array<string, string> klucz "posiedzenie:numer" => kategoria
     */
    private function categories(int $term): array
    {
        $out = [];
        foreach ($this->db->fetchAll('SELECT sitting, number, title, topic FROM voting WHERE term = :term', ['term' => $term]) as $row) {
            $out[$row['sitting'] . ':' . $row['number']] = VotingCategory::fromTitle(
                isset($row['title']) ? (string) $row['title'] : null,
                isset($row['topic']) ? (string) $row['topic'] : null,
            )->value;
        }

        return $out;
    }
}
