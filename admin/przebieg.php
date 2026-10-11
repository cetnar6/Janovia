<?php
/**
 * Panel trenera: przebieg meczu — tablica wyniku, boisko i zmiany na żywo.
 *
 * Ekran obsługuje się jedną ręką, na telefonie, przy linii bocznej. Stąd
 * brak kroku „zapisz całość": każde zdarzenie od razu ląduje w bazie i w
 * pliku, z którego czyta strona klubu. Trener, który dopisuje gola w 89
 * minucie, nie ma już czasu szukać przycisku publikacji.
 *
 * Boisko jest to samo, co na ekranie składu — ten sam skrypt, te same
 * kafelki — tylko znaczy co innego: pokazuje, kto gra TERAZ, a przeciągnięcie
 * rezerwowego na grającego zapisuje zmianę zamiast przestawiać ustawienie.
 * Skład bierzemy z ekranu składu, bo to ten sam mecz.
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/formacje.php';
require_once __DIR__ . '/inc/migracje.php';
require_once __DIR__ . '/inc/przebieg.php';
require_once __DIR__ . '/inc/zdjecia.php';

wymagaj_dostepu_do_przebiegu();

$db = baza();

$gotowa = tabela_istnieje('sklady')
       && kolumna_istnieje('sklady', 'stan')
       && tabela_istnieje('zdarzenia');

/* ---------- stan wejściowy ---------- */

/* Domyślnie prowadzi się przebieg najbliższego spotkania, ale wpis z
   wcześniejszej kolejki też musi dać się poprawić — pomyłka w minucie
   albo zapomniana kartka wychodzą zwykle po meczu. Stąd wybór z listy
   po lewej, a nie sztywne „tylko bieżący". */
$mecz_biezacy = $gotowa ? mecz_biezacy() : null;

$archiwum = $gotowa ? mecze_z_przebiegiem($db) : [];
$wybrany_klucz = null;

if ($gotowa && isset($_GET['mecz'])) {
    $szukany = (string) $_GET['mecz'];

    foreach ($archiwum as $m) {
        if ((string) $m['mecz_klucz'] === $szukany) {
            $wybrany_klucz = $szukany;
            break;
        }
    }
}

$klucz_meczu = $wybrany_klucz ?? ($mecz_biezacy['klucz'] ?? null);

/* Dokąd wracają wszystkie zapisy — razem z wybranym meczem, żeby panel
   nie przeskakiwał na bieżące spotkanie po każdym kliknięciu. */
$powrot = 'przebieg.php'
        . ($wybrany_klucz !== null ? '?mecz=' . rawurlencode($wybrany_klucz) : '');

/* Czy oglądamy spotkanie inne niż to, które panel prowadzi na żywo. */
$archiwalny = $wybrany_klucz !== null
           && $wybrany_klucz !== ($mecz_biezacy['klucz'] ?? null);

$sklad = $gotowa
    ? ($wybrany_klucz !== null ? sklad_meczu($db, $wybrany_klucz) : sklad_biezacy($db))
    : null;

$xi = $sklad ? id_ze_skladu($sklad['ustawienie']) : [];
$lawka = [];

foreach ($sklad ? id_ze_skladu($sklad['rezerwowi'] ?? '[]') : [] as $id) {
    if (!in_array($id, $xi, true)) {
        $lawka[] = $id;
    }
}

$kadra = array_merge($xi, $lawka);

/* ---------- zapis ---------- */

