<?php
/**
 * Jedno wejście dla zadania cyklicznego na hostingu.
 *
 * W panelu OVH: Web Cloud → Hosting → Więcej → Cron. Jako ścieżkę podaj ten
 * plik, wybierz PHP 8.x i częstotliwość raz dziennie (minuty są na hostingu
 * współdzielonym i tak zablokowane, a częściej nie ma po co).
 *
 * Trzy źródła są odpytywane niezależnie: awaria jednego nie przerywa
 * pozostałych. Ma to znaczenie, bo OVH wyłącza zadanie po dziesięciu błędach
 * z rzędu — gdyby padnięcie Facebooka blokowało tabelę ligową, jeden problem
 * zabijałby całą aktualizację.
 *
 * Kod wyjścia 1 tylko wtedy, gdy zawiodły WSZYSTKIE źródła — wtedy warto,
 * żeby OVH wysłał powiadomienie.
 */

declare(strict_types=1);

date_default_timezone_set('Europe/Warsaw');

/* Uruchamiamy się z wiersza poleceń (cron) albo z wykonania przez system
   zadań hostingu. Zwykłe wejście przez przeglądarkę odrzucamy: skrypt
   ciągnie dane z zewnątrz i nie ma powodu, żeby ktokolwiek mógł go wołać
   z internetu. Parametrów w adresie i tak nie da się na OVH przekazać. */
if (PHP_SAPI !== 'cli' && isset($_SERVER['REQUEST_METHOD'])) {
    http_response_code(403);
    exit("Ten skrypt uruchamia zadanie cykliczne, nie przeglądarka.\n");
}

require_once __DIR__ . '/update_liga.php';
require_once __DIR__ . '/update_archiwum.php';
require_once __DIR__ . '/update_fb.php';

$zadania = [
    'tabela i terminarz' => static function (): string {
        $w = aktualizuj_lige();
        return sprintf('%d zespołów, %d meczów, %d nadchodzących',
            $w['tabela'], $w['mecze'], $w['nadchodzace']);
    },
    'archiwum sezonów' => static function (): string {
        $w = aktualizuj_archiwum();
        return sprintf('%d sezonów', $w['sezony']);
    },
    'posty z Facebooka' => static function (): string {
        $w = aktualizuj_facebooka();
        return sprintf('%d postów, %d zdjęć%s',
            $w['posty'], $w['zdjecia'],
            $w['usuniete'] ? sprintf(', usunięto %d starych', $w['usuniete']) : '');
    },
];

$udane = 0;
$bledy = [];

foreach ($zadania as $nazwa => $zadanie) {
    try {
        echo date('H:i:s'), '  ', $nazwa, ': ', $zadanie(), "\n";
        $udane++;
    } catch (Throwable $e) {
        $bledy[] = $nazwa . ' — ' . $e->getMessage();
        fwrite(STDERR, date('H:i:s') . '  BŁĄD ' . $nazwa . ': ' . $e->getMessage() . "\n");
    }
}

if ($bledy && $udane === 0) {
    fwrite(STDERR, "Żadne źródło nie odpowiedziało — sprawdź połączenie i token.\n");
    exit(1);
}

echo sprintf("Gotowe: %d z %d źródeł odświeżonych.\n", $udane, count($zadania));
