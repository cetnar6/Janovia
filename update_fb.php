<?php
/**
 * Pobiera ostatnie posty ze strony klubu na Facebooku przez Graph API
 * i zapisuje je do data/facebook.json. Zdjęcia ląduje lokalnie w data/fb/,
 * bo adresy z fbcdn.net wygasają po kilku dniach.
 *
 * Odpowiednik update_fb.py, przepisany na PHP — hosting współdzielony daje
 * PHP, ale nie Pythona.
 *
 * Token NIE jest zapisany w kodzie. Kolejność szukania:
 *   1. zmienna środowiskowa FB_TOKEN  (tak działa GitHub Actions)
 *   2. klucz 'fb_token' w admin/inc/config.php  (tak działa serwer i panel)
 *
 * Uruchomienie z crona:  php /sciezka/do/update_fb.php
 * Podgląd bez zapisu:    php update_fb.php --dry-run
 */

declare(strict_types=1);

/* Bez tego PHP liczy w UTC i znacznik „zaktualizowano" wychodzi o dwie
   godziny wstecz — ta sama pułapka, co kiedyś przy godzinach meczów. */
date_default_timezone_set('Europe/Warsaw');

const FB_API        = 'https://graph.facebook.com/v21.0';
const FB_ILE_POSTOW = 100;

const FB_POLA = 'id,message,created_time,permalink_url,full_picture,'
    // subattachments to zdjęcia w środku albumu — bez tego dostajemy tylko
    // media_type="album" i jedno full_picture, a nie całą galerię. Bez jawnego
    // limitu Facebook ucina listę do swojej domyślnej strony wyników.
    . 'attachments{media_type,media,subattachments.limit(200){media}}';

/** Token: najpierw ze środowiska, potem z konfiguracji panelu. */
function fb_token(): string
{
    $ze_srodowiska = trim((string) getenv('FB_TOKEN'));
    if ($ze_srodowiska !== '') { return $ze_srodowiska; }

    $config = __DIR__ . '/admin/inc/config.php';
    if (is_file($config)) {
        $c = require $config;
        if (!empty($c['fb_token'])) { return trim((string) $c['fb_token']); }
    }

    return '';
}

function fb_page_id(): string
{
    $ze_srodowiska = trim((string) getenv('FB_PAGE_ID'));
    if ($ze_srodowiska !== '') { return $ze_srodowiska; }

    $config = __DIR__ . '/admin/inc/config.php';
    if (is_file($config)) {
        $c = require $config;
        if (!empty($c['fb_page_id'])) { return trim((string) $c['fb_page_id']); }
    }

    return '';
}

/**
 * Zapytanie do Graph API. Przy $cichy zwraca null zamiast rzucać wyjątkiem —
 * używane tam, gdzie brak dostępu jest spodziewany i nie jest błędem.
 */
function fb_graph(string $sciezka, string $token, array $parametry = [], bool $cichy = false): ?array
{
    $parametry['access_token'] = $token;
    $url = FB_API . $sciezka . '?' . http_build_query($parametry);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_USERAGENT      => 'KS Janovia Janowiec',
    ]);
    $tresc = curl_exec($ch);
    $kod   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $blad  = curl_error($ch);

    if ($tresc === false) {
        if ($cichy) { return null; }
        throw new RuntimeException('Nie udało się połączyć z Facebookiem: ' . $blad);
    }

    if ($kod >= 400) {
        if ($cichy) { return null; }

        $odp = json_decode((string) $tresc, true);

        if (isset($odp['error'])) {
            $e = $odp['error'];
            throw new RuntimeException(sprintf(
                'Facebook odmówił (%d): %s [typ: %s, kod: %s]',
                $kod,
                $e['message'] ?? '?',
                $e['type'] ?? '?',
                $e['code'] ?? '?'
            ));
        }

        throw new RuntimeException(sprintf('Facebook odmówił (%d): %s', $kod, mb_substr((string) $tresc, 0, 400)));
    }

    return json_decode((string) $tresc, true);
}

/**
 * Przyjmuje token strony ALBO token użytkownika.
 *
 * Przy tokenie użytkownika /me/accounts zwraca listę stron, którymi zarządza,
 * razem z ich własnymi tokenami — i tych trzeba użyć, bo token użytkownika
 * wskazuje na profil prywatny, a nie na fanpage.
 *
 * Zwraca [id strony, token strony, nazwa, komunikaty do wypisania].
 */
