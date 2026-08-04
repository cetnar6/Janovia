<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/layout.php';

if (zalogowany()) {
    przekieruj('index.php');
}

$blad = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sprawdz_csrf();

    $login = trim((string) ($_POST['login'] ?? ''));
    $haslo = (string) ($_POST['haslo'] ?? '');

    $stmt = baza()->prepare('SELECT id, login, hash_hasla FROM administratorzy WHERE login = ?');
    $stmt->execute([$login]);
    $admin = $stmt->fetch();

    // password_verify porównuje w stałym czasie; komunikat celowo nie zdradza,
    // czy pomylono login czy hasło
    if ($admin && password_verify($haslo, $admin['hash_hasla'])) {
        zaloguj((int) $admin['id'], $admin['login']);

        baza()->prepare('UPDATE administratorzy SET ostatnie_logowanie = ? WHERE id = ?')
              ->execute([date('Y-m-d H:i:s'), $admin['id']]);

        przekieruj('index.php');
    }

    $blad = 'Nieprawidłowy login lub hasło.';
    usleep(400000);   // spowalnia zgadywanie hasła
}

naglowek('Logowanie', false);
?>

<div class="logowanie">
    <img class="logowanie__herb" src="../png/JanoviaJanowiec.png" alt="Herb Janovii Janowiec">
    <h1>Panel administratora</h1>

    <?php if ($blad): ?>
        <p class="komunikat komunikat--blad"><?= e($blad) ?></p>
    <?php endif; ?>

    <form method="post">
        <?= pole_csrf() ?>

        <label>Login
            <input type="text" name="login" required autofocus autocomplete="username">
        </label>

        <label>Hasło
            <input type="password" name="haslo" required autocomplete="current-password">
        </label>

        <button class="btn" type="submit">Zaloguj</button>
    </form>
</div>

<?php stopka(); ?>