/* Odświeżenie plików nie potrzebuje ani składu, ani trwającego meczu —
   dlatego stoi przed bramką na $sklad niżej. */
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && (string) ($_POST['akcja'] ?? '') === 'odswiez' && $gotowa) {
    sprawdz_csrf();

    odswiez_pliki_strony($db);
    komunikat('Strona klubu dostała świeże dane z panelu.');
    przekieruj($powrot);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $sklad) {
    sprawdz_csrf();

    $akcja = (string) ($_POST['akcja'] ?? '');

    /* Minuta i zawodnicy z formularza przechodzą przez to samo sito co skład:
       dalej idą wyłącznie wartości z list, które panel sam wystawił. */
    $minuta = filter_var($_POST['minuta'] ?? null, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 0, 'max_range' => MAKS_MINUTA],
    ]);

    /* Połowa idzie osobnym zapisem, bo kolumna może jeszcze nie istnieć:
       na hostingu migracje uruchamia administrator ręcznie, a panel ma
       działać także zanim to zrobi. */
    $ustaw_polowe = static function (PDO $db, int $id, int $polowa): void {
        if (kolumna_istnieje('sklady', 'polowa')) {
            $db->prepare('UPDATE sklady SET polowa = ? WHERE id = ?')->execute([$polowa, $id]);
        }
    };

    /* Z której połowy jest wpisywane teraz zdarzenie. */
    $polowa_teraz = polowa_skladu($sklad);

    $z_listy = static function ($wartosc, array $dozwolone): ?int {
        $id = filter_var($wartosc, FILTER_VALIDATE_INT);

        return $id !== false && in_array($id, $dozwolone, true) ? $id : null;
    };

    $zdarzenia = zdarzenia_meczu($db, $klucz_meczu);
    [$na_boisku, $wolni] = po_zmianach($xi, $lawka, $zdarzenia);

    if ($akcja === 'stan') {
        $nowy = (string) ($_POST['stan'] ?? '');
        $stan_przed = (string) ($sklad['stan'] ?? 'przed');

        if (!in_array($nowy, STANY_MECZU, true)) {
            komunikat('Nieznany stan meczu.', 'blad');
            przekieruj($powrot);
        }

        /* Godzina pierwszego gwizdka służy tylko do podpowiadania minuty
           przy kolejnych wpisach. Zapisujemy ją przy każdym przejściu
           w „trwa", bo wznowienie po omyłkowym zakończeniu meczu też
           ma dawać sensowną podpowiedź. */
        $db->prepare('UPDATE sklady SET stan = ?, start_meczu = ?, przerwa_od = ? WHERE id = ?')
           ->execute([
               $nowy,
               $nowy === 'trwa' ? date('Y-m-d H:i:s') : ($sklad['start_meczu'] ?? null),
               /* Rozpoczęcie i wznowienie meczu kasują zatrzymanie stopera —
                  bez tego zegar nowej połowy stałby w miejscu po przerwie,
                  z której nikt go nie wznowił. */
               $nowy === 'trwa' ? null : ($sklad['przerwa_od'] ?? null),
               $sklad['id'],
           ]);

        /* Pierwszy gwizdek i cofnięcie meczu wracają do pierwszej połowy.
           Wznowienie omyłkowo zakończonego meczu — nie: wtedy gra się dalej
           tam, gdzie mecz stanął. */
        if ($nowy === 'przed' || ($nowy === 'trwa' && $stan_przed === 'przed')) {
            $ustaw_polowe($db, (int) $sklad['id'], 1);
        }

        opublikuj_przebieg($db);

        komunikat([
            'przed'  => 'Mecz cofnięty do stanu przed pierwszym gwizdkiem.',
            'trwa'   => 'Mecz rozpoczęty — szczegóły są już widoczne na stronie.',
            'koniec' => 'Mecz zakończony. Przebieg zostaje na stronie jako podsumowanie.',
        ][$nowy]);

        przekieruj($powrot);
    }

    /* Przerwa zatrzymuje zegar, nie mecz: stan zostaje „trwa", bo spotkanie
       się nie skończyło, a kibic ma widzieć wynik i zamrożony czas. */
    if ($akcja === 'przerwa' || $akcja === 'wznow') {
        $wiersz = sklad_biezacy($db);

        if (!$wiersz || $wiersz['stan'] !== 'trwa') {
            komunikat('Stoper można zatrzymać tylko w trakcie meczu.', 'blad');
            przekieruj($powrot);
        }

        if ($akcja === 'przerwa') {
            if (!$wiersz['przerwa_od']) {
                $db->prepare('UPDATE sklady SET przerwa_od = ? WHERE id = ?')
                   ->execute([date('Y-m-d H:i:s'), $wiersz['id']]);
            }

            opublikuj_przebieg($db);
            komunikat('Przerwa — stoper stoi. Wznów go po drugim gwizdku.');
            przekieruj($powrot);
        }

        /* Wznowienie przesuwa pierwszy gwizdek tak, żeby zegar ruszył
           z właściwego miejsca — dalej jedną różnicą (teraz minus start),
           a nie sumą kawałków, których po każdej przerwie trzeba by pilnować.

           Po przerwie między połowami zegar wraca na równe 45:00, bo tak
           liczy się druga połowa: doliczony czas pierwszej nie przechodzi
           na drugą. Przerwa wzięta wcześniej (kontuzja, przerwana gra) to
           co innego — wtedy gra się dalej od miejsca, w którym stanęło. */
        if ($wiersz['przerwa_od']) {
            $stanelo = (int) strtotime((string) $wiersz['przerwa_od'])
                     - (int) strtotime((string) $wiersz['start_meczu']);

            $polowa = $stanelo >= PRZERWA_OD_MINUTY * 60;
            $odliczyc = $polowa ? 45 * 60 : $stanelo;

            $db->prepare('UPDATE sklady SET start_meczu = ?, przerwa_od = NULL WHERE id = ?')
               ->execute([date('Y-m-d H:i:s', time() - max(0, $odliczyc)), $wiersz['id']]);

            /* Dopiero przerwa między połowami zmienia połowę — krótka przerwa
               na kontuzję zostawia tę samą. */
            if ($polowa) {
                $ustaw_polowe($db, (int) $wiersz['id'], 2);
            }

            opublikuj_przebieg($db);

            komunikat($polowa
                ? 'Druga połowa — stoper rusza od 45:00.'
                : 'Gramy dalej — stoper ruszył z miejsca, w którym stanął.');

            przekieruj($powrot);
        }

        opublikuj_przebieg($db);
        komunikat('Gramy dalej.');
        przekieruj($powrot);
    }

    /* Stoper liczy od zapisanego pierwszego gwizdka, więc poprawia się go
       przez podanie bieżącej minuty — tak, jak trener na nią patrzy. Godzina
       rozpoczęcia wyliczy się sama; pytanie o nią kazałoby liczyć wstecz
       komuś, kto właśnie zorientował się, że spóźnił się z uruchomieniem. */
    if ($akcja === 'stoper') {
        $biezaca = filter_var($_POST['minuta'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => MAKS_MINUTA],
        ]);

        if ($biezaca === false || $biezaca === null) {
            komunikat('Podaj minutę meczu — liczbę od 0 do ' . MAKS_MINUTA . '.', 'blad');
            przekieruj($powrot);
        }

        /* Minus jedna minuta, bo w chwili pierwszego gwizdka gra się „pierwszą
           minutę", a nie zerową: bez tego wpisanie 81 ustawiałoby stoper na 82.
           Przy zatrzymanym zegarze odliczamy od chwili przerwy, nie od teraz —
           inaczej poprawka ruszyłaby stoper, który ma stać. */
        $odniesienie = $sklad['przerwa_od']
            ? (int) strtotime((string) $sklad['przerwa_od'])
            : time();

        $db->prepare('UPDATE sklady SET start_meczu = ? WHERE id = ?')
           ->execute([date('Y-m-d H:i:s', $odniesienie - max(0, $biezaca - 1) * 60), $sklad['id']]);

        opublikuj_przebieg($db);

        komunikat('Stoper przestawiony — mecz jest w ' . $biezaca . ' minucie.');
        przekieruj($powrot);
    }

    if ($akcja === 'nowy') {
        /* Przed kolejnym meczem przebieg trzeba wyzerować, bo tabela zna
           tylko jedno spotkanie — to samo założenie, co przy składzie. */
        if (kolumna_istnieje('zdarzenia', 'mecz_klucz') && $klucz_meczu !== null) {
            $db->prepare('DELETE FROM zdarzenia WHERE mecz_klucz = ?')->execute([$klucz_meczu]);
        } else {
            $db->exec('DELETE FROM zdarzenia');
        }
        /* Także przerwa: zostawiona z poprzedniego meczu zamroziłaby stoper
           następnego, zanim ktokolwiek zdążyłby zauważyć, skąd to się wzięło. */
        $db->prepare("UPDATE sklady SET stan = 'przed', start_meczu = NULL, przerwa_od = NULL
                       WHERE id = ?")
           ->execute([$sklad['id']]);
        $ustaw_polowe($db, (int) $sklad['id'], 1);
        opublikuj_przebieg($db);

        komunikat('Przebieg wyczyszczony — można wpisywać następny mecz.');
        przekieruj($powrot);
    }

    if ($akcja === 'usun') {
        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);

        if ($id) {
            $db->prepare('DELETE FROM zdarzenia WHERE id = ?')->execute([$id]);

            opublikuj_przebieg($db);
            komunikat('Zdarzenie usunięte.');
        }

        przekieruj($powrot);
    }

    if ($minuta === false || $minuta === null) {
        komunikat('Podaj minutę meczu — liczbę od 0 do ' . MAKS_MINUTA . '.', 'blad');
        przekieruj($powrot);
    }

    if ($akcja === 'gol') {
        $strzelec = $z_listy($_POST['strzelec'] ?? null, $kadra);
        $asysta = $z_listy($_POST['asysta'] ?? null, $kadra);

        if ($strzelec === null) {
            komunikat('Wybierz strzelca z listy.', 'blad');
            przekieruj($powrot);
        }

        // sam sobie nie asystuje — to zawsze pomyłka w wyborze
        if ($asysta === $strzelec) {
            $asysta = null;
        }

        dopisz_zdarzenie($db, $klucz_meczu, 'gol', $minuta, $strzelec, $asysta, $polowa_teraz);

        opublikuj_przebieg($db);
        komunikat('Gol zapisany — ' . $minuta . ' minuta.');
        przekieruj($powrot);
    }

    if ($akcja === 'kartka') {
        $ukarany = $z_listy($_POST['zawodnik'] ?? null, $kadra);
        $kolor = (string) ($_POST['kolor'] ?? '');
        $typ = $kolor === 'czerwona' ? 'kartka_czerwona' : 'kartka_zolta';

        if ($ukarany === null) {
            komunikat('Wybierz zawodnika z listy.', 'blad');
            przekieruj($powrot);
        }

        /* Czerwona nie zdejmuje nikogo z boiska: skład w panelu pokazuje, kogo
           trener ustawił, a nie kto akurat gra w dziesiątkę. Zmiana po
           czerwonej i tak przechodzi normalną drogą. */
        dopisz_zdarzenie($db, $klucz_meczu, $typ, $minuta, $ukarany, null, $polowa_teraz);

        opublikuj_przebieg($db);
        komunikat(($typ === 'kartka_czerwona' ? 'Czerwona' : 'Żółta')
                . ' kartka zapisana — ' . $minuta . ' minuta.');
        przekieruj($powrot);
    }

    if ($akcja === 'gol_rywala') {
        dopisz_zdarzenie($db, $klucz_meczu, 'gol_rywala', $minuta, null, null, $polowa_teraz);

        opublikuj_przebieg($db);
        komunikat('Gol rywala zapisany — ' . $minuta . ' minuta.', 'blad');
        przekieruj($powrot);
    }

    if ($akcja === 'zmiana') {
        $schodzi = $z_listy($_POST['schodzi'] ?? null, $na_boisku);
        $wchodzi = $z_listy($_POST['wchodzi'] ?? null, $wolni);

        if ($schodzi === null || $wchodzi === null) {
            komunikat('Zmiana wymaga zawodnika z boiska i zawodnika z ławki.', 'blad');
            przekieruj($powrot);
        }

        dopisz_zdarzenie($db, $klucz_meczu, 'zmiana', $minuta, $wchodzi, $schodzi, $polowa_teraz);

        opublikuj_przebieg($db);
        komunikat('Zmiana zapisana — ' . $minuta . ' minuta.');
        przekieruj($powrot);
    }

    przekieruj($powrot);
}

