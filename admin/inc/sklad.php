<?php
/**
 * Skład na mecz: odczyt kadry i publikacja zatwierdzonego ustawienia.
 *
 * Osobny plik, bo z tych samych funkcji korzystają dwa światy: panel, gdy
 * trener zatwierdza skład, i cron, gdy skład przygotowany na przyszłą
 * kolejkę ma trafić na stronę w dniu meczu. Dublowanie ich skończyłoby się
 * dwoma różnymi kształtami tego samego data/sklad.json.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/formacje.php';
require_once __DIR__ . '/migracje.php';
require_once __DIR__ . '/przebieg.php';
require_once __DIR__ . '/zdjecia.php';

/** Zawodnicy do wystawienia: aktywni, bez sztabu. */
function zawodnicy_do_skladu(PDO $db): array
{
    $sztab = POZYCJE_SZTABU;
    $pytajniki = implode(',', array_fill(0, count($sztab), '?'));

    $stmt = $db->prepare(
        "SELECT id, imie, nazwisko, pozycja, numer, kapitan, zdjecie
           FROM zawodnicy
          WHERE aktywny = 1 AND pozycja NOT IN ($pytajniki)
          ORDER BY CASE pozycja
                     WHEN 'bramkarz'  THEN 1
                     WHEN 'obrońca'   THEN 2
                     WHEN 'pomocnik'  THEN 3
                     WHEN 'napastnik' THEN 4
                     ELSE 5
                   END,
                   numer IS NULL, numer, nazwisko"
    );
    $stmt->execute($sztab);

    return $stmt->fetchAll();
}

/** Ścieżka pliku, z którego stronę klubu czyta zatwierdzony skład. */
function plik_skladu(): string
{
    return katalog_strony() . '/data/sklad.json';
}

/**
 * Zapisuje zatwierdzony skład do data/sklad.json.
 *
 * Plik jest samowystarczalny: oprócz nazwisk niesie też współrzędne pól.
 * Dzięki temu script.js na stronie nie musi znać listy formacji — gdyby ją
 * znał, każda zmiana ustawienia w panelu wymagałaby zmiany w drugim miejscu
 * i prędzej czy później boisko na stronie rozjechałoby się z panelem.
 */
/**
 * Skład w kształcie, który czyta strona: pola z współrzędnymi i ławka.
 *
 * Wydzielone z publikacji, bo ten sam kształt trafia dziś w dwa miejsca —
 * do data/sklad.json z najbliższym meczem i do wyników rozegranych spotkań,
 * gdzie kibic ogląda skład sprzed tygodnia.
 */
function sklad_do_publikacji(
    array $zawodnicy,
    string $formacja,
    array $ustawienie,
    array $rezerwowi
): array {
    $wg_id = [];

    foreach ($zawodnicy as $z) {
        $wg_id[(int) $z['id']] = $z;
    }

    /* Ten sam zapis zawodnika na murawie i na ławce — strona klubu rysuje
       jedno i drugie z tych samych pól, więc nie ma powodu na dwa kształty. */
    $kto = static function (array $z): array {
        return [
            /* Identyfikator jedzie razem z nazwiskiem: po nim strona dopasuje
               strzelców z przebiegu meczu do krążków na boisku. Dwóch Pulaków
               w składzie wystarczy, żeby dopasowanie po nazwisku kłamało. */
            'id'       => (int) $z['id'],
            'imie'     => $z['imie'],
            'nazwisko' => $z['nazwisko'],
            'pozycja'  => $z['pozycja'],
            'numer'    => $z['numer'] !== null ? (int) $z['numer'] : null,
            'kapitan'  => (int) ($z['kapitan'] ?? 0) === 1,
            'zdjecie'  => adres_zdjecia($z['zdjecie'] ?? null, 'zawodnicy'),
        ];
    };

    $pola = [];

    foreach (formacje()[$formacja]['pola'] as $pole) {
        $id = $ustawienie[$pole['id']] ?? null;
        $z = $id !== null ? ($wg_id[$id] ?? null) : null;

        $pola[] = [
            'etykieta' => $pole['etykieta'],
            'x'        => $pole['x'],
            'y'        => $pole['y'],
            'grupa'    => $pole['grupa'],
            'zawodnik' => $z ? $kto($z) : null,
        ];
    }

    $lawka = [];

    foreach ($rezerwowi as $id) {
        if (isset($wg_id[$id])) {
            $lawka[] = $kto($wg_id[$id]);
        }
    }

    return ['formacja' => $formacja, 'pola' => $pola, 'rezerwowi' => $lawka];
}

