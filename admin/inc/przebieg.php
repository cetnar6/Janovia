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

/* Kartki zapisujemy jako zwykłe zdarzenia, nie jako kolumnę przy zawodniku:
   liczy się minuta i kolejność, a druga żółta w tym samym meczu to osobny
   wpis, nie zmiana stanu. */
const KARTKI = ['kartka_zolta', 'kartka_czerwona'];

/* Kartki rywala to osobne typy, bo niosą co innego: nie zawodnika z naszej
   kadry, tylko numer na cudzej koszulce. */
const KARTKI_RYWALA = ['kartka_zolta_rywala', 'kartka_czerwona_rywala'];

/* Od której minuty zatrzymanie stopera traktujemy jako przerwę między
   połowami — a więc takie, po którym zegar wraca na równe 45:00. Zatrzymanie
   wcześniejsze to kontuzja albo przerwana gra i wtedy czas rusza z miejsca,
   w którym stanął. */
const PRZERWA_OD_MINUTY = 40;

/* Regulaminowa długość połowy. Wszystko ponad nią to czas doliczony, więc
   zamiast „48" piszemy „45+3" — tak jak tablica na stadionie. */
const POLOWA_MINUT = 45;

/* Ile minut doliczonego czasu jeszcze w to wierzymy. Sędzia dolicza kilka,
   wyjątkowo kilkanaście; „45+43" nie znaczy doliczonego czasu, tylko że
   nikt nie przełączył połowy w panelu. Wtedy uczciwiej pokazać samą
   minutę, niż podać liczbę, która na pewno jest nieprawdziwa. */
const MAKS_DOLICZONY = 15;

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
    /* Najpierw połowa, dopiero potem minuta: gol z doliczonego czasu
       pierwszej połowy ma minutę 48, więc sama minuta wypychała go za
       zdarzenia z 46 minuty drugiej połowy — czyli za przerwę, choć padł
       przed nią. Wpisy bez zapisanej połowy czytamy tą samą regułą, co
       reszta panelu: ponad 45 to druga połowa. */
    $kolejnosc = kolumna_istnieje('zdarzenia', 'polowa')
        ? 'COALESCE(polowa, CASE WHEN minuta > ' . POLOWA_MINUT . ' THEN 2 ELSE 1 END), minuta, id'
        : 'minuta, id';

    if (!kolumna_istnieje('zdarzenia', 'mecz_klucz')) {
        return $db->query('SELECT * FROM zdarzenia ORDER BY ' . $kolejnosc)->fetchAll();
    }

    if ($klucz === null || $klucz === '') {
        return [];
    }

    $stmt = $db->prepare('SELECT * FROM zdarzenia WHERE mecz_klucz = ? ORDER BY ' . $kolejnosc);
    $stmt->execute([$klucz]);

    return $stmt->fetchAll();
}

function dopisz_zdarzenie(
    PDO $db,
    ?string $klucz,
    string $typ,
    int $minuta,
    ?int $zawodnik,
    ?int $drugi,
    int $polowa = 1,
    ?int $numer_rywala = null
): void {
    if (!kolumna_istnieje('zdarzenia', 'mecz_klucz')) {
        $db->prepare(
            'INSERT INTO zdarzenia (typ, minuta, zawodnik_id, zawodnik2_id, utworzono)
                  VALUES (?, ?, ?, ?, ?)'
        )->execute([$typ, $minuta, $zawodnik, $drugi, date('Y-m-d H:i:s')]);

        return;
    }

    /* Połowa jedzie z panelu, a nie z minuty: 46 minuta to równie dobrze
       doliczony czas pierwszej połowy, co początek drugiej — po przerwie
       stoper wraca na 45:00 i obie wyglądają tak samo. */
    if (kolumna_istnieje('zdarzenia', 'polowa')) {
        if (kolumna_istnieje('zdarzenia', 'numer_rywala')) {
            $db->prepare(
                'INSERT INTO zdarzenia (mecz_klucz, typ, minuta, polowa, zawodnik_id,
                                        zawodnik2_id, numer_rywala, utworzono)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([$klucz, $typ, $minuta, $polowa, $zawodnik, $drugi,
                        $numer_rywala, date('Y-m-d H:i:s')]);

            return;
        }

        $db->prepare(
            'INSERT INTO zdarzenia (mecz_klucz, typ, minuta, polowa, zawodnik_id, zawodnik2_id, utworzono)
                  VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$klucz, $typ, $minuta, $polowa, $zawodnik, $drugi, date('Y-m-d H:i:s')]);

        return;
    }

    $db->prepare(
        'INSERT INTO zdarzenia (mecz_klucz, typ, minuta, zawodnik_id, zawodnik2_id, utworzono)
              VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$klucz, $typ, $minuta, $zawodnik, $drugi, date('Y-m-d H:i:s')]);
}

