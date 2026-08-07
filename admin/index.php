<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/layout.php';

wymagaj_logowania();

$db = baza();

$zawodnikow = (int) $db->query('SELECT COUNT(*) FROM zawodnicy WHERE aktywny = 1')->fetchColumn();
$postow     = (int) $db->query('SELECT COUNT(*) FROM posty WHERE widoczny = 1')->fetchColumn();

$plik_json = katalog_strony() . '/data/zawodnicy.json';
$eksport = is_file($plik_json) ? date('d.m.Y, H:i', filemtime($plik_json)) : null;

$plik_fb = katalog_strony() . '/data/facebook.json';
$fb_sprawdzono = is_file($plik_fb) ? date('d.m.Y, H:i', filemtime($plik_fb)) : null;

naglowek('Pulpit');
?>

<h1>Pulpit</h1>

<div class="kafle">
    <a class="kafel" href="zawodnicy.php">
        <strong><?= $zawodnikow ?></strong>
        <span>zawodników w kadrze</span>
    </a>

    <a class="kafel" href="posty.php">
        <strong><?= $postow ?></strong>
        <span>opublikowanych aktualności</span>
    </a>
</div>

<section class="karta">
    <h2>Publikacja na stronie</h2>
    <p>
        Strona klubu jest statyczna — czyta gotowe pliki JSON, nie łączy się z bazą.
        Po zmianach w kadrze albo aktualnościach wyślij dane na stronę.
    </p>
    <p class="drobne">
        Ostatnia publikacja: <?= $eksport ? e($eksport) : 'jeszcze nie było' ?>
    </p>

    <form method="post" action="eksport.php">
        <?= pole_csrf() ?>
        <button class="btn" type="submit">Opublikuj na stronie</button>
    </form>
</section>

<section class="karta">
    <h2>Nowe posty z Facebooka</h2>
    <p>
        Sprawdza teraz, na żądanie, czy na stronie klubu na Facebooku pojawiło się
        coś nowego — bez czekania na codzienny automat.
    </p>
    <p class="drobne">
        Ostatnie sprawdzenie: <?= $fb_sprawdzono ? e($fb_sprawdzono) : 'jeszcze nie było' ?>
    </p>

    <form method="post" action="facebook.php">
        <?= pole_csrf() ?>
        <button class="btn" type="submit">Sprawdź Facebooka teraz</button>
    </form>
</section>

<?php stopka(); ?>
