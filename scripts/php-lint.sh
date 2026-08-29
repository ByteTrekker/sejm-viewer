#!/usr/bin/env bash
# Sprawdzenie składni PHP. Bez zależności zewnętrznych - projekt celowo
# nie wymaga composer install do uruchomienia.
#
# Bez `mapfile`, bo macOS ma bash 3.2 i hook musi działać u każdego lokalnie.
set -euo pipefail

if (( $# > 0 )); then
    files=("$@")
else
    files=()
    while IFS= read -r file; do
        files+=("$file")
    done < <(find bin src -name '*.php' -type f)
fi

failed=0
for file in "${files[@]}"; do
    if ! output="$(php -l "$file" 2>&1)"; then
        echo "$output"
        failed=1
    fi
done

if (( failed == 0 )); then
    echo "OK   składnia PHP: ${#files[@]} plików"
fi

exit "$failed"
