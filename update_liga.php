<?php
/**
 * Pobiera tabelę i terminarz z 90minut.pl i zapisuje je do data/liga.json.
 *
 * Odpowiednik update_liga.py, przepisany na PHP, żeby dane odświeżał sam
 * serwer — hosting współdzielony daje PHP, ale nie Pythona.
 *
 * Uruchomienie z crona:      php /sciezka/do/update_liga.php
 * Podgląd bez zapisu:        php update_liga.php --dry-run
 *
 * Z panelu wołane przez aktualizuj_lige(); wtedy plik jest tylko dołączany
 * i nic sam z siebie nie robi — patrz warunek na końcu.
 */

declare(strict_types=1);

const LIGA_URL       = 'http://www.90minut.pl/liga/1/liga15094.html';
const LIGA_NASZ_KLUB = 'Janovia Janowiec';

const LIGA_MIESIACE = [
    'stycznia' => 1, 'lutego' => 2, 'marca' => 3, 'kwietnia' => 4,
    'maja' => 5, 'czerwca' => 6, 'lipca' => 7, 'sierpnia' => 8,
    'września' => 9, 'wrzesnia' => 9, 'października' => 10, 'pazdziernika' => 10,
    'listopada' => 11, 'grudnia' => 12,
];

/**
 * 90minut serwuje stronę w ISO-8859-2, więc bez przekodowania polskie znaki
 * w nazwach klubów rozsypią się na krzaki.
 */
function liga_pobierz(string $url): string
{
    $naglowek = 'KS Janovia Janowiec - aktualizacja tabeli (1x dziennie)';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_USERAGENT      => $naglowek,
        ]);
        $tresc = curl_exec($ch);
        $blad  = curl_error($ch);
        // bez curl_close() — od PHP 8.0 nic nie robi, a od 8.5 wypisuje
        // ostrzeżenie, które psułoby JSON wypisywany przy --dry-run

        if ($tresc === false) {
            throw new RuntimeException('Nie udało się pobrać strony: ' . $blad);
        }
    } else {
        $kontekst = stream_context_create(['http' => [
            'timeout' => 30,
            'header'  => 'User-Agent: ' . $naglowek,
        ]]);
        $tresc = @file_get_contents($url, false, $kontekst);

        if ($tresc === false) {
            throw new RuntimeException('Nie udało się pobrać strony (brak cURL i allow_url_fopen?).');
        }
    }

    return mb_convert_encoding($tresc, 'UTF-8', 'ISO-8859-2');
}

/** Usuwa tagi, encje i nadmiarowe spacje z fragmentu HTML. */
function liga_czysc(string $s): string
{
    $s = preg_replace('/<[^>]+>/', '', $s);
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // twarda spacja z encji &nbsp; to U+00A0, nie zwykły odstęp
    return trim(str_replace("\xC2\xA0", ' ', $s));
}

/** Z tytułu „Klasa B 2026/2027, grupa: …" wyciąga lata sezonu. */
function liga_sezon_lata(string $html): array
{
    if (preg_match('~(\d{4})\s*/\s*(\d{4})~', $html, $m)) {
        return [(int) $m[1], (int) $m[2]];
    }

    $rok = (int) date('Y');
    return [$rok, $rok + 1];
}

function liga_nazwa(string $html): string
{
    return preg_match('~<title>(.*?)</title>~s', $html, $m) ? liga_czysc($m[1]) : '';
}

/**
 * Wiersz tabeli: pozycja, klub, M, Pkt, Z, R, P, bramki.
 * Pozycja bywa pusta przy klubach ex aequo — wtedy liczymy dalej od poprzedniej.
 */
function liga_parsuj_tabele(string $html): array
{
    $wzor = '~<tr[^>]*>\s*'
          . '<td><b>(\d*)\.?</b></td>\s*'
          . '<td align="left">(.*?)</td>\s*'
          . '<td>(\d+)</td>\s*'
          . '<td><b>(-?\d+)</b></td>\s*'
          . '<td>(\d+)</td>\s*<td>(\d+)</td>\s*<td>(\d+)</td>\s*'
          . '<td>(\d+\s*-\s*\d+)</td>~s';

    $tabela = [];
    $ostatnia = 0;

    if (preg_match_all($wzor, $html, $trafienia, PREG_SET_ORDER)) {
        foreach ($trafienia as $m) {
            $poz = $m[1] !== '' ? (int) $m[1] : $ostatnia + 1;
            $ostatnia = $poz;

            $tabela[] = [
                'poz'    => $poz,
                'klub'   => liga_czysc($m[2]),
                'm'      => (int) $m[3],
                'pkt'    => (int) $m[4],
                'z'      => (int) $m[5],
                'r'      => (int) $m[6],
                'p'      => (int) $m[7],
                'bramki' => str_replace(' ', '', liga_czysc($m[8])),
            ];
        }
    }

    return $tabela;
}

/**
 * „9 sierpnia, 16:00" -> „2026-08-09T16:00:00". Bez godziny -> data o 00:00.
 * Obsługuje też zakres z nagłówka kolejki („22-23 sierpnia").
 */