/* Wdrożenie potrafi dołożyć nowy plik dla strony (tak przyszły składy
   rozegranych meczów). Nikt nie będzie po nim wchodził do panelu tylko po
   to, żeby kliknąć zapis, więc braki uzupełniamy przy pierwszym wejściu —
   raz, bo potem pliki już są. */
if ($gotowa && $_SERVER['REQUEST_METHOD'] === 'GET' && brakujace_pliki_strony()) {
    odswiez_pliki_strony($db);
}

/* ---------- widok ---------- */

$zdarzenia = $gotowa ? zdarzenia_meczu($db, $klucz_meczu) : [];
[$na_boisku, $wolni] = po_zmianach($xi, $lawka, $zdarzenia);

$wg_id = zawodnicy_po_id($db, $kadra);
$stan = (string) ($sklad['stan'] ?? 'przed');

/* Minuta wpisuje się sama, gdy mecz trwa — przy linii bocznej liczy się
   każde pole, którego nie trzeba wypełniać. */
$pauza = $stan === 'trwa' ? ($sklad['przerwa_od'] ?? null) : null;
$podpowiedz = $stan === 'trwa' ? minuta_meczu($sklad['start_meczu'] ?? null, $pauza) : null;

$wynik = ['my' => 0, 'oni' => 0];

