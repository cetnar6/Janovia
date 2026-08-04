<?php
/**
 * Formularz dodawania i edycji zawodnika.
 * Bez ?id= dodaje nowego, z ?id= edytuje istniejącego.
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/zdjecia.php';

wymagaj_logowania();

const POZYCJE = ['bramkarz', 'obrońca', 'pomocnik', 'napastnik'];

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$bledy = [];

if ($id) {
    $stmt = baza()->prepare('SELECT * FROM zawodnicy WHERE id = ?');
    $stmt->execute([$id]);
    $z = $stmt->fetch();

    if (!$z) {
        komunikat('Nie znaleziono zawodnika.', 'blad');
        przekieruj('zawodnicy.php');
    }
} else {
    $z = ['imie' => '', 'nazwisko' => '', 'pozycja' => 'pomocnik',
          'numer' => '', 'zdjecie' => null, 'aktywny' => 1];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sprawdz_csrf();

    $z['imie']     = trim((string) ($_POST['imie'] ?? ''));
    $z['nazwisko'] = trim((string) ($_POST['nazwisko'] ?? ''));
    $z['pozycja']  = (string) ($_POST['pozycja'] ?? '');
    $z['numer']    = trim((string) ($_POST['numer'] ?? ''));
    $z['aktywny']  = isset($_POST['aktywny']) ? 1 : 0;

    if ($z['imie'] === '')     { $bledy[] = 'Imię jest wymagane.'; }
    if ($z['nazwisko'] === '') { $bledy[] = 'Nazwisko jest wymagane.'; }

    if (!in_array($z['pozycja'], POZYCJE, true)) {
        $bledy[] = 'Wybierz pozycję z listy.';
    }

    $numer = null;

    if ($z['numer'] !== '') {
        if (!ctype_digit($z['numer']) || (int) $z['numer'] < 1 || (int) $z['numer'] > 99) {
            $bledy[] = 'Numer na koszulce musi być liczbą od 1 do 99.';
        } else {
            $numer = (int) $z['numer'];

            // numer zajęty przez innego zawodnika w kadrze
            $sql = 'SELECT imie, nazwisko FROM zawodnicy WHERE numer = ? AND aktywny = 1 AND id <> ?';
            $stmt = baza()->prepare($sql);
            $stmt->execute([$numer, $id]);

            if ($zajety = $stmt->fetch()) {
                $bledy[] = sprintf(
                    'Numer %d nosi już %s %s. Zwolnij go albo wybierz inny.',
                    $numer, $zajety['imie'], $zajety['nazwisko']
                );
            }
        }
    }

    $nowe_zdjecie = null;

    if (!$bledy) {
        try {
            $nowe_zdjecie = zapisz_zdjecie($_FILES['zdjecie'] ?? [], 'zawodnicy');
        } catch (RuntimeException $e) {
            $bledy[] = $e->getMessage();
        }
    }

    if (!$bledy) {
        $usun_stare = isset($_POST['usun_zdjecie']);
        $zdjecie = $nowe_zdjecie ?? ($usun_stare ? null : $z['zdjecie']);

        if (($nowe_zdjecie || $usun_stare) && $z['zdjecie']) {
            usun_zdjecie($z['zdjecie'], 'zawodnicy');
        }

        if ($id) {
            $sql = 'UPDATE zawodnicy
                    SET imie = ?, nazwisko = ?, pozycja = ?, numer = ?, zdjecie = ?, aktywny = ?
                    WHERE id = ?';
            baza()->prepare($sql)->execute([
                $z['imie'], $z['nazwisko'], $z['pozycja'], $numer, $zdjecie, $z['aktywny'], $id,
            ]);
            komunikat('Dane zawodnika zapisane.');
        } else {
            $sql = 'INSERT INTO zawodnicy (imie, nazwisko, pozycja, numer, zdjecie, aktywny)
                    VALUES (?, ?, ?, ?, ?, ?)';
            baza()->prepare($sql)->execute([
                $z['imie'], $z['nazwisko'], $z['pozycja'], $numer, $zdjecie, $z['aktywny'],
            ]);
            komunikat('Zawodnik dodany do kadry.');
        }

        przekieruj('zawodnicy.php');
    }
}

$foto = adres_zdjecia($z['zdjecie'] ?? null, 'zawodnicy');

naglowek($id ? 'Edycja zawodnika' : 'Nowy zawodnik');
?>

<div class="naglowek-sekcji">
    <h1><?= $id ? 'Edycja zawodnika' : 'Nowy zawodnik' ?></h1>
    <a class="btn btn--pusty" href="zawodnicy.php">Wróć do listy</a>
</div>

<?php foreach ($bledy as $b): ?>
    <p class="komunikat komunikat--blad"><?= e($b) ?></p>
<?php endforeach; ?>

<form class="karta formularz" method="post" enctype="multipart/form-data">
    <?= pole_csrf() ?>

    <div class="pola">
        <label>Imię
            <input type="text" name="imie" maxlength="50" required value="<?= e($z['imie']) ?>">
        </label>

        <label>Nazwisko
            <input type="text" name="nazwisko" maxlength="60" required value="<?= e($z['nazwisko']) ?>">
        </label>

        <label>Pozycja
            <select name="pozycja" required>
                <?php foreach (POZYCJE as $p): ?>
                    <option value="<?= e($p) ?>"<?= $z['pozycja'] === $p ? ' selected' : '' ?>>
                        <?= e(ucfirst($p)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label>Numer na koszulce
            <input type="number" name="numer" min="1" max="99" value="<?= e((string) $z['numer']) ?>">
            <small>Można zostawić puste.</small>
        </label>
    </div>

    <label class="przelacznik">
        <input type="checkbox" name="aktywny" value="1"<?= $z['aktywny'] ? ' checked' : '' ?>>
        <span>W kadrze — odznacz, gdy zawodnik odszedł z klubu. Zniknie ze strony, ale zostanie w bazie.</span>
    </label>

    <div class="zdjecie-pole">
        <?php if ($foto): ?>
            <img class="podglad" src="../<?= e($foto) ?>" alt="Aktualne zdjęcie">
            <label class="przelacznik">
                <input type="checkbox" name="usun_zdjecie" value="1">
                <span>Usuń obecne zdjęcie</span>
            </label>
        <?php endif; ?>

        <label><?= $foto ? 'Zastąp zdjęcie' : 'Zdjęcie' ?>
            <input type="file" name="zdjecie" accept="image/jpeg,image/png,image/webp">
            <small>JPG, PNG albo WEBP, do 5 MB. Najlepiej kadr pionowy 3:4.</small>
        </label>
    </div>

    <button class="btn" type="submit"><?= $id ? 'Zapisz zmiany' : 'Dodaj zawodnika' ?></button>
</form>

<?php stopka(); ?>
