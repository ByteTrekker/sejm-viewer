<?php

declare(strict_types=1);

namespace Milczenie\Report;

use Milczenie\Storage\Database;

/**
 * Nieobecnosci w glosowaniach imiennych.
 *
 * OSTRZEZENIE METODOLOGICZNE, ktore musi isc razem z kazda liczba z tego raportu:
 * API podaje wylacznie fakt nieobecnosci, NIE podaje jej przyczyny. Delegacja
 * zagraniczna, zwolnienie lekarskie, urlop macierzynski i zwykla absencja wygladaja
 * w danych identycznie. Ten raport mierzy, ilu glosowan posel nie wzial udzialu -
 * i nic ponadto. Kazdy wniosek o przyczynach jest nadinterpretacja.
 *
 * Mianownik jest per posel: liczy sie tylko te glosowania, w ktorych posel
 * w ogole wystepuje na liscie - dzieki temu poslowie obejmujacy mandat w trakcie
 * kadencji nie sa karani za glosowania sprzed slubowania.
 */
final class AbsenceBuilder
{
    private const MIN_SAMPLE = 50;
    private const ABSENT = 'ABSENT';

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array<string, mixed>|null null, gdy dla kadencji nie ma pobranych glosowan
     */
    public function build(int $term): ?array
    {
        $votings = (int) ($this->db->fetchRow(
            'SELECT COUNT(*) AS n FROM voting WHERE term = :term',
            ['term' => $term],
        )['n'] ?? 0);

        if ($votings === 0) {
            return null;
        }

        $rows = $this->db->fetchAll(
            <<<'SQL'
                SELECT v.mp_id,
                       COUNT(*) AS glosowan,
                       SUM(CASE WHEN v.vote = :absent THEN 1 ELSE 0 END) AS nieobecnosci,
                       (SELECT m.name FROM mp m WHERE m.id = v.mp_id AND m.term = v.term) AS nazwa,
                       (SELECT m.club FROM mp m WHERE m.id = v.mp_id AND m.term = v.term) AS klub,
                       (SELECT m.district FROM mp m WHERE m.id = v.mp_id AND m.term = v.term) AS okreg
                FROM vote v
                WHERE v.term = :term
                GROUP BY v.mp_id
                SQL,
            ['term' => $term, 'absent' => self::ABSENT],
        );

        $members = [];
        $clubs = [];

        foreach ($rows as $row) {
            $total = (int) $row['glosowan'];
            $absent = (int) $row['nieobecnosci'];
            $club = isset($row['klub']) ? (string) $row['klub'] : 'brak klubu';

            $members[] = [
                'id' => (int) $row['mp_id'],
                'nazwa' => isset($row['nazwa']) ? (string) $row['nazwa'] : ('poseł #' . $row['mp_id']),
                'klub' => $club,
                'okreg' => isset($row['okreg']) ? (string) $row['okreg'] : null,
                'glosowan' => $total,
                'nieobecnosci' => $absent,
                'udzial_nieobecnosci' => $total > 0 ? round($absent / $total, 4) : 0.0,
                'w_rankingu' => $total >= self::MIN_SAMPLE,
            ];

            $clubs[$club] ??= ['glosowan' => 0, 'nieobecnosci' => 0, 'poslow' => 0];
            $clubs[$club]['glosowan'] += $total;
            $clubs[$club]['nieobecnosci'] += $absent;
            $clubs[$club]['poslow']++;
        }

        usort($members, static fn (array $a, array $b): int
            => [$b['w_rankingu'], $b['udzial_nieobecnosci']] <=> [$a['w_rankingu'], $a['udzial_nieobecnosci']]);

        $clubList = [];
        foreach ($clubs as $name => $c) {
            $clubList[] = [
                'klucz' => $name,
                'poslow' => $c['poslow'],
                'glosowan' => $c['glosowan'],
                'nieobecnosci' => $c['nieobecnosci'],
                'udzial_nieobecnosci' => $c['glosowan'] > 0 ? round($c['nieobecnosci'] / $c['glosowan'], 4) : 0.0,
            ];
        }
        usort($clubList, static fn (array $a, array $b): int => $b['udzial_nieobecnosci'] <=> $a['udzial_nieobecnosci']);

        $allVotes = array_sum(array_column($members, 'glosowan'));
        $allAbsent = array_sum(array_column($members, 'nieobecnosci'));

        return [
            'glosowan' => $votings,
            'poslow' => count($members),
            'min_probka' => self::MIN_SAMPLE,
            'udzial_ogolem' => $allVotes > 0 ? round($allAbsent / $allVotes, 4) : 0.0,
            'pobrano' => $this->db->getMeta('votings_fetched_at'),
            'poslowie' => $members,
            'kluby' => $clubList,
        ];
    }
}