/**
 * Połowa, w której wypadło zdarzenie.
 *
 * Wpisy sprzed tej kolumny jej nie mają — wtedy zgadujemy po minucie. Mylimy
 * się wyłącznie na golach z doliczonego czasu pierwszej połowy, a i tak
 * pokazujemy wtedy to samo, co przed zmianą.
 */
function polowa_zdarzenia(array $z): int
{
    $polowa = (int) ($z['polowa'] ?? 0);

    if ($polowa === 1 || $polowa === 2) {
        return $polowa;
    }

    return (int) ($z['minuta'] ?? 0) > POLOWA_MINUT ? 2 : 1;
}

/** „45+3" albo „90+5" dla czasu doliczonego, zwykła liczba dla reszty. */
function opis_minuty(int $minuta, int $polowa): string
{
    $podstawa = $polowa === 2 ? 2 * POLOWA_MINUT : POLOWA_MINUT;
    $doliczone = $minuta - $podstawa;

    return $doliczone > 0 && $doliczone <= MAKS_DOLICZONY
        ? $podstawa . '+' . $doliczone
        : (string) $minuta;
}

/**
 * Minuta wpisana ręcznie — liczba albo doliczony czas („45+3", „90+5").
 *
 * Zapis z plusem niesie więcej niż liczbę: mówi też, której połowy dotyczy.
 * „45+3" to zawsze koniec pierwszej, nawet jeśli panel stoi już przy drugiej
 * — bo tak właśnie poprawia się gola dopisanego po fakcie.
 *
 * Zwraca ['minuta' => int, 'polowa' => int] albo null, gdy wpisu nie da się
 * odczytać.
 */
function minuta_z_wpisu($tekst, int $polowa_domyslna): ?array
{
    $tekst = preg_replace('/\s+/u', '', (string) $tekst);

    if ($tekst === null || $tekst === '') {
        return null;
    }

    if (!preg_match('/^(\d{1,3})(?:\+(\d{1,2}))?$/', $tekst, $t)) {
        return null;
    }

    $podstawa = (int) $t[1];
    $doliczone = isset($t[2]) ? (int) $t[2] : null;

    if ($doliczone === null) {
        $minuta = $podstawa;
        $polowa = $polowa_domyslna;
    } else {
        /* Plus ma sens tylko po regulaminowym końcu połowy. Przy innej
           liczbie („30+2") czytamy to jako zwykłą sumę i zostajemy przy
           bieżącej połowie — żadna inna interpretacja nie byłaby pewniejsza. */
        $polowa = $podstawa === 2 * POLOWA_MINUT ? 2
                : ($podstawa === POLOWA_MINUT ? 1 : $polowa_domyslna);

        $minuta = $podstawa + $doliczone;
    }

    if ($minuta < 0 || $minuta > MAKS_MINUTA) {
        return null;
    }

    return ['minuta' => $minuta, 'polowa' => $polowa];
}

