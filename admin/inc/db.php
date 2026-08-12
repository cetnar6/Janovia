<?php
/**
 * Połączenie z bazą przez PDO. Ten sam kod obsługuje SQLite (praca lokalna)
 * i MySQL (hosting) — różnicę widać wyłącznie w DSN poniżej.
 */

declare(strict_types=1);

// bez tego PHP liczy godziny w UTC, a strona pokazuje je w czasie polskim —
// mecz wpisany w panelu na 11:00 wychodził na stronie jako 13:00
date_default_timezone_set('Europe/Warsaw');

function konfiguracja(): array
{
    static $config = null;

    if ($config === null) {
        $plik = __DIR__ . '/config.php';

        if (!is_file($plik)) {
            exit('Brak admin/inc/config.php — skopiuj config.przyklad.php i uzupełnij.');
        }

        $config = require $plik;
    }

    return $config;
}

function baza(): PDO
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $c = konfiguracja();

    $opcje = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        // zapytania idą do bazy jako prawdziwe prepared statements,
        // nie jako sklejony tekst — to blokuje SQL injection
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    if ($c['sterownik'] === 'sqlite') {
        $katalog = dirname($c['sqlite_plik']);

        if (!is_dir($katalog)) {
            mkdir($katalog, 0775, true);
        }

        $pdo = new PDO('sqlite:' . $c['sqlite_plik'], null, null, $opcje);
        $pdo->exec('PRAGMA foreign_keys = ON');
    } else {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            $c['mysql_host'],
            $c['mysql_baza']
        );

        $pdo = new PDO($dsn, $c['mysql_uzytkownik'], $c['mysql_haslo'], $opcje);
    }

    return $pdo;
}

/** Katalog główny strony (tam, gdzie index.html). */
function katalog_strony(): string
{
    return dirname(__DIR__, 2);
}
