<?php
/**
 * Wzór konfiguracji. Skopiuj do config.php i dostosuj:
 *     cp admin/inc/config.przyklad.php admin/inc/config.php
 *
 * config.php jest w .gitignore, żeby dane dostępowe nie trafiły do repozytorium.
 */

return [
    // 'sqlite' na komputerze, 'mysql' na hostingu
    'sterownik' => 'sqlite',

    // --- SQLite ---
    'sqlite_plik' => __DIR__ . '/../../dane/janovia.db',

    // --- MySQL (używane, gdy sterownik = 'mysql') ---
    'mysql_host'  => 'localhost',
    'mysql_baza'  => 'janovia',
    'mysql_uzytkownik' => 'root',
    'mysql_haslo' => '',

    // katalog na wgrywane zdjęcia, liczony od katalogu głównego strony
    'katalog_uploadow' => 'uploads',

    // maksymalny rozmiar wgrywanego zdjęcia w bajtach (5 MB)
    'max_rozmiar_zdjecia' => 5 * 1024 * 1024,

    // --- Facebook (przycisk "Sprawdź Facebooka teraz" w panelu) ---
    // ten sam token, co w sekrecie FB_TOKEN na GitHubie — panel uruchamia
    // update_fb.php, więc potrzebuje własnej kopii tokenu
    'fb_token' => '',

    // opcjonalne — wskazuje jedną, konkretną stronę, gdy token zarządza kilkoma
    'fb_page_id' => '',
];