function fb_znajdz_strone(string $token): array
{
    $komunikaty = [];

    $konta = fb_graph('/me/accounts', $token, ['fields' => 'id,name,access_token', 'limit' => 50], true);
    $strony = $konta['data'] ?? [];

    if ($strony) {
        $wskazane = fb_page_id();
        $wybrana = null;

        if ($wskazane !== '') {
            foreach ($strony as $s) {
                if ($s['id'] === $wskazane) { $wybrana = $s; break; }
            }

            if (!$wybrana) {
                throw new RuntimeException(sprintf(
                    'FB_PAGE_ID=%s nie pasuje do żadnej z Twoich stron: %s',
                    $wskazane,
                    implode(', ', array_column($strony, 'name'))
                ));
            }
        } else {
            foreach ($strony as $s) {
                if (mb_stripos((string) ($s['name'] ?? ''), 'janovia', 0, 'UTF-8') !== false) {
                    $wybrana = $s; break;
                }
            }
            $wybrana = $wybrana ?? $strony[0];
        }

        if (count($strony) > 1) {
            $komunikaty[] = 'Zarządzasz stronami: ' . implode(', ', array_column($strony, 'name'));
        }

        $komunikaty[] = sprintf('Strona: %s (id %s)', $wybrana['name'], $wybrana['id']);

        return [$wybrana['id'], $wybrana['access_token'] ?? $token, $wybrana['name'], $komunikaty];
    }

    // brak listy stron — zakładamy, że dostaliśmy od razu token strony
    $me = fb_graph('/me', $token, ['fields' => 'id,name']);
    $komunikaty[] = sprintf('Strona: %s (id %s)', $me['name'] ?? '?', $me['id'] ?? '?');

    return [$me['id'], $token, $me['name'] ?? '', $komunikaty];
}

/**
 * Adresy wszystkich zdjęć posta. Album trzyma je w subattachments, pojedyncze
 * zdjęcie tylko w media samego załącznika; gdy nie ma żadnego z tych pól,
 * wracamy do full_picture (miniatura).
 */
function fb_zrodla_zdjec(array $zalacznik, ?string $fullPicture): array
{
    $pod = $zalacznik['subattachments']['data'] ?? [];

    if ($pod) {
        $zrodla = [];
        foreach ($pod as $s) {
            $src = $s['media']['image']['src'] ?? null;
            if ($src) { $zrodla[] = $src; }
        }
        if ($zrodla) { return $zrodla; }
    }

    $src = $zalacznik['media']['image']['src'] ?? null;
    if ($src) { return [$src]; }

    return $fullPicture ? [$fullPicture] : [];
}

/** Zapisuje zdjęcie lokalnie i zwraca ścieżkę względną albo null. */
function fb_pobierz_zdjecie(?string $url, string $nazwa, array &$bledy): ?string
{
    if (!$url) { return null; }

    $katalog = __DIR__ . '/data/fb';
    if (!is_dir($katalog)) { mkdir($katalog, 0775, true); }

    $plik = $katalog . '/' . $nazwa . '.jpg';
    $wzgledna = 'data/fb/' . $nazwa . '.jpg';

    // raz pobrane zdjęcie zostaje — adresy fbcdn wygasają, plik nie
    if (is_file($plik) && filesize($plik) > 0) { return $wzgledna; }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_USERAGENT      => 'KS Janovia Janowiec',
    ]);
    $dane = curl_exec($ch);
    $kod  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $blad = curl_error($ch);

    if ($dane === false || $kod >= 400 || $dane === '') {
        $bledy[] = 'nie pobrałem zdjęcia: ' . ($blad !== '' ? $blad : 'HTTP ' . $kod);
        return null;
    }

    if (file_put_contents($plik, $dane) === false) {
        $bledy[] = 'brak prawa zapisu do ' . $plik;
        return null;
    }

    return $wzgledna;
}

/** Pierwsze zdanie posta jako tytuł kafelka. */
function fb_naglowek(?string $tekst, int $limit = 95): string
{
    if (!$tekst) { return 'Post na Facebooku'; }

    $tekst = trim((string) preg_replace('~\s+~u', ' ', $tekst));
    if ($tekst === '') { return 'Post na Facebooku'; }

    // pozycję liczymy w znakach, nie bajtach — inaczej polskie litery
    // przesuwałyby cięcie i tytuł urywałby się w losowym miejscu
    if (preg_match('~[.!?](\s|$)~u', $tekst, $m, PREG_OFFSET_CAPTURE)) {
        $pozycja = mb_strlen(substr($tekst, 0, $m[0][1]), 'UTF-8');
        if ($pozycja < $limit) {
            return mb_substr($tekst, 0, $pozycja + 1, 'UTF-8');
        }
    }

    if (mb_strlen($tekst, 'UTF-8') <= $limit) { return $tekst; }

    $uciete = mb_substr($tekst, 0, $limit, 'UTF-8');
    $spacja = mb_strrpos($uciete, ' ', 0, 'UTF-8');

    if ($spacja !== false) { $uciete = mb_substr($uciete, 0, $spacja, 'UTF-8'); }

    return $uciete . '…';
}

/** Usuwa pliki po postach, których już nie pokazujemy. */
function fb_sprzataj_zdjecia(array $aktualne): int
{
    $katalog = __DIR__ . '/data/fb';
    if (!is_dir($katalog)) { return 0; }

    $usuniete = 0;

    foreach (scandir($katalog) ?: [] as $plik) {
        if (substr($plik, -4) !== '.jpg') { continue; }

        if (!in_array('data/fb/' . $plik, $aktualne, true)) {
            unlink($katalog . '/' . $plik);
            $usuniete++;
        }
    }

    return $usuniete;
}