foreach ($zdarzenia as $z) {
    if ($z['typ'] === 'gol') { $wynik['my']++; }
    if ($z['typ'] === 'gol_rywala') { $wynik['oni']++; }
}

/* Przed pierwszym gwizdkiem boisko jest tylko podglądem zatwierdzonego
   składu — nie ma jeszcze czego zmieniać ani komu strzelać. */
$aktywny = $stan !== 'przed';

$formacja = $sklad && czy_znana_formacja((string) $sklad['formacja'])
    ? (string) $sklad['formacja']
    : formacja_domyslna();

/* Boisko pokazuje bieżące ustawienie: wchodzący staje na polu tego,
   kogo zmienił. */
$ustawienie = [];

if ($sklad) {
    $odczytane = json_decode((string) $sklad['ustawienie'], true);

    if (is_array($odczytane)) {
        foreach ($odczytane as $pole => $id) {
            if (in_array((int) $id, $xi, true)) {
                $ustawienie[$pole] = (int) $id;
            }
        }
    }
}

/* Przed meczem pokazujemy skład tak, jak go zatwierdzono — zdarzenia mogą
   jeszcze leżeć po poprzednim spotkaniu, dopóki trener nie wyczyści przebiegu. */
$ustawienie_biezace = $aktywny
    ? ustawienie_po_zmianach($ustawienie, $zdarzenia)
    : $ustawienie;

/* Bramki per zawodnik — stąd piłki przy awatarze na boisku i na ławce. */
$gole_zawodnikow = [];
$zmiany = [];

foreach ($zdarzenia as $z) {
    if ($z['typ'] === 'gol' && $z['zawodnik_id'] !== null) {
        $id = (int) $z['zawodnik_id'];
        $gole_zawodnikow[$id] = ($gole_zawodnikow[$id] ?? 0) + 1;
    }

    if ($z['typ'] === 'zmiana') {
        $zmiany[] = [
            'minuta'  => opis_minuty((int) $z['minuta'], polowa_zdarzenia($z)),
            'wchodzi' => (int) $z['zawodnik_id'],
            'schodzi' => (int) $z['zawodnik2_id'],
        ];
    }
}

/* Drużyny bierzemy z terminarza, nie z opisu składu: opis to wolny tekst,
   a tablica wyniku ma pokazać herby i stronę boiska zgodnie z tym, co widzi
   kibic na stronie klubu.

   Mecz rozegrany wypada już z terminarza, więc wtedy czytamy drużyny
   z klucza spotkania („kolejka|gospodarz|gość") — to ten sam zapis, który
   zrobił terminarz w dniu meczu. */
$mecz = mecz_po_kluczu($klucz_meczu) ?? mecz_biezacy();

if ((!$mecz || ($mecz['klucz'] ?? null) !== $klucz_meczu) && $klucz_meczu !== null) {
    $czesci = explode('|', $klucz_meczu, 3);

    if (count($czesci) === 3 && $czesci[1] !== '' && $czesci[2] !== '') {
        $mecz = ['gospodarz' => $czesci[1], 'gosc' => $czesci[2], 'klucz' => $klucz_meczu];
    }
}

$u_siebie = !$mecz || $mecz['gospodarz'] === NASZ_KLUB;
$rywal = $mecz ? ($u_siebie ? $mecz['gosc'] : $mecz['gospodarz']) : 'Rywal';

/** Herb klubu albo tarcza z pierwszą literą — tak samo jak na stronie. */
function kafelek_herbu(string $klub): string
{
    $src = adres_herbu($klub);

    return $src
        ? '<img class="tablica__herb" src="' . e($src) . '" alt="">'
        : '<span class="tablica__herb tablica__herb--brak">'
          . e(mb_strtoupper(mb_substr($klub, 0, 1))) . '</span>';
}

/**
 * Przycisk kartki — stoi pod plusem przy naszym herbie i działa tak samo:
 * otwiera okno, w którym wybiera się zawodnika, minutę i kolor.
 */
function przycisk_kartki(): string
{
    return '<button type="button" class="tablica__kartka" data-okno="okno-kartki"'
         . ' aria-label="Dodaj kartkę">'
         . '<svg viewBox="0 0 20 26" aria-hidden="true">'
         . '<rect class="tablica__kartka-zolta" x="1" y="4" width="13" height="18" rx="2"'
         . ' transform="rotate(-8 7.5 13)"/>'
         . '<rect class="tablica__kartka-czerwona" x="6" y="4" width="13" height="18" rx="2"'
         . ' transform="rotate(8 12.5 13)"/>'
         . '</svg></button>';
}

/** Podpis zawodnika na liście wyboru: numer robi za pewny punkt zaczepienia. */
function podpis_zawodnika(?array $z): string
{
    if (!$z) {
        return 'zawodnik spoza kadry';
    }

    return ($z['numer'] !== null ? $z['numer'] . '. ' : '') . $z['imie'] . ' ' . $z['nazwisko'];
}

/** Lista wyboru strzelca: najpierw ci, którzy w tej chwili są na boisku. */
function opcje_strzelcow(array $na_boisku, array $reszta, array $wg_id, string $pusta): string
{
    $opcja = static function ($id) use ($wg_id): string {
        return isset($wg_id[$id])
            ? '<option value="' . (int) $id . '">' . e(podpis_zawodnika($wg_id[$id])) . '</option>'
            : '';
    };

    $html = '<option value="">' . e($pusta) . '</option>';
    $html .= '<optgroup label="Na boisku">';

    foreach ($na_boisku as $id) {
        $html .= $opcja($id);
    }

    $html .= '</optgroup>';

    if ($reszta) {
        /* Zmiennik, który już zszedł, też mógł strzelić — tylko wcześniej. */
        $html .= '<optgroup label="Poza boiskiem">';

        foreach ($reszta as $id) {
            $html .= $opcja($id);
        }

        $html .= '</optgroup>';
    }

    return $html;
}

