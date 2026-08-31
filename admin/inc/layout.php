<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function naglowek(string $tytul, bool $zNawigacja = true): void
{
    $k = komunikat();
    ?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($tytul) ?> — panel Janovii</title>
    <link rel="icon" type="image/png" sizes="32x32" href="../png/favicon-32.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700;800&display=swap">
    <link rel="stylesheet" href="style.css?v=4">
</head>
<body>

<?php if ($zNawigacja): ?>
<header class="pasek">
    <a class="pasek__logo" href="index.php">
        <img src="../png/JanoviaJanowiec.png" alt="">
        <span>Panel administratora</span>
    </a>

    <nav class="pasek__nav">
        <a href="zawodnicy.php">Zawodnicy</a>
        <a href="posty.php">Aktualności</a>
        <a href="mecze.php">Mecze</a>
        <a href="sponsorzy.php">Sponsorzy</a>
        <a href="../index.html" target="_blank" rel="noopener">Strona &nearr;</a>
    </nav>

    <form class="pasek__wyloguj" method="post" action="logout.php">
        <?= pole_csrf() ?>
        <span><?= e($_SESSION['admin_login'] ?? '') ?></span>
        <button type="submit">Wyloguj</button>
    </form>
</header>
<?php endif; ?>

<main class="tresc">
<?php if ($k): ?>
    <p class="komunikat komunikat--<?= e($k['rodzaj']) ?>"><?= e($k['tresc']) ?></p>
<?php endif; ?>
<?php
}

function stopka(): void
{
    ?>
</main>
</body>
</html>
<?php
}
