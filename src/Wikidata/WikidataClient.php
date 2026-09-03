<?php

declare(strict_types=1);

namespace Milczenie\Wikidata;

/**
 * Konta w mediach spolecznosciowych polskich politykow.
 *
 * API Sejmu nie podaje zadnych - jedynym kontaktem jest sluzbowy adres @sejm.pl.
 * Wikidata jest jedynym maszynowo czytelnym zrodlem, ale jest zrodlem SPOLECZNOSCIOWYM:
 * dane sa niekompletne i nikt ich nie gwarantuje. Dlatego kazdy rekord niesie
 * identyfikator encji, zeby dalo sie go sprawdzic u zrodla.
 *
 * Zapytania sa celowo waskie - szersze (z serwisem etykiet i OPTIONAL-ami) konczyly
 * sie bledem 502 po stronie Wikidaty.
 */
final class WikidataClient
{
    private const ENDPOINT = 'https://query.wikidata.org/sparql';

    /** Wlasciwosc Wikidaty => nazwa platformy w naszym modelu. */
    public const PROPERTIES = [
        'P2002' => 'x',
        'P2013' => 'facebook',
        'P2003' => 'instagram',
        'P856' => 'www',
    ];

    public function __construct(
        private readonly int $timeoutSeconds = 90,
        private readonly ?\Closure $logger = null,
    ) {
    }

    /**
     * @return list<array{qid: string, name: string, platform: string, handle: string}>
     */
    public function politiciansWithAccounts(): array
    {
        $out = [];

        foreach (self::PROPERTIES as $property => $platform) {
            $rows = $this->query(sprintf(
                'SELECT ?p ?l ?v WHERE { ?p wdt:P27 wd:Q36 ; wdt:P106 wd:Q82955 ; wdt:%s ?v ; rdfs:label ?l . FILTER(LANG(?l) = "pl") }',
                $property,
            ));

            foreach ($rows as $row) {
                $qid = basename((string) ($row['p']['value'] ?? ''));
                $name = trim((string) ($row['l']['value'] ?? ''));
                $handle = trim((string) ($row['v']['value'] ?? ''));

                if ($qid === '' || $name === '' || $handle === '') {
                    continue;
                }

                $out[] = ['qid' => $qid, 'name' => $name, 'platform' => $platform, 'handle' => $handle];
            }

            $this->log(sprintf('  %s (%s): %d rekordow', $platform, $property, count($rows)));
        }

        return $out;
    }

    /**
     * @return list<array<string, array<string, string>>>
     */
    private function query(string $sparql): array
    {
        $url = self::ENDPOINT . '?' . http_build_query(['query' => $sparql, 'format' => 'json']);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $ch = curl_init($url);
            if (!$ch instanceof \CurlHandle) {
                throw new \RuntimeException('Nie udalo sie zainicjowac cURL.');
            }

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->timeoutSeconds,
                CURLOPT_ENCODING => 'gzip',
                CURLOPT_HTTPHEADER => ['Accept: application/sparql-results+json'],
                CURLOPT_USERAGENT => 'sejm-viewer/0.1 (obywatelski monitoring danych Sejmu)',
            ]);

            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

            if ($status === 200 && is_string($body)) {
                $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                /** @var list<array<string, array<string, string>>> $bindings */
                $bindings = is_array($decoded) ? ($decoded['results']['bindings'] ?? []) : [];

                return $bindings;
            }

            $this->log(sprintf('  ! Wikidata HTTP %d (proba %d/3)', $status, $attempt));
            sleep(5 * $attempt);
        }

        throw new \RuntimeException('Wikidata nie odpowiedziala poprawnie po trzech probach.');
    }

    private function log(string $message): void
    {
        if ($this->logger !== null) {
            ($this->logger)($message);
        }
    }
}
