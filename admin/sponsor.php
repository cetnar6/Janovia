<?php
/**
 * Formularz dodawania i edycji sponsorów z paska na stronie głównej.
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/zdjecia.php';

wymagaj_logowania();

// tylko podpowiedzi do pola tekstowego — własna rola też przejdzie
const ROLE_SPONSORA = [
    'Partner główny',
    'Partner akademii',
    'Partner medialny',
    'Sponsor techniczny',
];

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$bledy = [];

if ($id) {
    $stmt = baza()->prepare('SELECT * FROM sponsorzy WHERE id = ?');
    $stmt->execute([$id]);
    $s = $stmt->fetch();

    if (!$s) {
        komunikat('Nie znaleziono sponsora.', 'blad');
        przekieruj('sponsorzy.php');
    }
} else {
    // nowy sponsor ląduje na końcu paska
    $ostatnia = (int) baza()->query('SELECT COALESCE(MAX(kolejnosc), 0) FROM sponsorzy')->fetchColumn();

    $s = ['nazwa' => '', 'rola' => 'Partner główny', 'logo' => null, 'strona' => '',
          'poswiata' => 0, 'kolejnosc' => $ostatnia + 10, 'widoczny' => 1];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sprawdz_csrf();

    $s['nazwa']     = trim((string) ($_POST['nazwa'] ?? ''));
    $s['rola']      = trim((string) ($_POST['rola'] ?? ''));
    $s['strona']    = trim((string) ($_POST['strona'] ?? ''));
    $s['kolejnosc'] = (int) ($_POST['kolejnosc'] ?? 0);
    $s['poswiata']  = isset($_POST['poswiata']) ? 1 : 0;
    $s['widoczny']  = isset($_POST['widoczny']) ? 1 : 0;

    if ($s['nazwa'] === '') { $bledy[] = 'Nazwa sponsora jest wymagana.'; }
    if ($s['rola'] === '')  { $bledy[] = 'Rola (podpis pod logo) jest wymagana.'; }

    if ($s['strona'] !== '') {
        // bez schematu ludzie wpisują "firma.pl" — dokładamy https sami
        if (!preg_match('~^[a-z][a-z0-9+.-]*://~i', $s['strona'])) {
            $s['strona'] = 'https://' . $s['strona'];
        }

        $schemat = strtolower((string) parse_url($s['strona'], PHP_URL_SCHEME));

        /* Tylko http(s). Adres trafia prosto do href na stronie klubu, więc
           bez tego dałoby się wstawić javascript: i wykonać obcy kod
           u każdego, kto kliknie logo. */
        if (!filter_var($s['strona'], FILTER_VALIDATE_URL) || !in_array($schemat, ['http', 'https'], true)) {
            $bledy[] = 'Adres strony musi być poprawnym odnośnikiem http:// lub https://';
        }
    }

    $nowe_logo = null;

    if (!$bledy) {
        try {
            $nowe_logo = zapisz_zdjecie($_FILES['logo'] ?? [], 'sponsorzy');
        } catch (RuntimeException $e) {
            $bledy[] = $e->getMessage();
        }
    }

    // pasek pokazuje wyłącznie kształt logo, więc bez pliku nie ma czego pokazać
    if (!$bledy && !$nowe_logo && empty($s['logo'])) {
        $bledy[] = 'Wgraj logo sponsora — bez niego nie ma czego pokazać w pasku.';
    }

    if (!$bledy) {
        $logo = $nowe_logo ?? $s['logo'];

        if ($nowe_logo && !empty($s['logo'])) {
            usun_zdjecie($s['logo'], 'sponsorzy');
        }

        if ($id) {
            $sql = 'UPDATE sponsorzy
                    SET nazwa = ?, rola = ?, logo = ?, strona = ?, poswiata = ?, kolejnosc = ?, widoczny = ?
                    WHERE id = ?';
            baza()->prepare($sql)->execute([
                $s['nazwa'], $s['rola'], $logo, $s['strona'] !== '' ? $s['strona'] : null,
                $s['poswiata'], $s['kolejnosc'], $s['widoczny'], $id,
            ]);
            komunikat('Sponsor zapisany.');
        } else {
            $sql = 'INSERT INTO sponsorzy (nazwa, rola, logo, strona, poswiata, kolejnosc, widoczny)
                    VALUES (?, ?, ?, ?, ?, ?, ?)';
            baza()->prepare($sql)->execute([
                $s['nazwa'], $s['rola'], $logo, $s['strona'] !== '' ? $s['strona'] : null,
                $s['poswiata'], $s['kolejnosc'], $s['widoczny'],
            ]);
            komunikat('Sponsor dodany.');
        }

        przekieruj('sponsorzy.php');
    }
}

