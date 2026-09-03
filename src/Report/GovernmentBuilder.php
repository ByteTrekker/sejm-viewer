<?php

declare(strict_types=1);

namespace Milczenie\Report;

use Milczenie\Domain\RecipientNormalizer;
use Milczenie\Domain\ReplySignatory;
use Milczenie\Storage\Database;

/**
 * Sklad rzadu - taki, jaki DA SIE ustalic z dokumentow.
 *
 * Formalnej listy Rady Ministrow nie ma w zadnym API. Postanowienia Prezydenta
 * o powolaniu sa w ELI, ale nazwiska stoja wylacznie w PDF-ie, wiec z metadanych
 * wyjmiemy date i odnosnik, nigdy skladu. Zamiast zgadywac albo przepisywac
 * liste ze strony rzadowej, ktora za miesiac bedzie nieaktualna, pokazujemy to,
 * co wynika z dokumentow urzedowych:
 *
 *   - kto FAKTYCZNIE odpowiada w imieniu resortu, z podpisow pod odpowiedziami
 *     na interpelacje (pole "author" w API Sejmu),
 *   - kiedy resort dostal albo zmienil statut (zarzadzenia Prezesa RM w M.P.),
 *   - kiedy Prezydent zmienial sklad Rady Ministrow (postanowienia w M.P.).
 *
 * To jest mniej niz lista ministrow i wiecej niz lista ministrow: nie powie,
 * kto formalnie kieruje resortem, ale powie, kto w jego imieniu odpowiada
 * parlamentowi i jak szybko.
 */
final class GovernmentBuilder
{
    /** Osoba wchodzi do zestawienia od tylu podpisow - nizej to szum. */
    private const MIN_SIGNATURES = 3;

