<?php
/**
 * Przebieg meczu: gole, zmiany i stan spotkania.
 *
 * Wspólne dla dwóch ekranów, bo obsługują ten sam mecz z dwóch stron:
 * „Przebieg meczu" wpisuje zdarzenia z formularzy, a „Skład" pozwala
 * zrobić zmianę przeciągnięciem rezerwowego na zawodnika z boiska.
 * Gdyby każdy liczył to po swojemu, prędzej czy później pokazałyby
 * dwa różne składy tego samego meczu.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/* Doliczony czas bywa długi, ale 130 minuta to już nie mecz, tylko literówka. */
const MAKS_MINUTA = 130;

const STANY_MECZU = ['przed', 'trwa', 'koniec'];

/* Od której minuty zatrzymanie stopera traktujemy jako przerwę między
   połowami — a więc takie, po którym zegar wraca na równe 45:00. Zatrzymanie
   wcześniejsze to kontuzja albo przerwana gra i wtedy czas rusza z miejsca,
   w którym stanął. */
const PRZERWA_OD_MINUTY = 40;

/** Zawodnicy po identyfikatorach, w mapie id => wiersz. */
function zawodnicy_po_id(PDO $db, array $ids): array
{
    if (!$ids) {
        return [];
    }

    $pytajniki = implode(',', array_fill(0, count($ids), '?'));

    /* Także pozycja, kapitan i zdjęcie: ten sam zestaw rysuje boisko w panelu
       przebiegu, a dociąganie ich drugim zapytaniem nic by nie dało. */
    $stmt = $db->prepare(
        "SELECT id, imie, nazwisko, pozycja, numer, kapitan, zdjecie
           FROM zawodnicy WHERE id IN ($pytajniki)"
    );
    $stmt->execute($ids);

    $wg_id = [];

    foreach ($stmt->fetchAll() as $z) {
        $wg_id[(int) $z['id']] = $z;
    }

    return $wg_id;
}

/** Lista identyfikatorów z zapisanego JSON-a; śmieci wylatują po drodze. */
function id_ze_skladu($json): array
{
    $odczytane = json_decode((string) $json, true);
    $ids = [];

    if (is_array($odczytane)) {
        foreach ($odczytane as $id) {
            $id = (int) $id;

            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
    }

    return $ids;
}

/**
 * Zdarzenia jednego spotkania.
 *
 * Klucz meczu jest tu konieczny, a nie dla porządku: bez niego gole
 * poprzedniej kolejki zostawały w tabeli i doklejały się do następnego
 * meczu w chwili, gdy terminarz przeskakiwał dalej. Wynik 6:4 z meczu
 * z Pniem pokazał się tak przy spotkaniu ze Sprintem.
 */
function zdarzenia_meczu(PDO $db, ?string $klucz): array
{
    if (!kolumna_istnieje('zdarzenia', 'mecz_klucz')) {
        return $db->query('SELECT * FROM zdarzenia ORDER BY minuta, id')->fetchAll();
    }

    if ($klucz === null || $klucz === '') {
        return [];
    }

    $stmt = $db->prepare('SELECT * FROM zdarzenia WHERE mecz_klucz = ? ORDER BY minuta, id');
    $stmt->execute([$klucz]);

    return $stmt->fetchAll();
}

function dopisz_zdarzenie(
    PDO $db,
    ?string $klucz,
    string $typ,
    int $minuta,
    ?int $zawodnik,
    ?int $drugi
): void {
    if (!kolumna_istnieje('zdarzenia', 'mecz_klucz')) {
        $db->prepare(
            'INSERT INTO zdarzenia (typ, minuta, zawodnik_id, zawodnik2_id, utworzono)
                  VALUES (?, ?, ?, ?, ?)'
        )->execute([$typ, $minuta, $zawodnik, $drugi, date('Y-m-d H:i:s')]);

        return;
    }

    $db->prepare(
        'INSERT INTO zdarzenia (mecz_klucz, typ, minuta, zawodnik_id, zawodnik2_id, utworzono)
              VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$klucz, $typ, $minuta, $zawodnik, $drugi, date('Y-m-d H:i:s')]);
}

/**
 * Kto stoi na boisku po dotychczasowych zmianach i kogo jeszcze można wpuścić.
 *
 * Liczymy to ze zdarzeń zamiast trzymać osobno w bazie: skasowanie błędnie
 * wpisanej zmiany ma od razu cofnąć zawodnika na ławkę, a nie zostawić dwa
 * zapisy, które mówią co innego.
 */
