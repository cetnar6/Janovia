<?php
/**
 * Sesja administratora, ochrona przed CSRF i pomocnicze funkcje widoku.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function start_sesji(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_set_cookie_params([
        'httponly' => true,          // ciasteczka niedostępne dla JavaScriptu
        'samesite' => 'Strict',      // nie wysyłają się przy żądaniach z obcych stron
    ]);

    session_start();
}

function zalogowany(): bool
{
    start_sesji();
    return isset($_SESSION['admin_id']);
}

function wymagaj_logowania(): void
{
    if (!zalogowany()) {
        header('Location: login.php');
        exit;
    }
}

function zaloguj(int $id, string $login): void
{
    start_sesji();
    // nowy identyfikator sesji po zalogowaniu — utrudnia przejęcie sesji
    session_regenerate_id(true);

    $_SESSION['admin_id'] = $id;
    $_SESSION['admin_login'] = $login;
}

function wyloguj(): void
{
    start_sesji();
    $_SESSION = [];
    session_destroy();
}

/* ---------- CSRF ---------- */

function token_csrf(): string
{
    start_sesji();

    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf'];
}

function pole_csrf(): string
{
    return '<input type="hidden" name="csrf" value="' . e(token_csrf()) . '">';
}

/** Przerywa żądanie POST, które nie pochodzi z formularza tego panelu. */
function sprawdz_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    start_sesji();

    $przyslany = $_POST['csrf'] ?? '';

    if (!is_string($przyslany) || !hash_equals($_SESSION['csrf'] ?? '', $przyslany)) {
        http_response_code(400);
        exit('Nieprawidłowy token formularza. Odśwież stronę i spróbuj ponownie.');
    }
}

/* ---------- widok ---------- */

/** Skrót od htmlspecialchars — każdy tekst z bazy przechodzi przez to przy wypisywaniu. */
function e(?string $tekst): string
{
    return htmlspecialchars((string) $tekst, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Jednorazowy komunikat przenoszony między przekierowaniami. */
function komunikat(?string $tresc = null, string $rodzaj = 'ok'): ?array
{
    start_sesji();

    if ($tresc !== null) {
        $_SESSION['komunikat'] = ['tresc' => $tresc, 'rodzaj' => $rodzaj];
        return null;
    }

    $k = $_SESSION['komunikat'] ?? null;
    unset($_SESSION['komunikat']);

    return $k;
}

function przekieruj(string $adres): void
{
    header('Location: ' . $adres);
    exit;
}
