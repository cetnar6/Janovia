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

/* ---------- role ---------- */

const ROLA_ADMIN  = 'administrator';
const ROLA_TRENER = 'trener';

/* Konto do obsługi przebiegu meczu: wynik, strzelcy, zmiany, stoper.
   Takich kont bywa kilka — ktokolwiek akurat siedzi przy linii bocznej
   z telefonem — i żadne z nich nie ma po co widzieć kadry ani aktualności. */
const ROLA_WYNIK = 'wynik';

const ROLE = [ROLA_ADMIN, ROLA_TRENER, ROLA_WYNIK];

/**
 * Rola zalogowanego konta, czytana z bazy — nie z sesji.
 *
 * Sesja byłaby szybsza, ale zapamiętywałaby rolę z chwili logowania:
 * odebranie komuś uprawnień zaczęłoby działać dopiero po jego wylogowaniu.
 * Przy jednym zapytaniu na żądanie nie ma o co kruszyć kopii.
 */
function rola_uzytkownika(): ?string
{
    static $rola = null;

    if ($rola !== null) {
        return $rola;
    }

    if (!zalogowany()) {
        return null;
    }

    try {
        $stmt = baza()->prepare('SELECT rola FROM administratorzy WHERE id = ?');
        $stmt->execute([$_SESSION['admin_id']]);
        $z_bazy = $stmt->fetchColumn();
    } catch (PDOException $e) {
        /* Kolumny jeszcze nie ma — migracja „role" nie przeszła. Do tego czasu
           wszyscy są administratorami, czyli dokładnie tak, jak panel działał
           przed wprowadzeniem ról. Odwrotne założenie zamknęłoby Karolowi
           drzwi do migracji, którą trzeba uruchomić właśnie z panelu. */
        return $rola = ROLA_ADMIN;
    }

    /* Nieznana wartość (stara baza, ręczna poprawka w phpMyAdmin) to
       administrator — tak jak panel działał, zanim role w ogóle powstały. */
    $rola = in_array($z_bazy, ROLE, true) ? (string) $z_bazy : ROLA_ADMIN;

    return $rola;
}

/**
 * Ekran, na który trafia dane konto po zalogowaniu i zawsze, gdy zabłądzi
 * tam, gdzie nie ma wstępu. Jedno miejsce, bo inaczej każda nowa rola
 * wymagałaby poprawki w logowaniu, na pulpicie i przy każdej odmowie.
 */
function strona_startowa(): string
{
    if (czy_trener()) {
        return 'sklad.php';
    }

    return czy_wynik() ? 'przebieg.php' : 'index.php';
}

function czy_administrator(): bool
{
    return rola_uzytkownika() === ROLA_ADMIN;
}

function czy_trener(): bool
{
    return rola_uzytkownika() === ROLA_TRENER;
}

function czy_wynik(): bool
{
    return rola_uzytkownika() === ROLA_WYNIK;
}

/**
 * Wpuszcza wyłącznie administratora. Reszta trafia na swój ekran
 * z wyjaśnieniem — cicha przekierówka na stronę logowania wyglądałaby jak
 * awaria, a 403 bez wyjścia jak ślepy zaułek.
 */
function wymagaj_administratora(): void
{
    wymagaj_logowania();

    if (czy_administrator()) {
        return;
    }

    komunikat('Ta część panelu jest dostępna tylko dla administratora.', 'blad');
    przekieruj(strona_startowa());
}

/** Skład układa trener i administrator — obsługa wyniku nie ma tam czego szukać. */
function wymagaj_dostepu_do_skladu(): void
{
    wymagaj_logowania();

    if (!czy_wynik()) {
        return;
    }

    komunikat('To konto obsługuje wyłącznie przebieg meczu.', 'blad');
    przekieruj('przebieg.php');
}

/** Przebieg meczu prowadzi każdy, kto w ogóle wszedł do panelu. */
function wymagaj_dostepu_do_przebiegu(): void
{
    wymagaj_logowania();
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
