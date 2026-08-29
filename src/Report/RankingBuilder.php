<?php

declare(strict_types=1);

namespace Milczenie\Report;

use Milczenie\Domain\QuestionKind;
use Milczenie\Domain\RecipientNormalizer;
use Milczenie\Storage\Database;

/**
 * Liczy wskazniki terminowosci na poziomie pary (pytanie, adresat).
 *
 * Zalozenia metodologiczne - swiadome i jawne, bo od nich zalezy caly ranking:
 *  1. Termin liczymy od daty przekazania pytania adresatowi (`recipientDetails.sent`),
 *     nie od wplyniecia do Sejmu - inaczej obciazalibysmy resort opoznieniem kancelarii.
 *  2. Prolongata NIE wydluza terminu. Regulamin Sejmu nie przewiduje przedluzenia;
 *     pismo o zwloce jest sygnalem, a nie usprawiedliwieniem - liczymy je osobno.
 *  3. Pytania z wieloma adresatami sa wylaczone z rankingu, bo API nie wiaze
 *     odpowiedzi z konkretnym adresatem i nie da sie uczciwie przypisac winy.
 *  4. Pytania bez odpowiedzi, ktorym termin jeszcze nie uplynal, wypadaja z mianownika.
 */
final class RankingBuilder
{
    private const MIN_SAMPLE = 30;

