<?php

declare(strict_types=1);

namespace Milczenie\Report;

use Milczenie\Storage\Database;

/**
 * Sklad izby: wszyscy poslowie kadencji w jednej przeszukiwalnej liscie.
 *
 * Strona "Poslowie" odpowiada na pytanie, KTO PYTA, wiec z zalozenia pomija
 * tych, ktorzy nie zadali ani jednego pytania, i sortuje po aktywnosci. Skladu
 * izby z niej nie odczytasz. Ta lista jest odwrotna: kompletna i obojetna na
 * to, czy posel cokolwiek zrobil - punktem wyjscia jest mandat, nie dorobek.
 *
 * Liczby przy nazwiskach pochodzą z gotowych raportow, a nie z wlasnych zapytan.
 * Gdyby lista liczyla je po swojemu, dwa miejsca w serwisie moglyby podac dla
 * tego samego posla dwie rozne frekwencje.
 */
final class RosterBuilder
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param array<string, mixed> $report gotowy raport kadencji
     *
     * @return array<string, mixed>
     */
    public function build(int $term, array $report, bool $closed): array
    {
        $questions = $this->byId($report['poslowie'] ?? []);
        $absence = $this->byId($report['nieobecnosci']['poslowie'] ?? []);
        $discipline = $this->disciplineById($report['dyscyplina']['poslowie'] ?? []);
        /** @var array<int, int> $spells liczba klubow per posel - z raportu dyscypliny */
        $spells = $report['dyscyplina']['transfery']['klubow_per_posel'] ?? [];

        $roster = [];
        $clubs = [];
        $districts = [];

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $this->db->fetchAll(
            'SELECT id, name, club, district, active FROM mp WHERE term = :term ORDER BY name',
            ['term' => $term],
        );

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $club = ($row['club'] ?? '') !== '' ? (string) $row['club'] : null;
            $district = ($row['district'] ?? '') !== '' ? (string) $row['district'] : null;

            $q = $questions[$id] ?? null;
            $a = $absence[$id] ?? null;
            $d = $discipline[$id] ?? null;

            $roster[] = [
                'id' => $id,
                'nazwa' => (string) $row['name'],
                'klub' => $club,
                'okreg' => $district,
                // Flaga ma sens wylacznie w kadencji trwajacej. Po jej koncu API
                // oznacza jako nieaktywnych WSZYSTKICH, wiec pokazanie "mandat
                // wygasl" przy 517 poslach kadencji VII byloby falszem.
                'aktywny' => $closed ? null : (int) $row['active'] === 1,
                'pytan' => $q === null ? 0 : (int) $q['pytan'],
                'tematow' => $q === null ? 0 : (int) $q['tematow'],
                'udzial_nieobecnosci' => $a['udzial_nieobecnosci'] ?? null,
                'udzial_nieusprawiedliwionych' => $a['udzial_nieusprawiedliwionych'] ?? null,
                'glosowan' => $a === null ? null : (int) $a['glosowan'],
                'udzial_wbrew' => $d['udzial_wbrew'] ?? null,
                'klubow' => $spells[$id] ?? ($club === null ? 0 : 1),
            ];

            if ($club !== null) {
                $clubs[$club] = ($clubs[$club] ?? 0) + 1;
            }

            if ($district !== null) {
                $districts[$district] = ($districts[$district] ?? 0) + 1;
            }
        }

        arsort($clubs);
        ksort($districts);

        $current = array_values(array_filter(
            $roster,
            static fn (array $m): bool => $m['aktywny'] !== false,
        ));

        return [
            'poslowie' => $roster,
            'kluby' => $this->pairs($clubs),
            'okregi' => $this->pairs($districts),
            'meta' => [
                'wszystkich' => count($roster),
                // W kadencji trwajacej sklad biezacy rozni sie od listy wszystkich,
                // ktorzy przez izbe przeszli - i ta roznica jest sama w sobie liczba
                // warta pokazania. W kadencji zamknietej obie sa tym samym.
                'w_skladzie' => $closed ? null : count($current),
                'wygaslych' => $closed ? null : count($roster) - count($current),
                'klubow' => count($clubs),
                'okregow' => count($districts),
                'zamknieta' => $closed,
                // Wprost z raportu dyscypliny, a nie z przeliczenia tej listy:
                // transfery liczy jedno miejsce w projekcie.
                'zmienilo_klub' => $report['dyscyplina']['transfery']['poslow'] ?? null,
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<int, array<string, mixed>>
     */
    private function byId(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (isset($row['id'])) {
                $out[(int) $row['id']] = $row;
            }
        }

        return $out;
    }

    /**
     * Posel, ktory zmienil klub, ma w raporcie dyscypliny wiersz na kazdy z nich.
     * Do listy skladu wchodzi wynik laczny, bo kolumna ma odpowiadac na pytanie
     * "jak czesto glosowal wbrew wlasnemu klubowi", niezaleznie od tego, ilu ich mial.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array<int, array{udzial_wbrew: float|null}>
     */
    private function disciplineById(array $rows): array
    {
        $acc = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $acc[$id] ??= ['wbrew' => 0, 'z_linia' => 0];
            $acc[$id]['wbrew'] += (int) $row['wbrew_linii'];
            $acc[$id]['z_linia'] += (int) $row['glosowan_z_linia'];
        }

        return array_map(
            static fn (array $a): array => [
                'udzial_wbrew' => $a['z_linia'] > 0 ? round($a['wbrew'] / $a['z_linia'], 4) : null,
            ],
            $acc,
        );
    }

    /**
     * @param array<string, int> $counts
     *
     * @return list<array{nazwa: string, n: int}>
     */
    private function pairs(array $counts): array
    {
        $out = [];
        foreach ($counts as $name => $n) {
            $out[] = ['nazwa' => (string) $name, 'n' => $n];
        }

        return $out;
    }
}
