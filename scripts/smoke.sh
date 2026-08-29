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
cp "$root/public/template.html" "$root/public/template-vacatio.html" "$out/"

php -r '
require "'"$root"'/bin/bootstrap.php";
$db = Milczenie\Storage\Database::open("'"$db"'");
$p = $db->pdo;

$p->exec("INSERT INTO term (num, date_from, date_to) VALUES (10, \"2023-11-13\", NULL)");
$p->exec("INSERT INTO term (num, date_from, date_to) VALUES (7, \"2011-11-08\", \"2015-11-11\")");

$q = $p->prepare("INSERT INTO question (id, kind, term, num, title, receipt_date, sent_date, authors, author_count)
                  VALUES (?, \"interpelacja\", ?, ?, ?, ?, ?, \"[]\", 0)");
$a = $p->prepare("INSERT INTO addressee (question_id, recipient_raw, recipient_key, sent_date) VALUES (?, ?, ?, ?)");
$r = $p->prepare("INSERT INTO reply (question_id, reply_key, author, receipt_date, prolongation, only_attachment)
                  VALUES (?, ?, \"Minister Testowy\", ?, 0, 0)");

$sent = "2024-01-10";
$mk = function (int $n, string $suffix, ?string $replyDate, bool $hasReply, int $addressees = 1)
        use ($q, $a, $r, $sent): void {
    for ($i = 0; $i < $n; $i++) {
        $id = "interpelacja:10:" . $suffix . $i;
        $q->execute([$id, 10, 1000 + crc32($id) % 8000, "Interpelacja testowa " . $suffix . $i, "2024-01-05", $sent]);
        $a->execute([$id, "minister testowy", "minister testowy", $sent]);
        if ($addressees > 1) {
            $a->execute([$id, "minister drugi", "minister drugi", $sent]);
        }
        if ($hasReply) {
            $r->execute([$id, "K" . $suffix . $i, $replyDate]);
        }
    }
};

$mk(40, "ontime",  "2024-01-20", true);   // 10 dni - w terminie
$mk(20, "late",    "2024-02-20", true);   // 41 dni - po terminie
$mk(10, "nodate",  null,         true);   // odpowiedz bez daty
$mk(5,  "silent",  null,         false);  // brak odpowiedzi
$mk(7,  "multi",   "2024-01-20", true, 2); // wielu adresatow

// Kadencja niemierzalna: same odpowiedzi bez daty.
for ($i = 0; $i < 50; $i++) {
    $id = "interpelacja:7:" . $i;
    $q->execute([$id, 7, $i + 1, "Interpelacja VII " . $i, "2013-01-05", "2013-01-10"]);
    $a->execute([$id, "minister testowy", "minister testowy", "2013-01-10"]);
    $r->execute([$id, "V" . $i, null]);
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

$db->setMeta("fetched_at", "2024-06-01T00:00:00+00:00");
$db->setMeta("acts_fetched_at", "2024-06-01T00:00:00+00:00");
'

echo "--- ranking milczenia ---"
php "$root/bin/build.php" --db="$db" --out="$out" --snapshot=2024-06-01 >/dev/null

php -r '
$d = json_decode(file_get_contents("'"$out"'/data.json"), true, 512, JSON_THROW_ON_ERROR);
$fail = 0;
$check = function (string $name, $got, $want) use (&$fail): void {
    if ($got === $want) { printf("OK   %-46s %s\n", $name, var_export($got, true)); return; }
    printf("BŁĄD %-46s otrzymano %s, oczekiwano %s\n", $name, var_export($got, true), var_export($want, true));
    $fail = 1;
};

$k10 = $d["raporty"]["10"];
$m = null;
foreach ($k10["ministerstwa"] as $row) { if ($row["klucz"] === "minister testowy") { $m = $row; } }
if ($m === null) { echo "BŁĄD brak adresata testowego\n"; exit(1); }

$check("na czas", $m["na_czas"], 40);
$check("po terminie", $m["po_terminie"], 20);
$check("odpowiedzi bez daty poza mianownikiem", $m["odpowiedzi_bez_daty"], 10);
$check("bez odpowiedzi po terminie", $m["bez_odpowiedzi_po_terminie"], 5);
$check("mianownik = 40+20+5", $m["rozstrzygniete"], 65);
$check("pytania do wielu adresatow wykluczone", $k10["meta"]["wylaczone"]["wielu_adresatow"], 7);

$t7 = null; $t10 = null;
foreach ($d["kadencje"] as $k) { if ($k["numer"] === 7) { $t7 = $k; } if ($k["numer"] === 10) { $t10 = $k; } }
$check("kadencja z odpowiedziami bez daty jest niemierzalna", $t7["mierzalna"], false);
$check("kadencja z kompletem dat jest mierzalna", $t10["mierzalna"], true);
$check("domyslna kadencja jest mierzalna", $d["domyslna_kadencja"], 10);

exit($fail);
'

echo "--- vacatio legis ---"
php "$root/bin/build-vacatio.php" --db="$db" --out="$out" >/dev/null
php "$root/bin/build-vacatio.php" --db="$db" --out="$out" --exclude-technical >/dev/null

php -r '
$all  = json_decode(file_get_contents("'"$out"'/vacatio.json"), true, 512, JSON_THROW_ON_ERROR);
$only = json_decode(file_get_contents("'"$out"'/vacatio-merytoryczne.json"), true, 512, JSON_THROW_ON_ERROR);
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

for f in index.html vacatio.html vacatio-merytoryczne.html; do
    [ -s "$out/$f" ] || { echo "BŁĄD: nie powstał $f"; exit 1; }
    grep -q '__DATA__' "$out/$f" && { echo "BŁĄD: dane nie zostały wstrzyknięte do $f"; exit 1; }
done
echo "OK   wygenerowano trzy strony z wstrzykniętymi danymi"
