# sejm-viewer

Rankingi obywatelskie z danych Sejmu. Dwa dashboardy stojące na tej samej zasadzie: bierzemy **obowiązek z twardym
terminem ustawowym**, liczymy, kto go dotrzymuje, i pokazujemy to na pełnych
danych, nie na próbce.

| Dashboard | Miernik ustawowy | Zakres | Plik |
|---|---|---|---|
| Ranking milczenia ministerstw | 21 dni na odpowiedź na interpelację | 162 103 pytania, 2011–2026 | `public/index.html` |
| Vacatio legis | 14 dni od ogłoszenia do wejścia w życie | 19 410 aktów Dz.U., 2015–2026 | `public/vacatio.html` |
| Vacatio legis — bez aktów technicznych | jw., po odsianiu 2 229 aktów administracyjnych | 15 058 rozporządzeń | `public/vacatio-merytoryczne.html` |

---

# Ranking milczenia ministerstw

Regulamin Sejmu daje adresatowi **21 dni** na pisemną odpowiedź na interpelację
(art. 192 ust. 1) i na zapytanie poselskie (art. 195 ust. 1). Nikt tego terminu
nie pilnuje na bieżąco. Ten projekt liczy, kto go trzyma, a kto milczy — na
pełnych danych z `api.sejm.gov.pl`.

Wynik: statyczny dashboard (`public/index.html`), który działa z `file://`,
bez backendu i bez CDN-a. Obejmuje **cztery kadencje, od 2011-11-08** — czyli
trzy zmiany rządu — z przełącznikiem kadencji i widokiem porównawczym.

## Uruchomienie

```bash
php bin/fetch.php && php bin/build.php && open public/index.html
```

Nie ma zależności zewnętrznych — `composer install` nie jest potrzebny.
Wymagane: PHP 8.2+ z `pdo_sqlite`, `curl`, `mbstring`.

| Krok | Co robi | Czas |
|---|---|---|
| `bin/fetch.php` | pobiera interpelacje, zapytania i posłów do `var/sejm.sqlite` | ~2,5 min na kadencję |
| `bin/build.php` | liczy wskaźniki dla **wszystkich kadencji w bazie**, zapisuje `public/data.json` i `public/index.html` | ~3 s |

Przydatne flagi:

```bash
php bin/fetch.php --term=7,8,9,10          # pełny zakres dostępny w API
php bin/fetch.php --term=10 --skip-mp      # dociągnięcie bieżącej kadencji (idempotentne)
php bin/build.php --term=9,10              # raport tylko z wybranych kadencji
php bin/build.php --term=10 --snapshot=2025-01-01
```

## Zakres danych

Interpelacje i zapytania są w API od **kadencji VII (2011-11-08)**; kadencje I–VI
zwracają zero. Dane sprzed 2011 istnieją tylko jako HTML/PDF na `orka.sejm.gov.pl`
— to scraping, nie API.

Pola krytyczne dla metodologii, sprawdzone na pełnym zbiorze:

| Pole | VII | VIII | IX | X | Znaczenie |
|---|---|---|---|---|---|
| `recipientDetails.sent` | ✅ | ✅ | ✅ | ✅ | start biegu terminu |
| `sentDate` (na pytaniu) | ❌ | ✅ | ✅ | ✅ | nieużywane, gdy jest `recipientDetails.sent` |
| **`replies[].receiptDate`** | **❌ 92%** | ✅ | ✅ | ✅ | **data odpowiedzi — bez niej nie ma rankingu** |
| `prolongation` | ✅ | ✅ | ✅ | ✅ | pismo o zwłoce |
| `onlyAttachment` | ⚠️ | ✅ | ✅ | ✅ | kolumna „tylko skan" |

### Kadencja VII jest niemierzalna — i to trzeba wiedzieć

API zwraca dla niej datę odpowiedzi jako wartownik **`0000-12-30`**: 43 645
z 47 371 odpowiedzi (92,1%). Pytań z datowaną odpowiedzią inną niż pismo
o prolongacie jest **zero**.

To nie jest kosmetyka. Wpuszczenie wartownika do bazy dawało ujemne czasy
odpowiedzi i wynik **0,1% po terminie** — czyli kadencję niemal idealną,
wyłącznie dlatego, że brak daty wygląda w liczbach jak odpowiedź natychmiastowa.
Import odrzuca teraz wszystko sprzed 1990 r. (`QuestionImporter::date()`),
a `RankingBuilder` rozróżnia trzy stany zamiast dwóch:

