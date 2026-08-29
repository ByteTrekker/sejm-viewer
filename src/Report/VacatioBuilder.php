<?php

declare(strict_types=1);

namespace Milczenie\Report;

use Milczenie\Domain\IssuerNormalizer;
use Milczenie\Domain\TechnicalActClassifier;
use Milczenie\Storage\Database;

/**
 * Liczy vacatio legis - odstep miedzy ogloszeniem aktu a jego wejsciem w zycie.
 *
 * Zalozenia metodologiczne:
 *  1. Standard ustawowy: art. 4 ust. 1 ustawy z 20.07.2000 o ogloszaniu aktow
 *     normatywnych - akt wchodzi w zycie "po uplywie czternastu dni od dnia ogloszenia".
 *  2. Skrocenie jest LEGALNE (art. 4 ust. 2) przy waznym interesie panstwa, a moc wsteczna
 *     przy spelnieniu warunkow z art. 5. Ranking mierzy wiec skale odstepstwa od standardu,
 *     a nie lamanie prawa - i tak musi byc opisany.
 *  3. Prog liczymy zachowawczo: 14 dni roznicy uznajemy juz za spelnienie standardu,
 *     choc konwencja redakcyjna ("po uplywie 14 dni") daje zwykle 15. Zanizenie progu
 *     moze tylko oslabic zarzut, nigdy go nie zawyzyc.
 *  4. Ustawy maja w ELI organ wydajacy "SEJM", wiec nie da sie ich przypisac do resortu -
 *     ranking resortowy stoi wylacznie na rozporzadzeniach.
 *  5. API podaje jedna date wejscia w zycie. Akty, ktorych przepisy wchodza etapami,
 *     sa wiec uproszczone do najwczesniejszej daty.
 */
final class VacatioBuilder
{
    private const STANDARD_DAYS = 14;
    private const MIN_SAMPLE = 30;