function po_zmianach(array $xi, array $lawka, array $zdarzenia): array
{
    $na_boisku = $xi;
    $wolni = $lawka;

    $bez = static function (array $lista, int $id): array {
        return array_values(array_filter($lista, static function ($x) use ($id): bool {
            return (int) $x !== $id;
        }));
    };

    foreach ($zdarzenia as $z) {
        if ($z['typ'] !== 'zmiana') {
            continue;
        }

        $schodzi = (int) $z['zawodnik2_id'];
        $wchodzi = (int) $z['zawodnik_id'];

        $na_boisku = $bez($na_boisku, $schodzi);
        $wolni = $bez($wolni, $wchodzi);

        if (!in_array($wchodzi, $na_boisku, true)) {
            $na_boisku[] = $wchodzi;
        }
    }

    return [$na_boisku, $wolni];
}

/**
 * Ustawienie po zmianach: wchodzący staje na polu tego, kogo zmienił.
 *
 * Trener w trakcie meczu patrzy na boisko, a nie na listę nazwisk — jeśli
 * wpuszczony obrońca pokazałby się w ataku albo wcale, panel przestałby
 * odpowiadać na jedyne pytanie, które mu się w tej chwili zadaje:
 * kto gdzie teraz stoi.
 */
function ustawienie_po_zmianach(array $ustawienie, array $zdarzenia): array
{
    foreach ($zdarzenia as $z) {
        if ($z['typ'] !== 'zmiana') {
            continue;
        }

        $schodzi = (int) $z['zawodnik2_id'];
        $wchodzi = (int) $z['zawodnik_id'];

        $pole = array_search($schodzi, $ustawienie, true);

        if ($pole !== false) {
            $ustawienie[$pole] = $wchodzi;
        }
    }

    return $ustawienie;
}

/**
 * Która to minuta meczu — podpowiedź do formularza, nie wyrocznia.
 *
 * Liczona od pierwszego gwizdka. Gdy stoper stoi na przerwie, liczymy do
 * chwili jej rozpoczęcia, więc minuta zamarza razem z zegarem. Doliczony
 * czas i tak zna tylko sędzia, dlatego trener może minutę poprawić.
 */
function minuta_meczu(?string $start, ?string $przerwa_od = null): ?int
{
    if (!$start) {
        return null;
    }

    $od = strtotime($start);

    if ($od === false) {
        return null;
    }

    $do = $przerwa_od ? strtotime($przerwa_od) : time();

    if ($do === false) {
        $do = time();
    }

    $minuta = (int) floor(($do - $od) / 60) + 1;

    return max(1, min(MAKS_MINUTA, $minuta));
}

/** Plik, z którego strona klubu czyta przebieg meczu. */
function plik_przebiegu(): string
{
    return katalog_strony() . '/data/przebieg.json';
}

/** Plik z wynikami meczów rozegranych w panelu. */
function plik_wynikow(): string
{
    return katalog_strony() . '/data/wyniki.json';
}

/**
 * Wyniki spotkań zakończonych w panelu.
 *
 * 90minut wpisuje rezultat dopiero wtedy, gdy zrobi to sędzia albo gospodarz
 * — czasem w poniedziałek. My wiemy swoje w chwili końcowego gwizdka, więc
 * strona bierze wynik stąd wszędzie tam, gdzie terminarz ma jeszcze kreskę.
 * Zapisujemy go w kolejności gospodarz–gość, bo tak czyta go reszta strony;
 * „my i oni" to pojęcia panelu, nie tabeli.
 */
