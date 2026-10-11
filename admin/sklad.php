<?php
/**
 * Panel trenera: ustawienie składu na mecz.
 *
 * Dostęp mają trener i administrator. Administrator widzi dokładnie to samo —
 * nie ma sensu utrzymywać dwóch wariantów tego samego ekranu.
 *
 * Ekran działa do pierwszego gwizdka. Gdy mecz trwa, skład jest zamknięty,
 * a boisko — już z bieżącym ustawieniem, gołami i zmianami — mieszka w zakładce
 * „Przebieg meczu". Jeden ekran na jedną robotę: tu się układa, tam się gra.
 *
 * Każde spotkanie z terminarza ma własny skład. Po lewej stoi lista kolejek,
 * więc ustawienie na następny mecz można przygotować z wyprzedzeniem — na
 * stronę trafia zawsze skład najbliższego spotkania, resztę panel trzyma
 * do czasu, aż przyjdzie ich kolej.
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/formacje.php';
require_once __DIR__ . '/inc/migracje.php';
require_once __DIR__ . '/inc/przebieg.php';
require_once __DIR__ . '/inc/sklad.php';
require_once __DIR__ . '/inc/zdjecia.php';

wymagaj_dostepu_do_skladu();

/* Sufit dla ławki rezerwowych. Nie bierze się z przepisów gry — ma trzymać
   listę w rozmiarze, który da się objąć wzrokiem na telefonie, i nie pozwolić,
   żeby „rezerwowi" stali się drugą nazwą całej kadry. */
const MAKS_REZERWOWYCH = 9;

$db = baza();

/* Bez tabeli (albo bez jej nowszych kolumn) nie ma czego czytać ani zapisywać.
   Trener nie ma dostępu do migracji, więc zamiast błędu PDO dostaje zdanie,
   z którym może pójść do administratora. */
$gotowa = tabela_istnieje('sklady') && kolumna_istnieje('sklady', 'rezerwowi');

/* Przebieg meczu to osobna migracja. Bez niej ekran działa jak wcześniej —
   po prostu nigdy nie wchodzi w tryb meczowy. */
$ma_przebieg = $gotowa
    && kolumna_istnieje('sklady', 'stan')
    && tabela_istnieje('zdarzenia');

/* Przypisanie składu do spotkania też jest osobną migracją; bez niej panel
   prowadzi jeden skład, jak dotąd. */
$ma_mecze = $gotowa && kolumna_istnieje('sklady', 'mecz_klucz');

$mecze = $ma_mecze ? mecze_terminarza() : [];
$biezacy = $mecze[0] ?? null;

/* Który mecz jest teraz na ekranie: z adresu, a po zapisie z formularza.
   Nieznany klucz (mecz wypadł z terminarza) cofa do najbliższego spotkania. */
$klucz = (string) ($_POST['mecz'] ?? $_GET['mecz'] ?? '');
$wybrany = mecz_po_kluczu($klucz) ?: $biezacy;
$klucz = $wybrany['klucz'] ?? '';

$to_biezacy = !$biezacy || ($wybrany && $wybrany['klucz'] === $biezacy['klucz']);

$zawodnicy = $gotowa ? zawodnicy_do_skladu($db) : [];
$dozwoleni = array_column($zawodnicy, 'id');

