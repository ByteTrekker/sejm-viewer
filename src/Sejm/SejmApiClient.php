<?php

declare(strict_types=1);

namespace Milczenie\Sejm;

/**
 * Klient api.sejm.gov.pl. Bez autoryzacji i bez limitow, ale API bywa wolne -
 * stad retry z backoffem i strumieniowe stronicowanie.
 */
final class SejmApiClient
{
    private const BASE_URL = 'https://api.sejm.gov.pl';
    private const PAGE_SIZE = 500;
    private const MAX_ATTEMPTS = 4;

    public function __construct(
        private readonly int $timeoutSeconds = 120,
        private readonly ?\Closure $logger = null,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchTerms(): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->getJson('/sejm/term');

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchMembers(int $term): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->getJson(sprintf('/sejm/term%d/MP', $term));

        return $rows;
    }

    /**
     * Zwraca kolejne strony wynikow, zeby nie trzymac 44 tys. rekordow w pamieci naraz.
     *
     * @return \Generator<int, list<array<string, mixed>>>
     */
    public function paginate(int $term, string $endpoint): \Generator
    {
        $offset = 0;

        do {
            $path = sprintf('/sejm/term%d/%s?limit=%d&offset=%d', $term, $endpoint, self::PAGE_SIZE, $offset);
            /** @var list<array<string, mixed>> $page */
            $page = $this->getJson($path);

            if ($page === []) {
                return;
            }

            yield $page;
            $offset += count($page);
        } while (count($page) === self::PAGE_SIZE);
    }

    /**
     * Numery posiedzen kadencji - punkt wejscia do listy glosowan.
     *
     * @return list<int>
     */
    public function fetchProceedings(int $term): array
    {
        $rows = $this->getJson(sprintf('/sejm/term%d/proceedings', $term));

        $numbers = [];
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['number']) && (int) $row['number'] > 0) {
                $numbers[] = (int) $row['number'];
            }
        }

        return $numbers;
    }

    /**
     * Lista aktow z danego rocznika Dziennika Ustaw / Monitora Polskiego.
     * Uwaga: lista nie zawiera entryIntoForce - date wejscia w zycie ma dopiero detal aktu.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchActsIndex(string $publisher, int $year): array
    {
        $data = $this->getJson(sprintf('/eli/acts/%s/%d', rawurlencode($publisher), $year));
        /** @var list<array<string, mixed>> $items */
        $items = $data['items'] ?? [];

        return $items;
    }

    /**
     * @param list<array{publisher: string, year: int, pos: int}> $refs
     * @return \Generator<int, array<string, mixed>>
     */
    public function fetchActDetails(array $refs, int $concurrency = 8): \Generator
    {
        $paths = array_map(
            static fn (array $r): string => sprintf('/eli/acts/%s/%d/%d', rawurlencode($r['publisher']), $r['year'], $r['pos']),
            $refs,
        );

        foreach ($this->fetchMany($paths, $concurrency) as $act) {
            /** @var array<string, mixed> $act */
            yield $act;
        }
    }

    /**
     * Rownolegle pobranie wielu zasobow. Przy kilkunastu tysiacach zadan sekwencyjny
     * curl to pol godziny, wiec uzywamy curl_multi z ograniczona liczba polaczen,
     * zeby nie zajechac API. Odpowiedzi wracaja w kolejnosci ukonczenia, nie zadania -
     * kazdy rekord musi wiec sam sie identyfikowac.
     *
     * @param list<string> $paths
     * @return \Generator<int, array<mixed>>
     */
    public function fetchMany(array $paths, int $concurrency = 8): \Generator
    {
        $multi = curl_multi_init();
        $pending = $paths;
        $active = [];

        $start = function () use (&$pending, &$active, $multi): void {
            $path = array_shift($pending);
            if ($path === null) {
                return;
            }

            $ch = $this->handle($path);
            curl_multi_add_handle($multi, $ch);
            $active[(int) $ch] = $ch;
        };

        for ($i = 0; $i < $concurrency; $i++) {
            $start();
        }

        do {
            curl_multi_exec($multi, $running);
            curl_multi_select($multi, 0.5);

            while (($info = curl_multi_info_read($multi)) !== false) {
                $ch = $info['handle'];
                $body = curl_multi_getcontent($ch);
                $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                curl_multi_remove_handle($multi, $ch);
                unset($active[(int) $ch]);

                if ($status === 200 && is_string($body)) {
                    $decoded = json_decode($body, true);
                    if (is_array($decoded)) {
                        yield $decoded;
                    }
                } else {
                    $this->log(sprintf('  ! zasob pominiety: HTTP %d', $status));
                }

                $start();
            }
        } while ($active !== [] || $pending !== []);

        curl_multi_close($multi);
    }

    public function countItems(int $term, string $endpoint): int
    {
        $ch = $this->handle(sprintf('/sejm/term%d/%s?limit=1', $term, $endpoint));
        curl_setopt($ch, CURLOPT_NOBODY, false);
        curl_setopt($ch, CURLOPT_HEADER, true);

        $response = curl_exec($ch);
        if (!is_string($response)) {
            throw new \RuntimeException('Nie udalo sie pobrac naglowkow: ' . curl_error($ch));
        }

        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);

        $headers = substr($response, 0, $headerSize);
        if (preg_match('/^X-Total-Count:\s*(\d+)/mi', $headers, $m) === 1) {
            return (int) $m[1];
        }

        return 0;
    }

    /**
     * @return array<mixed>
     */
    private function getJson(string $path): array
    {
        $lastError = '';

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $ch = $this->handle($path);
            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $lastError = curl_error($ch);

            if (is_string($body) && $status === 200) {
                $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($decoded)) {
                    throw new \RuntimeException('Nieoczekiwany ksztalt odpowiedzi dla ' . $path);
                }

                return $decoded;
            }

            $this->log(sprintf('  ! %s -> HTTP %d %s (proba %d/%d)', $path, $status, $lastError, $attempt, self::MAX_ATTEMPTS));
            sleep(2 ** $attempt);
        }

        throw new \RuntimeException(sprintf('Nie udalo sie pobrac %s: %s', $path, $lastError));
    }

    private function handle(string $path): \CurlHandle
    {
        $ch = curl_init(self::BASE_URL . $path);
        if (!$ch instanceof \CurlHandle) {
            throw new \RuntimeException('Nie udalo sie zainicjowac cURL.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_ENCODING => 'gzip',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => 'sejm-viewer/0.1 (obywatelski monitoring danych Sejmu)',
        ]);

        return $ch;
    }

    private function log(string $message): void
    {
        if ($this->logger !== null) {
            ($this->logger)($message);
        }
    }
}
