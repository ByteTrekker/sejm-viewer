# ADR-0001: Technologia per warstwa

Data: 2026-08-29 · Status: przyjęte

## Kontekst

Projekt powstał w całości w PHP 8.2+, bez frameworka i bez zależności runtime.
Wybór nie był analizą — wynikał z domyślnego stosu przyjętego dla wszystkich
projektów autora. Warto go rozliczyć, bo aplikacja ma pięć warstw o zupełnie
różnym charakterze, a sąsiednie repozytoria (`business-osint`, `perfumofil`)
stoją na Pythonie i TypeScripcie, przez co nie dzielą z tym projektem żadnego
narzędzia.

Rozmiar warstw, stan na dzień decyzji:

| Warstwa | Linie | Co robi |
|---|---:|---|
| Frontend (`public/template*.html`) | 1712 | 3 typy wykresów, 4 tabele, tooltipy, motyw |
| Raporty (`src/Report/`) | 1305 | metodologia: progi, percentyle, wskaźniki, wykluczenia |
| Testy (`tests/`, `scripts/smoke.sh`) | 911 | 109 testów jednostkowych + test dymny |
| ETL (`src/Sejm/`, `src/Import/`, `bin/fetch*`) | 886 | 24 tys. żądań HTTP, retry, upsert |
| Domena + CLI (`src/Domain/`, `src/Console/`) | 458 | czyste reguły, parsowanie argumentów |
| Storage (`src/Storage/`) | 193 | schemat SQLite, typowane odczyty |

Zmierzone czasy pobierania: interpelacje 4 kadencji **670 s**, Dziennik Ustaw
2015–2026 **555 s**, głosowania kadencji X **824 s**. Baza po komplecie danych:
**240 MB**, w tym 2,1 mln wierszy w tabeli `vote`.

## Decyzja

**Zostajemy przy PHP w całości, z jednym wyjątkiem do rozważenia przy następnej
większej funkcji.** Kolejność ewentualnych zmian, gdyby zapadła decyzja
o migracji, jest odwrotna do intuicyjnej: najpierw frontend, potem ETL, na
końcu (albo nigdy) metodologia.

## Uzasadnienie warstwa po warstwie

### ETL — PHP działa, ale równoległość kosztowała

Warstwa robi 24 tys. żądań HTTP z retry i zapisem wsadowym. Model współbieżności
PHP-a to jedyne miejsce, gdzie język wyraźnie przeszkodził: równoległe pobieranie
wymagało **47 linii ręcznego `curl_multi`** — pętla po uchwytach, `curl_multi_select`,
`curl_multi_info_read`, ręczne uzupełnianie slotów. W Pythonie to `asyncio.gather`
z semaforem, około dziesięciu linii; w Go — goroutine i kanał.

Za PHP-em przemawia natomiast to, czego nie widać w kodzie: `curl` i `PDO` są
w bibliotece standardowej, więc ETL uruchomi się za trzy lata bez virtualenva,
bez `requirements.txt` i bez pytania, która wersja `httpx` jest kompatybilna.
Dla narzędzia obywatelskiego, które ma przeżyć zainteresowanie autora, to jest
realna wartość.

**Werdykt:** zostaje. Próg opłacalności migracji przekroczymy dopiero razem
z analizą treści (niżej), bo wtedy Python wchodzi i tak.

### Storage — SQLite jest właściwym wyborem i pozostanie nim dłużej, niż się wydaje

Pojedynczy plik, zero operacji, pełna odtwarzalność analizy: baza jest źródłem
prawdy, z którego da się przebudować każdy raport bez sieci. Przy 240 MB
i 2,1 mln wierszy zapytania analityczne (`GROUP BY` z podzapytaniami po 162 tys.
pytań) budują komplet trzech dashboardów w **2,6 s**. To nie jest wąskie gardło.

DuckDB byłby szybszy na tej klasie zapytań — jest kolumnowy i to jest dokładnie
jego zastosowanie — ale wygrałby sekundy tam, gdzie nikt nie czeka.

**Werdykt:** zostaje. Próg zmiany: dociągnięcie treści odpowiedzi (19 tys.
dokumentów tekstowych) albo głosowań czterech kadencji (~10 mln wierszy).
Wtedy DuckDB obok SQLite, nie zamiast.

### Metodologia (`src/Report/`) — nie ruszać

1305 linii czystej logiki: progi ustawowe, percentyle, wagi wskaźników, rozliczanie
wykluczeń. Warstwa jest pokryta w 97,6% i ma **MSI 98%** w testach mutacyjnych —
to znaczy, że testy nie tylko wykonują ten kod, ale wykrywają jego zmiany.

