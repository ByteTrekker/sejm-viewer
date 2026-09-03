#!/usr/bin/env bash
# Test dymny bez sieci: buduje oba raporty na syntetycznej bazie i sprawdza,
# czy metodologia daje oczekiwane liczby.
#
# Chroni cztery rzeczy, na których ten projekt stoi i które łatwo zepsuć refaktorem:
#   1. odpowiedź bez daty NIE liczy się ani jako punktualna, ani jako milczenie,
#   2. pytanie do wielu adresatów wypada z rankingu,
#   3. kadencja z przewagą odpowiedzi bez daty jest oznaczana jako niemierzalna,
#   4. vacatio legis liczy się od ogłoszenia, a filtr techniczny odsiewa to, co ma odsiewać.
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

db="$work/smoke.sqlite"
out="$work/public"
mkdir -p "$out"
cp -R "$root/public/pages" "$root/public/partials" "$out/"

php -r '
require "'"$root"'/bin/bootstrap.php";
$db = Milczenie\Storage\Database::open("'"$db"'");
$p = $db->pdo;

$p->exec("INSERT INTO term (num, date_from, date_to) VALUES (10, \"2023-11-13\", NULL)");
$p->exec("INSERT INTO term (num, date_from, date_to) VALUES (7, \"2011-11-08\", \"2015-11-11\")");

$p->exec("INSERT INTO mp (id, term, name, club, district, active) VALUES (11, 10, \"Anna Pytająca\", \"KLUB-A\", \"Kraków\", 1)");
$p->exec("INSERT INTO mp (id, term, name, club, district, active) VALUES (12, 10, \"Jan Seryjny\", \"KLUB-B\", \"Gdańsk\", 1)");
$p->exec("INSERT INTO mp (id, term, name, club, district, active) VALUES (13, 10, \"Ewa Milcząca\", \"KLUB-A\", \"Poznań\", 1)");

