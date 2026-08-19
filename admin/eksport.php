<?php
/**
 * Zapisuje zawartość bazy do plików JSON w katalogu data/.
 *
 * Strona klubu jest statyczna i nie łączy się z bazą — czyta gotowe pliki,
 * dzięki czemu działa też na GitHub Pages, gdzie PHP nie istnieje.
 * Ten krok jest pomostem między panelem a stroną.
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/zdjecia.php';

wymagaj_logowania();
sprawdz_csrf();

$db = baza();
$katalog = katalog_strony() . '/data';

if (!is_dir($katalog)) {
    mkdir($katalog, 0775, true);
}

function zapisz_json(string $sciezka, array $dane): void
{
    $json = json_encode(
        $dane,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );

    file_put_contents($sciezka, $json . "\n");
}

/* ---------- zawodnicy ---------- */

$zawodnicy = $db->query(
    'SELECT imie, nazwisko, pozycja, numer, zdjecie
     FROM zawodnicy
     WHERE aktywny = 1
     ORDER BY CASE WHEN numer IS NULL THEN 1 ELSE 0 END, numer, nazwisko'
)->fetchAll();

$lista_zawodnikow = array_map(static function (array $z): array {
    return [
        'imie'     => $z['imie'],
        'nazwisko' => $z['nazwisko'],
        'pozycja'  => $z['pozycja'],
        'numer'    => $z['numer'] !== null ? (int) $z['numer'] : null,
        'zdjecie'  => adres_zdjecia($z['zdjecie'], 'zawodnicy'),
    ];
}, $zawodnicy);

zapisz_json($katalog . '/zawodnicy.json', [
    'zaktualizowano' => date('c'),
    'zawodnicy'      => $lista_zawodnikow,
]);

/* ---------- aktualności ---------- */

$posty = $db->query(
    'SELECT tytul, tresc, etykieta, zdjecie, opublikowano
     FROM posty
     WHERE widoczny = 1
     ORDER BY opublikowano DESC
     LIMIT 30'
)->fetchAll();

$lista_postow = array_map(static function (array $p): array {
    return [
        'naglowek' => $p['tytul'],
        'tekst'    => $p['tresc'],
        'typ'      => $p['etykieta'],
        'data'     => date('c', strtotime($p['opublikowano'])),
        'zdjecie'  => adres_zdjecia($p['zdjecie'], 'posty'),
        'link'     => null,          // wpis własny nie prowadzi na zewnątrz
        'zrodlo'   => 'klub',
    ];
}, $posty);

zapisz_json($katalog . '/posty.json', [
    'zaktualizowano' => date('c'),
    'posty'          => $lista_postow,
]);

/* ---------- mecze dodane ręcznie w panelu ---------- */

const NASZ_KLUB_EKSPORT = 'Janovia Janowiec';

$mecze = $db->query(
    'SELECT przeciwnik, u_siebie, termin, etykieta, wynik, herb
     FROM mecze
     WHERE widoczny = 1
     ORDER BY termin'
)->fetchAll();

$lista_meczow = array_map(static function (array $m): array {
    return [
        'gospodarz'        => $m['u_siebie'] ? NASZ_KLUB_EKSPORT : $m['przeciwnik'],
        'gosc'             => $m['u_siebie'] ? $m['przeciwnik'] : NASZ_KLUB_EKSPORT,
        'przeciwnik'       => $m['przeciwnik'],
        'herb_przeciwnika' => adres_zdjecia($m['herb'], 'mecze'),
        'wynik'            => $m['wynik'],
        'kolejka'          => null,
        'kolejka_opis'     => $m['etykieta'],
        'etykieta'         => $m['etykieta'],
        'data_iso'         => date('c', strtotime($m['termin'])),
        'data_przyblizona' => false,
    ];
}, $mecze);

zapisz_json($katalog . '/mecze-panelu.json', [
    'zaktualizowano' => date('c'),
    'mecze'          => $lista_meczow,
]);

/* ---------- sponsorzy (pasek na stronie głównej) ---------- */

$sponsorzy = $db->query(
    'SELECT nazwa, rola, logo, poswiata
     FROM sponsorzy
     WHERE widoczny = 1
     ORDER BY kolejnosc, nazwa'
)->fetchAll();

$lista_sponsorow = array_map(static function (array $s): array {
    return [
        'nazwa'    => $s['nazwa'],
        'rola'     => $s['rola'],
        'logo'     => adres_zdjecia($s['logo'], 'sponsorzy'),
        'poswiata' => (bool) $s['poswiata'],
    ];
}, $sponsorzy);

zapisz_json($katalog . '/sponsorzy.json', [
    'zaktualizowano' => date('c'),
    'sponsorzy'      => $lista_sponsorow,
]);

komunikat(sprintf(
    'Opublikowano: %d zawodników, %d aktualności, %d meczów i %d sponsorów.',
    count($lista_zawodnikow),
    count($lista_postow),
    count($lista_meczow),
    count($lista_sponsorow)
));

przekieruj('index.php');