| Stan | Do mianownika? |
|---|---|
| odpowiedź z datą | tak — na czas albo po terminie |
| **odpowiedź bez daty** | **nie — nie wiadomo, czy w terminie** |
| brak odpowiedzi po terminie | tak — liczy się jako milczenie |
| brak odpowiedzi, termin biegnie | nie |

Kadencja, w której odpowiedzi bez daty przekraczają 20% pytań, dostaje flagę
`mierzalna: false`, wypada z rankingu i z wykresu porównawczego, a dashboard
pokazuje zamiast niej wyjaśnienie. Dla kadencji VII pewne pozostają dwie liczby:
41 856 pytań skierowanych i 48 bez żadnej odpowiedzi.

Daty z lat 2011–2015 da się odzyskać wyłącznie ze stron `sejm.gov.pl/sejm7.nsf`
— to scraping, nie API.

### Porównywanie kadencji

Każda kadencja liczona jest na **własną datę odcięcia**: zamknięta na dzień
swojego końca, trwająca na dziś. Bez tego kadencja trwająca wypadałaby sztucznie
lepiej, bo część jej pytań wciąż biegnie w terminie i nie wchodzi do mianownika.

Porównywalny jest **poziom zagregowany** (cały rząd: odsetek po terminie, mediana,
liczba pytań bez odpowiedzi). Pojedynczych resortów między kadencjami porównywać
nie wolno bez ręcznej decyzji — resorty były dzielone i łączone, a
`RecipientNormalizer::CONTINUITY` scala wyłącznie czyste zmiany nazw
wewnątrz kadencji, nigdy zmian kompetencji.

## Metodologia

Jednostką analizy jest **para (pytanie, adresat)**, nie pytanie. Założenia są
świadome i widoczne w interfejsie, bo od nich zależy cały ranking:

1. Termin biegnie od **daty przekazania pytania adresatowi**
   (`recipientDetails.sent`), nie od wpłynięcia do Sejmu — inaczej obciążalibyśmy
   resort opóźnieniem kancelarii.
2. **Prolongata nie wydłuża terminu.** Regulamin nie przewiduje przedłużenia;
   pismo o zwłoce liczymy osobno jako sygnał, nie usprawiedliwienie.
3. Pytania do **kilku adresatów naraz są wyłączone** (1 156 pytań w kadencji X, ~5%).
   API zwraca odpowiedzi jako płaską listę na poziomie pytania i nie wiąże ich
   z konkretnym adresatem — nie da się uczciwie przypisać odpowiedzialności.
4. Pytania bez odpowiedzi, którym **termin jeszcze nie upłynął**, nie wchodzą
   do mianownika.
5. Do rankingu wchodzą adresaci z co najmniej **30 rozstrzygniętymi** pytaniami.

**Wskaźnik milczenia (0–100)** = 40% udziału po terminie + 25% udziału bez
odpowiedzi + 15% udziału prolongat + 20% znormalizowanej mediany czasu
odpowiedzi. Brak odpowiedzi waży podwójnie (wchodzi też w pierwszy składnik),
bo cisza jest gorsza niż spóźniona odpowiedź.

Próg 21 dni potwierdza sam rozkład danych: 81% odpowiedzi mieści się w 21 dniach,
po czym histogram gwałtownie opada. Ogon 22–35 dni to niemal wyłącznie prolongaty.

### Czego ten ranking nie mówi

Mierzy **terminowość, nie jakość**. Odpowiedź „nie posiadamy takich danych"
wysłana w 3 dni wygląda tu lepiej niż rzetelna analiza w 25 dni. Jedyne dostępne
w API proxy jakości to kolumna „tylko skan" — odpowiedź wysłana wyłącznie jako
PDF, bez treści tekstowej.

## Odnośniki do źródła

Każda pozycja obu list linkuje do sejm.gov.pl, żeby dało się zweryfikować liczbę
w dwóch kliknięciach:

| Zasób | Wzorzec | Uwagi |
|---|---|---|
| treść pytania | `sejm{N}.nsf/interpelacja.xsp?typ=int\|zap&nr={nr}` | zapytania poselskie **też** mieszkają pod `interpelacja.xsp` — rozróżnia je wyłącznie `typ`; wariant `zapytanie.xsp` zwraca stronę błędu |
| treść odpowiedzi | `sejm{N}.nsf/interpelacjaTresc.xsp?key={reply_key}` | dla odpowiedzi tekstowych zwraca HTML, dla `onlyAttachment` serwuje PDF — link jest oznaczany jako „(PDF)" |

Pisma o prolongacie nie mają w API klucza, więc nie da się do nich zrobić
odnośnika — takie pozycje dostają znacznik zamiast linku (~1 na 25).

## Architektura

