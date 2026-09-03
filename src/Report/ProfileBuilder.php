<?php

declare(strict_types=1);

namespace Milczenie\Report;

use Milczenie\Storage\Database;

/**
 * Profil pojedynczego posla: jedno miejsce, w ktorym schodzi sie wszystko, co
 * o nim wiemy - frekwencja, sposob glosowania, zgodnosc z klubem, transfery
 * i aktywnosc w pytaniach.
 *
 * Profile skladamy z raportu JUZ policzonego dla kadencji, a nie liczymy od nowa:
 * inaczej te same reguly zylyby w dwoch miejscach i liczby na profilu rozjechalyby
 * sie z liczbami w rankingach.
 */
final class ProfileBuilder
{
    /** Ile pytan pokazujemy na profilu. */
    private const KEEP_TOP = 3;

    /** Przy tylu kandydatach przycinamy liste - kompromis miedzy pamiecia a liczba sortowan. */
    private const KEEP_BUFFER = 12;

    /**
     * Domyslna liczba ostatnich glosowan na profilu. Pelna lista to ok. 4,5 tys. pozycji
     * na posla - okolo 400 KB na strone i ponad gigabajt na wszystkie kadencje. Dane sa
     * dostepne w calosci (--profile-votes=all), ale domyslnie ograniczone.
     */
    public const DEFAULT_RECENT_VOTES = 400;

    /**
     * @param int|null $recentVotes liczba ostatnich glosowan na profilu; null = wszystkie
     */
    public function __construct(
        private readonly Database $db,
        private readonly ?int $recentVotes = self::DEFAULT_RECENT_VOTES,
    ) {
    }

    /**
     * @param array<string, mixed> $report raport kadencji z bin/build.php
     * @return array<int, array<string, mixed>> profil per identyfikator posla
     */
    public function buildAll(int $term, array $report): array
    {
        $identity = $this->identity($term);
        if ($identity === []) {
            return [];
        }

        $votes = $this->voteBreakdown($term);
        $social = $this->social($term);
        $questions = $this->indexById($report['poslowie'] ?? [], 'id');
        $absence = $this->indexById($report['nieobecnosci']['poslowie'] ?? [], 'id');
        $transfers = $this->indexById($report['dyscyplina']['transfery']['lista'] ?? [], 'id');

        // Dyscyplina jest liczona per (posel, klub), wiec posel moze miec kilka wierszy.
        $discipline = [];
        foreach ($report['dyscyplina']['poslowie'] ?? [] as $row) {
            $discipline[(int) $row['id']][] = $row;
        }

        $clubAbsence = [];
        foreach ($report['nieobecnosci']['kluby'] ?? [] as $row) {
            $clubAbsence[(string) $row['klucz']] = $row;
        }

        // Jedno zapytanie na kadencje zamiast jednego na posla: wersja per posel
        // liczyla profile minutami, bo json_each przechodzil caly zbior pytan za kazdym razem.
        $waiting = $this->longestWaitingByMember($term);
        $recent = $this->recentVotes($term);
        $deviations = $this->deviatingVotes($term);

        $profiles = [];
        foreach ($identity as $id => $mp) {
            $q = $questions[$id] ?? null;
            $a = $absence[$id] ?? null;

            $profiles[$id] = [
                'id' => $id,
                'kadencja' => $term,
                // Oficjalna strona posla: identyfikator jest dopelniany do trzech cyfr.
                'url_sejm' => sprintf(
                    'https://www.sejm.gov.pl/sejm%d.nsf/posel.xsp?id=%03d&type=A',
                    $term,
                    $id,
                ),
                'nazwa' => $mp['nazwa'],
                'klub' => $mp['klub'],
                'okreg' => $mp['okreg'],
                'aktywny' => $mp['aktywny'],
                'konta' => $social[$id] ?? [],
                'glosy' => $votes[$id] ?? null,
                'frekwencja' => $a,
                'frekwencja_klubu' => $a !== null ? ($clubAbsence[$a['klub']] ?? null) : null,
                'dyscyplina' => $discipline[$id] ?? [],
                'transfery' => $transfers[$id]['okresy'] ?? null,
                'pytania' => $q,
                'najdluzej_czekaly' => $waiting[$id] ?? [],
                'glosowania' => [
                    'ostatnie' => $recent[$id] ?? [],
                    'wbrew_linii' => array_slice($deviations[$id] ?? [], 0, 200),
                    'wbrew_linii_razem' => count($deviations[$id] ?? []),
                    'pokazano_ostatnich' => $this->recentVotes,
                ],
            ];
        }

        return $profiles;
    }

