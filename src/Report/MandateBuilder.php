<?php

declare(strict_types=1);

namespace Milczenie\Report;

use Milczenie\Storage\Database;

/**
 * Rozklad mandatow: ile miejsc ma kazdy klub i jak sie to zmienialo.
 *
 * Sklad izby liczymy z GLOSOWAN, nie z rejestru poslow, i to samo dla kazdej
 * kadencji. Rejestr podaje stan biezacy, wiec dla kadencji zamknietej nie da
 * sie z niego odczytac, kto zasiadal na koniec - a bez tego nie byloby czego
 * porownywac miedzy kadencjami. Przy glosie zapisany jest klub, wiec ostatni
 * glos posla mowi, pod jakim szyldem konczyl.
 */
final class MandateBuilder
{
    /**
     * Okno, w ktorym posel musi byl glosowac, zeby liczyc go do skladu koncowego.
     *
     * Ostatni dzien posiedzenia nie wystarcza: kto byl wtedy nieobecny, zniknalby
     * ze skladu. Trzydziesci dni obejmuje zwykle dwa posiedzenia i daje 459-461
     * mandatow zamiast 460 - roznica bierze sie z mandatow, ktore zmienily rece
     * wlasnie w tym oknie, i jest raportowana, nie zaokraglana.
     */
    private const WINDOW_DAYS = 30;

    /** Konstytucyjny sklad izby - art. 96 ust. 1 Konstytucji. */
    public const SEATS = 460;

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param array<string, mixed> $report gotowy raport kadencji
     *
     * @return array<string, mixed>|null null, gdy dla kadencji nie pobrano glosowan
     */
    public function build(int $term, array $report): array|null
    {
        $last = $this->db->fetchRow(
            'SELECT MAX(date) AS d FROM voting WHERE term = :term',
            ['term' => $term],
        )['d'] ?? null;

        if ($last === null) {
            return null;
        }

        $rows = $this->db->fetchAll(
            <<<'SQL'
                WITH ostatni AS (
                    SELECT v.mp_id, v.club,
                           ROW_NUMBER() OVER (
                               PARTITION BY v.mp_id
                               ORDER BY o.date DESC, o.sitting DESC, o.number DESC
                           ) AS rn
                    FROM vote v
                    JOIN voting o ON o.term = v.term AND o.sitting = v.sitting AND o.number = v.number
                    WHERE v.term = :term AND o.date >= date(:last, :window)
                )
                SELECT club, COUNT(*) AS n
                FROM ostatni
                WHERE rn = 1 AND club IS NOT NULL
                GROUP BY club
                ORDER BY n DESC, club
                SQL,
            ['term' => $term, 'last' => $last, 'window' => '-' . self::WINDOW_DAYS . ' day'],
        );

        $seats = array_sum(array_map(static fn (array $r): int => (int) $r['n'], $rows));

        $clubs = array_map(
            static fn (array $r): array => [
                'klub' => (string) $r['club'],
                'n' => (int) $r['n'],
                'udzial' => $seats > 0 ? round((int) $r['n'] / $seats, 4) : 0.0,
            ],
            $rows,
        );

        $transfers = $report['dyscyplina']['transfery'] ?? [];

        return [
            'kluby' => $clubs,
            // Salda licza sie z przeplywow, a nie z porownania skladu poczatkowego
            // z koncowym: mandat, ktory wygasl i zostal obsadzony, przesuwa liczby
            // klubu bez zadnego transferu i mieszalby sie z przenosinami poslow.
            'saldo' => $this->balance($transfers['przeplywy'] ?? []),
            'przeplywy' => array_values(array_filter(
                $transfers['przeplywy'] ?? [],
                static fn (array $t): bool => !$t['zmiana_szyldu'],
            )),
            'zmiany_szyldu' => $transfers['zmiany_szyldu'] ?? [],
            'meta' => [
                'data' => (string) $last,
                'okno_dni' => self::WINDOW_DAYS,
                'mandatow' => $seats,
                'konstytucyjnie' => self::SEATS,
                // Polowa skladu plus jeden. To linia UMOWNA, nie prog ustawowy:
                // Konstytucja wymaga wiekszosci glosow oddanych przy kworum polowy
                // skladu, a nie 231 mandatow. Strona musi to mowic wprost.
                'wiekszosc' => intdiv(self::SEATS, 2) + 1,
                'zamknieta' => $report['meta']['zamknieta'] ?? false,
                'transferow' => $transfers['poslow'] ?? 0,
            ],
        ];
    }

    /**
     * @param list<array{z: string, do: string, n: int, zmiana_szyldu: bool}> $flows
     *
     * @return list<array{klub: string, przyszlo: int, odeszlo: int, saldo: int}>
     */
    private function balance(array $flows): array
    {
        $acc = [];
        foreach ($flows as $flow) {
            if ($flow['zmiana_szyldu']) {
                continue;
            }

            $acc[$flow['z']]['odeszlo'] = ($acc[$flow['z']]['odeszlo'] ?? 0) + $flow['n'];
            $acc[$flow['do']]['przyszlo'] = ($acc[$flow['do']]['przyszlo'] ?? 0) + $flow['n'];
        }

        $out = [];
        foreach ($acc as $club => $a) {
            $in = $a['przyszlo'] ?? 0;
            $outgoing = $a['odeszlo'] ?? 0;
            $out[] = ['klub' => (string) $club, 'przyszlo' => $in, 'odeszlo' => $outgoing, 'saldo' => $in - $outgoing];
        }

        usort($out, static fn (array $a, array $b): int => [$b['saldo'], $a['klub']] <=> [$a['saldo'], $b['klub']]);

        return $out;
    }
}