/* ---------- zapis ---------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $gotowa) {
    sprawdz_csrf();

    $akcja = (string) ($_POST['akcja'] ?? 'zapisz');

    /* Każdy zapis dotyczy meczu wybranego na ekranie, a powrót ma na ten sam
       mecz wrócić — stąd klucz doklejany do adresu. */
    $powrot = 'sklad.php' . ($klucz !== '' ? '?mecz=' . rawurlencode($klucz) : '');
    $wiersz = $ma_mecze ? sklad_meczu($db, $klucz, $to_biezacy) : sklad_meczu($db, null, true);

    /* Czytamy PRZED zapisem: niżej UPDATE wyzeruje tę flagę i nie dałoby się
       już odróżnić „skład właśnie zniknął ze strony" od „nigdy go tam nie było". */
    $bylo_na_stronie = (int) ($wiersz['opublikowany'] ?? 0) === 1;

    /* Zdjęcie ze strony nie dotyka ustawienia — trener chce tylko, żeby skład
       przestał być widoczny, a nie żeby stracić to, co poukładał. */
    if ($akcja === 'ukryj') {
        if ($wiersz) {
            $db->prepare('UPDATE sklady SET opublikowany = 0, opublikowano = NULL WHERE id = ?')
               ->execute([$wiersz['id']]);
        }

        if ($to_biezacy) {
            ukryj_sklad();
        }

        komunikat('Skład zdjęty ze strony. Ustawienie zostało w panelu.');
        przekieruj($powrot);
    }

    /* W trakcie meczu skład jest zamknięty. Przyciski zapisu i tak znikają
       z ekranu, ale otwarta od rana zakładka w telefonie nadal potrafiłaby
       wysłać formularz sprzed pierwszego gwizdka i cofnąć ustawienie.
       Dotyczy to wyłącznie meczu, który się właśnie rozgrywa — skład na
       następną kolejkę wolno układać choćby w przerwie. */
    if ($ma_przebieg && $to_biezacy && (string) ($wiersz['stan'] ?? '') === 'trwa') {
        komunikat('Mecz trwa — skład jest zamknięty. Odśwież stronę i zapisuj zmiany.', 'blad');
        przekieruj($powrot);
    }

    $formacja = (string) ($_POST['formacja'] ?? '');

    if (!czy_znana_formacja($formacja)) {
        komunikat('Nieznana formacja — skład nie został zapisany.', 'blad');
        przekieruj($powrot);
    }

    /* Ustawienie przychodzi jako JSON z pola ukrytego, bo liczba i nazwy pól
       zależą od formacji wybranej już po wczytaniu strony. Traktujemy je jak
       każde inne dane z formularza: przepuszczamy wyłącznie pary, które
       pasują do wybranej formacji i do listy aktywnych zawodników. */
    $surowe = json_decode((string) ($_POST['ustawienie'] ?? '[]'), true);
    $pola = pola_formacji($formacja);
    $ustawienie = [];
    $uzyci = [];

    if (is_array($surowe)) {
        foreach ($surowe as $pole => $zawodnik) {
            if (!in_array($pole, $pola, true)) {
                continue;
            }

            $id = filter_var($zawodnik, FILTER_VALIDATE_INT);

            if ($id === false || !in_array($id, $dozwoleni, true)) {
                continue;
            }

            // ten sam zawodnik nie może stać w dwóch miejscach naraz
            if (in_array($id, $uzyci, true)) {
                continue;
            }

            $ustawienie[$pole] = $id;
            $uzyci[] = $id;
        }
    }

    /* Ławka jedzie tą samą drogą co ustawienie: ukryte pole z JSON-em, bo
       lista powstaje dopiero w przeglądarce. Kolejność z formularza zostaje —
       trener układa zmienników w kolejności, w jakiej zamierza ich wpuścić. */
    $surowa_lawka = json_decode((string) ($_POST['rezerwowi'] ?? '[]'), true);
    $rezerwowi = [];

    if (is_array($surowa_lawka)) {
        foreach ($surowa_lawka as $zawodnik) {
            if (count($rezerwowi) >= MAKS_REZERWOWYCH) {
                break;
            }

            $id = filter_var($zawodnik, FILTER_VALIDATE_INT);

            if ($id === false || !in_array($id, $dozwoleni, true)) {
                continue;
            }

            // nikt nie siedzi na ławce i stoi na boisku naraz ani nie siada dwa razy
            if (in_array($id, $uzyci, true) || in_array($id, $rezerwowi, true)) {
                continue;
            }

            $rezerwowi[] = $id;
        }
    }

    $nazwa = mb_substr(trim((string) ($_POST['nazwa'] ?? '')), 0, 160);
    $teraz = date('Y-m-d H:i:s');
    $ilu = count($ustawienie);
    $na_lawce = $rezerwowi ? ' Na ławce: ' . count($rezerwowi) . '.' : '';

    /* Niepełny skład nie ma prawa trafić na stronę: kibic zobaczyłby boisko
       z dziurami i nie wiedziałby, czy to pomyłka, czy trener tak chciał. */
    /* Ustawienie zapisujemy mimo odmowy publikacji — trener nie ma stracić
       tego, co poukładał, tylko dlatego, że kliknął za wcześnie. Komunikat
       jest już wtedy ustawiony i nie wolno go niżej nadpisać ogólnikowym
       „Skład zapisany", bo zniknąłby jedyny ślad po powodzie odmowy. */
    $odmowa = $akcja === 'zatwierdz' && $ilu < 11;

    if ($odmowa) {
        komunikat(
            'Do zatwierdzenia brakuje ' . (11 - $ilu) . ' z 11 zawodników. '
            . 'Ustawienie zapisane, ale na stronie się nie pokaże.',
            'blad'
        );
        $akcja = 'zapisz';
    }

    $publikuj = $akcja === 'zatwierdz';

    /* Jeden wiersz na mecz, nadpisywany w miejscu. Historia ustawień tego
       samego spotkania to osobny temat — dopóki jej nie ma, nie zostawiamy
       w bazie sterty wersji bez sposobu, żeby je przeglądać. */
    $json = json_encode($ustawienie, JSON_UNESCAPED_UNICODE);
    $json_lawki = json_encode(array_values($rezerwowi), JSON_UNESCAPED_UNICODE);

    if ($wiersz) {
        $db->prepare(
            'UPDATE sklady SET nazwa = ?, formacja = ?, ustawienie = ?, rezerwowi = ?,
                    zaktualizowano = ?, opublikowany = ?, opublikowano = ? WHERE id = ?'
        )->execute([$nazwa, $formacja, $json, $json_lawki, $teraz,
                    $publikuj ? 1 : 0, $publikuj ? $teraz : null, $wiersz['id']]);
    } elseif ($ma_mecze) {
        $db->prepare(
            'INSERT INTO sklady (mecz_klucz, mecz_opis, mecz_data, nazwa, formacja, ustawienie,
                                 rezerwowi, zaktualizowano, utworzono, opublikowany, opublikowano)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$klucz !== '' ? $klucz : null, $wybrany['opis'] ?? null,
                    $wybrany['data_iso'] ?? null, $nazwa, $formacja, $json, $json_lawki,
                    $teraz, $teraz, $publikuj ? 1 : 0, $publikuj ? $teraz : null]);
    } else {
        $db->prepare(
            'INSERT INTO sklady (nazwa, formacja, ustawienie, rezerwowi, zaktualizowano,
                                 utworzono, opublikowany, opublikowano)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$nazwa, $formacja, $json, $json_lawki, $teraz, $teraz,
                    $publikuj ? 1 : 0, $publikuj ? $teraz : null]);
    }

    if ($publikuj) {
        /* Na stronę idzie wyłącznie skład najbliższego spotkania. Zatwierdzony
           wcześniej czeka w panelu i pojedzie sam, gdy jego mecz stanie się
           tym najbliższym — pilnuje tego cron. */
        if ($to_biezacy) {
            opublikuj_sklad($zawodnicy, $formacja, $ustawienie, $rezerwowi, $nazwa);
            komunikat('Skład zatwierdzony — przycisk „Pokaż skład” jest już widoczny na stronie.'
                      . $na_lawce);
        } else {
            komunikat('Skład zatwierdzony. Pokaże się na stronie, gdy ten mecz stanie się '
                      . 'najbliższy — na razie kibice widzą skład na ' .
                      ($biezacy['opis'] ?? 'najbliższy mecz') . '.' . $na_lawce);
        }

        przekieruj($powrot);
    }

    /* Zapis roboczy zdejmuje skład ze strony. Inaczej kibice oglądaliby
       poprzednie ustawienie, a trener w panelu widziałby nowe i nie miałby
       powodu podejrzewać, że to dwie różne rzeczy. Dotyczy to oczywiście
       tylko meczu, którego skład akurat na tej stronie wisi. */
    if ($to_biezacy) {
        ukryj_sklad();
    }

    if ($odmowa) {
        // komunikat o odmowie już stoi — nie zastępujemy go niczym słabszym
    } elseif ($bylo_na_stronie) {
        komunikat(
            'Skład zapisany, ale zniknął ze strony — zatwierdź go ponownie, '
            . 'żeby znów był widoczny.',
            'blad'
        );
    } else {
        komunikat(
            ($ilu === 11
                ? 'Skład zapisany — jedenastu zawodników w ustawieniu ' . $formacja . '.'
                : 'Skład zapisany (' . $ilu . ' z 11 pól obsadzonych).') . $na_lawce,
            $ilu === 11 ? 'ok' : 'blad'
        );
    }

    przekieruj($powrot);
}

