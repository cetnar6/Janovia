<?php
/**
 * Formularz dodawania i edycji aktualności.
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/zdjecia.php';

wymagaj_logowania();

const ETYKIETY = ['Klub', 'Zapowiedź', 'Relacja', 'Transfer', 'Akademia', 'Piłka nożna'];

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$bledy = [];

if ($id) {
    $stmt = baza()->prepare('SELECT * FROM posty WHERE id = ?');
    $stmt->execute([$id]);
    $p = $stmt->fetch();

    if (!$p) {
        komunikat('Nie znaleziono aktualności.', 'blad');
        przekieruj('posty.php');
    }

    $p['opublikowano'] = date('Y-m-d\TH:i', strtotime($p['opublikowano']));
} else {
    $p = ['tytul' => '', 'tresc' => '', 'etykieta' => 'Klub', 'zdjecie' => null,
          'opublikowano' => date('Y-m-d\TH:i'), 'widoczny' => 1];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sprawdz_csrf();

    $p['tytul']        = trim((string) ($_POST['tytul'] ?? ''));
    $p['tresc']        = trim((string) ($_POST['tresc'] ?? ''));
    $p['etykieta']     = (string) ($_POST['etykieta'] ?? 'Klub');
    $p['opublikowano'] = (string) ($_POST['opublikowano'] ?? '');
    $p['widoczny']     = isset($_POST['widoczny']) ? 1 : 0;

    if ($p['tytul'] === '') { $bledy[] = 'Tytuł jest wymagany.'; }
    if ($p['tresc'] === '') { $bledy[] = 'Treść jest wymagana.'; }

    if (!in_array($p['etykieta'], ETYKIETY, true)) {
        $bledy[] = 'Wybierz etykietę z listy.';
    }

    $czas = strtotime($p['opublikowano']);

    if ($czas === false) {
        $bledy[] = 'Nieprawidłowa data publikacji.';
    }

    $nowe_zdjecie = null;

    if (!$bledy) {
        try {
            $nowe_zdjecie = zapisz_zdjecie($_FILES['zdjecie'] ?? [], 'posty');
        } catch (RuntimeException $e) {
            $bledy[] = $e->getMessage();
        }
    }

    if (!$bledy) {
        $usun_stare = isset($_POST['usun_zdjecie']);
        $zdjecie = $nowe_zdjecie ?? ($usun_stare ? null : $p['zdjecie']);

        if (($nowe_zdjecie || $usun_stare) && $p['zdjecie']) {
            usun_zdjecie($p['zdjecie'], 'posty');
        }

        $data = date('Y-m-d H:i:s', $czas);

        if ($id) {
            $sql = 'UPDATE posty
                    SET tytul = ?, tresc = ?, etykieta = ?, zdjecie = ?, opublikowano = ?, widoczny = ?
                    WHERE id = ?';
            baza()->prepare($sql)->execute([
                $p['tytul'], $p['tresc'], $p['etykieta'], $zdjecie, $data, $p['widoczny'], $id,
            ]);
            komunikat('Aktualność zapisana.');
        } else {
            $sql = 'INSERT INTO posty (tytul, tresc, etykieta, zdjecie, opublikowano, widoczny)
                    VALUES (?, ?, ?, ?, ?, ?)';
            baza()->prepare($sql)->execute([
                $p['tytul'], $p['tresc'], $p['etykieta'], $zdjecie, $data, $p['widoczny'],
            ]);
            komunikat('Aktualność dodana.');
        }

        przekieruj('posty.php');
    }
}

$foto = adres_zdjecia($p['zdjecie'] ?? null, 'posty');

naglowek($id ? 'Edycja aktualności' : 'Nowa aktualność');
?>

<div class="naglowek-sekcji">
    <h1><?= $id ? 'Edycja aktualności' : 'Nowa aktualność' ?></h1>
    <a class="btn btn--pusty" href="posty.php">Wróć do listy</a>
</div>

<?php foreach ($bledy as $b): ?>
    <p class="komunikat komunikat--blad"><?= e($b) ?></p>
<?php endforeach; ?>

<form class="karta formularz" method="post" enctype="multipart/form-data">
    <?= pole_csrf() ?>

    <label>Tytuł
        <input type="text" name="tytul" maxlength="160" required value="<?= e($p['tytul']) ?>">
    </label>

    <label>Treść
        <textarea name="tresc" rows="12" required><?= e($p['tresc']) ?></textarea>
        <small>Puste linie tworzą akapity — dokładnie tak, jak wpiszesz, pokaże się na stronie.</small>
    </label>

    <div class="pola">
        <label>Etykieta
            <select name="etykieta">
                <?php foreach (ETYKIETY as $et): ?>
                    <option value="<?= e($et) ?>"<?= $p['etykieta'] === $et ? ' selected' : '' ?>>
                        <?= e($et) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label>Data publikacji
            <input type="datetime-local" name="opublikowano" required value="<?= e($p['opublikowano']) ?>">
        </label>
    </div>

    <label class="przelacznik">
        <input type="checkbox" name="widoczny" value="1"<?= $p['widoczny'] ? ' checked' : '' ?>>
        <span>Widoczna na stronie — odznacz, żeby przygotować wpis na później.</span>
    </label>

    <div class="zdjecie-pole">
        <?php if ($foto): ?>
            <img class="podglad podglad--szeroki" src="../<?= e($foto) ?>" alt="Aktualne zdjęcie">
            <label class="przelacznik">
                <input type="checkbox" name="usun_zdjecie" value="1">
                <span>Usuń obecne zdjęcie</span>
            </label>
        <?php endif; ?>

        <label><?= $foto ? 'Zastąp zdjęcie' : 'Zdjęcie' ?>
            <input type="file" name="zdjecie" accept="image/jpeg,image/png,image/webp">
            <small>JPG, PNG albo WEBP, do 5 MB.</small>
        </label>
    </div>

    <button class="btn" type="submit"><?= $id ? 'Zapisz zmiany' : 'Opublikuj' ?></button>
</form>

<?php stopka(); ?>
