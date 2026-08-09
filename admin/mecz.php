<?php
/**
 * Formularz dodawania i edycji ręcznie wpisanych meczów
 * (sparingi, turnieje — cokolwiek, czego nie widzi 90minut.pl).
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/zdjecia.php';

wymagaj_logowania();

const ETYKIETY_MECZU = ['Sparing', 'Turniej', 'Puchar', 'Mecz towarzyski'];

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$bledy = [];

if ($id) {
    $stmt = baza()->prepare('SELECT * FROM mecze WHERE id = ?');
    $stmt->execute([$id]);
    $m = $stmt->fetch();

    if (!$m) {
        komunikat('Nie znaleziono meczu.', 'blad');
        przekieruj('mecze.php');
    }

    $m['termin'] = date('Y-m-d\TH:i', strtotime($m['termin']));
} else {
    $m = ['przeciwnik' => '', 'u_siebie' => 1, 'termin' => '', 'etykieta' => 'Sparing',
          'wynik' => '', 'herb' => null, 'widoczny' => 1];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sprawdz_csrf();

    $m['przeciwnik'] = trim((string) ($_POST['przeciwnik'] ?? ''));
    $m['u_siebie']   = isset($_POST['u_siebie']) ? 1 : 0;
    $m['termin']     = (string) ($_POST['termin'] ?? '');
    $m['etykieta']   = (string) ($_POST['etykieta'] ?? 'Sparing');
    $m['wynik']      = trim((string) ($_POST['wynik'] ?? ''));
    $m['widoczny']   = isset($_POST['widoczny']) ? 1 : 0;

    if ($m['przeciwnik'] === '') { $bledy[] = 'Nazwa przeciwnika jest wymagana.'; }
    if (mb_strtolower($m['przeciwnik']) === 'janovia janowiec') {
        $bledy[] = 'Przeciwnikiem nie może być sam klub.';
    }

    if (!in_array($m['etykieta'], ETYKIETY_MECZU, true)) {
        $bledy[] = 'Wybierz typ meczu z listy.';
    }

    if ($m['wynik'] !== '' && !preg_match('/^\d{1,2}-\d{1,2}$/', $m['wynik'])) {
        $bledy[] = 'Wynik podaj w formacie liczba-liczba, np. 3-1.';
    }

    $czas = strtotime($m['termin']);

    if ($czas === false) {
        $bledy[] = 'Nieprawidłowy termin meczu.';
    }

    $nowy_herb = null;

    if (!$bledy) {
        try {
            $nowy_herb = zapisz_zdjecie($_FILES['herb'] ?? [], 'mecze');
        } catch (RuntimeException $e) {
            $bledy[] = $e->getMessage();
        }
    }

    if (!$bledy) {
        $usun_stary = isset($_POST['usun_herb']);
        $herb = $nowy_herb ?? ($usun_stary ? null : ($m['herb'] ?? null));

        if (($nowy_herb || $usun_stary) && !empty($m['herb'])) {
            usun_zdjecie($m['herb'], 'mecze');
        }

        $data = date('Y-m-d H:i:s', $czas);
        $wynik = $m['wynik'] !== '' ? $m['wynik'] : null;

        if ($id) {
            $sql = 'UPDATE mecze
                    SET przeciwnik = ?, u_siebie = ?, termin = ?, etykieta = ?, wynik = ?, herb = ?, widoczny = ?
                    WHERE id = ?';
            baza()->prepare($sql)->execute([
                $m['przeciwnik'], $m['u_siebie'], $data, $m['etykieta'], $wynik, $herb, $m['widoczny'], $id,
            ]);
            komunikat('Mecz zapisany.');
        } else {
            $sql = 'INSERT INTO mecze (przeciwnik, u_siebie, termin, etykieta, wynik, herb, widoczny)
                    VALUES (?, ?, ?, ?, ?, ?, ?)';
            baza()->prepare($sql)->execute([
                $m['przeciwnik'], $m['u_siebie'], $data, $m['etykieta'], $wynik, $herb, $m['widoczny'],
            ]);
            komunikat('Mecz dodany.');
        }

        przekieruj('mecze.php');
    }
}

$herb_podglad = adres_zdjecia($m['herb'] ?? null, 'mecze');

naglowek($id ? 'Edycja meczu' : 'Nowy mecz');
?>

<div class="naglowek-sekcji">
    <h1><?= $id ? 'Edycja meczu' : 'Nowy mecz' ?></h1>
    <a class="btn btn--pusty" href="mecze.php">Wróć do listy</a>
</div>

<?php foreach ($bledy as $b): ?>
    <p class="komunikat komunikat--blad"><?= e($b) ?></p>
<?php endforeach; ?>

<form class="karta formularz" method="post" enctype="multipart/form-data">
    <?= pole_csrf() ?>

    <label>Przeciwnik
        <input type="text" name="przeciwnik" maxlength="80" required value="<?= e($m['przeciwnik']) ?>">
    </label>

    <div class="pola">
        <label>Gdzie
            <select name="u_siebie">
                <option value="1"<?= $m['u_siebie'] ? ' selected' : '' ?>>U siebie (Janowiec)</option>
                <option value="0"<?= !$m['u_siebie'] ? ' selected' : '' ?>>Wyjazd</option>
            </select>
        </label>

        <label>Typ meczu
            <select name="etykieta">
                <?php foreach (ETYKIETY_MECZU as $et): ?>
                    <option value="<?= e($et) ?>"<?= $m['etykieta'] === $et ? ' selected' : '' ?>>
                        <?= e($et) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
    </div>

    <div class="pola">
        <label>Termin
            <input type="datetime-local" name="termin" required value="<?= e($m['termin']) ?>">
        </label>

        <label>Wynik
            <input type="text" name="wynik" placeholder="np. 3-1" value="<?= e($m['wynik'] ?? '') ?>">
            <small>Zostaw puste, dopóki mecz się nie odbędzie.</small>
        </label>
    </div>

    <div class="zdjecie-pole">
        <?php if ($herb_podglad): ?>
            <img class="podglad" src="../<?= e($herb_podglad) ?>" alt="Obecny herb przeciwnika">
            <label class="przelacznik">
                <input type="checkbox" name="usun_herb" value="1">
                <span>Usuń obecny herb</span>
            </label>
        <?php endif; ?>

        <label><?= $herb_podglad ? 'Zastąp herb przeciwnika' : 'Herb przeciwnika' ?>
            <input type="file" name="herb" accept="image/png,image/webp,image/jpeg">
            <small>
                PNG (najlepiej z przezroczystym tłem), do 5 MB. Bez tego przeciwnik dostanie
                zastępczą tarczę z pierwszą literą nazwy.
            </small>
        </label>
    </div>

    <label class="przelacznik">
        <input type="checkbox" name="widoczny" value="1"<?= $m['widoczny'] ? ' checked' : '' ?>>
        <span>Widoczny na stronie — odznacz, żeby przygotować mecz na później.</span>
    </label>

    <button class="btn" type="submit"><?= $id ? 'Zapisz zmiany' : 'Dodaj mecz' ?></button>
</form>

<?php stopka(); ?>
