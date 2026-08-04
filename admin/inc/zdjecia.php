<?php
/**
 * Wgrywanie zdjęć.
 *
 * W bazie zapisujemy wyłącznie nazwę pliku — obraz leży w uploads/.
 * Nazwę nadajemy sami i wymuszamy rozszerzenie z wykrytego typu MIME,
 * żeby nikt nie wgrał pliku wykonywalnego pod nazwą zdjęcia.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

const DOZWOLONE_TYPY = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];

function katalog_zdjec(string $podkatalog): string
{
    $c = konfiguracja();
    $sciezka = katalog_strony() . '/' . $c['katalog_uploadow'] . '/' . $podkatalog;

    if (!is_dir($sciezka)) {
        mkdir($sciezka, 0775, true);
    }

    return $sciezka;
}

/**
 * Przyjmuje wpis z $_FILES. Zwraca nazwę zapisanego pliku,
 * null gdy nic nie wgrano, albo rzuca RuntimeException przy błędzie.
 */
function zapisz_zdjecie(array $plik, string $podkatalog): ?string
{
    if (($plik['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ($plik['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Wgrywanie nie powiodło się (kod ' . $plik['error'] . ').');
    }

    $c = konfiguracja();

    if ($plik['size'] > $c['max_rozmiar_zdjecia']) {
        throw new RuntimeException(sprintf(
            'Zdjęcie jest za duże (%.1f MB). Limit to %.0f MB.',
            $plik['size'] / 1048576,
            $c['max_rozmiar_zdjecia'] / 1048576
        ));
    }

    // typ czytamy z zawartości pliku, nie z nazwy ani nagłówka przeglądarki
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $typ = $finfo->file($plik['tmp_name']);

    if (!isset(DOZWOLONE_TYPY[$typ])) {
        throw new RuntimeException('Dozwolone formaty to JPG, PNG i WEBP (wykryto: ' . $typ . ').');
    }

    // dodatkowa kontrola: czy to faktycznie obraz o sensownych wymiarach
    $wymiary = getimagesize($plik['tmp_name']);

    if ($wymiary === false || $wymiary[0] < 10 || $wymiary[1] < 10) {
        throw new RuntimeException('Plik nie wygląda na poprawny obraz.');
    }

    $nazwa = bin2hex(random_bytes(8)) . '.' . DOZWOLONE_TYPY[$typ];
    $cel = katalog_zdjec($podkatalog) . '/' . $nazwa;

    if (!move_uploaded_file($plik['tmp_name'], $cel)) {
        throw new RuntimeException('Nie udało się zapisać pliku na dysku.');
    }

    chmod($cel, 0644);

    return $nazwa;
}

function usun_zdjecie(?string $nazwa, string $podkatalog): void
{
    if (!$nazwa) {
        return;
    }

    // basename ucina ewentualne ../ z nazwy zapisanej w bazie
    $sciezka = katalog_zdjec($podkatalog) . '/' . basename($nazwa);

    if (is_file($sciezka)) {
        unlink($sciezka);
    }
}

/** Adres zdjęcia widziany ze strony (albo null). */
function adres_zdjecia(?string $nazwa, string $podkatalog): ?string
{
    if (!$nazwa) {
        return null;
    }

    $c = konfiguracja();

    return $c['katalog_uploadow'] . '/' . $podkatalog . '/' . basename($nazwa);
}
