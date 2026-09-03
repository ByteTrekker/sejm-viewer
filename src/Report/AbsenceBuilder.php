<?php

declare(strict_types=1);

namespace Milczenie\Report;

use Milczenie\Storage\Database;

/**
 * Nieobecnosci w glosowaniach imiennych - lacznie i nieusprawiedliwione.
 *
 * ZAKRES TEGO, CO API MOWI, a czego nie:
 * Same glosowania podaja wylacznie fakt ("ABSENT"). Status usprawiedliwienia
 * pochodzi z osobnego zasobu (/MP/{id}/votings/stats, pole absenceExcuse) i jest
 * przypisany do CALEGO DNIA POSIEDZENIA, nie do pojedynczego glosowania.
 *
 * API nadal nie podaje POWODU usprawiedliwienia - delegacja, choroba i urlop
 * rodzicielski sa nierozroznialne. Usprawiedliwienie jest statusem formalnym
 * nadanym przez Kancelarie Sejmu, nie ocena zasadnosci.
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

        // Status usprawiedliwienia dotyczy dnia posiedzenia, wiec laczymy przez
        // posiedzenie i date glosowania. Brak wiersza we frekwencji traktujemy jak brak
        // usprawiedliwienia - i raportujemy osobno, ile takich przypadkow bylo.
        $rows = $this->db->fetchAll(
            <<<'SQL'
                SELECT v.mp_id,
                       COUNT(*) AS glosowan,
                       SUM(CASE WHEN v.vote = :absent THEN 1 ELSE 0 END) AS nieobecnosci,
                       SUM(CASE WHEN v.vote = :absent AND COALESCE(a.excused, 0) = 0 THEN 1 ELSE 0 END) AS nieusprawiedliwione,
                       SUM(CASE WHEN v.vote = :absent AND a.term IS NULL THEN 1 ELSE 0 END) AS bez_danych,
                       (SELECT m.name FROM mp m WHERE m.id = v.mp_id AND m.term = v.term) AS nazwa,
                       (SELECT m.club FROM mp m WHERE m.id = v.mp_id AND m.term = v.term) AS klub,
                       (SELECT m.district FROM mp m WHERE m.id = v.mp_id AND m.term = v.term) AS okreg
                FROM vote v
                JOIN voting o ON o.term = v.term AND o.sitting = v.sitting AND o.number = v.number
                -- Laczymy takze po numerze posiedzenia: dwa posiedzenia potrafia
                -- dzielic ten sam dzien, a wtedy sam klucz (posel, data) zwielokrotnia
                -- wiersze glosow i zawyza zarowno licznik, jak i mianownik.
                LEFT JOIN mp_attendance a
                       ON a.term = v.term AND a.mp_id = v.mp_id
                      AND a.sitting = v.sitting AND a.date = o.date
                WHERE v.term = :term
                GROUP BY v.mp_id
                SQL,
            ['term' => $term, 'absent' => self::ABSENT],
        );

        $members = [];
        $clubs = [];
        $noData = 0;

        foreach ($rows as $row) {
            $total = (int) $row['glosowan'];
            $absent = (int) $row['nieobecnosci'];
            $unexcused = (int) $row['nieusprawiedliwione'];
            $club = isset($row['klub']) ? (string) $row['klub'] : 'brak klubu';
            $noData += (int) $row['bez_danych'];

            $members[] = [
                'id' => (int) $row['mp_id'],
                'nazwa' => isset($row['nazwa']) ? (string) $row['nazwa'] : ('poseł #' . $row['mp_id']),
                'klub' => $club,
                'okreg' => isset($row['okreg']) ? (string) $row['okreg'] : null,
                'glosowan' => $total,
                'nieobecnosci' => $absent,
                'nieusprawiedliwione' => $unexcused,
                'udzial_nieobecnosci' => $total > 0 ? round($absent / $total, 4) : 0.0,
                'udzial_nieusprawiedliwionych' => $total > 0 ? round($unexcused / $total, 4) : 0.0,
                'udzial_uspr_wsrod_nieobecnosci' => $absent > 0 ? round(($absent - $unexcused) / $absent, 4) : null,
                'w_rankingu' => $total >= self::MIN_SAMPLE,
            ];

            $clubs[$club] ??= ['glosowan' => 0, 'nieobecnosci' => 0, 'nieusprawiedliwione' => 0, 'poslow' => 0];
            $clubs[$club]['glosowan'] += $total;
            $clubs[$club]['nieobecnosci'] += $absent;
            $clubs[$club]['nieusprawiedliwione'] += $unexcused;
            $clubs[$club]['poslow']++;
        }

        usort($members, static fn (array $a, array $b): int
            => [$b['w_rankingu'], $b['udzial_nieusprawiedliwionych']] <=> [$a['w_rankingu'], $a['udzial_nieusprawiedliwionych']]);

        $clubList = [];
        foreach ($clubs as $name => $c) {
            $clubList[] = [
                'klucz' => $name,
                'poslow' => $c['poslow'],
                'glosowan' => $c['glosowan'],
                'nieobecnosci' => $c['nieobecnosci'],
                'nieusprawiedliwione' => $c['nieusprawiedliwione'],
                'udzial_nieobecnosci' => $c['glosowan'] > 0 ? round($c['nieobecnosci'] / $c['glosowan'], 4) : 0.0,
                'udzial_nieusprawiedliwionych' => $c['glosowan'] > 0 ? round($c['nieusprawiedliwione'] / $c['glosowan'], 4) : 0.0,
            ];
        }
        usort($clubList, static fn (array $a, array $b): int => $b['udzial_nieusprawiedliwionych'] <=> $a['udzial_nieusprawiedliwionych']);

        $allVotes = array_sum(array_column($members, 'glosowan'));
        $allAbsent = array_sum(array_column($members, 'nieobecnosci'));
        $allUnexcused = array_sum(array_column($members, 'nieusprawiedliwione'));

        return [
            'glosowan' => $votings,
            'poslow' => count($members),
            'min_probka' => self::MIN_SAMPLE,
            'udzial_ogolem' => $allVotes > 0 ? round($allAbsent / $allVotes, 4) : 0.0,
            'udzial_nieusprawiedliwionych' => $allVotes > 0 ? round($allUnexcused / $allVotes, 4) : 0.0,
            'nieusprawiedliwionych' => $allUnexcused,
            'nieobecnosci' => $allAbsent,
            'bez_danych_o_usprawiedliwieniu' => $noData,
            'pobrano' => $this->db->getMeta('votings_fetched_at'),
            'poslowie' => $members,
            'kluby' => $clubList,
        ];
    }
}
