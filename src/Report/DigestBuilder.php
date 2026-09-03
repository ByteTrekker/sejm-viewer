<?php

declare(strict_types=1);

namespace Milczenie\Report;

use Milczenie\Storage\Database;

/**
 * Przykladowe raporty okresowe - po jednym na kazdy sensowny rytm.
 *
 * To NIE jest mechanizm cykliczny: nic sie samo nie uruchamia i nic nie jest
 * nigdzie wysylane. To pokaz tego, co da sie z danych zebrac w danym okresie,
 * zeby bylo widac, czy taki raport ma sens, zanim powstanie automatyzacja.
 *
 * Okresy licza sie WSTECZ OD NAJNOWSZEJ DANEJ, nie od dzisiaj - inaczej raport
 * "z ostatnich siedmiu dni" bylby pusty za kazdym razem, gdy Sejm nie obradowal.
 */
final class DigestBuilder
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Sklada gotowy akapit z policzonych liczb.
     *
     * Raport zlozony z samych wypunktowan wyglada jak zrzut z bazy. Zeby bylo widac,
     * czy taki raport nadaje sie do publikacji, kazdy dostaje tekst, ktory da sie
     * przeczytac na glos - i ktory nie mowi nic ponad to, co policzone.
     *
     * @param list<string> $parts
     */
    private function paragraph(array $parts): string
    {
        return implode(' ', array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    private function pct(float $value, int $decimals = 1): string
    {
        return str_replace('.', ',', number_format(100 * $value, $decimals)) . '%';
    }

    private function num(int $value): string
    {
        return number_format($value, 0, ',', ' ');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function build(int $term): array
    {
        return array_values(array_filter([
            $this->afterSitting($term),
            $this->weekly($term),
            $this->monthly($term),
            $this->quarterly($term),
        ]));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function afterSitting(int $term): array|null
    {
        $last = $this->db->fetchRow(
            'SELECT sitting, MAX(date) AS date FROM voting WHERE term = :term GROUP BY sitting ORDER BY date DESC LIMIT 1',
            ['term' => $term],
        );

        if ($last === null || $last['date'] === null) {
            return null;
        }

        $sitting = (int) $last['sitting'];
        $votings = (int) ($this->db->fetchRow(
            'SELECT COUNT(*) AS n FROM voting WHERE term = :term AND sitting = :sitting',
            ['term' => $term, 'sitting' => $sitting],
        )['n'] ?? 0);

        $absent = $this->db->fetchAll(
            <<<'SQL'
                SELECT v.mp_id,
                       (SELECT m.name FROM mp m WHERE m.id = v.mp_id AND m.term = v.term) AS nazwa,
                       v.club AS klub,
                       COUNT(*) AS glosowan,
                       SUM(CASE WHEN v.vote = 'ABSENT' AND COALESCE(a.excused, 0) = 0 THEN 1 ELSE 0 END) AS nieuspr
                FROM vote v
                JOIN voting o ON o.term = v.term AND o.sitting = v.sitting AND o.number = v.number
                LEFT JOIN mp_attendance a
                       ON a.term = v.term AND a.mp_id = v.mp_id AND a.sitting = v.sitting AND a.date = o.date
                WHERE v.term = :term AND v.sitting = :sitting
                GROUP BY v.mp_id
                HAVING nieuspr > 0
                ORDER BY nieuspr DESC
                LIMIT 5
                SQL,
            ['term' => $term, 'sitting' => $sitting],
        );

        $close = $this->db->fetchAll(
            <<<'SQL'
                SELECT number, title, date,
                       (SELECT COUNT(*) FROM vote v WHERE v.term = o.term AND v.sitting = o.sitting AND v.number = o.number AND v.vote = 'YES') AS za,
                       (SELECT COUNT(*) FROM vote v WHERE v.term = o.term AND v.sitting = o.sitting AND v.number = o.number AND v.vote = 'NO') AS przeciw
                FROM voting o
                WHERE o.term = :term AND o.sitting = :sitting
                  -- Bez tego warunku "najciasniejszym" glosowaniem zostaje takie,
                  -- w ktorym nikt nie glosowal ani za, ani przeciw - roznica zero.
                  AND za > 0 AND przeciw > 0
                ORDER BY ABS(za - przeciw) ASC, za DESC
                LIMIT 3
                SQL,
            ['term' => $term, 'sitting' => $sitting],
        );

        $unexcused = (int) ($this->db->fetchRow(
            <<<'SQL'
                SELECT SUM(CASE WHEN v.vote = 'ABSENT' AND COALESCE(a.excused, 0) = 0 THEN 1 ELSE 0 END) AS n
                FROM vote v
                JOIN voting o ON o.term = v.term AND o.sitting = v.sitting AND o.number = v.number
                LEFT JOIN mp_attendance a
                       ON a.term = v.term AND a.mp_id = v.mp_id AND a.sitting = v.sitting AND a.date = o.date
                WHERE v.term = :term AND v.sitting = :sitting
                SQL,
            ['term' => $term, 'sitting' => $sitting],
        )['n'] ?? 0);

        $closest = $close[0] ?? null;

        return [
            'rytm' => 'Po posiedzeniu',
            'okres' => sprintf('Posiedzenie nr %d, ostatni dzień %s', $sitting, (string) $last['date']),
            'wstep' => sprintf('%d głosowań imiennych.', $votings),
            'akapit' => $this->paragraph([
                sprintf(
                    'Na %d. posiedzeniu Sejmu odbyło się %s głosowań imiennych.',
                    $sitting,
                    $this->num($votings),
                ),
                $unexcused > 0
                    ? sprintf('Posłowie opuścili je %s razy bez usprawiedliwienia.', $this->num($unexcused))
                    : 'Wszystkie nieobecności były usprawiedliwione.',
                $closest !== null && abs((int) $closest['za'] - (int) $closest['przeciw']) <= 20
                    ? sprintf(
                        'Najciaśniejsze głosowanie rozstrzygnęło się stosunkiem %d do %d.',
                        (int) $closest['za'],
                        (int) $closest['przeciw'],
                    )
                    : 'Żadne głosowanie nie było rozstrzygnięte różnicą mniejszą niż dwadzieścia głosów.',
            ]),
            'punkty' => [
                [
                    'naglowek' => 'Najwięcej nieusprawiedliwionych nieobecności',
                    'pozycje' => array_map(
                        static fn (array $r): string => sprintf(
                            '%s (%s) — %d z %d głosowań',
                            $r['nazwa'] ?? ('poseł #' . $r['mp_id']),
                            $r['klub'] ?? 'brak klubu',
                            (int) $r['nieuspr'],
                            (int) $r['glosowan'],
                        ),
                        $absent,
                    ),
                ],
                [
                    'naglowek' => 'Najciaśniejsze głosowania',
                    'pozycje' => array_map(
                        static fn (array $r): string => sprintf(
                            '%d : %d — %s',
                            (int) $r['za'],
                            (int) $r['przeciw'],
                            mb_substr((string) $r['title'], 0, 90),
                        ),
                        $close,
                    ),
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function weekly(int $term): array|null
    {
        $latest = $this->db->fetchRow('SELECT MAX(sent_date) AS d FROM addressee')['d'] ?? null;
        if ($latest === null) {
            return null;
        }

        $to = new \DateTimeImmutable((string) $latest);
        $from = $to->modify('-7 days');

        $overdue = $this->db->fetchAll(
            <<<'SQL'
                SELECT q.kind, q.num, q.title, a.recipient_raw AS adresat, a.sent_date
                FROM question q
                JOIN addressee a ON a.question_id = q.id
                WHERE q.term = :term
                  AND a.sent_date BETWEEN :from AND :to
                  AND NOT EXISTS (SELECT 1 FROM reply r WHERE r.question_id = q.id)
                ORDER BY a.sent_date
                LIMIT 5
                SQL,
            ['term' => $term, 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')],
        );

        $fast = $this->db->fetchAll(
            <<<'SQL'
                SELECT title, display_address, promulgation, entry_into_force,
                       CAST(julianday(entry_into_force) - julianday(promulgation) AS INTEGER) AS dni
                FROM act
                WHERE type = 'Rozporządzenie' AND promulgation IS NOT NULL AND entry_into_force IS NOT NULL
                ORDER BY promulgation DESC
                LIMIT 40
                SQL,
        );
        $fast = array_slice(array_filter($fast, static fn (array $a): bool => (int) $a['dni'] <= 1), 0, 4);

        $przekazanych = (int) ($this->db->fetchRow(
            'SELECT COUNT(*) AS n FROM question q JOIN addressee a ON a.question_id = q.id
             WHERE q.term = :term AND a.sent_date BETWEEN :from AND :to',
            ['term' => $term, 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')],
        )['n'] ?? 0);

        return [
            'rytm' => 'Tygodniowy',
            'okres' => sprintf('%s – %s', $from->format('Y-m-d'), $to->format('Y-m-d')),
            'wstep' => 'Pytania przekazane w tym tygodniu, na które nie ma jeszcze odpowiedzi, oraz najświeższe rozporządzenia bez vacatio legis.',
            'akapit' => $this->paragraph([
                sprintf(
                    'W tym tygodniu Kancelaria Sejmu przekazała adresatom %s pytań.',
                    $this->num($przekazanych),
                ),
                sprintf('Ustawowy termin na odpowiedź to 21 dni, więc rozliczymy je za trzy tygodnie.'),
                $fast !== []
                    ? sprintf(
                        'W tym samym czasie %s rozporządzeń weszło w życie z dnia na dzień, bez okresu na dostosowanie.',
                        $this->num(count($fast)),
                    )
                    : 'Żadne rozporządzenie nie weszło w życie z dnia na dzień.',
            ]),
            'punkty' => [
                [
                    'naglowek' => 'Przekazane w tym tygodniu, wciąż bez odpowiedzi',
                    'pozycje' => array_map(
                        static fn (array $r): string => sprintf(
                            '%s nr %d do: %s — %s',
                            $r['kind'],
                            (int) $r['num'],
                            (string) $r['adresat'],
                            mb_substr((string) $r['title'], 0, 80),
                        ),
                        $overdue,
                    ),
                ],
                [
                    'naglowek' => 'Rozporządzenia wchodzące w życie z dnia na dzień',
                    'pozycje' => array_map(
                        static fn (array $r): string => sprintf(
                            '%s (%s) — ogłoszone %s, obowiązuje od %s',
                            mb_substr((string) $r['title'], 0, 80),
                            (string) $r['display_address'],
                            (string) $r['promulgation'],
                            (string) $r['entry_into_force'],
                        ),
                        $fast,
                    ),
                ],
            ],
        ];
    }

    /**
     * Kwartal to najkrotszy okres, w ktorym odsetki dyscypliny i zgodnosci klubow
     * przestaja skakac przy kazdym posiedzeniu.
     *
     * @return array<string, mixed>|null
     */
    private function quarterly(int $term): array|null
    {
        $discipline = (new DisciplineBuilder($this->db))->build($term);
        $coalition = (new CoalitionBuilder($this->db))->build($term);

        if ($discipline === null || $coalition === null) {
            return null;
        }

        $ranked = array_values(array_filter($discipline['kluby'], static fn (array $c): bool => $c['w_rankingu']));
        $najmniejSpojny = $ranked[0] ?? null;
        $najbardziej = $ranked === [] ? null : $ranked[count($ranked) - 1];
        $najblizsza = $coalition['pary'][0] ?? null;
        $najdalsza = $coalition['pary'] === [] ? null : $coalition['pary'][count($coalition['pary']) - 1];

        return [
            'rytm' => 'Kwartalny',
            'okres' => sprintf('Cała kadencja %s, stan bieżący', $term),
            'wstep' => sprintf('%s głosowań imiennych z ustaloną linią klubową.', $this->num($coalition['glosowan'])),
            'akapit' => $this->paragraph([
                $najmniejSpojny !== null && $najbardziej !== null
                    ? sprintf(
                        'Najmniej spójnym klubem pozostaje %s — %s oddanych głosów było niezgodnych z linią własnego klubu, wobec %s w najbardziej zdyscyplinowanym klubie %s.',
                        (string) $najmniejSpojny['klucz'],
                        $this->pct((float) $najmniejSpojny['udzial_wbrew'], 2),
                        $this->pct((float) $najbardziej['udzial_wbrew'], 2),
                        (string) $najbardziej['klucz'],
                    )
                    : '',
                $najblizsza !== null
                    ? sprintf(
                        'Najczęściej razem głosują %s i %s — ta sama linia w %s głosowań.',
                        (string) $najblizsza['a'],
                        (string) $najblizsza['b'],
                        $this->pct((float) $najblizsza['zgodnosc']),
                    )
                    : '',
                $najdalsza !== null
                    ? sprintf(
                        'Najrzadziej — %s i %s, %s.',
                        (string) $najdalsza['a'],
                        (string) $najdalsza['b'],
                        $this->pct((float) $najdalsza['zgodnosc']),
                    )
                    : '',
                sprintf('Klub zmieniło dotąd %s posłów.', $this->num((int) $discipline['transfery']['poslow'])),
            ]),
            'punkty' => [
                [
                    'naglowek' => 'Kluby najczęściej głosujące niezgodnie z własną linią',
                    'pozycje' => array_map(
                        fn (array $c): string => sprintf(
                            '%s — %s głosów wbrew linii, %s głosowań jednomyślnych',
                            (string) $c['klucz'],
                            $this->pct((float) $c['udzial_wbrew'], 2),
                            $this->pct((float) $c['udzial_jednomyslnych'], 0),
                        ),
                        array_slice($ranked, 0, 5),
                    ),
                ],
                [
                    'naglowek' => 'Pary klubów najczęściej głosujące tak samo',
                    'pozycje' => array_map(
                        fn (array $p): string => sprintf(
                            '%s + %s — %s zgodności w %s wspólnych głosowaniach',
                            (string) $p['a'],
                            (string) $p['b'],
                            $this->pct((float) $p['zgodnosc']),
                            $this->num((int) $p['wspolnych']),
                        ),
                        array_slice($coalition['pary'], 0, 5),
                    ),
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function monthly(int $term): array|null
    {
        $latest = $this->db->fetchRow('SELECT MAX(sent_date) AS d FROM addressee')['d'] ?? null;
        if ($latest === null) {
            return null;
        }

        $to = new \DateTimeImmutable((string) $latest);

        return $this->forPeriod($term, $to->modify('-30 days'), $to, 'Miesięczny');
    }

    /**
     * Archiwum: po jednym raporcie na kazdy miesiac kadencji.
     *
     * Raport sprzed roku wyglada INACZEJ, niz wygladalby wtedy - czesc odpowiedzi
     * przyszla pozniej i dzis widzimy je jako udzielone. To nie jest zapis tego,
     * co bylo wiadomo w danym miesiacu, tylko dzisiejszy widok na tamten okres,
     * i strona musi to mowic wprost.
     *
     * @return list<array<string, mixed>>
     */
    public function archive(int $term): array
    {
        $bounds = $this->db->fetchRow(
            'SELECT MIN(a.sent_date) AS od, MAX(a.sent_date) AS do
             FROM question q JOIN addressee a ON a.question_id = q.id WHERE q.term = :term',
            ['term' => $term],
        );

        if ($bounds === null || $bounds['od'] === null) {
            return [];
        }

        $cursor = (new \DateTimeImmutable((string) $bounds['od']))->modify('first day of this month');
        $last = (new \DateTimeImmutable((string) $bounds['do']))->modify('first day of this month');

        $out = [];
        while ($cursor <= $last) {
            $out[] = $this->forPeriod($term, $cursor, $cursor->modify('last day of this month'), $cursor->format('Y-m'));
            $cursor = $cursor->modify('+1 month');
        }

        return array_reverse($out);
    }

    /**
     * @return array<string, mixed>
     */
    private function forPeriod(int $term, \DateTimeImmutable $from, \DateTimeImmutable $to, string $rytm): array
    {

        $silent = $this->db->fetchAll(
            <<<'SQL'
                SELECT a.recipient_raw AS adresat, COUNT(*) AS bez_odpowiedzi
                FROM question q
                JOIN addressee a ON a.question_id = q.id
                WHERE q.term = :term
                  AND a.sent_date BETWEEN :from AND :to
                  AND NOT EXISTS (SELECT 1 FROM reply r WHERE r.question_id = q.id)
                GROUP BY a.recipient_raw
                ORDER BY bez_odpowiedzi DESC
                LIMIT 5
                SQL,
            ['term' => $term, 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')],
        );

        $sent = (int) ($this->db->fetchRow(
            'SELECT COUNT(*) AS n FROM question q JOIN addressee a ON a.question_id = q.id
             WHERE q.term = :term AND a.sent_date BETWEEN :from AND :to',
            ['term' => $term, 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')],
        )['n'] ?? 0);

        $bezOdpowiedzi = array_sum(array_map(static fn (array $r): int => (int) $r['bez_odpowiedzi'], $silent));
        $lider = $silent[0] ?? null;

        return [
            'rytm' => $rytm,
            'okres' => sprintf('%s – %s', $from->format('Y-m-d'), $to->format('Y-m-d')),
            'wstep' => sprintf('%d pytań przekazanych adresatom w tym okresie.', $sent),
            'akapit' => $this->paragraph([
                sprintf(
                    'W okresie %s – %s posłowie skierowali do rządu %s pytań.',
                    $from->format('Y-m-d'),
                    $to->format('Y-m-d'),
                    $this->num($sent),
                ),
                $lider !== null
                    ? sprintf(
                        'Najwięcej z nich, bo %s, czeka wciąż na odpowiedź od resortu: %s.',
                        $this->num((int) $lider['bez_odpowiedzi']),
                        (string) $lider['adresat'],
                    )
                    : 'Każde z nich doczekało się odpowiedzi.',
                $bezOdpowiedzi > 0
                    ? sprintf(
                        'W pięciu najbardziej milczących resortach leży razem %s pytań bez odpowiedzi.',
                        $this->num($bezOdpowiedzi),
                    )
                    : '',
            ]),
            'punkty' => [
                [
                    'naglowek' => 'Najwięcej pytań wciąż bez odpowiedzi',
                    'pozycje' => array_map(
                        static fn (array $r): string => sprintf('%s — %d pytań', (string) $r['adresat'], (int) $r['bez_odpowiedzi']),
                        $silent,
                    ),
                ],
            ],
        ];
    }
}