function liga_na_iso(?string $termin, int $rokJesien, int $rokWiosna): ?string
{
    if (!$termin) { return null; }

    $wzor = '~^(\d{1,2})(?:\s*-\s*\d{1,2})?\s+([a-ząćęłńóśźż]+)(?:,\s*(\d{1,2}):(\d{2}))?~iu';

    if (!preg_match($wzor, $termin, $m)) { return null; }

    $miesiac = LIGA_MIESIACE[mb_strtolower($m[2], 'UTF-8')] ?? null;
    if (!$miesiac) { return null; }

    // sezon przełamuje się na Nowy Rok: lipiec–grudzień to pierwszy rok
    $rok = $miesiac >= 7 ? $rokJesien : $rokWiosna;

    $dzien   = (int) $m[1];
    $godzina = isset($m[3]) ? (int) $m[3] : 0;
    $minuta  = isset($m[4]) ? (int) $m[4] : 0;

    if (!checkdate($miesiac, $dzien, $rok)) { return null; }

    return sprintf('%04d-%02d-%02dT%02d:%02d:00', $rok, $miesiac, $dzien, $godzina, $minuta);
}

/**
 * Nagłówek kolejki: <b><u>Kolejka 3 - 5-6 września</u></b>
 * Mecz: cztery komórki — gospodarz | wynik lub „-" | gość | termin.
 *
 * Data w nagłówku bywa pominięta (runda wiosenna bez ustalonych terminów),
 * dlatego jest opcjonalna — inaczej mecze wpadałyby do poprzedniej kolejki.
 */
