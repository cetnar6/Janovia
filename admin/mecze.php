<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/zdjecia.php';

wymagaj_logowania();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['akcja'] ?? '') === 'usun') {
    sprawdz_csrf();

    $id = (int) ($_POST['id'] ?? 0);

    $stmt = baza()->prepare('SELECT herb FROM mecze WHERE id = ?');
    $stmt->execute([$id]);

    if ($mecz = $stmt->fetch()) {
        usun_zdjecie($mecz['herb'], 'mecze');
        baza()->prepare('DELETE FROM mecze WHERE id = ?')->execute([$id]);
        komunikat('Mecz usunięty.');
    }

    przekieruj('mecze.php');
}

$mecze = baza()->query('SELECT * FROM mecze ORDER BY termin DESC')->fetchAll();

naglowek('Mecze');
?>

<div class="naglowek-sekcji">
    <h1>Mecze</h1>
    <a class="btn" href="mecz.php">Dodaj mecz</a>
</div>

<p class="drobne">
    Mecze spoza 90minut.pl — sparingi, turnieje, cokolwiek, czego automat
    z ligi nie widzi. Pojawiają się na stronie razem z terminarzem ligowym,
    posortowane po dacie, dopóki nie wpiszesz wyniku.
</p>

<?php if (!$mecze): ?>
    <p class="pusto">Nie ma jeszcze żadnych ręcznie dodanych meczów.</p>
<?php else: ?>

<table class="tabela">
    <thead>
        <tr>
            <th>Herb</th>
            <th>Termin</th>
            <th>Mecz</th>
            <th>Typ</th>
            <th>Wynik</th>
            <th>Status</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($mecze as $m): ?>
        <?php
            $gospodarz = $m['u_siebie'] ? 'Janovia Janowiec' : $m['przeciwnik'];
            $gosc      = $m['u_siebie'] ? $m['przeciwnik'] : 'Janovia Janowiec';
            $herb      = adres_zdjecia($m['herb'], 'mecze');
        ?>
        <tr<?= $m['widoczny'] ? '' : ' class="nieaktywny"' ?>>
            <td>
                <?php if ($herb): ?>
                    <img class="miniatura" src="../<?= e($herb) ?>" alt="">
                <?php else: ?>
                    <span class="miniatura miniatura--brak">—</span>
                <?php endif; ?>
            </td>
            <td><?= e(date('d.m.Y, H:i', strtotime($m['termin']))) ?></td>
            <td><strong><?= e($gospodarz) ?> – <?= e($gosc) ?></strong></td>
            <td><?= e($m['etykieta']) ?></td>
            <td><?= $m['wynik'] ? e($m['wynik']) : '—' ?></td>
            <td><?= $m['widoczny'] ? 'widoczny' : 'ukryty' ?></td>
            <td class="akcje">
                <a class="btn btn--maly" href="mecz.php?id=<?= (int) $m['id'] ?>">Edytuj</a>

                <form method="post" onsubmit="return confirm('Usunąć ten mecz? Tej operacji nie da się cofnąć.');">
                    <?= pole_csrf() ?>
                    <input type="hidden" name="akcja" value="usun">
                    <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                    <button class="btn btn--maly btn--kasuj" type="submit">Usuń</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php endif; ?>

<?php stopka(); ?>