PHP nie ma prymitywów statystycznych, więc percentyl jest napisany ręcznie
(cztery linie). To jedyny koszt, jaki tu ponosimy, i jest on pomijalny wobec
ryzyka przepisania warstwy, w której błąd oznacza opublikowanie nieprawdziwej
liczby o instytucji publicznej.

**Werdykt:** zostaje bezwarunkowo. Przepisanie tej warstwy to najwyższe ryzyko
i najniższy zysk w całym projekcie.

### Frontend — tu leży realny koszt

1712 linii, z czego **174 wystąpienia ręcznie liczonej geometrii SVG**: skale,
pozycje ticków, marginesy wykresu, przycinanie etykiet. Każdy nowy wykres kosztuje
ponad sto linii arytmetyki, która nie ma nic wspólnego z dziedziną. Przy ostatniej
funkcji trzeba było napisać trzy takie wykresy naraz.

Zamiana na **Observable Plot** bundlowany do jednego pliku zachowuje wszystkie
wymagania (jeden plik, dane inline, brak CDN, działa z `file://`) i redukuje
typowy wykres do kilkunastu linii deklaratywnych, z osiami i skalami w standardzie.
Koszt: pierwsza zależność frontendowa i krok budowania, którego dziś nie ma.

**Werdykt:** to jest pierwsza zmiana do wykonania, jeżeli dashboard ma dostać
kolejne widoki. Vanilla JS był słuszny przy dwóch wykresach; przy dziewięciu
przestaje być.

### Analiza treści (planowana) — to nie będzie PHP

Wykrywanie pustych odpowiedzi, powtarzalnych formułek i copy-paste między
resortami to podobieństwo tekstu i klasteryzacja. PHP nie ma tu nic. Python ma
`rapidfuzz`, `scikit-learn` i cały ekosystem embeddingów.

**Werdykt:** gdy ta funkcja wejdzie, wchodzi z Pythonem — i wtedy sensowne staje
się przeniesienie razem z nią całego ETL-u, bo obie warstwy dotykają tych samych
danych źródłowych.

## Konsekwencje

* **Projekt pozostaje jednojęzyczny**, dopóki nie dojdzie analiza tekstu. To jest
  świadoma cena: nie dzielimy narzędzi z `business-osint` ani `perfumofil`,
  a zestaw bramek jakości trzeba było przetłumaczyć ręcznie (ruff → php-cs-fixer,
  mypy strict → PHPStan poziom 8, mutmut → Infection, pip-audit → composer audit).
* **Polyglot ma realny koszt**, który trzeba policzyć przed pierwszym `.py` w tym
  repozytorium: drugi toolchain, drugi zestaw bramek w CI, drugi próg pokrycia
  i mutacji. Nie wolno wprowadzić Pythona „przy okazji" jednego skryptu.
* **Runtime zostaje bez zależności.** Narzędzia jakości żyją w `require-dev`
  i CI weryfikuje to osobnym jobem na PHP 8.2 bez `composer install`. Ta
  właściwość jest warta więcej niż wygoda pisania i nie wolno jej stracić
  przy żadnej z powyższych zmian.
* **Wersje narzędzi rozjeżdżają się z runtime'em.** PHPUnit 13 wymaga PHP 8.4,
  podczas gdy projekt deklaruje 8.2 — stąd rozdzielone joby w CI. Przy każdej
  aktualizacji narzędzi trzeba sprawdzić, czy nie podnoszą wymagań runtime'u
  tylnymi drzwiami.

## Odrzucone warianty

**Przepisanie całości na Python teraz.** Zysk byłby realny (wspólne narzędzia
z sąsiednimi repozytoriami, lepszy ETL, gotowa analiza tekstu), ale wymagałby
przeniesienia 1305 linii metodologii z MSI 98% — czyli jedynego miejsca, gdzie
błąd wychodzi na zewnątrz jako fałszywa liczba o instytucji publicznej. Migracja
warstwy raportowej musi być ostatnia, nie pierwsza.

**Symfony albo inny framework.** Aplikacja nie ma requestów, sesji ani routingu:
dwa skrypty ETL, trzy skrypty budujące i statyczny wynik. Framework dołożyłby
zależności runtime, które są tu jawnym antywzorcem.

**Node dla całości.** Rozwiązałby frontend i dałby przyzwoity ETL, ale wprowadza
zależności runtime do warstwy pobierania danych i nie daje nic w analizie tekstu,
która jest następnym dużym krokiem.