/**
 * Zapisuje zatwierdzony skład do data/sklad.json.
 *
 * Plik jest samowystarczalny: oprócz nazwisk niesie też współrzędne pól.
 * Dzięki temu script.js na stronie nie musi znać listy formacji — gdyby ją
 * znał, każda zmiana ustawienia w panelu wymagałaby zmiany w drugim miejscu
 * i prędzej czy później boisko na stronie rozjechałoby się z panelem.
 */
function opublikuj_sklad(
    array $zawodnicy,
    string $formacja,
    array $ustawienie,
    array $rezerwowi,
    string $opis
): void {
    $sklad = sklad_do_publikacji($zawodnicy, $formacja, $ustawienie, $rezerwowi);

    $pola = $sklad['pola'];
    $lawka = $sklad['rezerwowi'];

    $json = json_encode([
        'zaktualizowano' => date('c'),
        'opis'           => $opis,
        'formacja'       => $formacja,
        'pola'           => $pola,
        'rezerwowi'      => $lawka,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

    $katalog = dirname(plik_skladu());

    if (!is_dir($katalog)) {
        mkdir($katalog, 0775, true);
    }

    file_put_contents(plik_skladu(), $json . "\n");
}

/**
 * Zdejmuje skład ze strony.
 *
 * Zapisujemy pusty skład zamiast kasować plik. Kasowanie też działa — strona
 * traktuje brak pliku i pusty skład tak samo — ale wtedy każda wizyta kończy
 * się czerwonym 404 w konsoli przeglądarki. Pusty plik mówi to samo, tylko
 * bez hałasu.
 */
function ukryj_sklad(): void
{
    $katalog = dirname(plik_skladu());

    if (!is_dir($katalog)) {
        return;
    }

    file_put_contents(plik_skladu(), json_encode([
        'zaktualizowano' => date('c'),
        'opis'           => '',
        'formacja'       => '',
        'pola'           => [],
        'rezerwowi'      => [],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
}

/**
 * Wrzuca na stronę zatwierdzony skład najbliższego spotkania.
 *
 * Używa tego i panel (po kliknięciu „Zatwierdź"), i cron — bo skład ustawiony
 * tydzień wcześniej musi pojawić się na stronie sam, gdy jego mecz stanie się
 * tym najbliższym. Bez zatwierdzonego składu plik zostaje wyczyszczony:
 * kibic ma zobaczyć zapowiedź, a nie skład z poprzedniej kolejki.
 */
function opublikuj_sklad_biezacy(PDO $db): bool
{
    if (!tabela_istnieje('sklady')) {
        return false;
    }

    $sklad = sklad_biezacy($db);

    if (!$sklad || (int) ($sklad['opublikowany'] ?? 0) !== 1) {
        ukryj_sklad();

        return false;
    }

    $zawodnicy = zawodnicy_do_skladu($db);
    $dozwoleni = array_column($zawodnicy, 'id');

    $ustawienie = [];
    $odczytane = json_decode((string) $sklad['ustawienie'], true);

    if (is_array($odczytane)) {
        foreach ($odczytane as $pole => $id) {
            if (in_array((int) $id, $dozwoleni, true)) {
                $ustawienie[$pole] = (int) $id;
            }
        }
    }

    $rezerwowi = [];
    $lawka = json_decode((string) ($sklad['rezerwowi'] ?? '[]'), true);

    if (is_array($lawka)) {
        foreach ($lawka as $id) {
            $id = (int) $id;

            if (in_array($id, $dozwoleni, true) && !in_array($id, $ustawienie, true)) {
                $rezerwowi[] = $id;
            }
        }
    }

    $formacja = czy_znana_formacja((string) $sklad['formacja'])
        ? (string) $sklad['formacja']
        : formacja_domyslna();

    opublikuj_sklad($zawodnicy, $formacja, $ustawienie, $rezerwowi, (string) $sklad['nazwa']);

    return true;
}