$q = $p->prepare("INSERT INTO question (id, kind, term, num, title, receipt_date, sent_date, authors, author_count)
                  VALUES (?, \"interpelacja\", ?, ?, ?, ?, ?, ?, ?)");
$a = $p->prepare("INSERT INTO addressee (question_id, recipient_raw, recipient_key, sent_date) VALUES (?, ?, ?, ?)");
// Podpis w formie, w jakiej pisza go kancelarie: funkcja, potem nazwisko.
// Jeden podpis celowo bez nazwiska - bramka sprawdza, ze taki jest policzony,
// a nie doklejony do kogokolwiek.
$r = $p->prepare("INSERT INTO reply (question_id, reply_key, author, receipt_date, prolongation, only_attachment)
                  VALUES (?, ?, ?, ?, 0, 0)");

$sent = "2024-01-10";
$mk = function (int $n, string $suffix, ?string $replyDate, bool $hasReply, int $addressees = 1, array $authors = [11], ?string $fixedTitle = null, string $signature = "Podsekretarz stanu Jan Testowy")
        use ($q, $a, $r, $sent): void {
    for ($i = 0; $i < $n; $i++) {
        $id = "interpelacja:10:" . $suffix . $i;
        $title = $fixedTitle ?? ("Interpelacja testowa " . $suffix . $i);
        // Wplyw 5 dni przed przekazaniem - to jest opoznienie kancelarii.
        $q->execute([$id, 10, 1000 + crc32($id) % 8000, $title, "2024-01-05", $sent,
                     json_encode($authors), count($authors)]);
        $a->execute([$id, "minister testowy", "minister testowy", $sent]);
        if ($addressees > 1) {
            $a->execute([$id, "minister drugi", "minister drugi", $sent]);
        }
        if ($hasReply) {
            $r->execute([$id, "K" . $suffix . $i, $signature, $replyDate]);
        }
    }
};

// Podpisy rozlozone celowo: 54 podsekretarza, 27 ministra i 3 bez nazwiska.
// Rozklad jest jawny, zeby bramki mogly na nim stac liczbami, a nie tym, co akurat wyjdzie.
$mk(40, "ontime",  "2024-01-20", true);   // 10 dni - w terminie
$mk(20, "late",    "2024-02-20", true, 1, [11], null, "Minister Anna Testowa");   // 41 dni - po terminie
$mk(10, "nodate",  null,         true);   // odpowiedz bez daty
$mk(5,  "silent",  null,         false);  // brak odpowiedzi
$mk(7,  "multi",   "2024-01-20", true, 2, [11], null, "Minister Anna Testowa"); // wielu adresatow
$mk(4,  "seria",   "2024-01-20", true, 1, [12], "Interpelacja w sprawie tej samej rzeczy"); // seria szablonowa
$mk(3,  "duo",     "2024-01-20", true, 1, [11, 12], null, "Minister sprawiedliwości"); // podpis bez nazwiska

// Kadencja niemierzalna: same odpowiedzi bez daty.
for ($i = 0; $i < 50; $i++) {
    $id = "interpelacja:7:" . $i;
    $q->execute([$id, 7, $i + 1, "Interpelacja VII " . $i, "2013-01-05", "2013-01-10", "[]", 0]);
    $a->execute([$id, "minister testowy", "minister testowy", "2013-01-10"]);
    $r->execute([$id, "V" . $i, "Podsekretarz stanu Jan Testowy", null]);
}

$act = $p->prepare("INSERT INTO act (eli, publisher, year, pos, type, title, announcement_date, promulgation, entry_into_force, in_force, status, display_address)
                    VALUES (?, \"DU\", 2024, ?, \"Rozporządzenie\", ?, \"2024-01-01\", ?, ?, \"IN_FORCE\", \"obowiązujący\", ?)");
$iss = $p->prepare("INSERT INTO act_issuer (eli, issuer_raw, issuer_key) VALUES (?, \"MIN. TESTOWY\", \"minister testowy\")");
$mkAct = function (int $n, string $suffix, string $entry, string $title) use ($act, $iss): void {
    for ($i = 0; $i < $n; $i++) {
        $eli = "DU/2024/" . $suffix . $i;
        $act->execute([$eli, 90000 + $i, $title . " " . $i, "2024-03-01", $entry, "Dz.U. 2024 poz. test"]);
        $iss->execute([$eli]);
    }
};
$mkAct(40, "std",  "2024-03-16", "Rozporządzenie Ministra Testowego w sprawie stawek");        // 15 dni
$mkAct(20, "fast", "2024-03-01", "Rozporządzenie Ministra Testowego w sprawie stawek pilnych"); // 0 dni
$mkAct(10, "tech", "2024-03-01", "Rozporządzenie Ministra Testowego w sprawie uznania za pomnik historii");

// Dwa kluby po 12 poslow - dopiero przy takiej liczebnosci linia klubowa cos znaczy
// (prog to 10 glosow). KLUB-C glosuje ZA z dwoma odszczepiencami, KLUB-D zawsze PRZECIW,
// wiec zgodnosc pary C+D musi wyjsc zerowa.
$mpStmt = $p->prepare("INSERT INTO mp (id, term, name, club, district, active) VALUES (?, 10, ?, ?, \"Test\", 1)");
for ($i = 0; $i < 12; $i++) {
    $mpStmt->execute([100 + $i, "Poseł C" . $i, "KLUB-C"]);
    $mpStmt->execute([200 + $i, "Poseł D" . $i, "KLUB-D"]);
}

// Glosowania: 60 glosowan (prog par klubowych to 50), posel 11 nieobecny w 2, posel 12 w 5.
$v = $p->prepare("INSERT INTO voting (term, sitting, number, date, title, topic, kind, total_voted)
                  VALUES (10, 1, ?, \"2024-03-01\", \"Sprawozdanie Komisji o rządowym projekcie ustawy\", NULL, \"ELECTRONIC\", 27)");
$vt = $p->prepare("INSERT INTO vote (term, sitting, number, mp_id, club, vote) VALUES (10, 1, ?, ?, ?, ?)");
for ($i = 1; $i <= 60; $i++) {
    $v->execute([$i]);
    $vt->execute([$i, 11, "KLUB-A", $i <= 2 ? "ABSENT" : "YES"]);
    $vt->execute([$i, 12, "KLUB-B", $i <= 5 ? "ABSENT" : "NO"]);
    $vt->execute([$i, 13, "KLUB-A", "YES"]);
    for ($j = 0; $j < 12; $j++) {
        // Dwaj poslowie KLUB-C glosuja wbrew linii w kazdym glosowaniu.
        $vt->execute([$i, 100 + $j, "KLUB-C", $j < 2 ? "NO" : "YES"]);
        $vt->execute([$i, 200 + $j, "KLUB-D", "NO"]);
    }
}

// Frekwencja: dzien posla 11 usprawiedliwiony, posla 12 nie. Posel 13 nie ma wiersza,
// wiec jego (zerowe) nieobecnosci nie maja danych o usprawiedliwieniu.
$att = $p->prepare("INSERT INTO mp_attendance (term, mp_id, sitting, date, num_votings, num_voted, num_missed, excused)
                    VALUES (10, ?, 1, \"2024-03-01\", 60, ?, ?, ?)");
$att->execute([11, 58, 2, 1]);
$att->execute([12, 55, 5, 0]);

$db->setMeta("votings_fetched_at", "2024-06-01T00:00:00+00:00");
$db->setMeta("fetched_at", "2024-06-01T00:00:00+00:00");
$db->setMeta("acts_fetched_at", "2024-06-01T00:00:00+00:00");
'

echo "--- ranking milczenia ---"
# Braki tlumaczen build zglasza na STDERR jako ostrzezenie - strona ma powstac
# nawet niepelna. Tu jest bramka, wiec dziennik zostaje i jest sprawdzany nizej.
php "$root/bin/build.php" --db="$db" --out="$out" --snapshot=2024-06-01 \
    >/dev/null 2>"$out/build.log" || { cat "$out/build.log"; exit 1; }

php -r '
$read = static function (string $file): array {
    $page = file_get_contents($file);
    if ($page === false) { fwrite(STDERR, "Brak $file\n"); exit(2); }
    return json_decode((string) preg_replace("/^.*?const DATA = (.*?);\n.*$/s", "$1", $page), true, 512, JSON_THROW_ON_ERROR);
};
$fail = 0;
$check = function (string $name, $got, $want) use (&$fail): void {
    if ($got === $want) { printf("OK   %-46s %s\n", $name, var_export($got, true)); return; }
    printf("BŁĄD %-46s otrzymano %s, oczekiwano %s\n", $name, var_export($got, true), var_export($want, true));
    $fail = 1;
};

// --- ranking adresatow: strona interpelacje ---
$inter = $read("'"$out"'/interpelacje.html");
$k10 = $inter["raporty"]["10"];
$m = null;
foreach ($k10["ministerstwa"] as $row) { if ($row["klucz"] === "minister testowy") { $m = $row; } }
if ($m === null) { echo "BŁĄD brak adresata testowego\n"; exit(1); }

// 40 "ontime" + 4 z serii + 3 wspolnych = 47 odpowiedzi w terminie
$check("na czas", $m["na_czas"], 47);
$check("po terminie", $m["po_terminie"], 20);
$check("odpowiedzi bez daty poza mianownikiem", $m["odpowiedzi_bez_daty"], 10);
$check("bez odpowiedzi po terminie", $m["bez_odpowiedzi_po_terminie"], 5);
$check("mianownik = na czas + po terminie + milczenie", $m["rozstrzygniete"], 72);
$check("pytania do wielu adresatow wykluczone", $k10["meta"]["wylaczone"]["wielu_adresatow"], 7);

$t7 = null; $t10 = null;
foreach ($inter["kadencje"] as $k) { if ($k["numer"] === 7) { $t7 = $k; } if ($k["numer"] === 10) { $t10 = $k; } }
$check("kadencja z odpowiedziami bez daty jest niemierzalna", $t7["mierzalna"], false);
$check("kadencja z kompletem dat jest mierzalna", $t10["mierzalna"], true);
$check("domyslna kadencja jest mierzalna", $inter["domyslna_kadencja"], 10);
$check("strona interpelacji nie niesie danych poslow", isset($k10["poslowie"]), false);

// --- droga pytania: osobna strona, wlasny wycinek ---
$kanc = $read("'"$out"'/droga.html")["raporty"]["10"]["droga"];
$check("kancelaria: mediana od wplywu do przekazania", $kanc["kancelaria"]["mediana_dni"], 5);
$check("kancelaria: zadne pytanie nie przekroczylo terminu", $kanc["kancelaria"]["ponad_termin"], 0);
$podpisy = [];
foreach ($kanc["podpisy"] as $row) { $podpisy[$row["klucz"]] = $row["n"]; }
$check("podpis rozpoznany jako minister", $podpisy["minister"] ?? 0, 20 + 7 + 3);

// --- poslowie i serie: osobna strona ---
$p10 = $read("'"$out"'/poslowie.html")["raporty"]["10"];
$check("wykryto jedna serie szablonowa", $p10["serie"]["serii"], 1);
$check("seria obejmuje 4 pytania", $p10["serie"]["pytan_w_seriach"], 4);
$poslowie = [];
foreach ($p10["poslowie"] as $row) { $poslowie[$row["id"]] = $row; }
$check("posel bez pytan ma zero", $poslowie[13]["pytan"], 0);
$check("wspolne pytanie liczy sie obu autorom", $poslowie[11]["pytan"], 78);
$check("autor serii ma 4 pytania w seriach", $poslowie[12]["w_seriach"], 4);
$check("unikalne tematy pomijaja powtorzenia", $poslowie[12]["tematow"], 4);

// --- nieobecnosci: osobna strona, tylko kadencje z glosowaniami ---
$abs = $read("'"$out"'/nieobecnosci.html");
// Klucze JSON-a wracaja w PHP jako inty - porownujemy po normalizacji.
$check("strona nieobecnosci pomija kadencje bez glosowan", array_map(intval(...), array_keys($abs["raporty"])), [10]);
$rows = [];
foreach ($abs["raporty"]["10"]["nieobecnosci"]["poslowie"] as $row) { $rows[$row["id"]] = $row; }
$check("nieobecnosci: mianownik per posel", $rows[11]["glosowan"], 60);
$check("nieobecnosci: posel 11 opuscil 2", $rows[11]["nieobecnosci"], 2);
$check("nieobecnosci: udzial posla 12", round($rows[12]["udzial_nieobecnosci"], 4), round(5 / 60, 4));
$check("nieobecnosci: posel bez absencji", $rows[13]["nieobecnosci"], 0);
// Mianownik musi rownac sie liczbie glosowan - zlaczenie po samej dacie potrafilo
// go zwielokrotnic, gdy dwa posiedzenia dziela dzien.
$check("mianownik nie jest zawyzony przez zlaczenie", $rows[11]["glosowan"], $abs["raporty"]["10"]["nieobecnosci"]["glosowan"]);
$check("nieobecnosci usprawiedliwione nie licza sie jako nieuspr.", $rows[11]["nieusprawiedliwione"], 0);
$check("nieobecnosci bez usprawiedliwienia licza sie w calosci", $rows[12]["nieusprawiedliwione"], 5);

// --- dyscyplina klubowa: linia liczona per (posel, klub) ---
$dysc = $read("'"$out"'/dyscyplina.html")["raporty"]["10"]["dyscyplina"];
$kluby = [];
foreach ($dysc["kluby"] as $c) { $kluby[$c["klucz"]] = $c; }
$check("kluby ponizej progu nie maja linii", isset($kluby["KLUB-A"]), false);
$check("KLUB-C: dwoch z dwunastu wbrew linii", round($kluby["KLUB-C"]["udzial_wbrew"], 4), round(2 / 12, 4));
$check("KLUB-D glosuje jednomyslnie", (float) $kluby["KLUB-D"]["udzial_jednomyslnych"], 1.0);
$odszczepieniec = null;
foreach ($dysc["poslowie"] as $m) { if ($m["id"] === 100) { $odszczepieniec = $m; } }
$check("odszczepieniec wbrew linii w kazdym glosowaniu", (float) $odszczepieniec["udzial_wbrew"], 1.0);
$check("transfery wykrywaja zmiane klubu", $dysc["transfery"]["poslow"], 0);

// --- sklad izby ---
// Lista skladu nie liczy niczego sama: nazwiska bierze z tabeli mp, a liczby
// z gotowych raportow. Dlatego bramka sprawdza dwie rzeczy - komplet nazwisk
// i to, ze transfery zgadzaja sie z raportem dyscypliny co do jednego posla.
$sklad = $read("'"$out"'/sklad.html")["raporty"]["10"]["sklad"];
$check("sklad niesie wszystkich poslow z bazy", count($sklad["poslowie"]), 3 + 12 + 12);
$check("transfery to ta sama liczba co w dyscyplinie", $sklad["meta"]["zmienilo_klub"], $dysc["transfery"]["poslow"]);
$withClub = array_filter($sklad["poslowie"], static fn (array $m): bool => $m["klub"] !== null);
$check("suma klubow rowna liczbie poslow z klubem", array_sum(array_column($sklad["kluby"], "n")), count($withClub));

// --- rozklad mandatow ---
// Sklad izby liczony z glosowan musi sie zgadzac z rejestrem tam, gdzie rejestr
// jest wiarygodny, a salda transferow musza sie zerowac - kazde odejscie jest
// czyimś przyjsciem.
$mand = $read("'"$out"'/mandaty.html")["raporty"]["10"]["mandaty"];
$check("mandaty sumuja sie do liczby podanej w meta", array_sum(array_column($mand["kluby"], "n")), $mand["meta"]["mandatow"]);
$check("salda transferow sumuja sie do zera", array_sum(array_column($mand["saldo"], "saldo")), 0);
$check("linia wiekszosci to polowa skladu plus jeden", $mand["meta"]["wiekszosc"], 231);
$check("przeplywy nie niosa zmian szyldu", array_sum(array_map(
    static fn (array $f): int => $f["zmiana_szyldu"] ? 1 : 0,
    $mand["przeplywy"],
)), 0);

// --- sklad rzadu ---
// Podpis pod odpowiedzia jest polem tekstowym wypelnianym recznie, wiec bramka
// pilnuje, ze rozklad na funkcje i nazwisko dziala, a to, czego nie rozlozy,
// jest policzone, a nie doklejone do kogokolwiek.
$rzad = $read("'"$out"'/rzad.html")["raporty"]["10"]["rzad"];
$osoby = [];
foreach ($rzad["osoby"] as $o) { $osoby[$o["nazwisko"]] = $o; }
$check("podpis rozlozony na funkcje i nazwisko", isset($osoby["Jan Testowy"]), true);
$check("funkcja odczytana z podpisu", $osoby["Jan Testowy"]["funkcja"] ?? null, "podsekretarz stanu");
$check("podpisy tej samej osoby zsumowane", $osoby["Jan Testowy"]["podpisow"] ?? 0, 40 + 10 + 4);
$check("podpisy bez nazwiska policzone osobno", $rzad["meta"]["podpisow_bez_nazwiska"], 3);
$check("resort ma etykiete, a nie surowy klucz", str_starts_with((string) ($rzad["resorty"][0]["nazwa"] ?? ""), "Minister"), true);

// --- kto z kim glosuje ---
$koal = $read("'"$out"'/koalicje.html")["raporty"]["10"]["koalicje"];
$para = null;
foreach ($koal["pary"] as $x) { if ($x["a"] === "KLUB-C" && $x["b"] === "KLUB-D") { $para = $x; } }
$check("kluby o przeciwnych liniach maja zerowa zgodnosc", (float) $para["zgodnosc"], 0.0);
$check("kategoria rozpoznana z tytulu glosowania", array_key_exists("projekty rządowe", $para["wg_kategorii"]), true);

exit($fail);
'

echo "--- vacatio legis ---"
php "$root/bin/build-vacatio.php" --db="$db" --out="$out" >/dev/null
php "$root/bin/build-vacatio.php" --db="$db" --out="$out" --exclude-technical >/dev/null

php -r '
$read = static function (string $file): array {
    $page = file_get_contents($file);
    return json_decode((string) preg_replace("/^.*?const DATA = (.*?);\n.*$/s", "$1", $page), true, 512, JSON_THROW_ON_ERROR);
};
$all  = $read("'"$out"'/vacatio.html");
$only = $read("'"$out"'/vacatio-merytoryczne.html");
$fail = 0;
$check = function (string $name, $got, $want) use (&$fail): void {
    if ($got === $want) { printf("OK   %-46s %s\n", $name, var_export($got, true)); return; }
    printf("BŁĄD %-46s otrzymano %s, oczekiwano %s\n", $name, var_export($got, true), var_export($want, true));
    $fail = 1;
};
$pick = static function (array $d): array {
    foreach ($d["organy"] as $o) { if ($o["klucz"] === "minister testowy") { return $o; } }
    throw new RuntimeException("brak organu testowego");
};
$a = $pick($all); $b = $pick($only);

$check("aktow razem", $a["aktow"], 70);
$check("ponizej standardu (20 pilnych + 10 technicznych)", $a["ponizej_standardu"], 30);
$check("z dnia na dzien", $a["natychmiast"], 30);
$check("mediana odpowiada dominujacej grupie", $a["mediana_dni"], 15);
$check("po odsianiu technicznych zostaje 60", $b["aktow"], 60);
$check("odsiano dokladnie 10", $only["meta"]["wykluczone_razem"], 10);
$check("wariant pelny", $all["meta"]["wariant"], "wszystkie");
$check("wariant odsiany", $only["meta"]["wariant"], "merytoryczne");

exit($fail);
'

for f in index.html interpelacje.html droga.html poslowie.html sklad.html mandaty.html rzad.html nieobecnosci.html vacatio.html vacatio-merytoryczne.html; do
    [ -s "$out/$f" ] || { echo "BŁĄD: nie powstał $f"; exit 1; }
    grep -q '__DATA__\|<!--@' "$out/$f" && { echo "BŁĄD: niewypełnione znaczniki w $f"; exit 1; }
    grep -q 'nav class="pages"' "$out/$f" || { echo "BŁĄD: brak nawigacji w $f"; exit 1; }
done
echo "OK   wygenerowano 10 stron z kompletem znaczników i nawigacją"

# Wersja angielska. Brak tlumaczenia NIE zostawia widocznego znacznika - wraca
# polski tekst pod angielskim adresem, czego po samej stronie nie widac. Dlatego
# bramka czyta to, co build zglosil, a nie szuka klamer w wyniku.
for f in index.html interpelacje.html droga.html poslowie.html sklad.html nieobecnosci.html; do
    [ -s "$out/en/$f" ] || { echo "BŁĄD: nie powstała angielska wersja $f"; exit 1; }
    grep -q '{{' "$out/en/$f" && { echo "BŁĄD: nieusunięty znacznik w en/$f"; exit 1; }
    grep -q '{{' "$out/$f" && { echo "BŁĄD: nieusunięty znacznik w $f"; exit 1; }
done

if grep -q 'BRAK TLUMACZEN' "$out/build.log"; then
    echo "BŁĄD: build zgłosił brakujące tłumaczenia:"
    grep -A25 'BRAK TLUMACZEN' "$out/build.log"
    exit 1
fi
echo "OK   wersja angielska kompletna — build nie zgłosił brakujących tłumaczeń"

# Przelacznik jezyka ma prowadzic do TEGO SAMEGO dokumentu, nie na strone glowna.
grep -q 'href="en/interpelacje.html" hreflang="en"' "$out/interpelacje.html" \
    || { echo "BŁĄD: przełącznik na stronie polskiej nie prowadzi do tej samej strony"; exit 1; }
grep -q 'href="../interpelacje.html" hreflang="pl"' "$out/en/interpelacje.html" \
    || { echo "BŁĄD: przełącznik na stronie angielskiej nie prowadzi do tej samej strony"; exit 1; }
echo "OK   przełącznik języka prowadzi do tego samego dokumentu"

# Profile leza w podkatalogu, wiec ich nawigacja musi byc przedrostkowana.
profil=$(ls "$out"/posel/*.html 2>/dev/null | head -1)
if [ -n "$profil" ]; then
    grep -q 'href="\.\./index.html"' "$profil" \
        || { echo "BŁĄD: nawigacja w profilu nie prowadzi poza podkatalog"; exit 1; }
    grep -q 'href="index.html"' "$profil" \
        && { echo "BŁĄD: profil ma odnośnik bez przedrostka"; exit 1; }
    echo "OK   nawigacja w profilu prowadzi poza podkatalog"

    # Z profilu przelacznik musi zejsc do katalogu jezyka i wejsc w ten sam profil.
    nazwa=$(basename "$profil")
    grep -q "href=\"../en/posel/$nazwa\"" "$profil" \
        || { echo "BŁĄD: przełącznik w profilu nie prowadzi do tego samego profilu"; exit 1; }
    [ -s "$out/en/posel/$nazwa" ] \
        || { echo "BŁĄD: brak angielskiej wersji profilu $nazwa"; exit 1; }
    grep -q "href=\"../../posel/$nazwa\"" "$out/en/posel/$nazwa" \
        || { echo "BŁĄD: przełącznik w angielskim profilu nie wraca do polskiego"; exit 1; }
    echo "OK   przełącznik języka w profilu prowadzi do tego samego profilu"
fi