function liga_parsuj_terminarz(string $html, int $rokJesien, int $rokWiosna): array
{
    $wzorNaglowka = '~<b><u>\s*Kolejka\s*(\d+)\s*(?:-\s*([^<]*?))?\s*</u></b>~s';
    $wzorWiersza  = '~<td nowrap valign="top" width="180">(.*?)</td>\s*'
                  . '<td nowrap valign="top" align="center" width="50">(.*?)</td>\s*'
                  . '<td nowrap valign="top" width="180">(.*?)</td>\s*'
                  . '<td valign="top" nowrap align="left" width="190">(.*?)</td>~s';

    preg_match_all($wzorNaglowka, $html, $naglowki, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

    $mecze = [];
    $ile = count($naglowki);

    for ($i = 0; $i < $ile; $i++) {
        $kolejka  = (int) $naglowki[$i][1][0];
        $etykieta = liga_czysc($naglowki[$i][2][0] ?? '');

        $start  = $naglowki[$i][0][1];
        $koniec = ($i + 1 < $ile) ? $naglowki[$i + 1][0][1] : strlen($html);
        $blok   = substr($html, $start, $koniec - $start);

        if (!preg_match_all($wzorWiersza, $blok, $wiersze, PREG_SET_ORDER)) {
            continue;
        }

        foreach ($wiersze as $w) {
            $gospodarz = liga_czysc($w[1]);
            $gosc      = liga_czysc($w[3]);

            if ($gospodarz === '' || $gosc === '') { continue; }

            $wynik  = liga_czysc($w[2]);
            $termin = liga_czysc($w[4]);
            $zTerminu = liga_na_iso($termin, $rokJesien, $rokWiosna);

            $mecze[] = [
                'kolejka'          => $kolejka,
                'kolejka_opis'     => $etykieta,
                'gospodarz'        => $gospodarz,
                'gosc'             => $gosc,
                'wynik'            => preg_match('~^\d+\s*-\s*\d+$~', $wynik) ? $wynik : null,
                'termin'           => $termin !== '' ? $termin : null,
                // dokładny termin bywa pusty — wtedy bierzemy dzień z nagłówka kolejki
                'data_iso'         => $zTerminu ?? liga_na_iso($etykieta, $rokJesien, $rokWiosna),
                'data_przyblizona' => $zTerminu === null,
            ];
        }
    }

    return $mecze;
}

/**
 * Komplet spotkań ostatniej kolejki, w której padł jakikolwiek wynik.
 * Zanim sezon ruszy — najbliższa kolejka z samymi terminami.
 */
function liga_ostatnia_kolejka(array $mecze): ?array
{
    if (!$mecze) { return null; }

    $rozegrane = array_filter($mecze, static fn(array $m): bool => (bool) $m['wynik']);

    $numery = array_column($rozegrane ?: $mecze, 'kolejka');
    $numer  = $rozegrane ? max($numery) : min($numery);

    $wybrane = array_values(array_filter(
        $mecze,
        static fn(array $m): bool => $m['kolejka'] === $numer
    ));

    return [
        'numer'     => $numer,
        'opis'      => $wybrane ? $wybrane[0]['kolejka_opis'] : '',
        'rozegrana' => (bool) array_filter($wybrane, static fn(array $m): bool => (bool) $m['wynik']),
        'mecze'     => $wybrane,
    ];
}

/** Dla każdego klubu ostatnie `ile` meczów: Z (wygrana), R (remis), P (porażka). */
function liga_policz_forme(array $mecze, int $ile = 5): array
{
    $rozegrane = array_values(array_filter($mecze, static fn(array $m): bool => (bool) $m['wynik']));
    usort($rozegrane, static fn(array $a, array $b): int => $a['kolejka'] <=> $b['kolejka']);

    $forma = [];

    foreach ($rozegrane as $m) {
        $czesci = preg_split('~\s*-\s*~', $m['wynik']);
        if (count($czesci) < 2 || !is_numeric($czesci[0]) || !is_numeric($czesci[1])) {
            continue;
        }

        $golGosp = (int) $czesci[0];
        $golGosc = (int) $czesci[1];

        $strony = [
            [$m['gospodarz'], $golGosp, $golGosc, $m['gosc'], 'u siebie'],
            [$m['gosc'], $golGosc, $golGosp, $m['gospodarz'], 'na wyjeździe'],
        ];

        foreach ($strony as [$klub, $swoje, $obce, $rywal, $gdzie]) {
            $znak = $swoje > $obce ? 'Z' : ($swoje < $obce ? 'P' : 'R');

            $forma[$klub][] = [
                'wynik'    => $znak,
                'rywal'    => $rywal,
                'rezultat' => $swoje . '-' . $obce,
                'kolejka'  => $m['kolejka'],
                'gdzie'    => $gdzie,
            ];
        }
    }

    foreach ($forma as $klub => $lista) {
        $forma[$klub] = array_slice($lista, -$ile);
    }

    return $forma;
}

/**
 * Python zapisywał JSON z wcięciem dwóch spacji, PHP wcina czterema.
 * Zrównujemy, żeby przejście na PHP nie wygenerowało różnicy w każdej linii.
 */
function liga_json(array $dane): string
{
    $json = json_encode($dane, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

    return preg_replace_callback(
        '/^(?: {4})+/m',
        static fn(array $m): string => str_repeat(' ', strlen($m[0]) / 2),
        $json
    );
}

/**
 * Pobiera, parsuje i zapisuje. Zwraca podsumowanie dla panelu i crona.
 */
function aktualizuj_lige(bool $dryRun = false): array
{
    $html = liga_pobierz(LIGA_URL);
    [$rokJesien, $rokWiosna] = liga_sezon_lata($html);

    $tabela = liga_parsuj_tabele($html);
    $mecze  = liga_parsuj_terminarz($html, $rokJesien, $rokWiosna);

    if (!$tabela && !$mecze) {
        throw new RuntimeException('Nie udało się nic sparsować — 90minut zmieniło układ strony?');
    }

    $nasze = array_values(array_filter($mecze, static fn(array $m): bool =>
        $m['gospodarz'] === LIGA_NASZ_KLUB || $m['gosc'] === LIGA_NASZ_KLUB));

    $nadchodzace = array_values(array_filter($nasze, static fn(array $m): bool => !$m['wynik']));
    $rozegrane   = array_values(array_filter($nasze, static fn(array $m): bool => (bool) $m['wynik']));

    $dane = [
        'zrodlo'           => LIGA_URL,
        'liga'             => liga_nazwa($html),
        'klub'             => LIGA_NASZ_KLUB,
        'zaktualizowano'   => date('Y-m-d\TH:i:s'),
        'tabela'           => $tabela,
        'forma'            => liga_policz_forme($mecze),
        'ostatnia_kolejka' => liga_ostatnia_kolejka($mecze),
        'nadchodzace'      => array_slice($nadchodzace, 0, 8),
        'ostatni'          => $rozegrane ? end($rozegrane) : null,
        'wszystkie_mecze'  => $mecze,
    ];

    $tekst = liga_json($dane);

    if (!$dryRun) {
        $plik = __DIR__ . '/data/liga.json';

        if (!is_dir(dirname($plik))) {
            mkdir(dirname($plik), 0775, true);
        }

        if (file_put_contents($plik, $tekst . "\n") === false) {
            throw new RuntimeException('Brak prawa zapisu do ' . $plik);
        }
    }

    return [
        'tekst'       => $tekst,
        'tabela'      => count($tabela),
        'mecze'       => count($mecze),
        'nadchodzace' => count($nadchodzace),
    ];
}

/* Uruchamiamy się sami wyłącznie z wiersza poleceń (cron). Dołączony z panelu
   plik ma tylko udostępnić aktualizuj_lige(), a nie od razu ruszać po dane. */
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    try {
        $wynik = aktualizuj_lige(in_array('--dry-run', $argv, true));

        if (in_array('--dry-run', $argv, true)) {
            echo $wynik['tekst'], "\n";
        } else {
            printf(
                "Zapisano data/liga.json: %d zespołów w tabeli, %d meczów, %d nadchodzących.\n",
                $wynik['tabela'], $wynik['mecze'], $wynik['nadchodzace']
            );
        }
    } catch (Throwable $e) {
        fwrite(STDERR, 'Błąd: ' . $e->getMessage() . "\n");
        exit(1);
    }
}