$poza_boiskiem = array_values(array_diff($kadra, $na_boisku));

naglowek('Przebieg meczu');
?>

<div class="przebieg__ekran">

<?php if ($archiwum): ?>
    <!-- Spotkania, które panel ma zapisane. Domyślnie prowadzimy najbliższe,
         ale poprawka w rozegranym meczu musi być o jedno kliknięcie stąd. -->
    <aside class="kolejki" aria-label="Mecze zapisane w panelu">
        <h2 class="kolejki__tytul">Mecze</h2>

        <ul class="kolejki__lista">
            <?php foreach ($archiwum as $m):
                $tu = (string) $m['mecz_klucz'] === (string) $klucz_meczu;
                $to_biezacy = (string) $m['mecz_klucz'] === (string) ($mecz_biezacy['klucz'] ?? '');
            ?>
                <li>
                    <a class="kolejka<?= $tu ? ' is-wybrana' : '' ?>"
                       href="przebieg.php?mecz=<?= rawurlencode((string) $m['mecz_klucz']) ?>"
                       <?= $tu ? 'aria-current="page"' : '' ?>>
                        <span class="kolejka__kiedy">
                            <?= e($m['mecz_data']
                                    ? date('d.m', (int) strtotime((string) $m['mecz_data']))
                                    : '—') ?>
                            <?php if ($to_biezacy): ?>
                                <b class="kolejka__najblizszy">najbliższy</b>
                            <?php endif; ?>
                        </span>

                        <span class="kolejka__kto">
                            <?= e((string) ($m['mecz_opis'] ?: $m['nazwa'] ?: $m['mecz_klucz'])) ?>
                        </span>

                        <span class="kolejka__sklad<?= (int) $m['zdarzen'] ? ' is-gotowy' : '' ?>">
                            <?= ['przed' => 'przed meczem', 'trwa' => 'trwa', 'koniec' => 'po meczu']
                                [(string) $m['stan']] ?? e((string) $m['stan']) ?>
                            · <?= (int) $m['zdarzen'] ?> zdarz.
                        </span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </aside>
<?php endif; ?>

<div class="przebieg__tresc">

<div class="naglowek-sekcji">
    <h1>Przebieg meczu</h1>

    <span class="przebieg__stan przebieg__stan--<?= $pauza ? 'przerwa' : e($stan) ?>">
        <?= $pauza
            ? 'Przerwa'
            : ['przed' => 'Przed meczem', 'trwa' => 'Mecz trwa', 'koniec' => 'Po meczu'][$stan] ?>
        <?php if ($stan === 'trwa' && $podpowiedz !== null): ?>
            <!-- skrypt dolicza sekundy sam; serwerowa wartość jest tu po to,
                 żeby stoper nie startował od pustego miejsca -->
            · <b class="stoper"><?= (int) $podpowiedz ?>:00</b>
        <?php endif; ?>
    </span>
</div>

<?php if (!$gotowa): ?>
    <section class="karta">
        <h2>Baza nie ma jeszcze gdzie zapisać przebiegu</h2>
        <p>
            Brakuje tabeli <code>zdarzenia</code> albo kolumny ze stanem meczu.
            Administrator musi uruchomić aktualizację bazy w zakładce Migracje.
        </p>
        <?php if (czy_administrator()): ?>
            <a class="btn" href="migracje.php">Przejdź do migracji</a>
        <?php endif; ?>
    </section>
<?php elseif (!$sklad): ?>
    <section class="karta">
        <h2>Najpierw skład</h2>
        <p>
            Gole i zmiany wybiera się z zawodników ustawionych na ten mecz,
            więc bez zapisanego składu nie ma z czego wybierać.
        </p>
        <a class="btn" href="sklad.php">Przejdź do składu</a>

        <?php /* Także tutaj, bo odświeżenie dotyczy całej strony, nie tego
                 jednego meczu — a bez składu na najbliższe spotkanie ekran
                 kończy się w tym miejscu. */ ?>
        <form method="post" class="przebieg__odswiez">
            <?= pole_csrf() ?>
            <input type="hidden" name="akcja" value="odswiez">
            <button class="btn btn--pusty" type="submit">Odśwież dane na stronie</button>
        </form>
    </section>
<?php else: ?>

<?php if ($archiwalny): ?>
    <p class="przebieg__archiwum">
        Poprawiasz mecz <b><?= e((string) ($sklad['mecz_opis'] ?: $sklad['mecz_klucz'])) ?></b>,
        a nie ten najbliższy. Każdy zapis od razu idzie na stronę.
        <a href="przebieg.php">Wróć do bieżącego meczu</a>
    </p>
<?php endif; ?>

<p class="drobne">
    <?= $sklad['nazwa'] !== '' ? e((string) $sklad['nazwa']) . ' · ' : '' ?>
    ustawienie <?= e($formacja) ?>
    <?php if ($stan === 'trwa'): ?>
        · każdy zapis od razu widać na stronie klubu
    <?php endif; ?>
</p>

<!-- Tablica wyniku. Plus pod herbem zamiast pola „kto strzelił" w formularzu:
     bramka pada raz na pół godziny, ale wtedy liczy się, żeby zapis był jednym
     ruchem kciuka — reszta dzieje się w okienku. -->