function aktualizuj_facebooka(bool $dryRun = false): array
{
    $token = fb_token();

    if ($token === '') {
        throw new RuntimeException(
            'Brak tokenu. Ustaw FB_TOKEN w środowisku albo fb_token w admin/inc/config.php.'
        );
    }

    [$strona, $tokenStrony, $nazwaStrony, $komunikaty] = fb_znajdz_strone($token);

    $odp = fb_graph('/' . $strona . '/posts', $tokenStrony, [
        'fields' => FB_POLA,
        'limit'  => FB_ILE_POSTOW,
    ]);

    $surowe = $odp['data'] ?? [];

    if (!$surowe) {
        throw new RuntimeException(sprintf(
            "Strona „%s” nie zwróciła żadnych postów. Najczęstsze przyczyny:\n"
            . "  - token nie ma uprawnienia pages_read_engagement\n"
            . "  - to token profilu prywatnego, nie fanpage'a\n"
            . '  - na stronie faktycznie nie ma opublikowanych postów',
            $nazwaStrony
        ));
    }

    $posty = [];
    $uzyte = [];
    $bledy = [];

    foreach ($surowe as $p) {
        $tekst = $p['message'] ?? '';
        $nazwaPliku = str_replace('_', '-', (string) $p['id']);

        $zalaczniki = $p['attachments']['data'] ?? [];
        $pierwszy = $zalaczniki[0] ?? [];
        $typ = $pierwszy['media_type'] ?? null;

        $zrodla = fb_zrodla_zdjec($pierwszy, $p['full_picture'] ?? null);

        $galeria = [];

        foreach ($zrodla as $i => $zrodlo) {
            // numer dopisujemy tylko przy albumie — pojedyncze zdjęcie
            // zostaje pod tą samą nazwą co dotychczas
            $nazwa = count($zrodla) === 1 ? $nazwaPliku : $nazwaPliku . '-' . $i;
            $plik = $dryRun ? null : fb_pobierz_zdjecie($zrodlo, $nazwa, $bledy);

            if ($plik) {
                $galeria[] = $plik;
                $uzyte[] = $plik;
            }
        }

        $posty[] = [
            'id'       => $p['id'],
            'naglowek' => fb_naglowek($tekst),
            'tekst'    => $tekst,
            'data'     => $p['created_time'] ?? null,
            'link'     => $p['permalink_url'] ?? null,
            'zdjecie'  => $galeria[0] ?? null,
            'galeria'  => $galeria,
            'typ'      => $typ,
        ];
    }

    $dane = [
        // link buduje się z faktycznie odpytanej strony, żeby „Zobacz wszystkie”
        // nie prowadziło gdzie indziej niż źródło postów
        'zrodlo'         => 'https://www.facebook.com/' . $strona,
        'strona'         => $nazwaStrony,
        'zaktualizowano' => date('c'),
        'posty'          => $posty,
    ];

    $tekstJson = liga_json_fb($dane);
    $usuniete = 0;

    if (!$dryRun) {
        $usuniete = fb_sprzataj_zdjecia(array_values(array_unique($uzyte)));

        $plik = __DIR__ . '/data/facebook.json';
        if (!is_dir(dirname($plik))) { mkdir(dirname($plik), 0775, true); }

        if (file_put_contents($plik, $tekstJson . "\n") === false) {
            throw new RuntimeException('Brak prawa zapisu do ' . $plik);
        }
    }

    return [
        'tekst'      => $tekstJson,
        'posty'      => count($posty),
        'zdjecia'    => count(array_unique($uzyte)),
        'usuniete'   => $usuniete,
        'komunikaty' => $komunikaty,
        'bledy'      => $bledy,
    ];
}

/** Wcięcie dwóch spacji, tak jak zapisywała wersja w Pythonie. */
function liga_json_fb(array $dane): string
{
    $json = json_encode($dane, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

    return preg_replace_callback(
        '/^(?: {4})+/m',
        static fn(array $m): string => str_repeat(' ', strlen($m[0]) / 2),
        $json
    );
}

/* Sam z siebie działa tylko z wiersza poleceń — patrz komentarz w update_liga.php. */
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    try {
        $dry = in_array('--dry-run', $argv, true);
        $wynik = aktualizuj_facebooka($dry);

        foreach ($wynik['komunikaty'] as $k) { fwrite(STDERR, $k . "\n"); }
        foreach ($wynik['bledy'] as $b)      { fwrite(STDERR, '  ' . $b . "\n"); }

        echo $dry ? $wynik['tekst'] . "\n"
                  : sprintf("Zapisano data/facebook.json: %d postów, %d zdjęć.\n",
                            $wynik['posty'], $wynik['zdjecia']);
    } catch (Throwable $e) {
        fwrite(STDERR, 'Błąd: ' . $e->getMessage() . "\n");
        exit(1);
    }
}
