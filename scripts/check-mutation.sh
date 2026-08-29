#!/usr/bin/env bash
# Testy mutacyjne warstwy czystej. Odpowiednik `make mutation` z projektów
# pythonowych: test ma WYKRYWAĆ zmianę reguły, a nie tylko wykonać linię.
set -euo pipefail

if ! php -m | grep -qiE '^(pcov|xdebug)$'; then
    echo "POMINIĘTE: brak sterownika pokrycia (pcov albo xdebug)."
    echo "           Mutacje są egzekwowane w CI, gdzie PHP działa z pcov."
    exit 0
fi

mkdir -p var
exec vendor/bin/infection \
    --no-interaction \
    --no-progress \
    --threads=max \
    --show-mutations