$logo_podglad = adres_zdjecia($s['logo'] ?? null, 'sponsorzy');

naglowek($id ? 'Edycja sponsora' : 'Nowy sponsor');
?>

<div class="naglowek-sekcji">
    <h1><?= $id ? 'Edycja sponsora' : 'Nowy sponsor' ?></h1>
    <a class="btn btn--pusty" href="sponsorzy.php">Wróć do listy</a>
</div>

<?php foreach ($bledy as $b): ?>
    <p class="komunikat komunikat--blad"><?= e($b) ?></p>
<?php endforeach; ?>

<form class="karta formularz" method="post" enctype="multipart/form-data">
    <?= pole_csrf() ?>

    <label>Nazwa
        <input type="text" name="nazwa" maxlength="80" required value="<?= e($s['nazwa']) ?>">
        <small>Widoczna dla czytników ekranu i wyszukiwarek, nie na samym pasku.</small>
    </label>

    <div class="pola">
        <label>Rola
            <input type="text" name="rola" maxlength="40" required list="role-sponsora"
                   value="<?= e($s['rola']) ?>">
            <datalist id="role-sponsora">
                <?php foreach (ROLE_SPONSORA as $r): ?>
                    <option value="<?= e($r) ?>"></option>
                <?php endforeach; ?>
            </datalist>
            <small>Podpis pod logo. Możesz wybrać z listy albo wpisać własny.</small>
        </label>

        <label>Kolejność
            <input type="number" name="kolejnosc" step="10" value="<?= (int) $s['kolejnosc'] ?>">
            <small>Im mniejsza liczba, tym wcześniej w pasku.</small>
        </label>
    </div>

    <label>Adres strony sponsora
        <input type="url" name="strona" maxlength="255" placeholder="https://firma.pl"
               value="<?= e($s['strona'] ?? '') ?>">
        <small>
            Nieobowiązkowy. Gdy podasz, logo w pasku stanie się odnośnikiem
            otwieranym w nowej karcie. Samo „firma.pl" też przejdzie — https://
            dopiszemy automatycznie.
        </small>
    </label>

    <div class="zdjecie-pole">
        <?php if ($logo_podglad): ?>
            <img class="podglad" src="../<?= e($logo_podglad) ?>" alt="Obecne logo sponsora">
        <?php endif; ?>

        <label><?= $logo_podglad ? 'Zastąp logo' : 'Logo' ?>
            <input type="file" name="logo" accept="image/png,image/webp"
                   <?= $logo_podglad ? '' : 'required' ?>>
            <small>
                PNG z przezroczystym tłem, do 5 MB. Pasek barwi logo na złoto
                i pokazuje wyłącznie jego kształt — jeśli tło nie jest w pełni
                wycięte, logo wyjdzie jako złoty kwadrat.
            </small>
        </label>
    </div>

    <label class="przelacznik">
        <input type="checkbox" name="poswiata" value="1"<?= $s['poswiata'] ? ' checked' : '' ?>>
        <span>Poświata pod logo — delikatny blask dla najważniejszych partnerów.</span>
    </label>

    <label class="przelacznik">
        <input type="checkbox" name="widoczny" value="1"<?= $s['widoczny'] ? ' checked' : '' ?>>
        <span>Widoczny na stronie — odznacz, żeby chwilowo zdjąć logo z paska.</span>
    </label>

    <button class="btn" type="submit"><?= $id ? 'Zapisz zmiany' : 'Dodaj sponsora' ?></button>
</form>

<?php stopka(); ?>