/** Która połowa trwa w zapisanym meczu. */
function polowa_skladu(?array $sklad): int
{
    $polowa = (int) ($sklad['polowa'] ?? 1);

    return $polowa === 2 ? 2 : 1;
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

/**
 * Skład, w jakim zagrano dane spotkanie — w kształcie, którego używa strona.
 *
 * Pusta tablica, gdy składu nie zatwierdzono: wtedy w oknie meczu zostaje
 * sama oś czasu, zamiast pustego boiska udającego ustawienie.
 */
function sklad_rozegranego(PDO $db, string $klucz): ?array
{
    require_once __DIR__ . '/sklad.php';

    $stmt = $db->prepare('SELECT * FROM sklady WHERE mecz_klucz = ? LIMIT 1');
    $stmt->execute([$klucz]);
    $wiersz = $stmt->fetch();

    if (!$wiersz || (int) ($wiersz['opublikowany'] ?? 0) !== 1) {
        return null;
    }

    $zawodnicy = zawodnicy_do_skladu($db);
    $dozwoleni = array_column($zawodnicy, 'id');

    $ustawienie = [];
    $odczytane = json_decode((string) $wiersz['ustawienie'], true);

    if (is_array($odczytane)) {
        foreach ($odczytane as $pole => $id) {
            if (in_array((int) $id, $dozwoleni, true)) {
                $ustawienie[$pole] = (int) $id;
            }
        }
    }

    if (!$ustawienie) {
        return null;
    }

    $rezerwowi = [];
    $lawka = json_decode((string) ($wiersz['rezerwowi'] ?? '[]'), true);

    if (is_array($lawka)) {
        foreach ($lawka as $id) {
            $id = (int) $id;

            if (in_array($id, $dozwoleni, true) && !in_array($id, $ustawienie, true)) {
                $rezerwowi[] = $id;
            }
        }
    }

    $formacja = czy_znana_formacja((string) $wiersz['formacja'])
        ? (string) $wiersz['formacja']
        : formacja_domyslna();

    return sklad_do_publikacji($zawodnicy, $formacja, $ustawienie, $rezerwowi);
}

/** Plik z wynikami meczów rozegranych w panelu. */
function plik_wynikow(): string
{
    return katalog_strony() . '/data/wyniki.json';
}

/**
 * Plik ze składami rozegranych spotkań.
 *
 * Osobny od wyników, bo waży dziesięć razy tyle: wyniki i oś czasu strona
 * wczytuje zawsze (ikona przy meczu musi wiedzieć, czy jest co pokazać),
 * a składy dopiero wtedy, gdy ktoś naprawdę zajrzy do okna meczu.
 */
function plik_skladow(): string
{
    return katalog_strony() . '/data/sklady.json';
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
    $sklady = [];

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
            /* Oś czasu i skład jadą razem z wynikiem: strona pokazuje jedno
               i drugie pod ikoną przy meczu, więc nie ma po co ciągnąć osobnego
               pliku na kliknięcie — rozegrany mecz to kilka kilobajtów. */
            'zdarzenia' => $przebieg['zdarzenia'],
        ];

        $sklad = sklad_rozegranego($db, $klucz);

        if ($sklad) {
            $sklady[$klucz] = $sklad;
        }
    }

    $katalog = dirname(plik_wynikow());

    if (!is_dir($katalog)) {
        mkdir($katalog, 0775, true);
    }

    file_put_contents(plik_wynikow(), json_encode([
        'zaktualizowano' => date('c'),
        'mecze'          => (object) $mecze,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");

    file_put_contents(plik_skladow(), json_encode([
        'zaktualizowano' => date('c'),
        'mecze'          => (object) $sklady,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
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
        $polowa = polowa_zdarzenia($z);

        /* Gotowy opis minuty idzie z panelu, żeby strona i panel nie liczyły
           doliczonego czasu każde po swojemu. */
        $opis_minuty = opis_minuty($minuta, $polowa);

        if ($z['typ'] === 'gol' || $z['typ'] === 'gol_rywala') {
            if ($z['typ'] === 'gol') {
                $my++;
            } else {
                $oni++;
            }

            $lista[] = [
                'typ'    => $z['typ'],
                'minuta' => $minuta,
                'polowa' => $polowa,
                'minuta_opis' => $opis_minuty,
                'kto'    => $z['typ'] === 'gol' ? $kto($z['zawodnik_id']) : null,
                'asysta' => $z['typ'] === 'gol' ? $kto($z['zawodnik2_id']) : null,
                'wynik'  => ['my' => $my, 'oni' => $oni],
            ];

            continue;
        }

        if (in_array($z['typ'], KARTKI, true)) {
            $lista[] = [
                'typ'    => $z['typ'],
                'minuta' => $minuta,
                'polowa' => $polowa,
                'minuta_opis' => $opis_minuty,
                'kto'    => $kto($z['zawodnik_id']),
            ];

            continue;
        }

        if (in_array($z['typ'], KARTKI_RYWALA, true)) {
            $lista[] = [
                'typ'    => $z['typ'],
                'minuta' => $minuta,
                'polowa' => $polowa,
                'minuta_opis' => $opis_minuty,
                /* Zamiast nazwiska sam numer — tyle o cudzej kadrze wiemy. */
                'numer'  => isset($z['numer_rywala']) && $z['numer_rywala'] !== null
                                ? (int) $z['numer_rywala']
                                : null,
            ];

            continue;
        }

        $lista[] = [
            'typ'     => 'zmiana',
            'minuta'  => $minuta,
            'polowa'  => $polowa,
            'minuta_opis' => $opis_minuty,
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
        /* Która połowa leci — bez tego strona nie wie, czy 47 minuta to
           „45+2", czy początek drugiej połowy. */
        'polowa'         => polowa_skladu($sklad),
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

/**
 * Których plików dla strony jeszcze nie ma.
 *
 * Pliki powstają przy zapisie w panelu, więc po wdrożeniu, które dokłada
 * nowy plik (tak było ze składami rozegranych meczów), strona pyta o coś,
 * czego nikt jeszcze nie zapisał — i dostaje 404 aż do pierwszej akcji
 * trenera albo nocnego crona. Stąd lista braków: panel uzupełnia je sam.
 */
function brakujace_pliki_strony(): array
{
    $braki = [];

    foreach ([plik_przebiegu(), plik_wynikow(), plik_skladow()] as $plik) {
        if (!is_file($plik)) {
            $braki[] = basename($plik);
        }
    }

    return $braki;
}

/**
 * Przepisuje na stronę wszystko, co panel ma do powiedzenia.
 *
 * Skład najbliższego meczu, przebieg, wyniki i składy spotkań rozegranych —
 * ten sam komplet, który odświeża nocny cron.
 */
function odswiez_pliki_strony(PDO $db): void
{
    require_once __DIR__ . '/sklad.php';

    opublikuj_sklad_biezacy($db);
    opublikuj_przebieg($db);
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
 * Spotkania, które panel ma zapisane — od najnowszego.
 *
 * Źródłem są składy, a nie terminarz: ten pokazuje wyłącznie mecze
 * nadchodzące, a przebieg trzeba móc poprawić także tydzień po meczu.
 */
function mecze_z_przebiegiem(PDO $db): array
{
    if (!tabela_istnieje('sklady') || !kolumna_istnieje('sklady', 'mecz_klucz')) {
        return [];
    }

    $ile = kolumna_istnieje('zdarzenia', 'mecz_klucz')
        ? '(SELECT COUNT(*) FROM zdarzenia z WHERE z.mecz_klucz = s.mecz_klucz)'
        : '0';

    return $db->query(
        "SELECT s.mecz_klucz, s.mecz_opis, s.mecz_data, s.nazwa, s.stan,
                $ile AS zdarzen
           FROM sklady s
          WHERE s.mecz_klucz IS NOT NULL
          ORDER BY s.mecz_data IS NULL, s.mecz_data DESC, s.id DESC"
    )->fetchAll();
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
