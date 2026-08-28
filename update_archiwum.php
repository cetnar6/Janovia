<?php
/**
 * Pobiera archiwalne tabele ligowe klubu z 90minut.pl i zapisuje je
 * do data/archiwum.json, skąd czyta je podstrona archiwum.html.
 *
 * Odpowiednik update_archiwum.py. Strona skarb.php wylicza wszystkie
 * rozgrywki, w jakich klub kiedykolwiek brał udział — jedno jej pobranie
 * wystarcza, żeby poznać adresy tabel z każdego sezonu. Tabele mają ten sam
 * układ co bieżąca, więc czyta je ten sam parser co update_liga.php.
 *
 * Sezony zakończone zapisujemy raz i nie pobieramy ponownie — bieżący
 * odświeżamy przy każdym uruchomieniu.
 *
 * Uruchomienie z crona:  php /sciezka/do/update_archiwum.php
 * Podgląd bez zapisu:    php update_archiwum.php --dry-run
 */

declare(strict_types=1);

require_once __DIR__ . '/update_liga.php';

const ARCHIWUM_ID_KLUB   = 22352;
const ARCHIWUM_SKARB_URL = 'http://www.90minut.pl/skarb.php?id_klub=' . ARCHIWUM_ID_KLUB . '&id_sezon=97';

/**
 * Z listy „Rozgrywki z udziałem" bierze wyłącznie rozgrywki ligowe —
 * Puchar Polski pomijamy, bo nie ma tabeli.
 */
function archiwum_znajdz_sezony(string $html): array
{
    $wzor = '~<a href="(/liga/1/liga\d+\.html)" class="main">(\d{4}/\d{2}) - \s*(.*?)</a>~s';

    if (!preg_match_all($wzor, $html, $trafienia, PREG_SET_ORDER)) {
        return [];
    }

    $sezony = [];

    foreach ($trafienia as $m) {
        $opis = liga_czysc($m[3]);

        if (mb_stripos($opis, 'puchar', 0, 'UTF-8') !== false) {
            continue;
        }

        $sezony[] = [
            'sezon'  => $m[2],
            'zrodlo' => 'http://www.90minut.pl' . $m[1],
            'opis'   => $opis,
        ];
    }

    return $sezony;
}

function archiwum_pozycja_klubu(array $tabela): ?array
{
    foreach ($tabela as $w) {
        if ($w['klub'] === LIGA_NASZ_KLUB) {
            return $w;
        }
    }

    return null;
}

function aktualizuj_archiwum(bool $dryRun = false): array
{
    $plik = __DIR__ . '/data/archiwum.json';

    // zapamiętane sezony — zakończonych nie pobieramy po raz drugi
    $stare = [];
    if (is_file($plik)) {
        $poprzednie = json_decode((string) file_get_contents($plik), true);
        foreach ($poprzednie['sezony'] ?? [] as $s) {
            $stare[$s['zrodlo']] = $s;
        }
    }

    $znalezione = archiwum_znajdz_sezony(liga_pobierz(ARCHIWUM_SKARB_URL));

    if (!$znalezione) {
        throw new RuntimeException('Nie znalazłem żadnego sezonu ligowego — 90minut zmienił układ strony?');
    }

    // najwyższy numer sezonu to ten trwający
    $etykiety = array_column($znalezione, 'sezon');
    array_multisort($etykiety, SORT_DESC, $znalezione);
    $biezacy = $znalezione[0]['zrodlo'];

    $sezony = [];
    $pominiete = [];

    foreach ($znalezione as $s) {
        if ($s['zrodlo'] !== $biezacy && isset($stare[$s['zrodlo']])) {
            $sezony[] = $stare[$s['zrodlo']];
            continue;
        }

        $htmlLigi = liga_pobierz($s['zrodlo']);
        $tabela   = liga_parsuj_tabele($htmlLigi);

        if (!$tabela) {
            $pominiete[] = $s['zrodlo'];
            continue;
        }

        $sezony[] = [
            'sezon'         => $s['sezon'],
            'liga'          => liga_nazwa($htmlLigi) ?: $s['opis'],
            'zrodlo'        => $s['zrodlo'],
            'bierzacy'      => $s['zrodlo'] === $biezacy,
            'nasza_pozycja' => archiwum_pozycja_klubu($tabela),
            'tabela'        => $tabela,
        ];
    }

    // najnowszy sezon pierwszy
    usort($sezony, static fn(array $a, array $b): int => strcmp($b['sezon'], $a['sezon']));

    $tekst = liga_json([
        'klub'           => LIGA_NASZ_KLUB,
        'zaktualizowano' => date('Y-m-d\TH:i:s'),
        'sezony'         => $sezony,
    ]);

    if (!$dryRun) {
        if (!is_dir(dirname($plik))) {
            mkdir(dirname($plik), 0775, true);
        }

        if (file_put_contents($plik, $tekst . "\n") === false) {
            throw new RuntimeException('Brak prawa zapisu do ' . $plik);
        }
    }

    return ['tekst' => $tekst, 'sezony' => count($sezony), 'pominiete' => $pominiete];
}

/* Sam z siebie działa tylko z wiersza poleceń — patrz komentarz w update_liga.php. */
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    try {
        $dry = in_array('--dry-run', $argv, true);
        $wynik = aktualizuj_archiwum($dry);

        foreach ($wynik['pominiete'] as $p) {
            fwrite(STDERR, '  pominięto (pusta tabela): ' . $p . "\n");
        }

        echo $dry ? $wynik['tekst'] . "\n"
                  : sprintf("Zapisano data/archiwum.json: %d sezonów.\n", $wynik['sezony']);
    } catch (Throwable $e) {
        fwrite(STDERR, 'Błąd: ' . $e->getMessage() . "\n");
        exit(1);
    }
}
