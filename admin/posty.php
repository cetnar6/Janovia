<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/zdjecia.php';

wymagaj_logowania();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['akcja'] ?? '') === 'usun') {
    sprawdz_csrf();

    $id = (int) ($_POST['id'] ?? 0);

    $stmt = baza()->prepare('SELECT zdjecie FROM posty WHERE id = ?');
    $stmt->execute([$id]);

    if ($post = $stmt->fetch()) {
        usun_zdjecie($post['zdjecie'], 'posty');
        baza()->prepare('DELETE FROM posty WHERE id = ?')->execute([$id]);
        komunikat('Aktualność usunięta.');
    }

    przekieruj('posty.php');
}

$posty = baza()->query('SELECT * FROM posty ORDER BY opublikowano DESC')->fetchAll();

naglowek('Aktualności');
?>

<div class="naglowek-sekcji">
    <h1>Aktualności</h1>
    <a class="btn" href="post.php">Napisz aktualność</a>
</div>

<p class="drobne">
    Te wpisy pojawiają się na stronie razem z postami pobranymi z Facebooka,
    posortowane według daty publikacji.
</p>

<?php if (!$posty): ?>
    <p class="pusto">Nie ma jeszcze żadnych własnych aktualności.</p>
<?php else: ?>

<table class="tabela">
    <thead>
        <tr>
            <th>Zdjęcie</th>
            <th>Tytuł</th>
            <th>Etykieta</th>
            <th>Publikacja</th>
            <th>Status</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($posty as $p): ?>
        <?php $foto = adres_zdjecia($p['zdjecie'], 'posty'); ?>
        <tr<?= $p['widoczny'] ? '' : ' class="nieaktywny"' ?>>
            <td>
                <?php if ($foto): ?>
                    <img class="miniatura miniatura--szeroka" src="../<?= e($foto) ?>" alt="">
                <?php else: ?>
                    <span class="miniatura miniatura--brak">—</span>
                <?php endif; ?>
            </td>
            <td><strong><?= e($p['tytul']) ?></strong></td>
            <td><?= e($p['etykieta']) ?></td>
            <td><?= e(date('d.m.Y, H:i', strtotime($p['opublikowano']))) ?></td>
            <td><?= $p['widoczny'] ? 'widoczna' : 'ukryta' ?></td>
            <td class="akcje">
                <a class="btn btn--maly" href="post.php?id=<?= (int) $p['id'] ?>">Edytuj</a>

                <form method="post" onsubmit="return confirm('Usunąć tę aktualność? Tej operacji nie da się cofnąć.');">
                    <?= pole_csrf() ?>
                    <input type="hidden" name="akcja" value="usun">
                    <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                    <button class="btn btn--maly btn--kasuj" type="submit">Usuń</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php endif; ?>

<?php stopka(); ?>
