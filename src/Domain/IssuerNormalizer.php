<?php

declare(strict_types=1);

namespace Milczenie\Domain;

/**
 * ELI zwraca organ wydajacy wersalikami i skrotem ("MIN. FINANSÓW"), inaczej niz
 * API interpelacji ("minister finansów i gospodarki"). Normalizujemy do wspolnego
 * klucza, zeby dalo sie zestawic ten sam resort w obu rankingach.
 */
final class IssuerNormalizer
{
    /**
     * ELI zapisuje organy wersalikami i skrotami. Rozwijamy je do formy czytelnej,
     * zgodnej z zapisem uzywanym w rankingu interpelacji.
     *
     * @var array<string, string>
     */
    private const LABELS = [
        'sejm' => 'Sejm RP',
        'rada ministrow' => 'Rada Ministrów',
        'prez. rady ministrow' => 'Prezes Rady Ministrów',
        'prezydent rzeczypospolitej polskiej' => 'Prezydent RP',
        'nieznany' => 'Organ nieokreślony w API',
    ];

    /** @var array<string, string> */
    private array $displayNames = [];

    /**
     * Swiadomie NIE scalamy organow miedzy latami. Ranking obejmuje kilkanascie lat,
     * w ktorych te same kompetencje wedrowaly miedzy urzedami o roznych nazwach -
     * sklejenie "Min. finansow" z "Min. rozwoju i finansow" sugerowaloby ciaglosc,
     * ktorej nie bylo. Pokazujemy organ tak, jak nazywa go Dziennik Ustaw.
     */
    public function normalize(string $raw): string
    {
        $slug = $this->slug($raw);
        $key = str_starts_with($slug, 'min. ') ? 'minister ' . substr($slug, 5) : $slug;

        $this->displayNames[$key] ??= $this->prettify($raw);

        return $key;
    }

    public function displayName(string $key): string
    {
        return $this->displayNames[$key] ?? $key;
    }

    private function slug(string $raw): string
    {
        $value = mb_strtolower(trim($raw), 'UTF-8');
        $value = strtr($value, [
            'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n',
            'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
        ]);

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    private function prettify(string $raw): string
    {
        $slug = $this->slug($raw);
        if (isset(self::LABELS[$slug])) {
            return self::LABELS[$slug];
        }

        $value = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $raw)), 'UTF-8');
        if (str_starts_with($value, 'min. ')) {
            return 'Minister ' . mb_substr($value, 5, null, 'UTF-8');
        }

        return mb_strtoupper(mb_substr($value, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($value, 1, null, 'UTF-8');
    }
}
