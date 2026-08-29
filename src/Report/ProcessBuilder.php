<?php

declare(strict_types=1);

namespace Milczenie\Report;

use Milczenie\Domain\QuestionKind;
use Milczenie\Domain\ReplySignature;
use Milczenie\Storage\Database;

/**
 * Droga pytania: wplyw do Sejmu -> przekazanie adresatowi -> odpowiedz -> czyj podpis.
 *
 * Ranking terminowosci liczy termin od PRZEKAZANIA, zeby nie obciazac resortu
 * opoznieniem kancelarii. Skutek uboczny jest taki, ze to opoznienie znika z obrazu.
 * Ten raport je pokazuje osobno - inaczej po cichu odejmowalibysmy je od rachunku.
 */
final class ProcessBuilder
{
    private const LAG_BUCKETS = [
        ['to' => 7, 'label' => 'do 7 dni'],
        ['to' => 14, 'label' => '8–14 dni'],
        ['to' => 21, 'label' => '15–21 dni'],
        ['to' => PHP_INT_MAX, 'label' => 'ponad 21 dni'],
    ];

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(int $term): array
    {
        return [
            'kancelaria' => $this->chancellery($term),
            'podpisy' => $this->signatures($term),
        ];
    }

    /**
     * Liczymy PYTANIA, nie pary (pytanie, adresat) - pytanie do trzech resortow
     * jest przekazywane raz i raz ma trafic do statystyki.
     *
     * @return array<string, mixed>
     */
    private function chancellery(int $term): array
    {
        $rows = $this->db->fetchAll(
            <<<'SQL'
                SELECT q.kind, q.num, q.title, q.receipt_date,
                       MIN(a.sent_date) AS forwarded,
                       CAST(julianday(MIN(a.sent_date)) - julianday(q.receipt_date) AS INTEGER) AS dni
                FROM question q
                JOIN addressee a ON a.question_id = q.id
                WHERE q.term = :term AND q.receipt_date IS NOT NULL AND a.sent_date IS NOT NULL
                GROUP BY q.id
                SQL,
            ['term' => $term],
        );

        $days = [];
        $buckets = array_fill(0, count(self::LAG_BUCKETS), 0);
        $worst = [];
        $deadline = QuestionKind::Interpellation->deadlineDays();

        foreach ($rows as $row) {
            $lag = (int) $row['dni'];
            $days[] = $lag;
            $buckets[$this->bucketIndex($lag)]++;

            if ($lag > $deadline) {
                $worst[] = [
                    'nr' => (int) $row['num'],
                    'rodzaj' => (string) $row['kind'],
                    'tytul' => (string) $row['title'],
                    'wplynelo' => (string) $row['receipt_date'],
                    'przekazano' => (string) $row['forwarded'],
                    'dni' => $lag,
                    'url' => $this->questionUrl($term, (string) $row['kind'], (int) $row['num']),
                ];
            }
        }

        usort($worst, static fn (array $a, array $b): int => $b['dni'] <=> $a['dni']);

        $total = count($days);
        $overDeadline = $buckets[count(self::LAG_BUCKETS) - 1];

        return [
            'pytan' => $total,
            'mediana_dni' => $this->percentile($days, 0.5),
            'srednia_dni' => $total > 0 ? round(array_sum($days) / $total, 1) : 0.0,
            'p90_dni' => $this->percentile($days, 0.9),
            'maks_dni' => $days === [] ? null : max($days),
            'ponad_termin' => $overDeadline,
            'udzial_ponad_termin' => $total > 0 ? round($overDeadline / $total, 4) : 0.0,
            // Ile ustawowego terminu na odpowiedz znika, zanim resort zobaczy pytanie.
            'udzial_terminu_zjedzony' => $total > 0
                ? round(min(1.0, (array_sum($days) / $total) / $deadline), 4)
                : 0.0,
            'przedzialy' => array_map(
                static fn (array $b, int $n): array => [
                    'etykieta' => $b['label'],
                    'n' => $n,
                ],
                self::LAG_BUCKETS,
                $buckets,
            ),
            'najdluzej' => array_slice($worst, 0, 25),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function signatures(int $term): array
    {
        $rows = $this->db->fetchAll(
            'SELECT r.author FROM reply r JOIN question q ON q.id = r.question_id WHERE q.term = :term',
            ['term' => $term],
        );

        $counts = [];
        foreach (ReplySignature::cases() as $case) {
            $counts[$case->value] = 0;
        }

        foreach ($rows as $row) {
            $author = $row['author'];
            $counts[ReplySignature::fromAuthor(is_string($author) ? $author : null)->value]++;
        }

        $total = count($rows);
        $out = [];
        foreach (ReplySignature::cases() as $case) {
            $n = $counts[$case->value];
            if ($n === 0) {
                continue;
            }

            $out[] = [
                'klucz' => $case->value,
                'nazwa' => $case->label(),
                'n' => $n,
                'udzial' => $total > 0 ? round($n / $total, 4) : 0.0,
            ];
        }

        usort($out, static fn (array $a, array $b): int => $b['n'] <=> $a['n']);

        return $out;
    }

    private function bucketIndex(int $lag): int
    {
        foreach (self::LAG_BUCKETS as $i => $bucket) {
            if ($lag <= $bucket['to']) {
                return $i;
            }
        }

        return count(self::LAG_BUCKETS) - 1;
    }

    /**
     * @param list<int> $values
     */
    private function percentile(array $values, float $q): ?int
    {
        if ($values === []) {
            return null;
        }

        sort($values);

        return $values[(int) floor($q * (count($values) - 1))];
    }

    private function questionUrl(int $term, string $kind, int $num): string
    {
        $type = $kind === QuestionKind::Interpellation->value ? 'int' : 'zap';

        return sprintf('https://sejm.gov.pl/sejm%d.nsf/interpelacja.xsp?typ=%s&nr=%d', $term, $type, $num);
    }
}
