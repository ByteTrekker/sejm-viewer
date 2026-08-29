<?php

declare(strict_types=1);

namespace Milczenie\Report;

use Milczenie\Domain\QuestionKind;
use Milczenie\Domain\ResponseOutcome;
use Milczenie\Storage\Database;

/**
 * Poslowie jako autorzy pytan oraz serie szablonowe.
 *
 * Sama liczba pytan jest miara mylaca: kilkunastu poslow wysyla setki pytan
 * roznaacych sie jedna nazwa wlasna. Dlatego kazdy posel ma dwie liczby -
 * pytania ogolem i liczbe UNIKALNYCH tematow - a serie sa raportowane osobno.
 */
final class MemberBuilder
{
    private const MIN_SAMPLE = 10;

    public function __construct(
        private readonly Database $db,
        private readonly \DateTimeImmutable $snapshot,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(int $term): array
    {
        $series = $this->templateSeries($term);
        /** @var array<string, true> $seriesTitles wszystkie serie, nie tylko pokazywane */
        $seriesTitles = $series['tytuly'];
        unset($series['tytuly']);

        $members = $this->members($term, $seriesTitles);

        return ['poslowie' => $members, 'serie' => $series];
    }

    /**
     * @param array<string, true> $seriesTitles zbior tytulow wystepujacych wiecej niz raz
     * @return list<array<string, mixed>>
     */
    private function members(int $term, array $seriesTitles): array
    {
        $rows = $this->db->fetchAll(
            <<<'SQL'
                SELECT q.id, q.kind, q.title, q.authors,
                       COALESCE(a.sent_date, q.sent_date) AS forwarded,
                       (SELECT MIN(r.receipt_date) FROM reply r
                          WHERE r.question_id = q.id AND r.receipt_date IS NOT NULL) AS first_reply,
                       (SELECT COUNT(*) FROM reply r WHERE r.question_id = q.id) AS reply_count,
                       (SELECT COUNT(*) FROM addressee x WHERE x.question_id = q.id) AS addressee_count
                FROM question q
                JOIN addressee a ON a.question_id = q.id
                WHERE q.term = :term
                GROUP BY q.id
                SQL,
            ['term' => $term],
        );

        $names = $this->memberNames($term);
        $buckets = [];

        foreach ($rows as $row) {
            if ($row['forwarded'] === null || (int) $row['addressee_count'] > 1) {
                continue;
            }

            $outcome = ResponseOutcome::classify(
                new \DateTimeImmutable((string) $row['forwarded']),
                $row['first_reply'] === null ? null : new \DateTimeImmutable((string) $row['first_reply']),
                (int) $row['reply_count'],
                $this->snapshot,
                QuestionKind::from((string) $row['kind'])->deadlineDays(),
            );

            /** @var list<string> $authors */
            $authors = json_decode((string) $row['authors'], true, 512, JSON_THROW_ON_ERROR) ?: [];
            $title = (string) $row['title'];

            foreach ($authors as $authorId) {
                $id = (int) $authorId;
                if (!isset($names[$id])) {
                    continue;
                }

                $buckets[$id] ??= ['pytan' => 0, 'tematy' => [], 'w_serii' => 0, 'rozstrzygniete' => 0, 'niedotrzymane' => 0, 'bez_odpowiedzi' => 0];
                $buckets[$id]['pytan']++;
                $buckets[$id]['tematy'][$title] = true;

                if (isset($seriesTitles[$title])) {
                    $buckets[$id]['w_serii']++;
                }

                if ($outcome->countsTowardsDeadline()) {
                    $buckets[$id]['rozstrzygniete']++;
                    $buckets[$id]['niedotrzymane'] += $outcome->isFailure() ? 1 : 0;
                    $buckets[$id]['bez_odpowiedzi'] += $outcome === ResponseOutcome::OverdueSilence ? 1 : 0;
                }
            }
        }

        $out = [];
        foreach ($names as $id => $mp) {
            $b = $buckets[$id] ?? ['pytan' => 0, 'tematy' => [], 'w_serii' => 0, 'rozstrzygniete' => 0, 'niedotrzymane' => 0, 'bez_odpowiedzi' => 0];
            $decided = (int) $b['rozstrzygniete'];

            $out[] = [
                'id' => $id,
                'nazwa' => $mp['name'],
                'klub' => $mp['club'],
                'okreg' => $mp['district'],
                'pytan' => (int) $b['pytan'],
                'tematow' => count($b['tematy']),
                'w_seriach' => (int) $b['w_serii'],
                'rozstrzygniete' => $decided,
                'niedotrzymane' => (int) $b['niedotrzymane'],
                'bez_odpowiedzi' => (int) $b['bez_odpowiedzi'],
                'udzial_po_terminie' => $decided > 0 ? round((int) $b['niedotrzymane'] / $decided, 4) : null,
                'udzial_bez_odpowiedzi' => $decided > 0 ? round((int) $b['bez_odpowiedzi'] / $decided, 4) : null,
                'w_rankingu' => $decided >= self::MIN_SAMPLE,
            ];
        }

        usort($out, static fn (array $a, array $b): int => [$b['tematow'], $b['pytan']] <=> [$a['tematow'], $a['pytan']]);

        return $out;
    }

    /**
     * Seria to identyczny tytul wystepujacy wiecej niz raz w kadencji.
     *
     * @return array<string, mixed>
     */
    private function templateSeries(int $term): array
    {
        $rows = $this->db->fetchAll(
            <<<'SQL'
                SELECT title, COUNT(*) AS n, MIN(num) AS pierwsze, MAX(num) AS ostatnie
                FROM question
                WHERE term = :term
                GROUP BY title
                HAVING COUNT(*) > 1
                ORDER BY n DESC, title
                SQL,
            ['term' => $term],
        );

        $total = 0;
        $list = [];
        $titles = [];
        foreach ($rows as $row) {
            $n = (int) $row['n'];
            $total += $n;
            $titles[(string) $row['title']] = true;
            $list[] = [
                'tytul' => (string) $row['title'],
                'n' => $n,
                'od_nr' => (int) $row['pierwsze'],
                'do_nr' => (int) $row['ostatnie'],
            ];
        }

        $allQuestions = (int) ($this->db->fetchRow('SELECT COUNT(*) AS n FROM question WHERE term = :term', ['term' => $term])['n'] ?? 0);

        return [
            'serii' => count($list),
            'pytan_w_seriach' => $total,
            'udzial' => $allQuestions > 0 ? round($total / $allQuestions, 4) : 0.0,
            'najwieksza' => $list === [] ? 0 : $list[0]['n'],
            'lista' => array_slice($list, 0, 25),
            'tytuly' => $titles,
        ];
    }

    /**
     * @return array<int, array{name: string, club: ?string, district: ?string}>
     */
    private function memberNames(int $term): array
    {
        $out = [];
        foreach ($this->db->fetchAll('SELECT id, name, club, district FROM mp WHERE term = :term', ['term' => $term]) as $row) {
            $out[(int) $row['id']] = [
                'name' => (string) $row['name'],
                'club' => isset($row['club']) ? (string) $row['club'] : null,
                'district' => isset($row['district']) ? (string) $row['district'] : null,
            ];
        }

        return $out;
    }
}