    /**
     * @return array<int, array{nazwa: string, klub: string, okreg: ?string, aktywny: bool}>
     */
    private function identity(int $term): array
    {
        $out = [];
        foreach ($this->db->fetchAll('SELECT id, name, club, district, active FROM mp WHERE term = :term', ['term' => $term]) as $row) {
            $out[(int) $row['id']] = [
                'nazwa' => (string) $row['name'],
                'klub' => isset($row['club']) ? (string) $row['club'] : 'brak klubu',
                'okreg' => isset($row['district']) ? (string) $row['district'] : null,
                'aktywny' => (bool) (int) $row['active'],
            ];
        }

        return $out;
    }

    /**
     * Rozklad glosow: za / przeciw / wstrzymal sie / nieobecny.
     *
     * @return array<int, array<string, int>>
     */
    private function voteBreakdown(int $term): array
    {
        $out = [];
        foreach ($this->db->fetchAll('SELECT mp_id, vote, COUNT(*) AS n FROM vote WHERE term = :term GROUP BY mp_id, vote', ['term' => $term]) as $row) {
            $out[(int) $row['mp_id']][(string) $row['vote']] = (int) $row['n'];
        }

        return $out;
    }

    /**
     * @return array<int, list<array{platforma: string, konto: string, url: string, qid: string}>>
     */
    private function social(int $term): array
    {
        $out = [];
        foreach ($this->db->fetchAll('SELECT mp_id, platform, handle, qid FROM mp_social WHERE term = :term', ['term' => $term]) as $row) {
            $platform = (string) $row['platform'];
            $handle = (string) $row['handle'];

            $out[(int) $row['mp_id']][] = [
                'platforma' => $platform,
                'konto' => $handle,
                'url' => $this->accountUrl($platform, $handle),
                'qid' => (string) $row['qid'],
            ];
        }

        return $out;
    }

    private function accountUrl(string $platform, string $handle): string
    {
        return match ($platform) {
            'x' => 'https://x.com/' . rawurlencode($handle),
            'facebook' => 'https://www.facebook.com/' . rawurlencode($handle),
            'instagram' => 'https://www.instagram.com/' . rawurlencode($handle),
            default => $handle,
        };
    }

    /**
     * Trzy pytania na posla, ktore najdluzej czekaly albo wciaz czekaja.
     *
     * @return array<int, list<array<string, mixed>>>
     */
    private function longestWaitingByMember(int $term): array
    {
        $rows = $this->db->fetchAll(
            <<<'SQL'
                SELECT q.kind, q.num, q.title, q.authors,
                       COALESCE(MIN(a.sent_date), q.sent_date) AS przekazano,
                       (SELECT MIN(r.receipt_date) FROM reply r
                          WHERE r.question_id = q.id AND r.receipt_date IS NOT NULL) AS odpowiedziano
                FROM question q
                JOIN addressee a ON a.question_id = q.id
                WHERE q.term = :term
                GROUP BY q.id
                SQL,
            ['term' => $term],
        );

        $today = new \DateTimeImmutable('today');
        $byMember = [];

        foreach ($rows as $row) {
            if ($row['przekazano'] === null) {
                continue;
            }

            $from = new \DateTimeImmutable((string) $row['przekazano']);
            $to = $row['odpowiedziano'] === null ? $today : new \DateTimeImmutable((string) $row['odpowiedziano']);
            $days = (int) $from->diff($to)->days;

            /** @var list<string> $authors */
            $authors = json_decode((string) $row['authors'], true, 512, JSON_THROW_ON_ERROR) ?: [];
            if ($authors === []) {
                continue;
            }

            $entry = [
                'nr' => (int) $row['num'],
                'rodzaj' => (string) $row['kind'],
                'tytul' => (string) $row['title'],
                'przekazano' => (string) $row['przekazano'],
                'odpowiedziano' => $row['odpowiedziano'] === null ? null : (string) $row['odpowiedziano'],
                'dni' => $days,
                'url' => sprintf(
                    'https://sejm.gov.pl/sejm%d.nsf/interpelacja.xsp?typ=%s&nr=%d',
                    $term,
                    $row['kind'] === 'interpelacja' ? 'int' : 'zap',
                    (int) $row['num'],
                ),
            ];

            foreach ($authors as $authorId) {
                $id = (int) $authorId;
                $byMember[$id][] = $entry;

                // Przycinamy w locie: trzymanie wszystkich pytan kazdego autora
                // wyczerpywalo pamiec przy 50 tys. pytan kadencji.
                if (count($byMember[$id]) > self::KEEP_BUFFER) {
                    usort($byMember[$id], static fn (array $x, array $y): int => $y['dni'] <=> $x['dni']);
                    $byMember[$id] = array_slice($byMember[$id], 0, self::KEEP_TOP);
                }
            }
        }

        foreach ($byMember as $id => $entries) {
            usort($entries, static fn (array $x, array $y): int => $y['dni'] <=> $x['dni']);
            $byMember[$id] = array_slice($entries, 0, self::KEEP_TOP);
        }

        return $byMember;
    }