<section class="tablica" aria-label="Wynik meczu">
    <div class="tablica__strona">
        <?= kafelek_herbu($u_siebie ? NASZ_KLUB : $rywal) ?>
        <b><?= e($u_siebie ? 'Janovia' : $rywal) ?></b>
        <?php if ($aktywny): ?>
            <button type="button" class="tablica__plus"
                    data-gol="<?= $u_siebie ? 'my' : 'oni' ?>"
                    aria-label="Dodaj gola: <?= e($u_siebie ? 'Janovia' : $rywal) ?>">+</button>

            <?php /* Kartka tylko pod naszym herbem: panel zna wyłącznie własną
                     kadrę, więc ukaranego rywala nie miałby jak nazwać. */ ?>
            <?php if ($u_siebie): ?>
                <?= przycisk_kartki() ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <div class="tablica__srodek">
        <?php if ($aktywny): ?>
            <span class="tablica__stan<?= $stan === 'trwa' && !$pauza ? ' tablica__stan--zywo' : '' ?>">
                <?php if ($stan === 'trwa'): ?>
                    <?= $pauza ? 'Przerwa' : 'Na żywo' ?> ·
                    <b class="stoper"><?= (int) $podpowiedz ?>:00</b>
                <?php else: ?>
                    Po meczu
                <?php endif; ?>
            </span>
        <?php endif; ?>

        <p class="tablica__wynik">
            <span><?= $u_siebie ? $wynik['my'] : $wynik['oni'] ?></span>
            <i>:</i>
            <span><?= $u_siebie ? $wynik['oni'] : $wynik['my'] ?></span>
        </p>
    </div>

    <div class="tablica__strona">
        <?= kafelek_herbu($u_siebie ? $rywal : NASZ_KLUB) ?>
        <b><?= e($u_siebie ? $rywal : 'Janovia') ?></b>
        <?php if ($aktywny): ?>
            <button type="button" class="tablica__plus"
                    data-gol="<?= $u_siebie ? 'oni' : 'my' ?>"
                    aria-label="Dodaj gola: <?= e($u_siebie ? $rywal : 'Janovia') ?>">+</button>

            <?php if (!$u_siebie): ?>
                <?= przycisk_kartki() ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<section class="karta przebieg__sterowanie">
    <form method="post">
        <?= pole_csrf() ?>
        <input type="hidden" name="akcja" value="stan">

        <?php if ($stan !== 'trwa'): ?>
            <button class="btn" type="submit" name="stan" value="trwa">
                <?= $stan === 'koniec' ? 'Wznów mecz' : 'Rozpocznij mecz' ?>
            </button>
        <?php endif; ?>

        <?php if ($stan === 'trwa'): ?>
            <button class="btn btn--pusty" type="submit" name="stan" value="koniec">Zakończ mecz</button>
        <?php endif; ?>
    </form>

    <?php if ($stan === 'trwa'): ?>
        <form method="post">
            <?= pole_csrf() ?>
            <input type="hidden" name="akcja" value="<?= $pauza ? 'wznow' : 'przerwa' ?>">
            <button class="btn btn--pusty" type="submit" id="przycisk-przerwy">
                <?= $pauza ? 'Wznów po przerwie' : 'Przerwa — zatrzymaj stoper' ?>
            </button>
        </form>
    <?php endif; ?>

    <form method="post">
        <?= pole_csrf() ?>
        <input type="hidden" name="akcja" value="odswiez">
        <button class="btn btn--pusty" type="submit">Odśwież dane na stronie</button>
    </form>

    <form method="post" onsubmit="return confirm('Skasować wszystkie gole i zmiany tego meczu?');">
        <?= pole_csrf() ?>
        <input type="hidden" name="akcja" value="nowy">
        <button class="btn btn--kasuj" type="submit">Nowy mecz — wyczyść przebieg</button>
    </form>

    <?php if ($stan === 'trwa'): ?>
        <form method="post" class="stoper-poprawka">
            <?= pole_csrf() ?>
            <input type="hidden" name="akcja" value="stoper">

            <label for="stoper-minuta">Która minuta?</label>
            <input type="number" id="stoper-minuta" name="minuta" required
                   min="0" max="<?= MAKS_MINUTA ?>" inputmode="numeric"
                   value="<?= (int) $podpowiedz ?>">

            <button class="btn btn--pusty btn--maly" type="submit">Przestaw stoper</button>
        </form>
    <?php endif; ?>

    <span class="przebieg__uwaga">
        <?php if ($stan === 'przed'): ?>
            Boisko pokazuje zatwierdzony skład. Gole i zmiany zapiszesz,
            gdy rozpoczniesz mecz — kibice widzą do tego czasu samą zapowiedź.
        <?php elseif ($stan === 'trwa'): ?>
            Strona odświeża szczegóły sama, mniej więcej co minutę.
        <?php else: ?>
            Przebieg zostaje na stronie jako podsumowanie, aż wyczyścisz go przed kolejnym meczem.
        <?php endif; ?>
    </span>
</section>

<div class="sklad__plansza">
    <section class="boisko" id="boisko" aria-label="Boisko">
        <div class="boisko__linie" aria-hidden="true">
            <span class="boisko__srodek"></span>
            <span class="boisko__kolo"></span>
            <span class="boisko__pole boisko__pole--dol"></span>
            <span class="boisko__pole boisko__pole--gora"></span>
            <span class="boisko__bramka boisko__bramka--dol"></span>
            <span class="boisko__bramka boisko__bramka--gora"></span>
        </div>
        <!-- pola wstawia sklad.js na podstawie zapisanej formacji -->
    </section>

    <section class="lawka<?= $aktywny ? ' lawka--mecz' : '' ?>" aria-label="Ławka rezerwowych">
        <header class="lawka__head">
            <h2>Na ławce</h2>
            <span class="lawka__licznik" id="licznik">0/11</span>
        </header>

        <p class="lawka__podpowiedz">
            <?php if ($aktywny): ?>
                Przeciągnij rezerwowego na zawodnika z boiska — to zapisze zmianę.
                Na telefonie dotknij rezerwowego, a potem tego, kto schodzi.
                Minutę panel podpowie sam, możesz ją poprawić.
            <?php else: ?>
                Zmiany ruszą po rozpoczęciu meczu. Do tego czasu to podgląd
                zatwierdzonego składu — zmienisz go w zakładce „Skład".
            <?php endif; ?>
        </p>

        <div class="lawka__lista" id="lawka"></div>
    </section>