/* ---------- odczyt ---------- */

$zapisany = $gotowa
    ? ($ma_mecze ? sklad_meczu($db, $klucz, $to_biezacy) : sklad_meczu($db, null, true))
    : null;

$formacja = $zapisany && czy_znana_formacja((string) $zapisany['formacja'])
    ? (string) $zapisany['formacja']
    : formacja_domyslna();

$opublikowany = $zapisany && (int) ($zapisany['opublikowany'] ?? 0) === 1;

$ustawienie = [];
$rezerwowi = [];

if ($zapisany) {
    $odczytane = json_decode((string) $zapisany['ustawienie'], true);

    if (is_array($odczytane)) {
        /* Zawodnik mógł zostać w międzyczasie usunięty albo przeniesiony do
           sztabu — wtedy jego pole po prostu zostaje puste. */
        foreach ($odczytane as $pole => $id) {
            if (in_array((int) $id, $dozwoleni, true)) {
                $ustawienie[$pole] = (int) $id;
            }
        }
    }

    /* Ławka przechodzi przez to samo sito, co boisko. Dodatkowo odpada ktoś,
       kto zdążył trafić do wyjściowej jedenastki — zapis tego nie dopuszcza,
       ale kolumna mogła zostać po starszej wersji panelu. */
    $odczytana_lawka = json_decode((string) ($zapisany['rezerwowi'] ?? '[]'), true);

    if (is_array($odczytana_lawka)) {
        foreach ($odczytana_lawka as $id) {
            $id = (int) $id;

            if (in_array($id, $dozwoleni, true)
                && !in_array($id, $ustawienie, true)
                && !in_array($id, $rezerwowi, true)
                && count($rezerwowi) < MAKS_REZERWOWYCH) {
                $rezerwowi[] = $id;
            }
        }
    }
}