```
bin/fetch.php   ETL: api.sejm.gov.pl -> SQLite
bin/build.php   raport: SQLite -> data.json + index.html
src/Sejm/       klient API (retry z backoffem, stronicowanie generatorem)
src/Import/     upsert do SQLite, transakcja na stronę wyników
src/Domain/     termin ustawowy, normalizacja nazw adresatów
src/Report/     RankingBuilder - cała metodologia w jednym miejscu
public/         template.html (źródło) -> index.html (z wstrzykniętymi danymi)
var/sejm.sqlite baza; źródło prawdy dla powtarzalności analizy
```

Import jest idempotentny (`ON CONFLICT DO UPDATE`), więc `fetch.php` można
uruchamiać cyklicznie — dociąga nowe pytania i aktualizuje te, do których
w międzyczasie doszła odpowiedź.

Normalizacja adresatów scala wyłącznie czyste zmiany nazwy resortu
(`RecipientNormalizer::CONTINUITY`), nigdy podziału ani połączenia kompetencji —
inaczej szereg czasowy jednego urzędu rozpadłby się na dwa słupki.

## Wyniki

| Kadencja | Okres | Pytań | Po terminie | Bez odpowiedzi |
|---|---|---|---|---|
| VII | 2011-11-08 – 2015-11-11 | 41 856 | *niemierzalne* | 48 |
| VIII | 2015-11-12 – 2019-11-11 | 42 798 | 39,9% | 252 |
| IX | 2019-11-12 – 2023-11-12 | 49 774 | 40,1% | 303 |
| X | 2023-11-13 – trwa | 21 950 | 33,4% | 284 |

## Ograniczenia i kierunki rozwoju