    public function __construct(
        private readonly Database $db,
        private readonly IssuerNormalizer $normalizer,
        private readonly ?TechnicalActClassifier $classifier = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(int $fromYear, int $toYear): array
    {
        $rows = $this->fetchRows($fromYear, $toYear);

        $issuers = [];
        $years = [];
        $lawYears = [];
        $shortest = [];
        $histogram = [];
        $skipped = 0;
        // 28 aktow w zbiorze wydaja dwa lub wiecej organow naraz. Kazdemu z nich
        // przypisujemy akt (bo kazdy odpowiada), ale w statystyce rocznej i w histogramie
        // akt moze wystapic tylko raz.
        $countedActs = [];
        $shortestSeen = [];
        $excluded = [];
        $excludedActs = [];

        foreach ($rows as $row) {
            $days = $row['dni'];
            if ($days === null) {
                $skipped++;
                continue;
            }

            if ($this->classifier !== null) {
                $category = $this->classifier->categorize((string) $row['title']);
                if ($category !== null) {
                    if (!isset($excludedActs[(string) $row['eli']])) {
                        $excludedActs[(string) $row['eli']] = true;
                        $excluded[$category] = ($excluded[$category] ?? 0) + 1;
                    }
                    continue;
                }
            }

            $year = (int) $row['year'];

            if ($row['type'] === 'Ustawa') {
                if (!isset($countedActs[(string) $row['eli']])) {
                    $countedActs[(string) $row['eli']] = true;
                    $lawYears[$year] ??= $this->emptyBucket();
                    $this->accumulate($lawYears[$year], $days);
                }
                continue;
            }

            $eli = (string) $row['eli'];
            if (!isset($countedActs[$eli])) {
                $countedActs[$eli] = true;
                $years[$year] ??= $this->emptyBucket();
                $this->accumulate($years[$year], $days);
                $histogram[$this->bin($days)] = ($histogram[$this->bin($days)] ?? 0) + 1;
            }

            // Klucz liczymy tu, a nie bierzemy z bazy: normalizator jest zrodlem prawdy
            // przy raportowaniu, wiec zmiana jego regul nie wymaga ponownego importu.
            $key = $this->normalizer->normalize((string) $row['issuer_raw']);
            $issuers[$key] ??= $this->emptyBucket();
            $this->accumulate($issuers[$key], $days);

            if ($days <= 1 && !isset($shortestSeen[$eli])) {
                $shortestSeen[$eli] = true;
                $shortest[] = [
                    'tytul' => (string) $row['title'],
                    'adres' => (string) $row['display_address'],
                    'organ' => $this->normalizer->displayName($key),
                    'ogloszono' => (string) $row['promulgation'],
                    'wchodzi' => (string) $row['entry_into_force'],
                    'dni' => $days,
                    'url' => $this->isapUrl((string) $row['eli']),
                    'url_tekst' => sprintf('https://api.sejm.gov.pl/eli/acts/%s/text.pdf', (string) $row['eli']),
                ];
            }
        }

        usort($shortest, static function (array $a, array $b): int {
            return [$a['dni'], $b['ogloszono']] <=> [$b['dni'], $a['ogloszono']];
        });

        return [
            'meta' => [
                'wygenerowano' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
                'pobrano' => $this->db->getMeta('acts_fetched_at'),
                'od_roku' => $fromYear,
                'do_roku' => $toYear,
                'standard_dni' => self::STANDARD_DAYS,
                'min_probka' => self::MIN_SAMPLE,
                'zrodlo' => 'api.sejm.gov.pl/eli',
                'bez_daty_wejscia' => $skipped,
                'wariant' => $this->classifier === null ? 'wszystkie' : 'merytoryczne',
                'wykluczone_techniczne' => $this->sortDesc($excluded),
                'wykluczone_razem' => array_sum($excluded),
            ],
            'histogram' => $this->histogram($histogram),
            'organy' => $this->rank($issuers),
            'lata' => $this->timeline($years),
            'ustawy_lata' => $this->timeline($lawYears),
            'najkrotsze' => array_slice($shortest, 0, 50),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchRows(int $fromYear, int $toYear): array
    {
        $sql = <<<'SQL'
            SELECT a.eli, a.year, a.type, a.title, a.display_address, a.promulgation, a.entry_into_force,
                   COALESCE(i.issuer_raw, 'nieznany') AS issuer_raw,
                   CASE
                       WHEN a.promulgation IS NULL OR a.entry_into_force IS NULL THEN NULL
                       ELSE CAST(julianday(a.entry_into_force) - julianday(a.promulgation) AS INTEGER)
                   END AS dni
            FROM act a
            LEFT JOIN act_issuer i ON i.eli = a.eli
            WHERE a.year BETWEEN :from AND :to
            SQL;

        $stmt = $this->db->pdo->prepare($sql);
        $stmt->execute(['from' => $fromYear, 'to' => $toYear]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll();

        foreach ($rows as $row) {
            $this->normalizer->normalize((string) $row['issuer_raw']);
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyBucket(): array
    {
        return ['aktow' => 0, 'ponizej' => 0, 'natychmiast' => 0, 'wstecz' => 0, 'dlugie' => 0, 'dni' => []];
    }

    /**
     * @param array<string, mixed> $bucket
     */
    private function accumulate(array &$bucket, int $days): void
    {
        $bucket['aktow']++;
        $bucket['dni'][] = $days;

        if ($days < self::STANDARD_DAYS) {
            $bucket['ponizej']++;
        }
        if ($days <= 1) {
            $bucket['natychmiast']++;
        }
        if ($days < 0) {
            $bucket['wstecz']++;
        }
        if ($days >= 30) {
            $bucket['dlugie']++;
        }
    }

    /**
     * @param array<string, int> $counts
     * @return array<string, int>
     */
    private function sortDesc(array $counts): array
    {
        arsort($counts);

        return $counts;
    }

    private const HIST_MAX = 45;

    /**
     * Kubelki histogramu: osobny slupek na kazdy dzien do 45, plus przelewy po obu stronach.
     * Dzien po dniu, bo cala tresc tego wykresu to skok na 14/15 dniu i gora przy zerze.
     */
    private function bin(int $days): int
    {
        if ($days < 0) {
            return -1;
        }

        return min($days, self::HIST_MAX);
    }

    /**
     * @param array<int, int> $counts
     * @return list<array<string, mixed>>
     */
    private function histogram(array $counts): array
    {
        $bins = [];

        for ($d = -1; $d <= self::HIST_MAX; $d++) {
            $isUnder = $d === -1;
            $isOver = $d === self::HIST_MAX;

            $bins[] = [
                'from' => $isUnder ? -999 : $d,
                'to' => $isOver ? 9999 : $d,
                'label' => $isUnder ? 'wstecz' : ($isOver ? self::HIST_MAX . '+' : (string) $d),
                'n' => $counts[$d] ?? 0,
                'tick' => $isUnder || $isOver || $d % 5 === 0 || $d === self::STANDARD_DAYS,
            ];
        }

        return $bins;
    }

    /**
     * @param array<string, array<string, mixed>> $buckets
     * @return list<array<string, mixed>>
     */
    private function rank(array $buckets): array
    {
        $out = [];

        foreach ($buckets as $key => $bucket) {
            $stats = $this->summarize($bucket);
            $stats['klucz'] = $key;
            $stats['nazwa'] = $this->normalizer->displayName($key);
            $out[] = $stats;
        }

        usort($out, static fn (array $a, array $b): int
            => [$b['w_rankingu'], $b['wskaznik_pospiechu']] <=> [$a['w_rankingu'], $a['wskaznik_pospiechu']]);

        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $buckets
     * @return list<array<string, mixed>>
     */
    private function timeline(array $buckets): array
    {
        ksort($buckets);
        $out = [];

        foreach ($buckets as $year => $bucket) {
            $stats = $this->summarize($bucket);
            $stats['rok'] = $year;
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
        $n = count($days);

        $below = $n > 0 ? (int) $bucket['ponizej'] / $n : 0.0;
        $immediate = $n > 0 ? (int) $bucket['natychmiast'] / $n : 0.0;
        $backwards = $n > 0 ? (int) $bucket['wstecz'] / $n : 0.0;
        $median = $this->percentile($days, 0.5) ?? 0;

        // Wskaznik pospiechu 0-100. Wejscie w zycie z dnia na dzien wazy podwojnie
        // (wchodzi tez w "ponizej standardu"), bo zabiera adresatowi caly czas na przygotowanie.
        $score = 100 * (
            0.40 * $below
            + 0.35 * $immediate
            + 0.15 * $backwards
            + 0.10 * (1 - min(1.0, max(0, $median) / self::STANDARD_DAYS))
        );

        return [
            'aktow' => $n,
            'ponizej_standardu' => (int) $bucket['ponizej'],
            'natychmiast' => (int) $bucket['natychmiast'],
            'wstecz' => (int) $bucket['wstecz'],
            'dlugie' => (int) $bucket['dlugie'],
            'udzial_ponizej' => round($below, 4),
            'udzial_natychmiast' => round($immediate, 4),
            'udzial_wstecz' => round($backwards, 4),
            'udzial_dlugie' => $n > 0 ? round((int) $bucket['dlugie'] / $n, 4) : 0.0,
            'mediana_dni' => $this->percentile($days, 0.5),
            'p10_dni' => $this->percentile($days, 0.1),
            'p90_dni' => $this->percentile($days, 0.9),
            'wskaznik_pospiechu' => round($score, 1),
            'w_rankingu' => $n >= self::MIN_SAMPLE,
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

        return $values[(int) floor($q * (count($values) - 1))];
    }

    /**
     * ELI "DU/2024/1976" -> adres w ISAP (WDU20240001976). PDF-a linkujemy wprost z API,
     * bo jest dostepny dla 100% aktow i nie stoi za ochrona antybotowa.
     */
    private function isapUrl(string $eli): string
    {
        [$publisher, $year, $pos] = array_pad(explode('/', $eli), 3, '');

        return sprintf(
            'https://isap.sejm.gov.pl/isap.nsf/DocDetails.xsp?id=W%s%s%s',
            $publisher,
            $year,
            str_pad($pos, 7, '0', STR_PAD_LEFT)
        );
    }
}
