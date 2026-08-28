<?php
/**
 * Ręczne odświeżenie tabeli i terminarza z 90minut.pl, na żądanie z panelu.
 *
 * Wcześniej uruchamiało to skrypt w Pythonie przez shell_exec, czyli działało
 * wyłącznie na maszynie z Pythonem. Teraz woła aktualizuj_lige() z pliku
 * update_liga.php — więc chodzi też na współdzielonym hostingu, gdzie jest
 * samo PHP. 90minut.pl jest publiczne, żaden token nie jest potrzebny.
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/auth.php';
require_once katalog_strony() . '/update_liga.php';

wymagaj_logowania();
sprawdz_csrf();

try {
    $wynik = aktualizuj_lige();

    komunikat(sprintf(
        'Odświeżono dane z 90minut.pl: %d zespołów w tabeli, %d meczów, %d nadchodzących.',
        $wynik['tabela'],
        $wynik['mecze'],
        $wynik['nadchodzace']
    ));
} catch (Throwable $e) {
    komunikat('Nie udało się odświeżyć danych: ' . $e->getMessage(), 'blad');
}

przekieruj('index.php');
