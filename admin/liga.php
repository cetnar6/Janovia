<?php
/**
 * Ręczne odświeżenie tabeli i terminarza z 90minut.pl, na żądanie z panelu —
 * zamiast czekać na codzienny automat w GitHub Actions.
 *
 * Uruchamia lokalnie ten sam skrypt co workflow (update_liga.py). Nie
 * wymaga żadnego tokenu — 90minut.pl jest publiczne.
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/auth.php';

wymagaj_logowania();
sprawdz_csrf();

if (!function_exists('shell_exec')) {
    komunikat('Funkcja shell_exec jest wyłączona na tym serwerze — nie da się stąd uruchomić skryptu.', 'blad');
    przekieruj('index.php');
}

$polecenie = sprintf(
    'cd %s && python3 update_liga.py 2>&1',
    escapeshellarg(katalog_strony())
);

$wyjscie = trim((string) shell_exec($polecenie));
$ostatnia_linia = trim((string) strrchr("\n" . $wyjscie, "\n"));

if ($wyjscie !== '' && stripos($wyjscie, 'Zapisano') !== false) {
    komunikat('Odświeżono dane z 90minut.pl — ' . $ostatnia_linia);
} else {
    komunikat(
        '90minut.pl nie odpowiedziało poprawnie: ' . ($ostatnia_linia !== '' ? $ostatnia_linia : '(brak odpowiedzi skryptu)'),
        'blad'
    );
}

przekieruj('index.php');
