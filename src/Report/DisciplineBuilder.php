<?php

declare(strict_types=1);

namespace Milczenie\Report;

use Milczenie\Storage\Database;

/**
 * Dyscyplina klubowa i transfery miedzy klubami - liczone wylacznie z glosow,
 * ktore juz sa w bazie.
 *
 * Zalozenia metodologiczne:
 *  1. Linia klubowa to najczestszy glos klubu w danym glosowaniu sposrod
 *     ZA / PRZECIW / WSTRZYMAL SIE. Nieobecnosc nie jest stanowiskiem, wiec nie
 *     wchodzi ani do linii, ani do mianownika - od tego jest osobny ranking.
 *  2. Linie ustalamy tylko dla klubow, ktore w danym glosowaniu oddaly co najmniej
 *     MIN_CLUB_VOTES glosow. Przy trzech glosujacych "wiekszosc" nic nie znaczy.
 *  3. Wiekszosc glosowan jest jednomyslna (procedura, wnioski formalne), wiec
 *     odsetki sa z natury niskie. Kolumna spojnosci klubu daje skale odniesienia.
 *  4. Klub jest zapisany przy kazdym glosie osobno, wiec zmiana klubu widoczna jest
 *     jako kolejny okres. Przerwa (np. status niezrzeszonego) jest osobnym okresem,
 *     a nie luka.
 */
final class DisciplineBuilder
{
    /** Ponizej tego progu "wiekszosc klubu" nic nie znaczy - przy piatce podzial 3:2 czyni buntownikami dwie osoby. */
    private const MIN_CLUB_VOTES = 10;

    private const MIN_SAMPLE = 100;

    /**
     * Klub, ktory istnial przez kilka posiedzen, nie moze byc "najbardziej
     * zdyscyplinowany" na podstawie kilkudziesieciu glosow. Prog dotyczy liczby
     * glosowan, w ktorych klub w ogole mial ustalona linie.
     */
    private const MIN_CLUB_VOTINGS = 500;

    /**
     * Niezrzeszeni nie sa klubem i nie maja linii - liczenie im dyscypliny to blad
     * kategorii, ktory w pierwszym przebiegu dal im 25% "buntow". Kolo tez odpada:
     * przy kilku osobach linia jest przypadkiem, nie stanowiskiem.
     */
    private const NOT_A_CLUB = ['niez.', 'niezrz.', 'niezrzeszeni'];

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array<string, mixed>|null null, gdy dla kadencji nie ma glosowan
     */
    public function build(int $term): ?array
    {
        $votings = (int) ($this->db->fetchRow('SELECT COUNT(*) AS n FROM voting WHERE term = :term', ['term' => $term])['n'] ?? 0);
        if ($votings === 0) {
            return null;
        }

        $members = $this->members($term);
        $clubs = $this->clubs($term);
        $transfers = $this->transfers($term);

        $withLine = array_sum(array_column($members, 'glosowan_z_linia'));
        $against = array_sum(array_column($members, 'wbrew_linii'));

        return [
            'glosowan' => $votings,
            'min_probka' => self::MIN_SAMPLE,
            'min_klub' => self::MIN_CLUB_VOTES,
            'min_glosowan_klubu' => self::MIN_CLUB_VOTINGS,
            'udzial_ogolem' => $withLine > 0 ? round($against / $withLine, 5) : 0.0,
            'poslowie' => $members,
            'kluby' => $clubs,
            'transfery' => $transfers,
        ];
    }

    /**
     * Zapytanie liczy linie klubowa raz na (glosowanie, klub) i dopiero wtedy
     * porownuje z nia pojedyncze glosy - inaczej 13 mln wierszy trzeba by
     * przeniesc do PHP-a.
     */
    private const LINE_SQL = <<<'SQL'
        WITH counts AS (
            SELECT sitting, number, club, vote, COUNT(*) AS n
            FROM vote
            WHERE term = :term AND vote IN ('YES', 'NO', 'ABSTAIN')
              AND club IS NOT NULL AND club NOT IN (:notAClub)
            GROUP BY sitting, number, club, vote
        ),
        line AS (
            SELECT sitting, number, club, vote AS linia, n,
                   SUM(n) OVER (PARTITION BY sitting, number, club) AS klub_n,
                   ROW_NUMBER() OVER (PARTITION BY sitting, number, club ORDER BY n DESC, vote) AS rn
            FROM counts
        ),
        ustalone AS (
            SELECT sitting, number, club, linia, klub_n, n AS zgodnych
            FROM line
            -- CAST jest konieczny: PDO wiaze parametry jako tekst, a klub_n to
            -- wyrazenie okienkowe bez powinowactwa typu. Bez rzutowania SQLite
            -- porownuje liczbe z tekstem, gdzie liczba zawsze przegrywa,
            -- i warunek jest zawsze falszywy. Kolumny ratuje affinity, wyrazenia nie.
            WHERE rn = 1 AND klub_n >= CAST(:minklub AS INTEGER)
        )
        SQL;

