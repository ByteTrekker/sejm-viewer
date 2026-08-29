<?php

declare(strict_types=1);

namespace Milczenie\Import;

use Milczenie\Domain\QuestionKind;
use Milczenie\Domain\RecipientNormalizer;
use Milczenie\Sejm\SejmApiClient;
use Milczenie\Storage\Database;

final class QuestionImporter
{
    public function __construct(
        private readonly SejmApiClient $api,
        private readonly Database $db,
        private readonly RecipientNormalizer $normalizer,
        private readonly \Closure $logger,
    ) {
    }

    /**
     * Metadane kadencji sa potrzebne do rankingu: dla kadencji zamknietej data odciecia
     * musi byc data jej konca, inaczej porownanie z kadencja trwajaca jest bez sensu.
     */
    public function importTerms(): int
    {
        $stmt = $this->db->pdo->prepare(
            'INSERT INTO term (num, date_from, date_to) VALUES (:num, :from, :to)
             ON CONFLICT (num) DO UPDATE SET date_from = excluded.date_from, date_to = excluded.date_to',
        );

        $count = 0;
        foreach ($this->api->fetchTerms() as $term) {
            $stmt->execute([
                'num' => (int) $term['num'],
                'from' => substr((string) $term['from'], 0, 10),
                'to' => isset($term['to']) ? substr((string) $term['to'], 0, 10) : null,
            ]);
            $count++;
        }

        return $count;
    }

    public function importMembers(int $term): int
    {
        $stmt = $this->db->pdo->prepare(
            'INSERT INTO mp (id, term, name, club, district, active) VALUES (:id, :term, :name, :club, :district, :active)
             ON CONFLICT (id, term) DO UPDATE SET name = excluded.name, club = excluded.club,
                 district = excluded.district, active = excluded.active',
        );

        $this->db->pdo->beginTransaction();
        $count = 0;

        foreach ($this->api->fetchMembers($term) as $mp) {
            $stmt->execute([
                'id' => (int) $mp['id'],
                'term' => $term,
                'name' => (string) ($mp['firstLastName'] ?? '?'),
                'club' => isset($mp['club']) ? (string) $mp['club'] : null,
                'district' => isset($mp['districtName']) ? (string) $mp['districtName'] : null,
                'active' => ($mp['active'] ?? true) ? 1 : 0,
            ]);
            $count++;
        }

        $this->db->pdo->commit();

        return $count;
    }

    public function import(int $term, QuestionKind $kind): int
    {
        $total = $this->api->countItems($term, $kind->endpoint());
        $this->log(sprintf('  kadencja %d / %s: %d rekordow w API', $term, $kind->value, $total));

        $questionStmt = $this->db->pdo->prepare(
            'INSERT INTO question (id, kind, term, num, title, receipt_date, sent_date, authors, author_count)
             VALUES (:id, :kind, :term, :num, :title, :receipt_date, :sent_date, :authors, :author_count)
             ON CONFLICT (id) DO UPDATE SET title = excluded.title, receipt_date = excluded.receipt_date,
                 sent_date = excluded.sent_date, authors = excluded.authors, author_count = excluded.author_count',
        );
        $addresseeStmt = $this->db->pdo->prepare(
            'INSERT INTO addressee (question_id, recipient_raw, recipient_key, sent_date)
             VALUES (:question_id, :recipient_raw, :recipient_key, :sent_date)
             ON CONFLICT (question_id, recipient_raw) DO UPDATE SET
                 recipient_key = excluded.recipient_key, sent_date = excluded.sent_date',
        );
        $replyStmt = $this->db->pdo->prepare(
            'INSERT INTO reply (question_id, reply_key, author, receipt_date, prolongation, only_attachment)
             VALUES (:question_id, :reply_key, :author, :receipt_date, :prolongation, :only_attachment)
             ON CONFLICT (question_id, reply_key) DO UPDATE SET
                 author = excluded.author, receipt_date = excluded.receipt_date,
                 prolongation = excluded.prolongation, only_attachment = excluded.only_attachment',
        );

        $imported = 0;

        foreach ($this->api->paginate($term, $kind->endpoint()) as $page) {
            $this->db->pdo->beginTransaction();

            foreach ($page as $item) {
                $id = sprintf('%s:%d:%d', $kind->value, $term, (int) $item['num']);
                $authors = array_map(strval(...), (array) ($item['from'] ?? []));

                $questionStmt->execute([
                    'id' => $id,
                    'kind' => $kind->value,
                    'term' => $term,
                    'num' => (int) $item['num'],
                    'title' => (string) ($item['title'] ?? ''),
                    'receipt_date' => $this->date($item['receiptDate'] ?? null),
                    'sent_date' => $this->date($item['sentDate'] ?? null),
                    'authors' => json_encode($authors, JSON_THROW_ON_ERROR),
                    'author_count' => count($authors),
                ]);

                foreach ($this->recipients($item) as $recipient) {
                    $addresseeStmt->execute([
                        'question_id' => $id,
                        'recipient_raw' => $recipient['name'],
                        'recipient_key' => $this->normalizer->normalize($recipient['name']),
                        'sent_date' => $recipient['sent'] ?? $this->date($item['sentDate'] ?? null),
                    ]);
                }

                foreach ((array) ($item['replies'] ?? []) as $i => $replyRaw) {
                    /** @var array<string, mixed> $reply */
                    $reply = $replyRaw;
                    $replyStmt->execute([
                        'question_id' => $id,
                        'reply_key' => (string) ($reply['key'] ?? ('idx-' . $i)),
                        'author' => isset($reply['from']) ? (string) $reply['from'] : null,
                        'receipt_date' => $this->date($reply['receiptDate'] ?? null),
                        'prolongation' => ($reply['prolongation'] ?? false) ? 1 : 0,
                        'only_attachment' => ($reply['onlyAttachment'] ?? false) ? 1 : 0,
                    ]);
                }

                $imported++;
            }

            $this->db->pdo->commit();
            $this->log(sprintf('  ... %d / %d', $imported, $total));
        }

        return $imported;
    }

    /**
     * API zwraca adresatow w dwoch miejscach: `to` (same nazwy) oraz `recipientDetails`
     * (nazwa + data przekazania). Preferujemy to drugie, bo tylko ono ma date startu terminu.
     *
     * @param array<string, mixed> $item
     * @return list<array{name: string, sent: string|null}>
     */
    private function recipients(array $item): array
    {
        $details = (array) ($item['recipientDetails'] ?? []);

        if ($details !== []) {
            $out = [];
            foreach ($details as $detail) {
                $name = trim((string) ($detail['name'] ?? ''));
                if ($name !== '') {
                    $out[] = ['name' => $name, 'sent' => $this->date($detail['sent'] ?? null)];
                }
            }

            if ($out !== []) {
                return $out;
            }
        }

        $out = [];
        foreach ((array) ($item['to'] ?? []) as $name) {
            $name = trim((string) $name);
            if ($name !== '') {
                $out[] = ['name' => $name, 'sent' => null];
            }
        }

        return $out;
    }

    /**
     * API zwraca brakujace daty jako wartownik "0000-12-30" (kadencja VII: 92% odpowiedzi).
     * Wpuszczenie go do bazy daje ujemne czasy odpowiedzi i fikcyjna 100-procentowa
     * terminowosc, wiec odrzucamy wszystko sprzed poczatku III RP.
     */
    private function date(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $date = substr($value, 0, 10);

        return $date >= '1990-01-01' ? $date : null;
    }

    private function log(string $message): void
    {
        ($this->logger)($message);
    }
}
