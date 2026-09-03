# CLAUDE.md

Instrukcje dla asystentów pracujących w tym repozytorium.

## Czym jest ten projekt

**sejm-viewer** — dwa dashboardy obywatelskie zbudowane na tej samej zasadzie: bierzemy **obowiązek
z twardym terminem ustawowym**, liczymy na pełnych danych, kto go dotrzymuje,
i pokazujemy wynik razem z metodologią.

* **Ranking milczenia ministerstw** — 21 dni na odpowiedź na interpelację
  (Regulamin Sejmu, art. 192 ust. 1 i art. 195 ust. 1). 162 103 pytania, kadencje VII–X.
* **Vacatio legis** — 14 dni między ogłoszeniem aktu a wejściem w życie
  (ustawa z 20.07.2000 o ogłaszaniu aktów normatywnych, art. 4 ust. 1).
  19 410 aktów Dz.U., 2015–2026.

Odbiorcą jest wyborca i dziennikarz, nie analityk. Liczba, której nie da się
kliknąć do źródła, jest w tym projekcie bezużyteczna.

## Komendy

```bash
make fetch        # pełne pobranie wszystkiego (~5 h, raz)
make refresh      # tylko to, co nowe (sekundy) — do cyklicznego odświeżania
make build        # trzy dashboardy z bazy
make check        # pełna bramka: styl, PHPStan, testy, pokrycie, mutacje, dymny, audyt
make help         # reszta celów
```

**Runtime nie ma zależności.** `bin/bootstrap.php` ma własny autoloader PSR-4,
więc `php bin/fetch.php` i `php bin/build.php` działają na czystym PHP 8.2+
z rozszerzeniami `pdo_sqlite`, `curl`, `mbstring`. `composer install` jest
potrzebny **wyłącznie do narzędzi jakości** (`require-dev`) i nigdy do
uruchomienia projektu. To jest decyzja, nie zaniedbanie: narzędzie ma się
uruchomić za trzy lata, gdy nikt nie pamięta wersji bibliotek.

## Mapa katalogów

```
bin/          skrypty CLI: dwa ETL-e i trzy buildy
src/Sejm/     klient API (retry z backoffem, stronicowanie generatorem, curl_multi dla ELI)
src/Import/   upsert do SQLite, transakcja na stronę wyników
src/Domain/   termin ustawowy, normalizacja nazw, klasyfikator aktów technicznych
src/Report/   RankingBuilder i VacatioBuilder — cała metodologia w dwóch plikach
src/Storage/  schemat i połączenie
public/pages/     szablon na każdą funkcję (jedna strona = jedna funkcja)
public/partials/  style.css, core.js, nav.html — części wspólne wszystkich stron
src/Web/          PageComposer: szablon + części wspólne + dane -> gotowa strona
docs/adr/     decyzje architektoniczne wraz z uzasadnieniem i odrzuconymi wariantami
scripts/      bramki: składnia, konwencja commitów, test dymny
var/          baza SQLite — nie w repozytorium
```

## Niezmienniki — naruszenie to defekt krytyczny

**N1. Nie pokazujemy jako policzonego czegoś, czego nie da się policzyć.**
API zwraca braki jako wartowniki (`0000-12-30` dla 92% odpowiedzi kadencji VII).
Wpuszczenie ich do arytmetyki dało kiedyś „0,1% po terminie" — kadencję niemal
idealną. Brak danych ma być widoczny jako brak danych: kadencja dostaje flagę
`mierzalna: false` i wyjaśnienie zamiast rankingu.

**N2. Odpowiedź bez daty nie jest ani punktualna, ani milcząca.**
Trzeci stan (`answered_no_date`) wypada z mianownika. Zepchnięcie go w którąkolwiek
stronę zamienia jeden fałsz na drugi.

**N3. Każda liczba prowadzi do źródła.** Pozycje list mają odnośniki do
sejm.gov.pl / ISAP. Jeżeli API nie daje klucza (pisma o prolongacie), pokazujemy
znacznik „brak odnośnika", nigdy martwy link.

**N4. Wykluczenia są raportowane, nigdy ciche.** Pytania do wielu adresatów,
akty odsiane jako techniczne, rekordy bez daty — każda kategoria ma licznik
widoczny w interfejsie. Liczymy **pytania i akty, nie wiersze złączenia**;
pomyłka w tym miejscu zawyżała kiedyś liczbę wykluczeń dwukrotnie.

**N5. Miara bez progu ustawowego niesie zastrzeżenie na wierzchu.** Nieobecności
nie mają miernika prawnego, a API nie podaje przyczyny — delegacja, choroba
i zwykła absencja są nierozróżnialne. Takie sekcje otwiera ostrzeżenie, a nie
przypis: liczba bez tego kontekstu krzywdzi konkretne osoby.


## Konwencje kodu

* **PHP 8.2+**, `declare(strict_types=1)`, klasy `final`, `readonly` gdzie się da,
  `\DateTimeImmutable` zamiast `DateTime`.
