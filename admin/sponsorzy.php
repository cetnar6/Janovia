<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/zdjecia.php';

wymagaj_logowania();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['akcja'] ?? '') === 'usun') {
    sprawdz_csrf();

    $id = (int) ($_POST['id'] ?? 0);

    $stmt = baza()->prepare('SELECT logo FROM sponsorzy WHERE id = ?');
    $stmt->execute([$id]);

    if ($sponsor = $stmt->fetch()) {
        usun_zdjecie($sponsor['logo'], 'sponsorzy');
        baza()->prepare('DELETE FROM sponsorzy WHERE id = ?')->execute([$id]);
        komunikat('Sponsor usunięty.');
    }

    przekieruj('sponsorzy.php');
}

$sponsorzy = baza()
    ->query('SELECT * FROM sponsorzy ORDER BY kolejnosc, nazwa')
    ->fetchAll();

naglowek('Sponsorzy');
?>

<div class="naglowek-sekcji">
    <h1>Sponsorzy</h1>
    <a class="btn" href="sponsor.php">Dodaj sponsora</a>
</div>

<p class="drobne">
    Logotypy z przewijającego się paska na stronie głównej. Pasek barwi je
    na złoto, więc liczy się wyłącznie kształt logo — dlatego potrzebny jest
    PNG z przezroczystym tłem. Kolejność ustawiasz liczbą: im mniejsza,
    tym wcześniej logo pojawia się w pasku.
</p>

<?php if (!$sponsorzy): ?>
    <p class="pusto">
        Nie ma jeszcze żadnego sponsora. Dopóki lista jest pusta, na stronie
        zostaje pasek wpisany na sztywno w index.html.
    </p>
<?php else: ?>

<table class="tabela">
    <thead>
        <tr>
            <th>Logo</th>
            <th>Nazwa</th>
            <th>Rola</th>
            <th>Kolejność</th>
            <th>Poświata</th>
            <th>Status</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($sponsorzy as $s): ?>
        <?php $logo = adres_zdjecia($s['logo'], 'sponsorzy'); ?>
        <tr<?= $s['widoczny'] ? '' : ' class="nieaktywny"' ?>>
            <td>
                <?php if ($logo): ?>
                    <img class="miniatura" src="../<?= e($logo) ?>" alt="">
                <?php else: ?>
                    <span class="miniatura miniatura--brak">—</span>
                <?php endif; ?>
            </td>
            <td><strong><?= e($s['nazwa']) ?></strong></td>
            <td><?= e($s['rola']) ?></td>
            <td><?= (int) $s['kolejnosc'] ?></td>
            <td><?= $s['poswiata'] ? 'tak' : '—' ?></td>
            <td><?= $s['widoczny'] ? 'widoczny' : 'ukryty' ?></td>
            <td class="akcje">
                <a class="btn btn--maly" href="sponsor.php?id=<?= (int) $s['id'] ?>">Edytuj</a>

                <form method="post" onsubmit="return confirm('Usunąć tego sponsora? Tej operacji nie da się cofnąć.');">
                    <?= pole_csrf() ?>
                    <input type="hidden" name="akcja" value="usun">
                    <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                    <button class="btn btn--maly btn--kasuj" type="submit">Usuń</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php endif; ?>

<?php stopka(); ?>