function opublikuj_wyniki(PDO $db): void
{
    if (!tabela_istnieje('sklady') || !kolumna_istnieje('sklady', 'mecz_klucz')) {
        return;
    }

    $mecze = [];

    $zakonczone = $db->query(
        "SELECT mecz_klucz FROM sklady WHERE mecz_klucz IS NOT NULL AND stan = 'koniec'"
    )->fetchAll(PDO::FETCH_COLUMN);

    foreach ($zakonczone as $klucz) {
        $klucz = (string) $klucz;
        [, $gospodarz] = array_pad(explode('|', $klucz, 3), 3, '');

        $przebieg = zdarzenia_do_publikacji($db, $klucz);
        $u_siebie = $gospodarz === NASZ_KLUB;

        $mecze[$klucz] = [
            'wynik' => ($u_siebie ? $przebieg['my'] : $przebieg['oni']) . '-'
                     . ($u_siebie ? $przebieg['oni'] : $przebieg['my']),
            /* Oś czasu jedzie razem z wynikiem: strona pokazuje ją pod ikoną
               przy meczu, więc nie ma po co ciągnąć osobnego pliku na kliknięcie
               — cały sezon to kilkaset wierszy. */
            'zdarzenia' => $przebieg['zdarzenia'],
        ];
    }

    $json = json_encode([
        'zaktualizowano' => date('c'),
        'mecze'          => (object) $mecze,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

    $katalog = dirname(plik_wynikow());

    if (!is_dir($katalog)) {
        mkdir($katalog, 0775, true);
    }

    file_put_contents(plik_wynikow(), $json . "\n");
}

/**
 * Zdarzenia meczu w kształcie dla strony: z nazwiskami i wynikiem po bramce.
 *
 * Ten sam kształt trafia do przebiegu trwającego meczu i do wyników spotkań
 * rozegranych — strona rysuje oś czasu jednym kodem, bez pytania, skąd dane
 * pochodzą.
 */
function zdarzenia_do_publikacji(PDO $db, ?string $klucz): array
{
    $zdarzenia = zdarzenia_meczu($db, $klucz);

    $ids = [];

    foreach ($zdarzenia as $z) {
        foreach (['zawodnik_id', 'zawodnik2_id'] as $kolumna) {
            if ($z[$kolumna] !== null) {
                $ids[] = (int) $z[$kolumna];
            }
        }
    }

    $wg_id = zawodnicy_po_id($db, array_values(array_unique($ids)));

    /* Zawodnik mógł w międzyczasie zniknąć z kadry — wtedy zostaje null
       i strona pokazuje samo zdarzenie bez nazwiska, zamiast się wysypać. */
    $kto = static function ($id) use ($wg_id): ?array {
        $id = (int) $id;

        if (!isset($wg_id[$id])) {
            return null;
        }

        $z = $wg_id[$id];

        return [
            // po id strona dopina bramki do krążków na boisku
            'id'       => (int) $z['id'],
            'imie'     => $z['imie'],
            'nazwisko' => $z['nazwisko'],
            'numer'    => $z['numer'] !== null ? (int) $z['numer'] : null,
        ];
    };

    $lista = [];
    $my = 0;
    $oni = 0;

    foreach ($zdarzenia as $z) {
        $minuta = (int) $z['minuta'];

        if ($z['typ'] === 'gol' || $z['typ'] === 'gol_rywala') {
            if ($z['typ'] === 'gol') {
                $my++;
            } else {
                $oni++;
            }

            $lista[] = [
                'typ'    => $z['typ'],
                'minuta' => $minuta,
                'kto'    => $z['typ'] === 'gol' ? $kto($z['zawodnik_id']) : null,
                'asysta' => $z['typ'] === 'gol' ? $kto($z['zawodnik2_id']) : null,
                'wynik'  => ['my' => $my, 'oni' => $oni],
            ];

            continue;
        }

        $lista[] = [
            'typ'     => 'zmiana',
            'minuta'  => $minuta,
            'wchodzi' => $kto($z['zawodnik_id']),
            'schodzi' => $kto($z['zawodnik2_id']),
        ];
    }

    return ['zdarzenia' => $lista, 'my' => $my, 'oni' => $oni];
}

/**
 * Zapisuje przebieg do data/przebieg.json.
 *
 * Plik niesie gotowe nazwiska, a nie identyfikatory: strona klubu nie ma
 * dostępu do bazy, a wynik po każdej bramce liczymy tutaj, żeby kolejność
 * w osi czasu i cyfry na tablicy nie mogły się rozjechać.
 */
function opublikuj_przebieg(PDO $db): void
{
    $mecz = mecz_biezacy();
    $sklad = sklad_biezacy($db);

    $przebieg = zdarzenia_do_publikacji($db, $mecz['klucz'] ?? null);

    $lista = $przebieg['zdarzenia'];
    $my = $przebieg['my'];
    $oni = $przebieg['oni'];

    /* Godzina pierwszego gwizdka jedzie do pliku, żeby strona mogła sama
       policzyć minutę meczu — inaczej musiałaby ją dostawać w każdym zapisie
       i między bramkami stałaby w miejscu. */
    $start = $sklad['start_meczu'] ?? null;
    $pauza = $sklad['przerwa_od'] ?? null;

    $json = json_encode([
        'zaktualizowano' => date('c'),
        /* Czyj to przebieg. Strona porównuje ten klucz ze spotkaniem, które
           właśnie pokazuje — inaczej wynik poprzedniej kolejki wyświetliłby
           się przy następnym meczu. */
        'mecz'           => $mecz['klucz'] ?? null,
        'opis_meczu'     => $mecz['opis'] ?? null,
        'stan'           => (string) ($sklad['stan'] ?? 'przed'),
        'start'          => $start ? date('c', (int) strtotime((string) $start)) : null,
        /* Chwila zatrzymania zegara — strona zamraża na niej stoper
           i pisze „Przerwa" zamiast „Na żywo". */
        'pauza'          => $pauza ? date('c', (int) strtotime((string) $pauza)) : null,
        'opis'           => (string) ($sklad['nazwa'] ?? ''),
        'wynik'          => ['my' => $my, 'oni' => $oni],
        'zdarzenia'      => $lista,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

    $katalog = dirname(plik_przebiegu());

    if (!is_dir($katalog)) {
        mkdir($katalog, 0775, true);
    }

    file_put_contents(plik_przebiegu(), $json . "\n");

    /* Wyniki idą razem z przebiegiem: każdy zapis w panelu może być tym,
       po którym mecz dostaje rezultat. */
    opublikuj_wyniki($db);
}

/* ---------- druga drużyna i herby ---------- */

const NASZ_KLUB = 'Janovia Janowiec';

/* Tyle trwa mecz w oczach strony klubu: po tym czasie spotkanie znika
   z terminarza. Ta sama liczba co CZAS_MECZU_MS w script.js — panel musi
   wskazać ten sam mecz, który kibic widzi jako najbliższy. */
const CZAS_MECZU_S = 2 * 60 * 60;

/**
 * Klucz spotkania — po nim skład trzyma się swojego meczu.
 *
 * Terminarz z 90minut nie ma identyfikatorów, więc sklejamy go z tego, co
 * spotkanie określa jednoznacznie: kolejki i obu drużyn. Data celowo nie
 * wchodzi w skład klucza — przełożony mecz ma zostać tym samym meczem,
 * a razem z nim ustawiony już skład.
 */
function klucz_meczu(array $m): string
{
    return mb_substr(
        ($m['kolejka'] ?? '-') . '|' . ($m['gospodarz'] ?? '') . '|' . ($m['gosc'] ?? ''),
        0,
        120
    );
}

/** Czytelny podpis spotkania: „Janovia — Sokół Pień, kolejka 8". */
function opis_meczu(array $m): string
{
    $kolejka = !empty($m['kolejka']) ? ', kolejka ' . $m['kolejka'] : '';

    return ($m['gospodarz'] ?? '') . ' — ' . ($m['gosc'] ?? '') . $kolejka;
}

/**
 * Spotkania, na które można dziś ustawiać skład: wszystkie nierozegrane
 * z terminarza, w kolejności terminów.
 *
 * Panel nie ma własnej listy meczów: terminarz ściąga cron do data/liga.json
 * i to samo źródło czyta strona. Dzięki temu panel i strona mówią o tych
 * samych spotkaniach, bez drugiego miejsca do pilnowania.
 */
function mecze_terminarza(): array
{
    $plik = katalog_strony() . '/data/liga.json';

    if (!is_file($plik)) {
        return [];
    }

    $dane = json_decode((string) file_get_contents($plik), true);

    if (!is_array($dane) || empty($dane['nadchodzace'])) {
        return [];
    }

    $mecze = [];

    foreach ($dane['nadchodzace'] as $m) {
        if (empty($m['gospodarz']) || empty($m['gosc'])) {
            continue;
        }

        $start = !empty($m['data_iso']) ? strtotime((string) $m['data_iso']) : false;

        /* Termin nieznany albo mecz jeszcze się nie skończył. Doliczenie czasu
           gry jest tu konieczne: bez niego spotkanie przestawałoby pasować
           w chwili pierwszego gwizdka, czyli dokładnie wtedy, gdy panel go
           potrzebuje. */
        if ($start !== false
            && $start + ($m['data_przyblizona'] ?? false ? 2 * 86400 : CZAS_MECZU_S) <= time()) {
            continue;
        }

        $mecze[] = [
            'klucz'       => klucz_meczu($m),
            'opis'        => opis_meczu($m),
            'gospodarz'   => (string) $m['gospodarz'],
            'gosc'        => (string) $m['gosc'],
            'kolejka'     => $m['kolejka'] ?? null,
            'data_iso'    => $m['data_iso'] ?? null,
            'kolejka_opis'=> $m['kolejka_opis'] ?? null,
            'przyblizona' => (bool) ($m['data_przyblizona'] ?? false),
        ];
    }

    return $mecze;
}

/**
 * Mecz, o który w tej chwili chodzi — pierwszy nierozegrany z terminarza.
 *
 * To jego skład jedzie na stronę i jego przebieg prowadzi panel; składy
 * ustawione na dalsze kolejki czekają w panelu na swoją kolej.
 */
function mecz_biezacy(): ?array
{
    return mecze_terminarza()[0] ?? null;
}

/** Spotkanie z terminarza o tym kluczu albo null, gdy już go tam nie ma. */
function mecz_po_kluczu(?string $klucz): ?array
{
    if ($klucz === null || $klucz === '') {
        return null;
    }

    foreach (mecze_terminarza() as $m) {
        if ($m['klucz'] === $klucz) {
            return $m;
        }
    }

    return null;
}

/**
 * Skład zapisany na dane spotkanie.
 *
 * Wiersz bez klucza to skład sprzed podziału na mecze — dopóki trener nie
 * przypisze go do spotkania, obsługuje ten najbliższy, czyli dokładnie tak,
 * jak panel działał wcześniej.
 */
function sklad_meczu(PDO $db, ?string $klucz, bool $biezacy = false): ?array
{
    if ($klucz !== null && $klucz !== '') {
        $stmt = $db->prepare('SELECT * FROM sklady WHERE mecz_klucz = ? LIMIT 1');
        $stmt->execute([$klucz]);
        $wiersz = $stmt->fetch();

        if ($wiersz) {
            return $wiersz;
        }
    }

    if (!$biezacy) {
        return null;
    }

    $stmt = $db->query('SELECT * FROM sklady WHERE mecz_klucz IS NULL ORDER BY id LIMIT 1');

    return $stmt->fetch() ?: null;
}

/** Skład najbliższego spotkania — ten, który widzą kibice i który się rozgrywa. */
function sklad_biezacy(PDO $db): ?array
{
    $mecz = mecz_biezacy();

    return sklad_meczu($db, $mecz['klucz'] ?? null, true);
}

/**
 * Herby klubów: nazwa drużyny => plik w katalogu png/.
 *
 * Czytane ze script.js, a nie przepisane tutaj drugi raz. Mapa żyje na stronie
 * klubu, bo to ona rysuje tabelę i terminarz; kopia w panelu rozjechałaby się
 * przy pierwszej zmianie ligi, a rozjazd objawiłby się brakiem herbu dopiero
 * w dniu meczu. Gdy odczyt się nie uda, zostaje pusta mapa — panel pokazuje
 * wtedy tarczę z literą, dokładnie jak strona dla klubu spoza listy.
 */
function herby_klubow(): array
{
    static $herby = null;

    if ($herby !== null) {
        return $herby;
    }

    $herby = [];
    $plik = katalog_strony() . '/script.js';

    if (!is_file($plik)) {
        return $herby;
    }

    $kod = (string) file_get_contents($plik);
    $od = strpos($kod, 'var HERBY = {');

    if ($od === false) {
        return $herby;
    }

    $do = strpos($kod, '};', $od);
    $blok = substr($kod, $od, $do === false ? null : $do - $od);

    if (preg_match_all("~'([^']+)'\s*:\s*'([^']+)'~u", $blok, $pary, PREG_SET_ORDER)) {
        foreach ($pary as $para) {
            $herby[$para[1]] = $para[2];
        }
    }

    return $herby;
}

/** Adres herbu względem katalogu panelu albo null, gdy klubu nie ma na liście. */
function adres_herbu(string $klub): ?string
{
    $plik = herby_klubow()[$klub] ?? null;

    if ($plik === null) {
        return null;
    }

    // wpis z ukośnikiem to herb wgrany w panelu (uploads/…), reszta leży w png/
    return '../' . (strpos($plik, '/') === false ? 'png/' . $plik : $plik);
}