/* Mecz w toku zamyka ten ekran — ustawienie, gole i zmiany są wtedy
   w „Przebiegu meczu". */
$trwa = $ma_przebieg && (string) ($zapisany['stan'] ?? 'przed') === 'trwa';

/* Które spotkania mają już ustawiony skład — żeby lista po lewej mówiła to
   od razu, bez wchodzenia w każde z osobna. */
$stan_skladow = [];

if ($ma_mecze && $mecze) {
    foreach ($db->query('SELECT mecz_klucz, opublikowany, ustawienie FROM sklady
                          WHERE mecz_klucz IS NOT NULL')->fetchAll() as $w) {
        $pola = json_decode((string) $w['ustawienie'], true);

        $stan_skladow[(string) $w['mecz_klucz']] = [
            'ilu'          => is_array($pola) ? count($pola) : 0,
            'opublikowany' => (int) $w['opublikowany'] === 1,
        ];
    }
}

/** Termin spotkania w jednej linijce: „sob 11.10, 15:00" albo samo okno kolejki. */
function termin_meczu(array $m): string
{
    if (empty($m['data_iso'])) {
        return (string) ($m['kolejka_opis'] ?? 'termin nieustalony');
    }

    $czas = strtotime((string) $m['data_iso']);

    if ($m['przyblizona']) {
        return (string) ($m['kolejka_opis'] ?? date('d.m', $czas));
    }

    $dni = ['nd', 'pon', 'wt', 'śr', 'czw', 'pt', 'sob'];

    return $dni[(int) date('w', $czas)] . ' ' . date('d.m, H:i', $czas);
}

naglowek('Skład');
?>

<div class="sklad__ekran">

<?php if ($ma_mecze && $mecze): ?>
    <!-- Lista kolejek. Skład układa się tu z wyprzedzeniem, a na stronę idzie
         zawsze ten z najbliższego spotkania — stąd plakietka „najbliższy". -->
    <aside class="kolejki" aria-label="Mecze z terminarza">
        <h2 class="kolejki__tytul">Nadchodzące mecze</h2>

        <ul class="kolejki__lista">
            <?php foreach ($mecze as $m):
                $stan = $stan_skladow[$m['klucz']] ?? null;
                $wybrany_tu = $m['klucz'] === $klucz;
            ?>
                <li>
                    <a class="kolejka<?= $wybrany_tu ? ' is-wybrana' : '' ?>"
                       href="sklad.php?mecz=<?= rawurlencode($m['klucz']) ?>"
                       <?= $wybrany_tu ? 'aria-current="page"' : '' ?>>
                        <span class="kolejka__kiedy">
                            <?= e(termin_meczu($m)) ?>
                            <?php if ($m === $mecze[0]): ?>
                                <b class="kolejka__najblizszy">najbliższy</b>
                            <?php endif; ?>
                        </span>

                        <span class="kolejka__kto"><?= e($m['opis']) ?></span>

                        <span class="kolejka__sklad<?= $stan && $stan['ilu'] >= 11 ? ' is-gotowy' : '' ?>">
                            <?php if (!$stan || !$stan['ilu']): ?>
                                bez składu
                            <?php elseif ($stan['ilu'] < 11): ?>
                                <?= (int) $stan['ilu'] ?>/11
                            <?php elseif ($stan['opublikowany']): ?>
                                skład zatwierdzony
                            <?php else: ?>
                                skład gotowy
                            <?php endif; ?>
                        </span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </aside>
<?php endif; ?>

<div class="sklad__tresc">

<div class="sklad__naglowek">
    <h1>Skład na mecz</h1>
    <?php if ($wybrany): ?>
        <p class="sklad__mecz"><?= e($wybrany['opis']) ?> · <?= e(termin_meczu($wybrany)) ?></p>
    <?php endif; ?>
    <?php if ($zapisany): ?>
        <p class="sklad__zapis">
            Ostatni zapis: <?= e(date('d.m.Y, H:i', strtotime((string) $zapisany['zaktualizowano']))) ?>
        </p>
    <?php endif; ?>

    <span class="sklad__stan sklad__stan--<?= $opublikowany ? 'na-stronie' : 'roboczy' ?>">
        <?= $opublikowany ? 'Widoczny na stronie' : 'Tylko w panelu' ?>
    </span>
</div>

<?php if (!$gotowa): ?>
    <section class="karta">
        <h2>Baza nie ma jeszcze gdzie zapisać składu</h2>
        <p>
            Brakuje tabeli <code>sklady</code> albo jej nowszych kolumn —
            na przykład tej na ławkę rezerwowych. Administrator musi uruchomić
            aktualizację bazy w zakładce Migracje; do tego czasu ustawienie
            nie przetrwa odświeżenia strony.
        </p>
        <?php if (czy_administrator()): ?>
            <a class="btn" href="migracje.php">Przejdź do migracji</a>
        <?php endif; ?>
    </section>
<?php elseif ($trwa): ?>
    <section class="karta">
        <h2>Mecz trwa</h2>
        <p>
            Skład jest zamknięty do końcowego gwizdka — kibice widzą ten,
            który zatwierdziłeś przed meczem. Boisko z bieżącym ustawieniem,
            tablica wyniku i zmiany czekają w „Przebiegu meczu".
        </p>
        <a class="btn" href="przebieg.php">Przejdź do przebiegu meczu</a>
    </section>
<?php elseif (!$zawodnicy): ?>
    <section class="karta">
        <h2>Brak zawodników do wystawienia</h2>
        <p>Lista aktywnych zawodników jest pusta, więc nie ma kogo ustawić na boisku.</p>
    </section>
<?php else: ?>

<form method="post" id="sklad-form" class="sklad">
    <?= pole_csrf() ?>
    <input type="hidden" name="mecz" value="<?= e($klucz) ?>">
    <input type="hidden" name="formacja" id="sklad-formacja" value="<?= e($formacja) ?>">
    <input type="hidden" name="ustawienie" id="sklad-ustawienie" value="">
    <input type="hidden" name="rezerwowi" id="sklad-rezerwowi" value="">

    <section class="sklad__pasek">
        <div class="sklad__formacje" role="radiogroup" aria-label="Formacja">
            <?php foreach (formacje() as $nazwa => $dane): ?>
                <button type="button"
                        class="formacja<?= $nazwa === $formacja ? ' is-wybrana' : '' ?>"
                        data-formacja="<?= e($nazwa) ?>"
                        role="radio"
                        aria-checked="<?= $nazwa === $formacja ? 'true' : 'false' ?>"
                        title="<?= e($dane['opis']) ?>">
                    <strong><?= e($nazwa) ?></strong>
                    <small><?= e($dane['opis']) ?></small>
                </button>
            <?php endforeach; ?>
        </div>

        <label class="sklad__opis">
            <span>Opis meczu</span>
            <input type="text" name="nazwa" maxlength="160"
                   placeholder="np. Janovia — Sokół Pień, 11.10"
                   value="<?= e((string) ($zapisany['nazwa'] ?? '')) ?>">
        </label>
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
            <!-- pola wstawia sklad.js na podstawie wybranej formacji -->
        </section>

        <section class="lawka" aria-label="Zawodnicy do wystawienia">
            <header class="lawka__head">
                <h2>Do wystawienia</h2>
                <span class="lawka__licznik" id="licznik">0/11</span>
            </header>

            <p class="lawka__podpowiedz">
                Przeciągnij zawodnika na boisko albo na ławkę pod nim — możesz
                też dotknąć jego, a potem miejsca. Zawodnika zdejmiesz,
                przeciągając go z powrotem na listę albo stukając tam, gdzie stoi.
            </p>

            <div class="lawka__lista" id="lawka"></div>
        </section>
    </div>

    <!-- Ławka leży pod planszą, w całej szerokości: zmiennicy to krótka lista,
         a boisko i tak zabiera wysokość, której nie ma z czego oddać. -->
    <section class="rezerwa" id="rezerwa" aria-label="Ławka rezerwowych">
        <header class="rezerwa__head">
            <h2>Ławka rezerwowych</h2>
            <span class="rezerwa__licznik" id="licznik-rezerw">0/<?= MAKS_REZERWOWYCH ?></span>
        </header>

        <div class="rezerwa__lista" id="rezerwa-lista"></div>
    </section>

    <footer class="sklad__stopka">
        <button class="btn" type="submit" name="akcja" value="zatwierdz">
            Zatwierdź i pokaż na stronie
        </button>
        <button class="btn btn--pusty" type="submit" name="akcja" value="zapisz">
            Zapisz roboczo
        </button>

        <?php if ($opublikowany): ?>
            <button class="btn btn--kasuj" type="submit" name="akcja" value="ukryj"
                    formnovalidate>
                Zdejmij ze strony
            </button>
        <?php endif; ?>

        <button class="btn btn--pusty" type="button" id="wyczysc">Wyczyść boisko</button>

        <span class="sklad__uwaga">
            <?php if ($opublikowany): ?>
                Kibice widzą ten skład pod odliczaniem na stronie głównej.
                Każdy zapis roboczy zdejmuje go stamtąd do czasu ponownego zatwierdzenia.
            <?php else: ?>
                Zatwierdzenie wymaga kompletnych jedenastu zawodników.
                Do tego czasu skład zostaje wyłącznie w panelu.
            <?php endif; ?>
        </span>
    </footer>
</form>

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
        'zawodnicy'  => array_map(static function (array $z): array {
            return [
                'id'       => (int) $z['id'],
                'imie'     => $z['imie'],
                'nazwisko' => $z['nazwisko'],
                'pozycja'  => $z['pozycja'],
                'numer'    => $z['numer'] !== null ? (int) $z['numer'] : null,
                'kapitan'  => (int) ($z['kapitan'] ?? 0) === 1,
                /* Ścieżka gotowa do wstawienia w src. adres_zdjecia zwraca ją
                   względem katalogu strony, a panel siedzi w admin/ — stąd
                   „../". Brak zdjęcia to null, nie pusty ciąg: skrypt ma
                   rozpoznać różnicę i pokazać inicjały. */
                'foto'     => ($a = adres_zdjecia($z['zdjecie'] ?? null, 'zawodnicy')) ? '../' . $a : null,
            ];
        }, $zawodnicy),
        'formacja'   => $formacja,
        'ustawienie' => (object) $ustawienie,
        'rezerwowi'  => array_values($rezerwowi),
        'maksRezerw' => MAKS_REZERWOWYCH,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
</script>
<script src="js/sklad.js?v=25"></script>

<?php endif; ?>

</div><!-- .sklad__tresc -->
</div><!-- .sklad__ekran -->

<?php stopka(); ?>
