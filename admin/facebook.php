<?php
/**
 * Ręczne sprawdzenie nowych postów na Facebooku, na żądanie z panelu —
 * zamiast czekać na codzienny automat w GitHub Actions.
 *
 * Uruchamia lokalnie ten sam skrypt co workflow (update_fb.py), z tokenem
 * wziętym z admin/inc/config.php (osobna kopia od sekretu na GitHubie —
 * ten sam token trzeba wkleić w oba miejsca).
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/auth.php';

wymagaj_logowania();
sprawdz_csrf();

$config = konfiguracja();
$token = trim((string) ($config['fb_token'] ?? ''));

if ($token === '') {
    komunikat(
        'Brak tokenu Facebooka. Dodaj "fb_token" (i opcjonalnie "fb_page_id") ' .
        'w admin/inc/config.php — ten sam token, którego używasz w sekrecie FB_TOKEN na GitHubie.',
        'blad'
    );
    przekieruj('index.php');
}

if (!function_exists('shell_exec')) {
    komunikat('Funkcja shell_exec jest wyłączona na tym serwerze — nie da się stąd uruchomić skryptu.', 'blad');
    przekieruj('index.php');
}

$env = 'FB_TOKEN=' . escapeshellarg($token);

if (!empty($config['fb_page_id'])) {
    $env .= ' FB_PAGE_ID=' . escapeshellarg((string) $config['fb_page_id']);
}

$polecenie = sprintf(
    'cd %s && %s python3 update_fb.py 2>&1',
    escapeshellarg(katalog_strony()),
    $env
);

$wyjscie = trim((string) shell_exec($polecenie));
$ostatnia_linia = trim((string) strrchr("\n" . $wyjscie, "\n"));

if ($wyjscie !== '' && stripos($wyjscie, 'Zapisano') !== false) {
    komunikat('Sprawdzono Facebooka — ' . $ostatnia_linia);
} else {
    komunikat(
        'Facebook nie odpowiedział poprawnie: ' . ($ostatnia_linia !== '' ? $ostatnia_linia : '(brak odpowiedzi skryptu)'),
        'blad'
    );
}

przekieruj('index.php');
