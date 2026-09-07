<?php
/**
 * Zmiany schematu bazy, które trzeba wykonać na działającej instalacji.
 *
 * Po co osobny mechanizm: sql/schema.*.sql opisuje bazę tworzoną od zera,
 * a katalog sql/ nie jest nawet wysyłany na serwer. Baza na hostingu żyje
 * od pierwszego uruchomienia i przy każdej zmianie schematu zostaje w tyle
 * za kodem. MySQL nie mówi o tym głośno — przy niepasującej wartości ENUM
 * w trybie nieścisłym zapisuje pusty ciąg zamiast zgłosić błąd, więc
 * zawodnik zapisuje się „poprawnie", tylko bez pozycji.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/* Pozycje grających — kolejność jak ustawienie na boisku. */
const POZYCJE_GRAJACE = ['bramkarz', 'obrońca', 'pomocnik', 'napastnik'];

/* Role sztabu. Na stronie trafiają do jednej sekcji „Sztab", ale pod nazwiskiem
   każdy ma swoją rolę — „kierownik" mówi więcej niż „sztab". Dopisanie kolejnej
   roli to jedna pozycja w tej tablicy: schemat bazy dociąga się z niej sam,
   panel bierze z niej listę wyboru, a strona kolejność w sekcji.
   'sztab' zostaje jako ogólny worek na kogoś bez konkretnej funkcji. */
const POZYCJE_SZTABU = [
    'trener',
    'asystent trenera',
    'trener bramkarzy',
    'kierownik',
    'masażysta',
    'sztab',
];

const POZYCJE_W_SCHEMACIE = [
    'bramkarz', 'obrońca', 'pomocnik', 'napastnik',
    'trener', 'asystent trenera', 'trener bramkarzy', 'kierownik', 'masażysta', 'sztab',
];

/** Czy to ktoś wokół drużyny, a nie zawodnik — sztab numeru na koszulce nie ma. */
function czy_sztab(string $pozycja): bool
{
    return in_array($pozycja, POZYCJE_SZTABU, true);
}

/** Lista pozycji w SQL-owym zapisie: 'bramkarz','obrońca',... */
function pozycje_do_sql(): string
{
    return implode(',', array_map(static function (string $p): string {
        return "'" . $p . "'";
    }, POZYCJE_W_SCHEMACIE));
}

/** Czy baza to MySQL (hosting), czy SQLite (praca lokalna). */
function sterownik_bazy(): string
{
    return konfiguracja()['sterownik'] === 'sqlite' ? 'sqlite' : 'mysql';
}

/** Definicja kolumny pozycja tak, jak widzi ją baza — do sprawdzenia, czy zna sztab. */
function definicja_pozycji(): string
{
    if (sterownik_bazy() === 'sqlite') {
        $sql = baza()
            ->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'zawodnicy'")
            ->fetchColumn();

        return (string) $sql;
    }

    $stmt = baza()->prepare(
        'SELECT COLUMN_TYPE FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute(['zawodnicy', 'pozycja']);

    return (string) $stmt->fetchColumn();
}

/**
 * Lista migracji. Każda umie sama powiedzieć, czy jest jeszcze potrzebna,
 * więc uruchomienie jej drugi raz niczego nie psuje.
 */
function migracje(): array
{
    return [
        [
            'id'    => 'pozycje',
            'nazwa' => 'Lista pozycji i ról w tabeli zawodników',
            'opis'  => 'Baza zna węższy zestaw niż panel. Do czasu aktualizacji zapis roli, '
                     . 'której nie zna, kończy się pustą pozycją zamiast błędu.',

            'potrzebna' => static function (): bool {
                $definicja = definicja_pozycji();

                foreach (POZYCJE_W_SCHEMACIE as $p) {
                    /* Szukamy wartości razem z apostrofami. Bez nich „trener"
                       znalazłby się w „asystent trenera" i migracja uznałaby,
                       że rola już jest, choć jej nie ma. */
                    if (strpos($definicja, "'" . $p . "'") === false) {
                        return true;
                    }
                }

                return false;
            },

            'zastosuj' => static function (PDO $db): void {
                $lista = pozycje_do_sql();

                if (sterownik_bazy() === 'mysql') {
                    $db->exec("ALTER TABLE zawodnicy MODIFY pozycja ENUM($lista) NOT NULL");
                    return;
                }

                /* SQLite nie zmienia warunku CHECK w miejscu — trzeba przepisać
                   tabelę. Kolejność: najpierw dane do nowej, dopiero potem
                   znika stara, żeby przerwanie w połowie nie zostawiło pustki. */
                $db->exec(
                    "CREATE TABLE zawodnicy_nowe (
                        id        INTEGER PRIMARY KEY AUTOINCREMENT,
                        imie      TEXT    NOT NULL,
                        nazwisko  TEXT    NOT NULL,
                        pozycja   TEXT    NOT NULL CHECK (pozycja IN ($lista)),
                        numer     INTEGER CHECK (numer IS NULL OR (numer BETWEEN 1 AND 99)),
                        zdjecie   TEXT,
                        aktywny   INTEGER NOT NULL DEFAULT 1 CHECK (aktywny IN (0,1)),
                        utworzono TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
                    )"
                );
                $db->exec(
                    'INSERT INTO zawodnicy_nowe (id, imie, nazwisko, pozycja, numer, zdjecie, aktywny, utworzono)
                        SELECT id, imie, nazwisko, pozycja, numer, zdjecie, aktywny, utworzono FROM zawodnicy'
                );
                $db->exec('DROP TABLE zawodnicy');
                $db->exec('ALTER TABLE zawodnicy_nowe RENAME TO zawodnicy');
                $db->exec(
                    'CREATE UNIQUE INDEX IF NOT EXISTS zawodnicy_numer_aktywny
                        ON zawodnicy (numer) WHERE aktywny = 1 AND numer IS NOT NULL'
                );
                $db->exec('CREATE INDEX IF NOT EXISTS zawodnicy_sort ON zawodnicy (aktywny, numer)');
            },
        ],
    ];
}

/** Tylko te migracje, których baza jeszcze nie ma. */
function migracje_zalegle(): array
{
    return array_values(array_filter(migracje(), static function (array $m): bool {
        return ($m['potrzebna'])();
    }));
}

/**
 * Zawodnicy z pustą pozycją — ofiary zapisu sprzed migracji. Pusty ciąg nie
 * bierze się znikąd: panel puszcza dalej tylko wartości z własnej listy, więc
 * wyczyścić mogła je wyłącznie baza, która nie znała jeszcze „sztabu".
 */
function zawodnicy_bez_pozycji(): array
{
    return baza()
        ->query("SELECT id, imie, nazwisko FROM zawodnicy WHERE pozycja = '' OR pozycja IS NULL ORDER BY nazwisko")
        ->fetchAll();
}
