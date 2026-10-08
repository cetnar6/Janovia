<?php
/**
 * Ręczne sprawdzenie nowych postów na Facebooku, na żądanie z panelu.
 *
 * Wcześniej uruchamiało to skrypt w Pythonie przez shell_exec, czyli działało
 * wyłącznie na maszynie z Pythonem. Teraz woła aktualizuj_facebooka()
 * z update_fb.php — więc chodzi też na współdzielonym hostingu.
 *
 * Token rozwiązuje fb_token() z update_fb.php — na serwerze przyjeżdża
 * w admin/inc/fb-token.php z sekretu FB_TOKEN przy wdrożeniu.
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/auth.php';
require_once katalog_strony() . '/update_fb.php';

wymagaj_logowania();
sprawdz_csrf();

/* Pytamy fb_token(), a nie samego configu: na serwerze token przyjeżdża
   w admin/inc/fb-token.php z sekretu FB_TOKEN, a config.php bywa tam pusty
   albo nieaktualny. Sprawdzanie wyłącznie configu dawało „brak tokenu"
   mimo poprawnie wdrożonego tokenu. */
if (fb_token() === '') {
    komunikat(
        'Brak tokenu Facebooka. Ustaw sekret FB_TOKEN na GitHubie i uruchom ' .
        'wdrożenie — token trafi na serwer jako admin/inc/fb-token.php.',
        'blad'
    );
    przekieruj('index.php');
}

try {
    $wynik = aktualizuj_facebooka();

    $tresc = sprintf(
        'Sprawdzono Facebooka: %d postów, %d zdjęć.',
        $wynik['posty'],
        $wynik['zdjecia']
    );

    if ($wynik['usuniete'] > 0) {
        $tresc .= sprintf(' Usunięto %d nieużywanych zdjęć.', $wynik['usuniete']);
    }

    // nieudane pobrania pojedynczych zdjęć nie przerywają całości,
    // ale warto o nich wiedzieć
    if ($wynik['bledy']) {
        $tresc .= ' Uwaga: ' . count($wynik['bledy']) . ' zdjęć się nie pobrało.';
    }

    komunikat($tresc);
} catch (Throwable $e) {
    komunikat('Nie udało się pobrać postów: ' . $e->getMessage(), 'blad');
}

przekieruj('index.php');
