<?php
/**
 * Dociąga schemat bazy do wersji, której oczekuje kod panelu.
 *
 * Strona jest w panelu, a nie w phpMyAdminie, bo baza na hostingu i tak
 * nie przyjmuje połączeń z zewnątrz — a wejście tutaj wymaga zalogowania
 * dokładnie tak samo jak edycja zawodnika.
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/migracje.php';

wymagaj_logowania();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sprawdz_csrf();

    $db = baza();
    $wykonane = 0;

    foreach (migracje() as $m) {
        if (!($m['potrzebna'])()) {
            continue;
        }

        ($m['zastosuj'])($db);

        // Sprawdzamy wynik zamiast wierzyć, że zapytanie „przeszło": MySQL
        // w trybie nieścisłym potrafi zgłosić samo ostrzeżenie i jechać dalej.
        if (($m['potrzebna'])()) {
            komunikat('Migracja „' . $m['nazwa'] . '" nie przyniosła skutku. Zajrzyj do logów serwera.', 'blad');
            przekieruj('migracje.php');
        }

        $wykonane++;
    }

    /* Naprawa danych osobno i dopiero po schemacie — przed nim baza
       wyczyściłaby „sztab" po raz drugi, dokładnie tak jak za pierwszym. */
    $naprawionych = 0;

    if (isset($_POST['napraw_puste'])) {
        $stmt = $db->prepare("UPDATE zawodnicy SET pozycja = 'sztab' WHERE pozycja = '' OR pozycja IS NULL");
        $stmt->execute();
        $naprawionych = $stmt->rowCount();
    }

    $czesci = [];
    if ($wykonane)     { $czesci[] = 'wykonane migracje: ' . $wykonane; }
    if ($naprawionych) { $czesci[] = 'naprawione wpisy: ' . $naprawionych; }

    komunikat($czesci ? ('Gotowe — ' . implode(', ', $czesci) . '.') : 'Nie było nic do zrobienia.', 'ok');
    przekieruj('migracje.php');
}

$zalegle = migracje_zalegle();
$puste   = zawodnicy_bez_pozycji();

naglowek('Baza danych');
?>

<h1>Baza danych</h1>

<?php if (!$zalegle && !$puste): ?>
    <section class="karta">
        <h2>Schemat jest aktualny</h2>
        <p>Baza zna wszystko, czego oczekuje panel. Nie ma tu nic do klikania.</p>
    </section>
<?php else: ?>

    <?php if ($zalegle): ?>
        <section class="karta">
            <h2>Zaległe zmiany w schemacie</h2>
            <ul>
                <?php foreach ($zalegle as $m): ?>
                    <li>
                        <strong><?= e($m['nazwa']) ?></strong><br>
                        <span class="drobne"><?= e($m['opis']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <?php if ($puste): ?>
        <section class="karta">
            <h2>Zawodnicy bez pozycji</h2>
            <p>
                Zapisani, zanim baza poznała „sztab" — zostali w niej z pustą pozycją,
                więc na stronie trafiają do grupy bez nazwy. Po naprawie dostaną pozycję
                <strong>sztab</strong>; jeśli któryś z nich jednak gra, popraw go potem
                w edycji zawodnika.
            </p>
            <ul>
                <?php foreach ($puste as $z): ?>
                    <li><?= e($z['imie'] . ' ' . $z['nazwisko']) ?></li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <form method="post" class="karta">
        <?= pole_csrf() ?>

        <?php if ($puste): ?>
            <label class="przelacznik">
                <input type="checkbox" name="napraw_puste" value="1" checked>
                <span>Ustaw „sztab" zawodnikom z pustą pozycją (<?= count($puste) ?>)</span>
            </label>
        <?php endif; ?>

        <p class="drobne">
            Zmiany nie ruszają istniejących danych poza tym, co wypisane wyżej,
            i można je uruchomić drugi raz bez szkody.
        </p>

        <button class="btn" type="submit">Zaktualizuj bazę</button>
    </form>

    <p class="drobne">
        Po aktualizacji wejdź na <a href="index.php">pulpit</a> i opublikuj stronę,
        żeby zmiany trafiły do plików JSON.
    </p>
<?php endif; ?>

<?php stopka(); ?>