    public function __construct(
        private readonly Database $db,
        private readonly ReplySignatory $signatory,
        private readonly RecipientNormalizer $recipients,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(int $term, string $from, string|null $to): array
    {
        $rows = $this->signatureRows($term);

        // Dwa przebiegi, nie jeden. Normalizator zapamietuje etykiete resortu
        // dopiero wtedy, gdy natrafi na jego NAZWE BIEZACA, wiec przy jednym
        // przebiegu resort scalony z poprzednikiem wyswietlal sie jako surowy
        // klucz ("minister rodziny, pracy i polityki spolecznej").
        foreach ($rows as $row) {
            $this->recipients->normalize((string) $row['recipient_raw']);
        }

        /** @var array<string, array{klucz: string, nazwisko: string, podpisow: int, od: string|null, do: string|null}> $people */
        $people = [];
        /** @var array<string, array<string, array{nazwa: string, n: int}>> $personOffices */
        $personOffices = [];
        /** @var array<string, array<string, int>> $personRoles */
        $personRoles = [];
        /** @var array<string, list<array{dni: float, waga: int}>> $personDays */
        $personDays = [];
        /** @var array<string, array{klucz: string, nazwa: string, podpisow: int}> $offices */
        $offices = [];
        /** @var array<string, array<string, int>> $officePeople */
        $officePeople = [];
        $unparsed = 0;

        foreach ($rows as $row) {
            $parsed = $this->signatory->parse((string) $row['author']);

            if ($parsed['klucz'] === null || $parsed['nazwisko'] === null) {
                $unparsed += (int) $row['n'];
                continue;
            }

            $key = $parsed['klucz'];
            $officeKey = $this->recipients->normalize((string) $row['recipient_raw']);
            $office = $this->recipients->displayName($officeKey);
            $n = (int) $row['n'];

            $people[$key] ??= [
                'klucz' => $key,
                'nazwisko' => $parsed['nazwisko'],
                'podpisow' => 0,
                'od' => null,
                'do' => null,
            ];

            $people[$key]['podpisow'] += $n;
            $people[$key]['od'] = $this->min($people[$key]['od'], $row['od']);
            $people[$key]['do'] = $this->max($people[$key]['do'], $row['do']);

            $personOffices[$key][$officeKey] = [
                'nazwa' => $office,
                'n' => ($personOffices[$key][$officeKey]['n'] ?? 0) + $n,
            ];

            if ($parsed['funkcja'] !== null) {
                $personRoles[$key][$parsed['funkcja']] = ($personRoles[$key][$parsed['funkcja']] ?? 0) + $n;
            }

            if ($row['dni'] !== null) {
                $personDays[$key][] = ['dni' => (float) $row['dni'], 'waga' => $n];
            }

            $offices[$officeKey] ??= ['klucz' => $officeKey, 'nazwa' => $office, 'podpisow' => 0];
            $offices[$officeKey]['podpisow'] += $n;
            $officePeople[$officeKey][$key] = ($officePeople[$officeKey][$key] ?? 0) + $n;
        }

        return [
            'osoby' => $this->finalizePeople($people, $personOffices, $personRoles, $personDays, $this->membersByKey($term)),
            'resorty' => $this->finalizeOffices($offices, $officePeople, $people),
            'akty' => $this->cabinetActs($from, $to),
            'statuty' => $this->statutes($from, $to),
            'meta' => [
                'podpisow_bez_nazwiska' => $unparsed,
                'min_podpisow' => self::MIN_SIGNATURES,
                'od' => $from,
                'do' => $to,
            ],
        ];
    }

    /**
     * @return array<string, int>
     */
    private function membersByKey(int $term): array
    {
        $out = [];
        foreach ($this->db->fetchAll('SELECT id, name FROM mp WHERE term = :term', ['term' => $term]) as $row) {
            $out[$this->signatory->key((string) $row['name'])] = (int) $row['id'];
        }

        return $out;
    }

    /**
     * Jeden wiersz na (podpis, adresat): ile razy ta osoba podpisala odpowiedz
     * w imieniu tego resortu, kiedy pierwszy i ostatni raz i ile srednio zajmowalo.
     *
     * Pytania do KILKU adresatow naraz sa wylaczone tak samo jak w rankingu:
     * API nie wiaze odpowiedzi z konkretnym adresatem, wiec przypisanie podpisu
     * do resortu byloby zgadywaniem.
     *
     * Odpowiedzi z wartownikiem zamiast daty (0000-12-30) LICZA SIE do podpisow,
     * ale nie do sredniego czasu. Podpis istnieje niezaleznie od tego, czy API
     * podalo date; wyrzucenie go zaniżałoby dorobek osoby o cala kadencje VII,
     * w ktorej dat nie ma prawie wcale.
     *
     * @return list<array<string, mixed>>
     */
    private function signatureRows(int $term): array
    {
        return $this->db->fetchAll(
            <<<'SQL'
                SELECT r.author,
                       a.recipient_raw,
                       COUNT(*) AS n,
                       MIN(CASE WHEN r.receipt_date >= '1990-01-01' THEN r.receipt_date END) AS od,
                       MAX(CASE WHEN r.receipt_date >= '1990-01-01' THEN r.receipt_date END) AS do,
                       AVG(CASE
                               WHEN r.receipt_date >= '1990-01-01'
                               THEN julianday(r.receipt_date) - julianday(a.sent_date)
                           END) AS dni
                FROM reply r
                JOIN question q ON q.id = r.question_id
                JOIN addressee a ON a.question_id = q.id
                WHERE q.term = :term
                  AND r.author <> ''
                  AND (SELECT COUNT(*) FROM addressee x WHERE x.question_id = q.id) = 1
                GROUP BY r.author, a.recipient_raw
                SQL,
            ['term' => $term],
        );
    }

    /**
     * @param array<string, array{klucz: string, nazwisko: string, podpisow: int, od: string|null, do: string|null}> $people
     * @param array<string, array<string, array{nazwa: string, n: int}>>                                             $offices
     * @param array<string, array<string, int>>                                                                      $roles
     * @param array<string, list<array{dni: float, waga: int}>>                                                      $days
     * @param array<string, int>                                                                                     $members klucz nazwiska -> id posla
     *
     * @return list<array<string, mixed>>
     */
    private function finalizePeople(array $people, array $offices, array $roles, array $days, array $members): array
    {
        $out = [];

        foreach ($people as $key => $person) {
            if ($person['podpisow'] < self::MIN_SIGNATURES) {
                continue;
            }

            $personRoles = $roles[$key] ?? [];
            arsort($personRoles);

            $personOffices = array_values($offices[$key] ?? []);
            usort($personOffices, static fn (array $a, array $b): int => $b['n'] <=> $a['n']);

            $weighted = 0.0;
            $weight = 0;
            foreach ($days[$key] ?? [] as $d) {
                $weighted += $d['dni'] * $d['waga'];
                $weight += $d['waga'];
            }

            $out[] = [
                'klucz' => $person['klucz'],
                'nazwisko' => $person['nazwisko'],
                'funkcja' => $personRoles === [] ? null : array_key_first($personRoles),
                'funkcje' => array_keys($personRoles),
                'resorty' => $personOffices,
                'resortow' => count($personOffices),
                'podpisow' => $person['podpisow'],
                'od' => $person['od'],
                'do' => $person['do'],
                'srednio_dni' => $weight > 0 ? round($weighted / $weight, 1) : null,
                // Czesc podpisujacych zasiada w Sejmie. Dopasowujemy po kluczu
                // nazwiska, wiec dwoch roznych ludzi o tym samym imieniu
                // i nazwisku bylby zlaczony - takich w izbie nie ma, ale gdyby
                // sie pojawili, ten odnosnik trzeba bedzie oprzec na czyms wiecej.
                'posel_id' => $members[$person['klucz']] ?? null,
            ];
        }

        usort($out, static fn (array $a, array $b): int => $b['podpisow'] <=> $a['podpisow']);

        return $out;
    }

    /**
     * @param array<string, array{klucz: string, nazwa: string, podpisow: int}>                                      $offices
     * @param array<string, array<string, int>>                                                                      $officePeople
     * @param array<string, array{klucz: string, nazwisko: string, podpisow: int, od: string|null, do: string|null}> $people
     *
     * @return list<array<string, mixed>>
     */
    private function finalizeOffices(array $offices, array $officePeople, array $people): array
    {
        $out = [];

        foreach ($offices as $key => $office) {
            $signers = $officePeople[$key] ?? [];
            arsort($signers);
            $top = $signers === [] ? null : array_key_first($signers);

            $out[] = [
                'klucz' => $office['klucz'],
                'nazwa' => $office['nazwa'],
                'podpisow' => $office['podpisow'],
                'osob' => count($signers),
                'najczesciej' => $top === null ? null : [
                    'klucz' => $top,
                    'nazwisko' => $people[$top]['nazwisko'] ?? null,
                    'n' => $signers[$top],
                ],
            ];
        }

        usort($out, static fn (array $a, array $b): int => $b['podpisow'] <=> $a['podpisow']);

        return $out;
    }

    /**
     * Postanowienia Prezydenta o skladzie Rady Ministrow, z okresu kadencji.
     *
     * Nazwisk tu nie ma i nie bedzie: tytul aktu ich nie niesie, a tresc jest
     * wylacznie w PDF. Kazda pozycja prowadzi wiec do aktu, zamiast udawac,
     * ze wiemy, kogo dotyczy.
     *
     * @return list<array<string, mixed>>
     */
    private function cabinetActs(string $from, string|null $to): array
    {
        $rows = $this->db->fetchAll(
            <<<'SQL'
                SELECT eli, display_address, title, promulgation
                FROM act
                WHERE publisher = 'MP'
                  AND type = 'Postanowienie'
                  AND (title LIKE '%Rady Ministrów%' OR title LIKE '%desygnowaniu%')
                  AND promulgation >= :from
                  AND (:to IS NULL OR promulgation <= :to)
                ORDER BY promulgation DESC
                SQL,
            ['from' => $from, 'to' => $to],
        );

        return array_map(
            fn (array $r): array => [
                'adres' => (string) $r['display_address'],
                'tytul' => (string) $r['title'],
                'data' => (string) $r['promulgation'],
                'rodzaj' => $this->classifyCabinetAct((string) $r['title']),
                'url' => sprintf('https://api.sejm.gov.pl/eli/acts/%s/text.pdf', $r['eli']),
            ],
            $rows,
        );
    }

    private function classifyCabinetAct(string $title): string
    {
        return match (true) {
            str_contains($title, 'desygnowaniu') => 'desygnowanie premiera',
            str_contains($title, 'powołaniu Prezesa Rady Ministrów') => 'powołanie premiera',
            str_contains($title, 'dymisji') => 'dymisja rządu',
            str_contains($title, 'zmianie w składzie') => 'zmiana w składzie',
            str_contains($title, 'powołaniu w skład') => 'powołanie w skład',
            str_contains($title, 'odwołaniu') => 'odwołanie',
            default => 'inne',
        };
    }

    /**
     * Statuty ministerstw: kiedy resort dostal statut albo go zmieniono.
     *
     * To jedyny slad formalnego istnienia resortu, jaki jest w metadanych -
     * nazwa ministerstwa stoi w TYTULE zarzadzenia, wiec nie trzeba siegac
     * do tresci.
     *
     * @return list<array<string, mixed>>
     */
    private function statutes(string $from, string|null $to): array
    {
        $rows = $this->db->fetchAll(
            <<<'SQL'
                SELECT eli, display_address, title, promulgation
                FROM act
                WHERE publisher = 'MP'
                  AND title LIKE '%statutu Ministerstwu%'
                  AND promulgation >= :from
                  AND (:to IS NULL OR promulgation <= :to)
                ORDER BY promulgation DESC
                SQL,
            ['from' => $from, 'to' => $to],
        );

        $out = [];
        foreach ($rows as $r) {
            $title = (string) $r['title'];
            if (preg_match('/statutu Ministerstwu ([^,]+?)(?:$|\s+w\s+|,)/u', $title, $m) !== 1) {
                continue;
            }

            $out[] = [
                'ministerstwo' => trim($m[1]),
                'nowy' => !str_contains($title, 'zmieniające') && !str_contains($title, 'jednolitego tekstu'),
                'adres' => (string) $r['display_address'],
                'data' => (string) $r['promulgation'],
                'url' => sprintf('https://api.sejm.gov.pl/eli/acts/%s/text.pdf', $r['eli']),
            ];
        }

        return $out;
    }

    private function min(string|null $a, mixed $b): string|null
    {
        $b = $b === null ? null : (string) $b;

        return $a === null ? $b : ($b === null ? $a : min($a, $b));
    }

    private function max(string|null $a, mixed $b): string|null
    {
        $b = $b === null ? null : (string) $b;

        return $a === null ? $b : ($b === null ? $a : max($a, $b));
    }
}