</div>

<!-- Pas pod planszą: zmiany zrobione w tym meczu, w kolejności minut. -->
<section class="rezerwa rezerwa--mecz" id="rezerwa" aria-label="Zmiany w tym meczu">
    <header class="rezerwa__head">
        <h2>Zmiany w tym meczu</h2>
        <span class="rezerwa__licznik" id="licznik-rezerw">0 zmian</span>
    </header>

    <div class="rezerwa__lista" id="rezerwa-lista"></div>
</section>

<section class="karta">
    <h2>Zapisane zdarzenia</h2>

    <?php if (!$zdarzenia): ?>
        <p class="przebieg__podpowiedz">
            Jeszcze nic się nie wydarzyło. Pierwszy wpis pojawi się tutaj
            i w tej samej chwili na stronie klubu.
        </p>
    <?php else: ?>
        <ol class="zdarzenia">
            <?php foreach ($zdarzenia as $z):
                $minuta = opis_minuty((int) $z['minuta'], polowa_zdarzenia($z));
                $kto = $wg_id[(int) $z['zawodnik_id']] ?? null;
                $kto2 = $wg_id[(int) $z['zawodnik2_id']] ?? null;
            ?>
                <li class="zdarzenie zdarzenie--<?= e((string) $z['typ']) ?>">
                    <span class="zdarzenie__minuta"><?= e($minuta) ?>'</span>

                    <span class="zdarzenie__tresc">
                        <?php if ($z['typ'] === 'gol'): ?>
                            <strong>Gol</strong> — <?= e(podpis_zawodnika($kto)) ?>
                            <?php if ($kto2): ?>
                                <small>asysta: <?= e(podpis_zawodnika($kto2)) ?></small>
                            <?php endif; ?>
                        <?php elseif ($z['typ'] === 'gol_rywala'): ?>
                            <strong>Gol rywala</strong>
                        <?php elseif ($z['typ'] === 'kartka_zolta'): ?>
                            <strong>Żółta kartka</strong> — <?= e(podpis_zawodnika($kto)) ?>
                        <?php elseif ($z['typ'] === 'kartka_czerwona'): ?>
                            <strong>Czerwona kartka</strong> — <?= e(podpis_zawodnika($kto)) ?>
                        <?php else: ?>
                            <strong>Zmiana</strong>
                            <small>
                                wchodzi <?= e(podpis_zawodnika($kto)) ?> ·
                                schodzi <?= e(podpis_zawodnika($kto2)) ?>
                            </small>
                        <?php endif; ?>
                    </span>

                    <form method="post">
                        <?= pole_csrf() ?>
                        <input type="hidden" name="akcja" value="usun">
                        <input type="hidden" name="id" value="<?= (int) $z['id'] ?>">
                        <button class="btn btn--kasuj btn--maly" type="submit">Usuń</button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>
</section>

