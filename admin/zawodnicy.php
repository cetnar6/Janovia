<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/zdjecia.php';

wymagaj_logowania();

/* ---------- usuwanie ---------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['akcja'] ?? '') === 'usun') {
    sprawdz_csrf();

    $id = (int) ($_POST['id'] ?? 0);

    $stmt = baza()->prepare('SELECT zdjecie FROM zawodnicy WHERE id = ?');
    $stmt->execute([$id]);
    $zawodnik = $stmt->fetch();

    if ($zawodnik) {
        usun_zdjecie($zawodnik['zdjecie'], 'zawodnicy');
        baza()->prepare('DELETE FROM zawodnicy WHERE id = ?')->execute([$id]);
        komunikat('Zawodnik usunięty.');
    }

    przekieruj('zawodnicy.php');
}

$zawodnicy = baza()->query(
    'SELECT * FROM zawodnicy
     ORDER BY aktywny DESC,
              CASE WHEN numer IS NULL THEN 1 ELSE 0 END, numer,
              nazwisko'
)->fetchAll();

naglowek('Zawodnicy');
?>

<div class="naglowek-sekcji">
    <h1>Zawodnicy</h1>
    <a class="btn" href="zawodnik.php">Dodaj zawodnika</a>
</div>

<?php if (!$zawodnicy): ?>
    <p class="pusto">Baza jest pusta. Zacznij od dodania pierwszego zawodnika.</p>
<?php else: ?>

<table class="tabela">
    <thead>
        <tr>
            <th>Zdjęcie</th>
            <th>Nr</th>
            <th>Imię i nazwisko</th>
            <th>Pozycja</th>
            <th>Status</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($zawodnicy as $z): ?>
        <?php $foto = adres_zdjecia($z['zdjecie'], 'zawodnicy'); ?>
        <tr<?= $z['aktywny'] ? '' : ' class="nieaktywny"' ?>>
            <td>
                <?php if ($foto): ?>
                    <img class="miniatura" src="../<?= e($foto) ?>" alt="">
                <?php else: ?>
                    <span class="miniatura miniatura--brak"><?= e(mb_substr($z['imie'], 0, 1)) ?></span>
                <?php endif; ?>
            </td>
            <td class="nr"><?= $z['numer'] !== null ? (int) $z['numer'] : '—' ?></td>
            <td><strong><?= e($z['imie']) ?> <?= e($z['nazwisko']) ?></strong></td>
            <td><?= e($z['pozycja']) ?></td>
            <td><?= $z['aktywny'] ? 'w kadrze' : 'poza kadrą' ?></td>
            <td class="akcje">
                <a class="btn btn--maly" href="zawodnik.php?id=<?= (int) $z['id'] ?>">Edytuj</a>

                <form method="post" onsubmit="return confirm('Usunąć <?= e($z['imie'] . ' ' . $z['nazwisko']) ?> z bazy? Tej operacji nie da się cofnąć.');">
                    <?= pole_csrf() ?>
                    <input type="hidden" name="akcja" value="usun">
                    <input type="hidden" name="id" value="<?= (int) $z['id'] ?>">
                    <button class="btn btn--maly btn--kasuj" type="submit">Usuń</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php endif; ?>

<?php stopka(); ?>