* **Dokumentacja i komentarze po polsku.** Komentarz wyjaśnia **dlaczego**, nie
  **co** — „co" ma wynikać z nazw. Docblock klasy mówi, jaką decyzję realizuje.
* **Metodologia mieszka w jednym miejscu na dashboard** — `RankingBuilder`
  i `VacatioBuilder`. Rozsypanie jej po szablonach i zapytaniach sprawia,
  że opis w interfejsie przestaje odpowiadać liczbom.
* **Normalizator jest źródłem prawdy przy raportowaniu, nie przy imporcie.**
  Klucze liczymy z surowej nazwy w momencie budowania raportu, żeby zmiana reguł
  nie wymagała ponownego pobrania danych.
* **Jedna funkcja = jedna strona.** Każda strona dostaje wyłącznie swój wycinek
  danych (`PAGE_SLICES` w `bin/build.php`). Wcześniej jeden `index.html` niósł
  komplet i ważył 1,2 MB — czytelnik zainteresowany nieobecnościami ściągał
  ranking resortów i listy pytań.
* **Style, rdzeń JS i nawigacja żyją w `public/partials/`**, nigdy w szablonie
  strony. Zanim to powstało, dwa szablony niosły 173 z 183 linii identycznego
  stylu i każda poprawka wymagała dwóch edycji.
* **Dane wstrzykiwane inline** do wygenerowanego HTML-a. Strona ma działać
  z `file://`, bez serwera i bez CDN-a.
* Wygenerowane pliki (`public/*.html` poza szablonami, `public/*.json`,
  `var/`) **nie wchodzą do repozytorium** — CI to sprawdza.

## Bramki jakości

Zestaw odpowiada temu, co stoi w pozostałych projektach (ruff + mypy strict +
pytest + mutmut + pip-audit + CodeQL), przełożonemu na PHP:

| Bramka | Narzędzie | Zakres |
|---|---|---|
| styl | `php-cs-fixer` (PSR-12 + `strict_types`) | `src`, `bin`, `tests` |
| analiza statyczna | `phpstan` **poziom 8** | `src`, `bin` |
| testy jednostkowe | `phpunit` | `tests/Unit` |
| próg pokrycia | `scripts/check-coverage.sh 90` | `src/Domain`, `src/Console` |
| testy mutacyjne | `infection` (MSI 90 / pokryte 95) | `src/Domain`, `src/Console` |
| test dymny | `scripts/smoke.sh` | 7 stron end-to-end, niezmienniki N1–N5 |
| audyt zależności | `composer audit` | `require-dev` |
| bezpieczeństwo | CodeQL (`javascript-typescript`, `actions`) | JS dashboardów, workflowy |

CodeQL **nie obejmuje PHP** — ten język nie jest wspierany. Analiza PHP-a stoi
wyłącznie na PHPStan i to jest znana granica, nie przeoczenie.

Pokrycie i mutacje wymagają sterownika (`pcov` albo `xdebug`). Lokalne cele
wypisują komunikat i przechodzą, gdy sterownika nie ma; **w CI są egzekwowane**
na PHP 8.3 z pcov.

### Zasady pisania testów

* Test nazywa **regułę**, nie funkcję:
  `test_repeated_flag_takes_the_last_occurrence`, nie `test_nullable_string`.
* **Warstwa czysta** (`src/Domain`, `src/Console`) jest objęta mutacjami. Nowa
  reguła musi mieć test, który wykrywa jej zmianę, a nie tylko wykonuje linię.
* Testy jednostkowe nie dotykają sieci ani bazy. Bez wyjątków.
* `scripts/smoke.sh` buduje wszystkie raporty na **syntetycznej bazie, bez sieci**
  i sprawdza niezmienniki N1–N5 liczbowo. To on wykrył podwójne liczenie wykluczeń.
* Nowa reguła metodologiczna bez asercji w teście dymnym nie wchodzi.
* Zmiana progu, wagi we wskaźniku albo reguły klasyfikatora wymaga aktualizacji
  opisu metodologii w szablonie **w tym samym commicie**.

## Czego nie proponować

* **Zmiany technologii bez ADR-a.** Wybór stosu per warstwa jest opisany
  w `docs/adr/0001-technologia-per-warstwa.md` razem z progami, przy których
  warto go zmienić. Propozycja migracji ma się do tego dokumentu odnieść.
* **Big-bangowego przepisania** na framework. Brak zależności jest tu decyzją:
  narzędzie ma się uruchomić za trzy lata, gdy nikt nie pamięta wersji Symfony.
* **Rozszerzania filtra aktów technicznych** bez policzenia, jak zmienia wynik.
  Taki filtr to najłatwiejszy sposób na uzyskanie dowolnej tezy — dlatego reguły
  są wąskie, jawne i pokazane na stronie z licznikami.
* **Scalania resortów między kadencjami.** Kompetencje wędrowały; sklejenie
  „Min. finansów" z „Min. rozwoju i finansów" sugeruje ciągłość, której nie było.
* **Wersjonowania wygenerowanych dashboardów.** Mają setki kilobajtów zapieczonych
  danych i zamieniają diff w szum.
