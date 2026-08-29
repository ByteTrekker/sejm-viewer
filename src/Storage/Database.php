<?php

declare(strict_types=1);

namespace Milczenie\Storage;

final class Database
{
    private const SCHEMA = <<<'SQL'
        CREATE TABLE IF NOT EXISTS question (
            id            TEXT PRIMARY KEY,
            kind          TEXT NOT NULL,
            term          INTEGER NOT NULL,
            num           INTEGER NOT NULL,
            title         TEXT NOT NULL,
            receipt_date  TEXT,
            sent_date     TEXT,
            authors       TEXT NOT NULL,
            author_count  INTEGER NOT NULL
        );

        CREATE TABLE IF NOT EXISTS addressee (
            question_id   TEXT NOT NULL,
            recipient_raw TEXT NOT NULL,
            recipient_key TEXT NOT NULL,
            sent_date     TEXT,
            PRIMARY KEY (question_id, recipient_raw)
        );

        CREATE TABLE IF NOT EXISTS reply (
            question_id     TEXT NOT NULL,
            reply_key       TEXT NOT NULL,
            author          TEXT,
            receipt_date    TEXT,
            prolongation    INTEGER NOT NULL DEFAULT 0,
            only_attachment INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (question_id, reply_key)
        );

        CREATE TABLE IF NOT EXISTS mp (
            id       INTEGER NOT NULL,
            term     INTEGER NOT NULL,
            name     TEXT NOT NULL,
            club     TEXT,
            district TEXT,
            active   INTEGER NOT NULL DEFAULT 1,
            PRIMARY KEY (id, term)
        );

        CREATE TABLE IF NOT EXISTS voting (
            term         INTEGER NOT NULL,
            sitting      INTEGER NOT NULL,
            number       INTEGER NOT NULL,
            date         TEXT,
            title        TEXT,
            topic        TEXT,
            kind         TEXT,
            total_voted  INTEGER,
            PRIMARY KEY (term, sitting, number)
        );

        CREATE TABLE IF NOT EXISTS vote (
            term    INTEGER NOT NULL,
            sitting INTEGER NOT NULL,
            number  INTEGER NOT NULL,
            mp_id   INTEGER NOT NULL,
            club    TEXT,
            vote    TEXT NOT NULL,
            PRIMARY KEY (term, sitting, number, mp_id)
        );

        CREATE TABLE IF NOT EXISTS act (
            eli               TEXT PRIMARY KEY,
            publisher         TEXT NOT NULL,
            year              INTEGER NOT NULL,
            pos               INTEGER NOT NULL,
            type              TEXT NOT NULL,
            title             TEXT NOT NULL,
            announcement_date TEXT,
            promulgation      TEXT,
            entry_into_force  TEXT,
            in_force          TEXT,
            status            TEXT,
            display_address   TEXT
        );

        CREATE TABLE IF NOT EXISTS act_issuer (
            eli        TEXT NOT NULL,
            issuer_raw TEXT NOT NULL,
            issuer_key TEXT NOT NULL,
            PRIMARY KEY (eli, issuer_raw)
        );

        CREATE TABLE IF NOT EXISTS term (
            num       INTEGER PRIMARY KEY,
            date_from TEXT NOT NULL,
            date_to   TEXT
        );

        CREATE TABLE IF NOT EXISTS meta (
            key   TEXT PRIMARY KEY,
            value TEXT NOT NULL
        );

        CREATE INDEX IF NOT EXISTS idx_question_term ON question (term, kind);
        CREATE INDEX IF NOT EXISTS idx_addressee_key ON addressee (recipient_key);
        CREATE INDEX IF NOT EXISTS idx_reply_question ON reply (question_id);
        CREATE INDEX IF NOT EXISTS idx_act_year ON act (year, type);
        CREATE INDEX IF NOT EXISTS idx_act_issuer_key ON act_issuer (issuer_key);
        CREATE INDEX IF NOT EXISTS idx_vote_mp ON vote (term, mp_id);
        SQL;

    private function __construct(public readonly \PDO $pdo)
    {
    }

    public static function open(string $path): self
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Nie mozna utworzyc katalogu ' . $dir);
        }

        $pdo = new \PDO('sqlite:' . $path, options: [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);

        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec(self::SCHEMA);

        return new self($pdo);
    }

    /**
     * PDO::query() deklaruje `PDOStatement|false`, choc przy ERRMODE_EXCEPTION
     * nigdy nie zwroci false. Zamiast rozsiewac po kodzie rzutowania, odczyty
     * przechodza przez te metody o uczciwym typie zwracanym.
     *
     * @param array<string, scalar|null> $params
     * @return list<array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll();

        return $rows;
    }

    /**
     * @param array<string, scalar|null> $params
     * @return array<string, mixed>|null
     */
    public function fetchRow(string $sql, array $params = []): ?array
    {
        return $this->fetchAll($sql, $params)[0] ?? null;
    }

    /**
     * @param array<string, scalar|null> $params
     * @return list<int>
     */
    public function fetchInts(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        /** @var list<scalar> $values */
        $values = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        return array_map(intval(...), $values);
    }

    public function setMeta(string $key, string $value): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO meta (key, value) VALUES (?, ?) ON CONFLICT (key) DO UPDATE SET value = excluded.value');
        $stmt->execute([$key, $value]);
    }

    public function getMeta(string $key): ?string
    {
        $stmt = $this->pdo->prepare('SELECT value FROM meta WHERE key = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();

        return is_string($value) ? $value : null;
    }
}
