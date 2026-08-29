.DEFAULT_GOAL := help
SHELL := /usr/bin/env bash

.PHONY: help fetch build check lint style style-fix stan test coverage mutation audit commits smoke clean

help: ## Lista celów
	@grep -E '^[a-z-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN{FS=":.*?## "}{printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}'

fetch: ## Pobierz komplet danych z API (kilkanaście minut)
	php bin/fetch.php --term=7,8,9,10
	php bin/fetch-acts.php --from=2015 --to=2026

build: ## Zbuduj wszystkie trzy dashboardy z bazy
	php bin/build.php
	php bin/build-vacatio.php
	php bin/build-vacatio.php --exclude-technical

lint: ## Składnia PHP (bez zależności)
	./scripts/php-lint.sh

style: ## PSR-12 — sprawdzenie bez zmian w plikach
	vendor/bin/php-cs-fixer check --diff

style-fix: ## PSR-12 — automatyczna poprawa
	vendor/bin/php-cs-fixer fix

stan: ## PHPStan poziom 8
	php -d memory_limit=1G vendor/bin/phpstan analyse --no-progress

test: ## Testy jednostkowe (bez sieci i bazy)
	vendor/bin/phpunit

coverage: ## Testy z progiem pokrycia warstwy czystej
	./scripts/check-coverage.sh 90

mutation: ## Testy mutacyjne domeny i parsowania argumentów
	./scripts/check-mutation.sh

smoke: ## Test dymny metodologii end-to-end (bez sieci)
	./scripts/smoke.sh

audit: ## Podatności w zależnościach deweloperskich
	composer audit

commits: ## Konwencja commitów w origin/main..HEAD
	./scripts/check-commits.sh

check: lint style stan test coverage mutation smoke audit ## Bramki CI możliwe do uruchomienia lokalnie

clean: ## Usuń wygenerowane artefakty (baza zostaje)
	rm -f public/index.html public/vacatio.html public/vacatio-merytoryczne.html
	rm -f public/data.json public/vacatio.json public/vacatio-merytoryczne.json
	rm -rf var/coverage var/infection.log
