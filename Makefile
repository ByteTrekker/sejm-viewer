.DEFAULT_GOAL := help
SHELL := /usr/bin/env bash

.PHONY: help fetch build check lint smoke commits clean

help: ## Lista celów
	@grep -E '^[a-z-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN{FS=":.*?## "}{printf "  \033[36m%-10s\033[0m %s\n", $$1, $$2}'

fetch: ## Pobierz komplet danych z API (kilkanaście minut)
	php bin/fetch.php --term=7,8,9,10
	php bin/fetch-acts.php --from=2015 --to=2026

build: ## Zbuduj wszystkie trzy dashboardy z bazy
	php bin/build.php
	php bin/build-vacatio.php
	php bin/build-vacatio.php --exclude-technical

check: lint smoke ## Pełna bramka lokalna

lint: ## Składnia PHP
	./scripts/php-lint.sh

smoke: ## Test dymny metodologii (bez sieci)
	./scripts/smoke.sh

commits: ## Konwencja commitów w origin/main..HEAD
	./scripts/check-commits.sh

clean: ## Usuń wygenerowane artefakty (baza zostaje)
	rm -f public/index.html public/vacatio.html public/vacatio-merytoryczne.html
	rm -f public/data.json public/vacatio.json public/vacatio-merytoryczne.json