    public function __construct(
        private readonly Database $db,
        private readonly RecipientNormalizer $normalizer,
        private readonly \DateTimeImmutable $snapshot,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(int $term): array
    {
        $rows = $this->fetchRows($term);

        // Liczymy PYTANIA, nie wiersze (pytanie, adresat). Pytanie do trzech resortow
        // daje trzy wiersze i bez tego rozroznienia stopka raportowalaby potrojna liczbe.
        $excludedIds = ['wielu_adresatow' => [], 'brak_daty_przekazania' => []];
        $ministries = [];
        $monthly = [];
        $clubs = [];
        $open = [];
        $answered = [];

        $mpClubs = $this->memberClubs($term);

        foreach ($rows as $row) {
            $questionId = sprintf('%s:%s', $row['kind'], $row['num']);

            if ($row['forwarded'] === null) {
                $excludedIds['brak_daty_przekazania'][$questionId] = true;
                continue;
            }

            if ((int) $row['addressee_count'] > 1) {
                $excludedIds['wielu_adresatow'][$questionId] = true;
                continue;
            }

            $case = $this->evaluate($row);
            $key = (string) $row['recipient_key'];

            $ministries[$key] ??= $this->emptyBucket();
            $this->accumulate($ministries[$key], $case);

            $month = substr((string) $row['forwarded'], 0, 7);
            $monthly[$month] ??= $this->emptyBucket();
            $this->accumulate($monthly[$month], $case);

            foreach ($this->clubsOf($row, $mpClubs) as $club) {
                $clubs[$club] ??= $this->emptyBucket();
                $this->accumulate($clubs[$club], $case);
            }

            if ($case['status'] === 'late') {
                $answered[] = [
                    'nr' => (int) $row['num'],
                    'rodzaj' => (string) $row['kind'],
                    'tytul' => (string) $row['title'],
                    'adresat' => $this->normalizer->displayName($key),
                    'przekazano' => (string) $row['forwarded'],
                    'odpowiedziano' => (string) $row['first_reply'],
                    'dni_oczekiwania' => $case['days'],
                    'dni_po_terminie' => $case['overdue_days'],
                    'kto_odpowiedzial' => $row['reply_author'] !== null ? (string) $row['reply_author'] : null,
                    'prolongata' => $case['prolonged'],
                    'tylko_skan' => $case['scan_only'],
                    'url' => $this->questionUrl($term, (string) $row['kind'], (int) $row['num']),
                    'url_odpowiedzi' => $this->replyUrl($term, $row['reply_key']),
                ];
            }

            if ($case['status'] === 'open_overdue') {
                $open[] = [
                    'nr' => (int) $row['num'],
                    'rodzaj' => (string) $row['kind'],
                    'tytul' => (string) $row['title'],
                    'adresat' => $this->normalizer->displayName($key),
                    'przekazano' => (string) $row['forwarded'],
                    'dni_po_terminie' => $case['overdue_days'],
                    'url' => $this->questionUrl($term, (string) $row['kind'], (int) $row['num']),
                    'url_odpowiedzi' => null,
                ];
            }
        }

        usort($answered, static fn (array $a, array $b): int => $b['dni_po_terminie'] <=> $a['dni_po_terminie']);
        usort($open, static fn (array $a, array $b): int => $b['dni_po_terminie'] <=> $a['dni_po_terminie']);

        return [
            'meta' => [
                'wygenerowano' => $this->snapshot->format('Y-m-d'),
                'pobrano' => $this->db->getMeta('fetched_at'),
                'kadencja' => $term,
                'termin_dni' => QuestionKind::Interpellation->deadlineDays(),
                'min_probka' => self::MIN_SAMPLE,
                'zrodlo' => 'api.sejm.gov.pl',
                'wylaczone' => array_map(static fn (array $ids): int => count($ids), $excludedIds),
            ],
            'ministerstwa' => $this->rank($ministries, useDisplayName: true),
            'miesiace' => $this->timeline($monthly),
            'kluby' => $this->rank($clubs, useDisplayName: false),
            'najdluzej_bez_odpowiedzi' => array_slice($open, 0, 50),
            'najdluzej_do_odpowiedzi' => array_slice($answered, 0, 50),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchRows(int $term): array
    {
        $sql = <<<'SQL'
            SELECT
                a.recipient_key,
                a.recipient_raw,
                q.kind,
                q.num,
                q.title,
                q.authors,
                COALESCE(a.sent_date, q.sent_date) AS forwarded,
                (SELECT COUNT(*) FROM addressee x WHERE x.question_id = q.id) AS addressee_count,
                (SELECT MIN(r.receipt_date) FROM reply r
                   WHERE r.question_id = q.id AND r.receipt_date IS NOT NULL) AS first_reply,
                (SELECT COUNT(*) FROM reply r WHERE r.question_id = q.id) AS reply_count,
                (SELECT MAX(r.prolongation) FROM reply r WHERE r.question_id = q.id) AS prolonged,
                (SELECT MIN(r.only_attachment) FROM reply r WHERE r.question_id = q.id) AS only_attachment,
                (SELECT r.author FROM reply r
                   WHERE r.question_id = q.id AND r.receipt_date IS NOT NULL
                   ORDER BY r.receipt_date, r.reply_key LIMIT 1) AS reply_author,
                (SELECT r.reply_key FROM reply r
                   WHERE r.question_id = q.id AND r.receipt_date IS NOT NULL
                     AND r.reply_key NOT LIKE 'idx-%'
                   ORDER BY r.receipt_date, r.reply_key LIMIT 1) AS reply_key
            FROM addressee a
            JOIN question q ON q.id = a.question_id
            WHERE q.term = :term
            SQL;

        $stmt = $this->db->pdo->prepare($sql);
        $stmt->execute(['term' => $term]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll();

        // Rejestrujemy oryginalne warianty zapisu nazw, zeby displayName() mial czym operowac.
        foreach ($rows as $row) {
            $this->normalizer->normalize((string) $row['recipient_raw']);
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{status: string, days: int|null, overdue_days: int, prolonged: bool, scan_only: bool, by_minister: bool}
     */
    private function evaluate(array $row): array
    {
        $deadlineDays = QuestionKind::from((string) $row['kind'])->deadlineDays();
        $forwarded = new \DateTimeImmutable((string) $row['forwarded']);
        $deadline = $forwarded->modify(sprintf('+%d days', $deadlineDays));
        $author = (string) ($row['reply_author'] ?? '');

        $common = [
            'prolonged' => (bool) (int) ($row['prolonged'] ?? 0),
            'scan_only' => (bool) (int) ($row['only_attachment'] ?? 0),
            // "Minister X" odpowiada osobiscie vs. sekretarz/podsekretarz stanu.
            'by_minister' => $author !== '' && preg_match('/^(Minister|Prezes|Wiceprezes|Szef)\b/u', $author) === 1,
        ];

        if ($row['first_reply'] !== null) {
            $replied = new \DateTimeImmutable((string) $row['first_reply']);
            $days = (int) $forwarded->diff($replied)->days;
            $overdue = max(0, (int) $deadline->diff($replied)->format('%r%a'));

            return [...$common, 'status' => $overdue > 0 ? 'late' : 'on_time', 'days' => $days, 'overdue_days' => $overdue];
        }

        // Odpowiedz jest, ale API nie podaje jej daty - nie wiadomo, czy w terminie.
        // Takie pytanie musi wypasc z mianownika, a nie udawac ani punktualnego, ani milczenia.
        if ((int) ($row['reply_count'] ?? 0) > 0) {
            return [...$common, 'status' => 'answered_no_date', 'days' => null, 'overdue_days' => 0];
        }

        $overdue = (int) $deadline->diff($this->snapshot)->format('%r%a');

        return [
            ...$common,
            'status' => $overdue > 0 ? 'open_overdue' : 'open_in_time',
            'days' => null,
            'overdue_days' => max(0, $overdue),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyBucket(): array
    {
        return [
            'skierowane' => 0, 'na_czas' => 0, 'po_terminie' => 0,
            'bez_odpowiedzi_po_terminie' => 0, 'w_biegu' => 0, 'odpowiedzi_bez_daty' => 0,
            'prolongaty' => 0, 'tylko_skan' => 0, 'odpowiedzial_minister' => 0,
            'dni' => [], 'opoznienia' => [],
        ];
    }

    /**
     * @param array<string, mixed> $bucket
     * @param array{status: string, days: int|null, overdue_days: int, prolonged: bool, scan_only: bool, by_minister: bool} $case
     */
    private function accumulate(array &$bucket, array $case): void
    {
        $bucket['skierowane']++;

        match ($case['status']) {
            'on_time' => $bucket['na_czas']++,
            'late' => $bucket['po_terminie']++,
            'open_overdue' => $bucket['bez_odpowiedzi_po_terminie']++,
            'open_in_time' => $bucket['w_biegu']++,
            'answered_no_date' => $bucket['odpowiedzi_bez_daty']++,
            default => null,
        };

        if ($case['days'] !== null) {
            $bucket['dni'][] = $case['days'];
            $bucket['prolongaty'] += $case['prolonged'] ? 1 : 0;
            $bucket['tylko_skan'] += $case['scan_only'] ? 1 : 0;
            $bucket['odpowiedzial_minister'] += $case['by_minister'] ? 1 : 0;
        }

        if ($case['overdue_days'] > 0) {
            $bucket['opoznienia'][] = $case['overdue_days'];
        }
    }

    /**
     * @param array<string, array<string, mixed>> $buckets
     * @return list<array<string, mixed>>
     */
    private function rank(array $buckets, bool $useDisplayName): array
    {
        $out = [];

        foreach ($buckets as $key => $bucket) {
            $stats = $this->summarize($bucket);
            $stats['nazwa'] = $useDisplayName ? $this->normalizer->displayName($key) : $key;
            $stats['klucz'] = $key;
            $out[] = $stats;
        }

        usort($out, static function (array $a, array $b): int {
            return [$b['w_rankingu'], $b['wskaznik_milczenia']] <=> [$a['w_rankingu'], $a['wskaznik_milczenia']];
        });

        return $out;
    }

    /**
     * @param array<string, array<string, mixed>> $buckets
     * @return list<array<string, mixed>>
     */
    private function timeline(array $buckets): array
    {
        ksort($buckets);
        $out = [];

        foreach ($buckets as $month => $bucket) {
            $stats = $this->summarize($bucket);
            $stats['miesiac'] = $month;
            $out[] = $stats;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $bucket
     * @return array<string, mixed>
     */
    private function summarize(array $bucket): array
    {
        /** @var list<int> $days */
        $days = $bucket['dni'];
        /** @var list<int> $overdue */
        $overdue = $bucket['opoznienia'];

        $answered = count($days);
        // Mianownik rozstrzygniec: pytania, ktore juz moglyby byc spoznione
        // i o ktorych wiadomo, kiedy (lub czy) przyszla odpowiedz.
        $decided = (int) $bucket['skierowane'] - (int) $bucket['w_biegu'] - (int) $bucket['odpowiedzi_bez_daty'];
        $failed = (int) $bucket['po_terminie'] + (int) $bucket['bez_odpowiedzi_po_terminie'];

        $lateRate = $decided > 0 ? $failed / $decided : 0.0;
        $openRate = $decided > 0 ? (int) $bucket['bez_odpowiedzi_po_terminie'] / $decided : 0.0;
        $prolongRate = $answered > 0 ? (int) $bucket['prolongaty'] / $answered : 0.0;
        $median = $this->percentile($days, 0.5);

        // Wskaznik milczenia 0-100. Brak odpowiedzi wazy podwojnie (wchodzi tez w lateRate),
        // bo cisza jest gorsza od spoznionej odpowiedzi.
        $score = 100 * (
            0.40 * $lateRate
            + 0.25 * $openRate
            + 0.15 * $prolongRate
            + 0.20 * min(1.0, ($median ?? 0) / 42)
        );

        return [
            'skierowane' => (int) $bucket['skierowane'],
            'odpowiedzi' => $answered,
            'na_czas' => (int) $bucket['na_czas'],
            'po_terminie' => (int) $bucket['po_terminie'],
            'bez_odpowiedzi_po_terminie' => (int) $bucket['bez_odpowiedzi_po_terminie'],
            'w_biegu' => (int) $bucket['w_biegu'],
            'odpowiedzi_bez_daty' => (int) $bucket['odpowiedzi_bez_daty'],
            'rozstrzygniete' => $decided,
            'udzial_po_terminie' => round($lateRate, 4),
            'udzial_bez_odpowiedzi' => round($openRate, 4),
            'udzial_prolongat' => round($prolongRate, 4),
            'udzial_tylko_skan' => $answered > 0 ? round((int) $bucket['tylko_skan'] / $answered, 4) : 0.0,
            'udzial_odpowiedzi_ministra' => $answered > 0 ? round((int) $bucket['odpowiedzial_minister'] / $answered, 4) : 0.0,
            'mediana_dni' => $median,
            'p90_dni' => $this->percentile($days, 0.9),
            'mediana_opoznienia' => $this->percentile($overdue, 0.5),
            'max_opoznienie' => $overdue === [] ? null : max($overdue),
            'wskaznik_milczenia' => round($score, 1),
            'w_rankingu' => $decided >= self::MIN_SAMPLE,
        ];
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
        $index = (int) floor($q * (count($values) - 1));

        return $values[$index];
    }

    /**
     * @return array<int, string>
     */
    private function memberClubs(int $term): array
    {
        $stmt = $this->db->pdo->prepare('SELECT id, club FROM mp WHERE term = ?');
        $stmt->execute([$term]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['id']] = (string) ($row['club'] ?? 'brak klubu');
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, string> $mpClubs
     * @return list<string>
     */
    private function clubsOf(array $row, array $mpClubs): array
    {
        /** @var list<string> $authors */
        $authors = json_decode((string) $row['authors'], true, 512, JSON_THROW_ON_ERROR) ?: [];

        $clubs = [];
        foreach ($authors as $authorId) {
            $club = $mpClubs[(int) $authorId] ?? null;
            if ($club !== null) {
                $clubs[$club] = true;
            }
        }

        return array_keys($clubs);
    }

    /**
     * Uwaga: zapytania poselskie tez mieszkaja pod interpelacja.xsp - rozroznia je
     * wylacznie parametr typ. Wariant zapytanie.xsp zwraca strone bledu.
     */
    private function questionUrl(int $term, string $kind, int $num): string
    {
        $type = $kind === QuestionKind::Interpellation->value ? 'int' : 'zap';

        return sprintf('https://sejm.gov.pl/sejm%d.nsf/interpelacja.xsp?typ=%s&nr=%d', $term, $type, $num);
    }

    /**
     * Pisma o prolongacie nie maja klucza w API, wiec nie da sie do nich zrobic odnosnika.
     */
    private function replyUrl(int $term, mixed $replyKey): ?string
    {
        if (!is_string($replyKey) || $replyKey === '') {
            return null;
        }

        return sprintf('https://sejm.gov.pl/sejm%d.nsf/interpelacjaTresc.xsp?key=%s', $term, rawurlencode($replyKey));
    }
}