* **Nazywania odstępstwa od standardu „łamaniem prawa".** Skrócenie vacatio legis
  jest dopuszczalne (art. 4 ust. 2); mierzymy skalę zjawiska, nie legalność.

## Git: commity, gałęzie i pull requesty

**Język: angielski.** Dotyczy komunikatów commitów, nazw gałęzi oraz tytułów
i opisów pull requestów. Historia gita i PR-y są artefaktem inżynierskim
o dłuższym życiu niż projekt i mogą trafić do odbiorcy spoza zespołu.

**Dokumentacja, komentarze i interfejs zostają po polsku** — patrz „Konwencje
kodu". To nie jest niekonsekwencja: `README.md`, kod i dashboard opisują dziedzinę
(interpelacje, vacatio legis, Dziennik Ustaw), gdzie polska terminologia jest
precyzyjniejsza, a git opisuje zmiany w kodzie.

### Format commita — Conventional Commits

```
<type>(<scope>): <subject>

<body>

<footer>
```

**Typy:** `feat`, `fix`, `docs`, `refactor`, `test`, `perf`, `build`, `ci`,
`chore`, `revert`.

**Scope** (opcjonalny, ale zalecany) — moduł, którego zmiana dotyczy:
`sejm`, `eli`, `import`, `domain`, `report`, `ui`, `storage`, `docs`, `ci`, `deps`.

**Subject:** tryb rozkazujący, małą literą, bez kropki na końcu, do 72 znaków.
„add reply links", nie „added" ani „adds".

**Body:** wyjaśnia **dlaczego**, nie **co** — „co" widać w diffie. Zawijanie
na 72 znakach. Jeżeli zmiana dotyka niezmiennika N1–N5 albo progu ustawowego,
wskaż to wprost. Body jest opcjonalne przy zmianach trywialnych i **obowiązkowe
przy zmianach w metodologii, progach i wagach wskaźników**.

**Footer:** `BREAKING CHANGE: <opis>` dla zmian łamiących kształt `data.json`,
`Refs #<numer>` dla powiązanych zgłoszeń.

Przykłady:

```
fix(report): exclude undated replies from the timeliness denominator

The API returns 0000-12-30 as a null date for 92% of term VII replies.
Feeding that into the arithmetic produced negative response times and a
fictitious 0.1% overdue rate. Undated replies are now a third state that
leaves the denominator instead of masquerading as punctual.

Refs N1, N2.
```

```
fix(report): count questions rather than join rows in exclusions

A question addressed to three ministries produced three rows, so the
footer reported triple the number of excluded questions.
```

```
docs(readme): document the technical-act filter and its effect
```

### Nazwy gałęzi

`<type>/<krótki-opis-po-angielsku>`, np. `feat/vacatio-legis-ranking`,
`fix/sentinel-dates-term-seven`, `docs/data-sources`.

### Pull requesty

* **Tytuł** — dokładnie ta sama konwencja co subject commita.
* **Opis po angielsku**, w strukturze:
  1. **Why** — problem lub potrzeba, nie lista plików.
  2. **What changed** — istotne decyzje, nie streszczenie diffa.
  3. **Invariants** — czy PR dotyka N1–N5; jeżeli któryś jest łamany, wymagane
     jest uzasadnienie w opisie.
  4. **Verification** — co zostało uruchomione i **czego nie uruchomiono**.
  5. **Deliberate omissions** — świadome pominięcia z uzasadnieniem.

**Sekcja Verification musi być uczciwa.** Napisanie „tested" bez wskazania,
czego nie sprawdzono, jest gorsze niż jej brak — czytelnik podejmuje decyzję
o scaleniu na podstawie tego, co uznaje za zweryfikowane. Testu dymnego nie
uruchamia się „na oko": albo `./scripts/smoke.sh` przeszedł, albo nie.

**Każda zmiana trafia na GitHuba przez draft pull request.** Nigdy push na
`main` — dotyczy to tak samo poprawki jednolinijkowej, jak nowej funkcji.
Jeden PR na funkcję. Gałąź: `<type>/<krótki-opis-po-angielsku>`.

PR pozostaje w stanie **draft, dopóki człowiek nie powie, że jest gotowy** —
nie do momentu zniknięcia blokad. Jeżeli istnieje znany warunek blokujący,
ma być wymieniony w opisie z nazwy. Nie scalamy własnych PR-ów bez polecenia.

## Dane

Wszystkie dane pochodzą z publicznych API Kancelarii Sejmu (`api.sejm.gov.pl`
oraz `api.sejm.gov.pl/eli`). Bez autoryzacji, bez limitów, ponowne wykorzystanie
na zasadach ustawy o otwartych danych.

Dane osobowe w zbiorze to **wyłącznie dane osób pełniących funkcje publiczne**
w związku z pełnieniem tych funkcji (posłowie jako autorzy pytań, ministrowie
i sekretarze stanu jako autorzy odpowiedzi). Nie rozszerzamy zbioru o dane osób
prywatnych ani nie łączymy go ze źródłami spoza tego zakresu.