    /**
     * Lista wykluczonych "klubow" wstrzykiwana do SQL-a z jednego miejsca -
     * wartosci sa stalymi klasy, nie danymi z zewnatrz.
     */
    private function lineSql(): string
    {
        $quoted = implode(', ', array_map(static fn (string $c): string => "'" . $c . "'", self::NOT_A_CLUB));

        return str_replace(':notAClub', $quoted, self::LINE_SQL);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function members(int $term): array
    {
        $sql = $this->lineSql() . <<<'SQL'

            -- Grupujemy po (posel, klub), a nie po samym posle: poslowi, ktory zmienil
            -- klub, liczymy zgodnosc osobno w kazdym z nich. Inaczej bunty z jednego
            -- klubu obciazalyby konto drugiego.
            SELECT v.mp_id, v.club AS klub,
                   COUNT(*) AS glosowan_z_linia,
                   SUM(CASE WHEN v.vote <> u.linia THEN 1 ELSE 0 END) AS wbrew_linii,
                   (SELECT m.name FROM mp m WHERE m.id = v.mp_id AND m.term = v.term) AS nazwa
            FROM vote v
            JOIN ustalone u ON u.sitting = v.sitting AND u.number = v.number AND u.club = v.club
            WHERE v.term = :term AND v.vote IN ('YES', 'NO', 'ABSTAIN')
            GROUP BY v.mp_id, v.club
            SQL;

        $out = [];
        foreach ($this->db->fetchAll($sql, ['term' => $term, 'minklub' => self::MIN_CLUB_VOTES]) as $row) {
            $total = (int) $row['glosowan_z_linia'];
            $against = (int) $row['wbrew_linii'];

            $out[] = [
                'id' => (int) $row['mp_id'],
                'nazwa' => isset($row['nazwa']) ? (string) $row['nazwa'] : ('poseł #' . $row['mp_id']),
                'klub' => (string) $row['klub'],
                'glosowan_z_linia' => $total,
                'wbrew_linii' => $against,
                'udzial_wbrew' => $total > 0 ? round($against / $total, 5) : 0.0,
                'w_rankingu' => $total >= self::MIN_SAMPLE,
            ];
        }

        usort($out, static fn (array $a, array $b): int
            => [$b['w_rankingu'], $b['udzial_wbrew']] <=> [$a['w_rankingu'], $a['udzial_wbrew']]);

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function clubs(int $term): array
    {
        $sql = $this->lineSql() . <<<'SQL'

            SELECT club,
                   COUNT(*) AS glosowan,
                   SUM(CASE WHEN zgodnych = klub_n THEN 1 ELSE 0 END) AS jednomyslnych,
                   SUM(klub_n) AS glosow,
                   SUM(klub_n - zgodnych) AS wbrew
            FROM ustalone
            GROUP BY club
            SQL;

        $out = [];
        foreach ($this->db->fetchAll($sql, ['term' => $term, 'minklub' => self::MIN_CLUB_VOTES]) as $row) {
            $votings = (int) $row['glosowan'];
            $votes = (int) $row['glosow'];

            $out[] = [
                'w_rankingu' => $votings >= self::MIN_CLUB_VOTINGS,
                'klucz' => (string) $row['club'],
                'glosowan' => $votings,
                'jednomyslnych' => (int) $row['jednomyslnych'],
                'udzial_jednomyslnych' => $votings > 0 ? round((int) $row['jednomyslnych'] / $votings, 4) : 0.0,
                'glosow' => $votes,
                'wbrew' => (int) $row['wbrew'],
                'udzial_wbrew' => $votes > 0 ? round((int) $row['wbrew'] / $votes, 5) : 0.0,
            ];
        }

        // Kluby ponizej progu zostaja w zestawieniu, ale spadaja na koniec i nie moga
        // trafic do naglowka raportu jako "najbardziej zdyscyplinowane".
        usort($out, static fn (array $a, array $b): int
            => [$b['w_rankingu'], $b['udzial_wbrew']] <=> [$a['w_rankingu'], $a['udzial_wbrew']]);

        return $out;
    }

    /**
     * Okresy przynaleznosci klubowej: klub zapisany przy glosie plus pierwsza
     * i ostatnia data, kiedy posel glosowal pod tym szyldem.
     *
     * @return array<string, mixed>
     */
    private function transfers(int $term): array
    {
        $rows = $this->db->fetchAll(
            <<<'SQL'
                SELECT v.mp_id, v.club, MIN(o.date) AS od, MAX(o.date) AS do, COUNT(*) AS glosow,
                       (SELECT m.name FROM mp m WHERE m.id = v.mp_id AND m.term = v.term) AS nazwa
                FROM vote v
                JOIN voting o ON o.term = v.term AND o.sitting = v.sitting AND o.number = v.number
                WHERE v.term = :term AND v.club IS NOT NULL
                GROUP BY v.mp_id, v.club
                ORDER BY v.mp_id, od
                SQL,
            ['term' => $term],
        );

        $byMember = [];
        foreach ($rows as $row) {
            $byMember[(int) $row['mp_id']][] = [
                'klub' => (string) $row['club'],
                'od' => (string) $row['od'],
                'do' => (string) $row['do'],
                'glosow' => (int) $row['glosow'],
                'nazwa' => isset($row['nazwa']) ? (string) $row['nazwa'] : ('poseł #' . $row['mp_id']),
            ];
        }

        $movers = [];
        foreach ($byMember as $id => $spells) {
            if (count($spells) < 2) {
                continue;
            }

            $movers[] = [
                'id' => $id,
                'nazwa' => $spells[0]['nazwa'],
                'zmian' => count($spells) - 1,
                'okresy' => array_map(
                    static fn (array $s): array => ['klub' => $s['klub'], 'od' => $s['od'], 'do' => $s['do'], 'glosow' => $s['glosow']],
                    $spells,
                ),
            ];
        }

        usort($movers, static fn (array $a, array $b): int => [$b['zmian'], $a['nazwa']] <=> [$a['zmian'], $b['nazwa']]);

        return [
            'poslow' => count($movers),
            'wszystkich' => count($byMember),
            'udzial' => $byMember === [] ? 0.0 : round(count($movers) / count($byMember), 4),
            'lista' => array_slice($movers, 0, 40),
        ];
    }
}
