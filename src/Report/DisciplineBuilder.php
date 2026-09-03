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
     * Odroznia zmiane nazwy klubu od przenosin poslow.
     *
     * Regula jest waska i sprawdzalna: przemianowanie przenosi CALY klub JEDNEGO
     * dnia. Wymagamy wiec obu warunkow naraz - co najmniej 90% ruchu na tej samej
     * granicy dat i objecie co najmniej 90% wszystkich, ktorzy kiedykolwiek pod
     * stara nazwa glosowali. Odejscie 40 poslow PiS do RozwojPlus to 19% klubu,
     * wiec zostaje transferem; PSL -> PSL-TD to 32 z 32 jednego dnia i nie jest.
     * Trzeci warunek - nazwa docelowa nie moze istniec wczesniej - odsiewa
     * wchloniecia malych kol przez kluby, ktore juz byly.
     *
     * @param array{n: int, granice: array<string, int>} $flow
     *
     * @return array<string, mixed>
     */
    private function classifyFlow(
        string $from,
        string $to,
        array $flow,
        int $sourceSize,
        string $targetFirstSeen,
    ): array {
        $boundaries = $flow['granice'];
        arsort($boundaries);
        $topBoundary = (string) array_key_first($boundaries);
        $onSameDay = $boundaries[$topBoundary];

        $arrival = explode('/', $topBoundary)[1];

        $rename = $sourceSize > 0
            && $onSameDay >= 0.9 * $flow['n']
            && $flow['n'] >= 0.9 * $sourceSize
            // Nazwa docelowa musi byc nowa. Bez tego wchloniecie malego kola przez
            // istniejacy klub wyglada jak przemianowanie: cztery osoby z ID weszly
            // do PSL, co jest calym ID, ale PSL istnial wczesniej i istnial dalej.
            && $targetFirstSeen !== '' && $targetFirstSeen >= $arrival;

        return [
            'z' => $from,
            'do' => $to,
            'n' => $flow['n'],
            'zmiana_szyldu' => $rename,
            'data' => $rename ? $arrival : null,
        ];
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

        // Lista jest przycieta do czolowki, ale liczba klubow per posel nie moze byc:
        // sklad izby zaznacza przy nazwisku, ze posel zmienil barwy, i musi to wiedziec
        // o kazdym, a nie tylko o czterdziestu. Bez tego dwie strony podawalyby dwie
        // rozne liczby transferow - 144 na jednej i 131 na drugiej.
        $spellsPerMember = array_map(static fn (array $spells): int => count($spells), $byMember);

        // Przeplywy miedzy klubami z KOMPLETU zmian, nie z przycietej listy: strona
        // rozkladu mandatow pokazuje, kto komu ubyl i przybyl, a suma sald musi
        // wychodzic na zero. Z czterdziestu najruchliwszych poslow nie wyszlaby.
        $flows = [];
        $size = [];
        $firstSeen = [];
        foreach ($byMember as $spells) {
            foreach ($spells as $spell) {
                $size[$spell['klub']] = ($size[$spell['klub']] ?? 0) + 1;
                $firstSeen[$spell['klub']] = min($firstSeen[$spell['klub']] ?? $spell['od'], $spell['od']);
            }

            for ($i = 1, $n = count($spells); $i < $n; ++$i) {
                $key = $spells[$i - 1]['klub'] . "\0" . $spells[$i]['klub'];
                $flows[$key]['n'] = ($flows[$key]['n'] ?? 0) + 1;
                $boundary = $spells[$i - 1]['do'] . '/' . $spells[$i]['od'];
                $flows[$key]['granice'][$boundary] = ($flows[$key]['granice'][$boundary] ?? 0) + 1;
            }
        }

        $transitions = [];
        foreach ($flows as $key => $flow) {
            [$from, $to] = explode("\0", (string) $key, 2);
            $transitions[] = $this->classifyFlow($from, $to, $flow, $size[$from] ?? 0, $firstSeen[$to] ?? '');
        }

        usort($transitions, static fn (array $a, array $b): int => [$b['n'], $a['z']] <=> [$a['n'], $b['z']]);

        // Zmiana nazwy klubu wyglada w danych dokladnie jak masowy transfer, bo API
        // zapisuje przy glosie nazwe, nie tozsamosc klubu. Bez tego rozdzielenia
        // kadencja X mialaby 144 "przenosiny", z czego 91 to samo przemianowanie
        // PSL na PSL-TD, Nowej Lewicy na Lewice i Polski 2050 na Polske 2050-TD.
        $renamed = [];
        foreach ($transitions as $t) {
            if ($t['zmiana_szyldu']) {
                $renamed[$t['z'] . "\0" . $t['do']] = true;
            }
        }

        $realMovers = array_values(array_filter($movers, function (array $m) use ($renamed): bool {
            $spells = $m['okresy'];
            for ($i = 1, $n = count($spells); $i < $n; ++$i) {
                if (!isset($renamed[$spells[$i - 1]['klub'] . "\0" . $spells[$i]['klub']])) {
                    return true;
                }
            }

            return false;
        }));

        return [
            'poslow' => count($realMovers),
            'wszystkich' => count($byMember),
            'udzial' => $byMember === [] ? 0.0 : round(count($realMovers) / count($byMember), 4),
            'lista' => array_slice($realMovers, 0, 40),
            'klubow_per_posel' => $spellsPerMember,
            'przeplywy' => $transitions,
            'zmiany_szyldu' => array_values(array_filter(
                $transitions,
                static fn (array $t): bool => $t['zmiana_szyldu'],
            )),
            'z_przemianowaniami' => count($movers),
        ];
    }
}
