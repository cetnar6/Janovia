<?php
/**
 * Ręczne sprawdzenie nowych postów na Facebooku, na żądanie z panelu.
 *
 * Wcześniej uruchamiało to skrypt w Pythonie przez shell_exec, czyli działało
 * wyłącznie na maszynie z Pythonem. Teraz woła aktualizuj_facebooka()
 * z update_fb.php — więc chodzi też na współdzielonym hostingu.
 *
 * Token bierze się z admin/inc/config.php (osobna kopia od sekretu na
 * GitHubie — ten sam token trzeba wkleić w oba miejsca).
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/auth.php';
require_once katalog_strony() . '/update_fb.php';

wymagaj_logowania();
sprawdz_csrf();

$config = konfiguracja();

if (trim((string) ($config['fb_token'] ?? '')) === '') {
    komunikat(
        'Brak tokenu Facebooka. Dodaj "fb_token" (i opcjonalnie "fb_page_id") ' .
        'w admin/inc/config.php — ten sam token, którego używasz w sekrecie FB_TOKEN na GitHubie.',
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