<?php if ($aktywny): ?>
    <!-- Okna: trzy rzeczy, których sam gest nie powie — kto strzelił,
         w której minucie i czy to na pewno ta zmiana. Każde z własnym
         formularzem, żeby zapis niósł wyłącznie własne pola. -->
    <div class="okno" id="okno-gol" hidden>
        <form class="okno__karta" method="post">
            <?= pole_csrf() ?>
            <input type="hidden" name="akcja" value="gol">

            <h2>Gol — Janovia</h2>

            <label>
                Strzelec
                <select name="strzelec" required>
                    <?= opcje_strzelcow($na_boisku, $poza_boiskiem, $wg_id, 'wybierz zawodnika') ?>
                </select>
            </label>

            <label>
                Asysta <small>nieobowiązkowa</small>
                <select name="asysta">
                    <?= opcje_strzelcow($na_boisku, $poza_boiskiem, $wg_id, 'bez asysty') ?>
                </select>
            </label>

            <label>
                Minuta
                <input type="number" name="minuta" required
                       min="0" max="<?= MAKS_MINUTA ?>" inputmode="numeric">
            </label>

            <div class="okno__przyciski">
                <button class="btn" type="submit">Zapisz gola</button>
                <button class="btn btn--pusty okno__anuluj" type="button">Anuluj</button>
            </div>
        </form>
    </div>

    <div class="okno" id="okno-kartki" hidden>
        <form class="okno__karta" method="post">
            <?= pole_csrf() ?>
            <input type="hidden" name="akcja" value="kartka">

            <h2>Kartka</h2>

            <label>
                Zawodnik
                <select name="zawodnik" required>
                    <?= opcje_strzelcow($na_boisku, $poza_boiskiem, $wg_id, 'wybierz zawodnika') ?>
                </select>
            </label>

            <label>
                Minuta
                <input type="number" name="minuta" required
                       min="0" max="<?= MAKS_MINUTA ?>" inputmode="numeric">
            </label>

            <?php /* Kolor wybiera przycisk, nie osobne pole: i tak trzeba
                     kliknąć „zapisz", więc jedno kliknięcie wystarczy. */ ?>
            <div class="okno__przyciski">
                <button class="btn btn--zolta" type="submit" name="kolor" value="zolta">
                    Żółta
                </button>
                <button class="btn btn--czerwona" type="submit" name="kolor" value="czerwona">
                    Czerwona
                </button>
                <button class="btn btn--pusty okno__anuluj" type="button">Anuluj</button>
            </div>
        </form>
    </div>

    <div class="okno" id="okno-gol-rywala" hidden>
        <form class="okno__karta" method="post">
            <?= pole_csrf() ?>
            <input type="hidden" name="akcja" value="gol_rywala">

            <h2>Gol — <?= e($rywal) ?></h2>

            <p class="okno__kto">
                Strzelca rywala nie zapisujemy — panel zna tylko własną kadrę,
                a zmyślone nazwisko byłoby gorsze niż jego brak.
            </p>

            <label>
                Minuta
                <input type="number" name="minuta" required
                       min="0" max="<?= MAKS_MINUTA ?>" inputmode="numeric">
            </label>

            <div class="okno__przyciski">
                <button class="btn" type="submit">Zapisz gola rywala</button>
                <button class="btn btn--pusty okno__anuluj" type="button">Anuluj</button>
            </div>
        </form>
    </div>

    <?php if ($stan === 'trwa'): ?>
        <!-- Przypomnienie o przerwie. Nie zatrzymuje nic samo z siebie:
             pierwsza połowa kończy się wtedy, kiedy gwizdnie sędzia, a nie
             kiedy zegar w panelu dobije do równej liczby. -->
        <div class="okno" id="okno-przerwa" hidden>
            <form class="okno__karta" method="post">
                <?= pole_csrf() ?>
                <input type="hidden" name="akcja" value="przerwa">

                <h2>Przerwa?</h2>

                <p class="okno__kto">
                    Mija <b class="stoper">45:00</b> — jeśli sędzia zszedł z boiska,
                    zatrzymaj stoper, a po drugim gwizdku wznów go jednym przyciskiem.
                </p>

                <div class="okno__przyciski">
                    <button class="btn" type="submit">Zatrzymaj stoper</button>
                    <button class="btn btn--pusty okno__anuluj" type="button">Jeszcze gramy</button>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <div class="okno" id="okno-zmiany" hidden>
        <form class="okno__karta" method="post">
            <?= pole_csrf() ?>
            <input type="hidden" name="akcja" value="zmiana">
            <input type="hidden" name="wchodzi" id="zmiana-wchodzi">
            <input type="hidden" name="schodzi" id="zmiana-schodzi">

            <h2>Zmiana</h2>
            <p class="okno__kto" id="zmiana-opis"></p>

            <label>
                Minuta
                <input type="number" name="minuta" required
                       min="0" max="<?= MAKS_MINUTA ?>" inputmode="numeric">
            </label>

            <div class="okno__przyciski">
                <button class="btn" type="submit">Zapisz zmianę</button>
                <button class="btn btn--pusty okno__anuluj" type="button">Anuluj</button>
            </div>
        </form>
    </div>
<?php endif; ?>

<!-- Pasek wyboru: na telefonie jedyny ślad po tym, kogo właśnie wskazano,
     gdy ławka zjedzie poza ekran. Na biurku jest zbędny — chowa go CSS. -->
<div class="pasek-wyboru" id="pasek-wyboru" hidden>
    <span class="pasek-wyboru__tresc">
        <strong class="pasek-wyboru__kto"></strong>
        <small class="pasek-wyboru__co"></small>
    </span>

    <button type="button" class="pasek-wyboru__anuluj">Anuluj</button>
</div>

<script id="dane-skladu" type="application/json">
<?= json_encode([
        'formacje'   => formacje(),
        'zawodnicy'  => array_values(array_map(static function (array $z): array {
            return [
                'id'       => (int) $z['id'],
                'imie'     => $z['imie'],
                'nazwisko' => $z['nazwisko'],
                'pozycja'  => $z['pozycja'],
                'numer'    => $z['numer'] !== null ? (int) $z['numer'] : null,
                'kapitan'  => (int) ($z['kapitan'] ?? 0) === 1,
                'foto'     => ($a = adres_zdjecia($z['zdjecie'] ?? null, 'zawodnicy')) ? '../' . $a : null,
            ];
        }, $wg_id)),
        'formacja'   => $formacja,
        'ustawienie' => (object) $ustawienie_biezace,
        'rezerwowi'  => [],
        /* 'mecz' wpuszcza przeciąganie zmian, 'podglad' zostawia samo boisko. */
        'tryb'       => $aktywny ? 'mecz' : 'podglad',
        'minuta'     => $podpowiedz,
        /* Punkt odniesienia dla stopera i dla minuty podpowiadanej w oknach —
           licząc w przeglądarce, nie musimy odświeżać strony co minutę. */
        'start'      => $sklad['start_meczu'] ?? null
                            ? date('c', (int) strtotime((string) $sklad['start_meczu']))
                            : null,
        'maksMinuta' => MAKS_MINUTA,
        /* Połowa mówi stoperowi, od czego liczyć czas doliczony: 45+2 albo
           90+2 — bez niej 47 minuta w obu wypadkach wygląda tak samo. */
        'polowa'     => polowa_skladu($sklad),
        /* Czy zegar w ogóle biegnie. Przy meczu rozegranym podpowiadanie
           minuty z „czasu od pierwszego gwizdka" dawało sufit 130 — liczbę
           tym gorszą, że wygląda na wpisaną celowo. */
        'trwa'       => $stan === 'trwa',
        'pauza'      => $pauza ? date('c', (int) strtotime((string) $pauza)) : null,
        /* { idZawodnika: liczba bramek } — klucze w JSON-ie i tak są tekstem,
           a skrypt czyta je po numerze, więc object_cast jest tu konieczny. */
        'gole'       => (object) $gole_zawodnikow,
        'doZmiany'   => array_values($aktywny ? $wolni : $lawka),
        'zmiany'     => $zmiany,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
</script>
<script src="js/sklad.js?v=23"></script>

<?php endif; ?>

</div><!-- .przebieg__tresc -->
</div><!-- .przebieg__ekran -->

<?php stopka(); ?>