    /**
     * Jak posel glosowal w ostatnich glosowaniach kadencji.
     *
     * @return array<int, list<array<string, mixed>>>
     */
    private function recentVotes(int $term): array
    {
        $rows = $this->db->fetchAll(
            <<<'SQL'
                WITH ostatnie AS (
                    SELECT sitting, number, date, title
                    FROM voting
                    WHERE term = :term
                    ORDER BY date DESC, sitting DESC, number DESC
                    LIMIT CAST(:limit AS INTEGER)
                )
                SELECT v.mp_id, o.sitting, o.number, o.date, o.title, v.vote, v.club
                FROM ostatnie o
                JOIN vote v ON v.term = :term AND v.sitting = o.sitting AND v.number = o.number
                SQL,
            ['term' => $term, 'limit' => $this->recentVotes ?? -1],
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['mp_id']][] = [
                'data' => (string) $row['date'],
                'posiedzenie' => (int) $row['sitting'],
                'nr' => (int) $row['number'],
                'tytul' => mb_substr((string) $row['title'], 0, 110),
                'glos' => (string) $row['vote'],
                'klub' => isset($row['club']) ? (string) $row['club'] : null,
                'wbrew' => false,
                'url' => $this->votingUrl($term, (int) $row['sitting'], (int) $row['number']),
            ];
        }

        foreach ($out as $id => $list) {
            usort($list, static fn (array $a, array $b): int => [$b['data'], $b['posiedzenie'], $b['nr']] <=> [$a['data'], $a['posiedzenie'], $a['nr']]);
            $out[$id] = $list;
        }

        return $out;
    }

    /**
     * Glosowania, w ktorych posel glosowal inaczej niz wiekszosc wlasnego klubu.
     * Ta sama definicja linii co w rankingu dyscypliny.
     *
     * @return array<int, list<array<string, mixed>>>
     */
    private function deviatingVotes(int $term): array
    {
        $rows = $this->db->fetchAll(
            <<<'SQL'
                WITH counts AS (
                    SELECT sitting, number, club, vote, COUNT(*) AS n
                    FROM vote
                    WHERE term = :term AND vote IN ('YES', 'NO', 'ABSTAIN')
                      AND club IS NOT NULL AND club NOT IN ('niez.', 'niezrz.', 'niezrzeszeni')
                    GROUP BY sitting, number, club, vote
                ),
                line AS (
                    SELECT sitting, number, club, vote AS linia,
                           SUM(n) OVER (PARTITION BY sitting, number, club) AS klub_n,
                           ROW_NUMBER() OVER (PARTITION BY sitting, number, club ORDER BY n DESC, vote) AS rn
                    FROM counts
                ),
                ustalone AS (
                    SELECT sitting, number, club, linia FROM line
                    WHERE rn = 1 AND klub_n >= 10
                )
                SELECT v.mp_id, v.sitting, v.number, v.vote, v.club, u.linia, o.date, o.title
                FROM vote v
                JOIN ustalone u ON u.sitting = v.sitting AND u.number = v.number AND u.club = v.club
                JOIN voting o ON o.term = v.term AND o.sitting = v.sitting AND o.number = v.number
                WHERE v.term = :term AND v.vote IN ('YES', 'NO', 'ABSTAIN') AND v.vote <> u.linia
                ORDER BY o.date DESC
                SQL,
            ['term' => $term],
        );

        $out = [];
        foreach ($rows as $row) {
            $id = (int) $row['mp_id'];
            if (count($out[$id] ?? []) >= 200) {
                continue;
            }

            $out[$id][] = [
                'data' => (string) $row['date'],
                'posiedzenie' => (int) $row['sitting'],
                'nr' => (int) $row['number'],
                'tytul' => mb_substr((string) $row['title'], 0, 110),
                'glos' => (string) $row['vote'],
                'linia' => (string) $row['linia'],
                'klub' => (string) $row['club'],
                'wbrew' => true,
                'url' => $this->votingUrl($term, (int) $row['sitting'], (int) $row['number']),
            ];
        }

        return $out;
    }

    /**
     * Strona konkretnego glosowania na sejm.gov.pl - wraz z imiennym wykazem glosow.
     */
    private function votingUrl(int $term, int $sitting, int $number): string
    {
        return sprintf(
            'https://www.sejm.gov.pl/sejm%d.nsf/agent.xsp?symbol=glosowania&NrKadencji=%d&NrPosiedzenia=%d&NrGlosowania=%d',
            $term,
            $term,
            $sitting,
            $number,
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function indexById(array $rows, string $key): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (isset($row[$key])) {
                $out[(int) $row[$key]] = $row;
            }
        }

        return $out;
    }
}
