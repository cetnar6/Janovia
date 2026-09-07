<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/migracje.php';

wymagaj_logowania();

$db = baza();

$zawodnikow = (int) $db->query('SELECT COUNT(*) FROM zawodnicy WHERE aktywny = 1')->fetchColumn();
$postow     = (int) $db->query('SELECT COUNT(*) FROM posty WHERE widoczny = 1')->fetchColumn();
$meczow     = (int) $db->query('SELECT COUNT(*) FROM mecze WHERE widoczny = 1')->fetchColumn();

$plik_json = katalog_strony() . '/data/zawodnicy.json';
$eksport = is_file($plik_json) ? date('d.m.Y, H:i', filemtime($plik_json)) : null;

$plik_fb = katalog_strony() . '/data/facebook.json';
$fb_sprawdzono = is_file($plik_fb) ? date('d.m.Y, H:i', filemtime($plik_fb)) : null;

$plik_liga = katalog_strony() . '/data/liga.json';
$liga_sprawdzono = is_file($plik_liga) ? date('d.m.Y, H:i', filemtime($plik_liga)) : null;

/* Zaległa migracja nie daje o sobie znać sama: MySQL w trybie nieścisłym
   przyjmuje zapis i tylko czyści wartość, której nie zna. Dlatego pulpit
   pyta o to przy każdym wejściu — inaczej dowiadujemy się dopiero po tym,
   jak zawodnik pojawi się na stronie bez pozycji. */
$zalegle_migracje = migracje_zalegle();
$bez_pozycji = zawodnicy_bez_pozycji();

naglowek('Pulpit');
?>

<h1>Pulpit</h1>

<?php if ($zalegle_migracje || $bez_pozycji): ?>
    <section class="karta">
        <h2>Baza wymaga aktualizacji</h2>
        <p>
            <?php if ($zalegle_migracje): ?>
                Schemat bazy jest starszy niż panel. Do czasu aktualizacji część
                zapisów kończy się po cichu pustą wartością zamiast błędu.
            <?php else: ?>
                W bazie są wpisy z pustą pozycją — zostały po zapisie sprzed aktualizacji.
            <?php endif; ?>
        </p>
        <a class="btn" href="migracje.php">Zobacz, co jest do zrobienia</a>
    </section>
<?php endif; ?>

<div class="kafle">
    <a class="kafel" href="zawodnicy.php">
        <strong><?= $zawodnikow ?></strong>
        <span>zawodników w kadrze</span>
    </a>

    <a class="kafel" href="posty.php">
        <strong><?= $postow ?></strong>
        <span>opublikowanych aktualności</span>
    </a>

    <a class="kafel" href="mecze.php">
        <strong><?= $meczow ?></strong>
        <span>ręcznie dodanych meczów</span>
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

<section class="karta">
    <h2>Tabela i terminarz z 90minut.pl</h2>
    <p>
        Sprawdza teraz, na żądanie, aktualną tabelę i terminarz ligowy —
        bez czekania na codzienny automat.
    </p>
    <p class="drobne">
        Ostatnie sprawdzenie: <?= $liga_sprawdzono ? e($liga_sprawdzono) : 'jeszcze nie było' ?>
    </p>

    <form method="post" action="liga.php">
        <?= pole_csrf() ?>
        <button class="btn" type="submit">Odśwież dane z 90minut.pl teraz</button>
    </form>
</section>

<?php stopka(); ?>
