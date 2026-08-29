#!/usr/bin/env bash
# Próg pokrycia warstwy czystej (domena + parsowanie argumentów).
# Odpowiednik `--cov-fail-under` z projektów pythonowych; PHPUnit nie ma
# wbudowanego progu, więc liczymy go z raportu clover.
set -euo pipefail

threshold="${1:-90}"
report="${COVERAGE_REPORT:-var/coverage/clover.xml}"

if ! php -m | grep -qiE '^(pcov|xdebug)$'; then
    echo "POMINIĘTE: brak sterownika pokrycia (pcov albo xdebug)."
    echo "           Próg jest egzekwowany w CI, gdzie PHP działa z pcov."
    exit 0
fi

mkdir -p "$(dirname "$report")"
vendor/bin/phpunit --coverage-clover "$report" >/dev/null

php -r '
$report = $argv[1];
$threshold = (float) $argv[2];
$xml = simplexml_load_file($report);
if ($xml === false) { fwrite(STDERR, "Nie mogę odczytać $report\n"); exit(2); }
$m = $xml->project->metrics;
$statements = (int) $m["statements"];
$covered = (int) $m["coveredstatements"];
$pct = $statements > 0 ? 100 * $covered / $statements : 100.0;
printf("pokrycie: %.1f%% (%d/%d instrukcji), próg %.0f%%\n", $pct, $covered, $statements, $threshold);
if ($pct + 1e-9 < $threshold) { fwrite(STDERR, "BŁĄD: pokrycie poniżej progu\n"); exit(1); }
echo "OK   próg pokrycia spełniony\n";
' "$report" "$threshold"
