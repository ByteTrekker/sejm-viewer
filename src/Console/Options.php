<?php

declare(strict_types=1);

namespace Milczenie\Console;

/**
 * Opakowanie na getopt(). Surowy getopt() zwraca `string|false|list<string>`
 * zaleznie od tego, ile razy flaga wystapila w linii polecen - rzutowanie tego
 * na string dziala do momentu, w ktorym ktos poda `--term` dwa razy i dostanie
 * "Array" zamiast wartosci. Tutaj kazdy przypadek jest obsluzony jawnie.
 */
final class Options
{
    /**
     * @param array<string, string|false|list<string>> $raw
     */
    public function __construct(private readonly array $raw)
    {
    }

    /**
     * @param list<string> $longOptions definicje w skladni getopt, np. ['term::', 'skip-mp']
     */
    public static function fromGetopt(array $longOptions): self
    {
        $parsed = getopt('', $longOptions);

        return new self(is_array($parsed) ? $parsed : []);
    }

    /**
     * Flaga bez wartosci (`--skip-mp`) - getopt zwraca dla niej `false`,
     * wiec sama obecnosc klucza jest sygnalem.
     */
    public function has(string $name): bool
    {
        return array_key_exists($name, $this->raw);
    }

    public function string(string $name, string $default): string
    {
        return $this->nullableString($name) ?? $default;
    }

    public function nullableString(string $name): ?string
    {
        $value = $this->raw[$name] ?? null;

        // Powtorzona flaga daje liste - bierzemy ostatnie wystapienie,
        // tak jak robia to typowe narzedzia CLI.
        if (is_array($value)) {
            $value = $value === [] ? null : $value[count($value) - 1];
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function int(string $name, int $default): int
    {
        $value = $this->nullableString($name);

        return $value === null ? $default : (int) $value;
    }

    /**
     * Lista rozdzielona przecinkami: `--term=7,8,9`.
     *
     * @param list<string> $default
     * @return list<string>
     */
    public function commaList(string $name, array $default): array
    {
        $value = $this->nullableString($name);
        if ($value === null) {
            return $default;
        }

        $parts = array_values(array_filter(array_map(trim(...), explode(',', $value)), static fn (string $p): bool => $p !== ''));

        return $parts === [] ? $default : $parts;
    }

    /**
     * @param list<int> $default
     * @return list<int>
     */
    public function commaListOfInt(string $name, array $default): array
    {
        // Wartosc zlozona z samych separatorow ("--term=,,") ma wrocic do domyslnej,
        // a nie dac pustej listy - inaczej raport cicho nie zbudowalby zadnej kadencji.
        $parts = $this->commaList($name, []);

        return $parts === [] ? $default : array_map(intval(...), $parts);
    }
}
