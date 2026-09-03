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
     * @return list<array<string, mixed>>
     */
    public function build(int $term): array
    {
        return array_values(array_filter([
            $this->afterSitting($term),
            $this->weekly($term),
            $this->monthly($term),
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
                ORDER BY ABS(za - przeciw) ASC, za DESC
                LIMIT 3
                SQL,
            ['term' => $term, 'sitting' => $sitting],
        );

        return [
            'rytm' => 'Po posiedzeniu',
            'okres' => sprintf('Posiedzenie nr %d, ostatni dzień %s', $sitting, (string) $last['date']),
            'wstep' => sprintf('%d głosowań imiennych.', $votings),
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

        return [
            'rytm' => 'Tygodniowy',
            'okres' => sprintf('%s – %s', $from->format('Y-m-d'), $to->format('Y-m-d')),
            'wstep' => 'Pytania przekazane w tym tygodniu, na które nie ma jeszcze odpowiedzi, oraz najświeższe rozporządzenia bez vacatio legis.',
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
     * @return array<string, mixed>|null
     */
    private function monthly(int $term): array|null
    {
        $latest = $this->db->fetchRow('SELECT MAX(sent_date) AS d FROM addressee')['d'] ?? null;
        if ($latest === null) {
            return null;
        }

        $to = new \DateTimeImmutable((string) $latest);
        $from = $to->modify('-30 days');

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

        return [
            'rytm' => 'Miesięczny',
            'okres' => sprintf('%s – %s', $from->format('Y-m-d'), $to->format('Y-m-d')),
            'wstep' => sprintf('%d pytań przekazanych adresatom w tym okresie.', $sent),
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