- **Pytania do wielu adresatów** (~5% zbioru) są dziś wyłączone. Da się je
  odzyskać, dopasowując autora odpowiedzi (`reply.from`, np. „Minister Krzysztof
  Hetman") do resortu — wymaga słownika kierownictwa resortów.
- **Jakość odpowiedzi.** Treść jest pod `/interpellations/{n}/reply/{key}/body`
  — 19 tys. dodatkowych żądań. Pozwoliłoby wykryć odpowiedzi puste, powtarzalne
  formułki i copy-paste między resortami.
- **Porównanie kadencji.** Kadencja 9 (44 315 interpelacji) już się pobiera tym
  samym kodem; brakuje widoku zestawiającego oba rządy przy tej samej dacie
  odcięcia.
- **Ranking resortów w czasie** — dziś porównanie międzykadencyjne działa tylko na poziomie zagregowanym. Mapowanie kompetencji resortów między kadencjami pozwoliłoby prześledzić jeden urząd przez 15 lat.
- **Ranking posłów** — kto najczęściej bywa ignorowany; dane (`question.authors`,
  `mp.club`) są już w bazie, użyte na razie tylko do wykresu klubów.
- **Odzyskanie kadencji VII** przez scraping `sejm.gov.pl/sejm7.nsf` — jedyny sposób na 15-letni szereg zamiast 11-letniego.
- **Listy szczegółowe** nie grupują serii bliźniaczych pytań (22 identyczne
  zapytania do MSiT z 2024-08 zajmują dziś całą listę „bez odpowiedzi").

---

# Vacatio legis — ile czasu dostajesz na nowe prawo

Ustawa z 20.07.2000 o ogłaszaniu aktów normatywnych (art. 4 ust. 1) daje adresatowi
**14 dni** między ogłoszeniem aktu w Dzienniku Ustaw a jego wejściem w życie.

```bash
php bin/fetch-acts.php --from=2015 --to=2026
php bin/build-vacatio.php                       # public/vacatio.html — wszystkie akty
php bin/build-vacatio.php --exclude-technical   # public/vacatio-merytoryczne.html
```

Obie strony powstają z **jednego szablonu** (`template-vacatio.html`) i różnią się
wyłącznie zbiorem danych; przełącznik w nagłówku prowadzi z jednej do drugiej.

Pobranie 19 410 aktów zajmuje ~9 min. Data wejścia w życie jest **wyłącznie
w detalu aktu**, nie na liście rocznika, więc każdy akt wymaga osobnego żądania —
stąd `SejmApiClient::fetchActDetails()` na `curl_multi` (8 połączeń równolegle).

## Wyniki (Dz.U. 2015–2026)

| | Rozporządzenia | Ustawy |
|---|---|---|
| aktów | 17 129 | 2 308 |
| poniżej 14 dni | **59,3%** | ~20–25% |
| wchodzi w życie z dnia na dzień | 7 362 | — |
| mediana | 1–8 dni (zależnie od roku) | **15 dni** |

Rozkład jest **dwumodalny**: albo standardowe 15 dni, albo zero. Mediana 15 dni dla
ustaw potwierdza, że konwencja redakcyjna „po upływie 14 dni" daje różnicę 15 —
dlatego próg 14 dni w rankingu jest zachowawczy i może zarzut tylko osłabić.

Rok 2020 wyróżnia się w obu populacjach: 69,3% rozporządzeń poniżej standardu
(54,6% z dnia na dzień, mediana 1 dzień) i 53,9% ustaw przy typowych ~20%.

## Wersja bez aktów technicznych

Krótkie vacatio bywa merytorycznie uzasadnione, więc powstaje pytanie, czy wynik nie
stoi na aktach czysto administracyjnych. `TechnicalActClassifier` odsiewa 2 229 aktów
(12%) w ośmiu jawnych kategoriach:

| Kategoria | Aktów |
|---|---|
| obszary ochrony przyrody i pomniki historii | 1 004 |
| organizacja rządu i urzędów | 567 |
| wybory przedterminowe i uzupełniające | 202 |
| zgoda na ratyfikację umowy międzynarodowej | 160 |
| sprostowania i uchylenia | 97 |
| odznaczenia, ordery i odznaki | 94 |
| osobowość prawna jednostek kościelnych | 85 |
| nazwy i granice jednostek terytorialnych | 20 |

Reguły są celowo wąskie, bo taki filtr łatwo zamienić w narzędzie do poprawiania
wyniku. **Nie wykluczamy nowelizacji** (45% zbioru — nowelizacja bywa bardzo
merytoryczna), ani stawek, wzorów wniosków czy ograniczeń epidemicznych. Każdy
wykluczony akt ma przypisaną kategorię, a strona pokazuje ich liczby.

**Wynik: filtr nie ratuje statystyk, tylko je pogarsza.**

| | Wszystkie akty | Bez technicznych |
|---|---|---|
| rozporządzeń | 17 129 | 15 058 |
| poniżej 14 dni | 59,3% | **61,8%** |
| z dnia na dzień | 43,0% | 43,7% |

Zmienia się za to czołówka. Prezydent RP spada z 1. na 19. miejsce (172 → 84 akty),
a Prezes Rady Ministrów z 2. na 30. (979 → 517) — ich wysokie wyniki brały się
głównie z pomników historii, powoływania pełnomocników i zarządzania wyborów
przedterminowych. Na czoło wchodzą resorty gospodarcze, których rozporządzenia
realnie dotyczą obywateli i firm.

## Metodologia

1. `vacatio = entryIntoForce − promulgation`, oba pola z API ELI.
2. **Skrócenie terminu jest legalne** — art. 4 ust. 2 dopuszcza je „w uzasadnionych
   przypadkach", a wejście w życie w dniu ogłoszenia, gdy wymaga tego ważny interes
   państwa. Ranking mierzy **skalę odstępstwa od standardu**, nie łamanie prawa.
3. **Tylko rozporządzenia** wchodzą do rankingu organów — ustawy mają w ELI organ
   wydający „SEJM" i nie da się ich przypisać do resortu. Ustawy pokazujemy osobno,
   jako szereg roczny.
4. Obwieszczenia (najliczniejszy typ w Dz.U., ~750/rok) są pominięte — to teksty
   jednolite, nie nowe obowiązki.
5. Organów **nie scalamy między latami**. Przez 12 lat te same kompetencje wędrowały
   między urzędami o różnych nazwach; sklejenie „Min. finansów" z „Min. rozwoju
   i finansów" sugerowałoby ciągłość, której nie było.
6. 28 aktów wydają dwa lub więcej organów naraz. Każdemu przypisujemy akt (bo każdy
   odpowiada), ale w statystyce rocznej i w histogramie akt liczy się raz.
7. API podaje **jedną** datę wejścia w życie — akty wchodzące etapami są uproszczone.

Czego ranking nie mówi: nawet po odsianiu aktów administracyjnych krótkie vacatio
bywa merytorycznie uzasadnione (coroczne stawki, wykonanie wyroku TK, sytuacje
kryzysowe). Ranking nie ocenia zasadności pojedynczego skrócenia i nie udaje,
że potrafi — mierzy skalę zjawiska.

## Źródło danych

`api.sejm.gov.pl` — oficjalne API Kancelarii Sejmu (interpelacje, posłowie)
oraz `api.sejm.gov.pl/eli` (Dziennik Ustaw). Bez autoryzacji i bez
limitów. Dane publiczne, ponowne wykorzystanie na zasadach ustawy o otwartych
danych.
