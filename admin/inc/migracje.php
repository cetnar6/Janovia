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

/** Definicja kolumny rola tak, jak widzi ją baza — do sprawdzenia, czy zna rolę. */
function definicja_roli(): string
{
    if (sterownik_bazy() === 'sqlite') {
        return (string) baza()
            ->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'administratorzy'")
            ->fetchColumn();
    }

    $stmt = baza()->prepare(
        'SELECT COLUMN_TYPE FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute(['administratorzy', 'rola']);

    return (string) $stmt->fetchColumn();
}

/** Czy tabela ma już taką kolumnę — podstawa dla migracji dokładających pola. */
function kolumna_istnieje(string $tabela, string $kolumna): bool
{
    if (sterownik_bazy() === 'sqlite') {
        foreach (baza()->query('PRAGMA table_info(' . $tabela . ')') as $k) {
            if ($k['name'] === $kolumna) {
                return true;
            }
        }

        return false;
    }

    $stmt = baza()->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$tabela, $kolumna]);

    return (int) $stmt->fetchColumn() > 0;
}

/** Czy baza ma już taką tabelę — podstawa dla migracji tworzących nowe. */
function tabela_istnieje(string $tabela): bool
{
    if (sterownik_bazy() === 'sqlite') {
        $stmt = baza()->prepare(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?"
        );
    } else {
        $stmt = baza()->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
    }

    $stmt->execute([$tabela]);

    return (int) $stmt->fetchColumn() > 0;
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

        /* Uwaga na kolejność: migracja pozycji przepisuje tabelę SQLite
           z listy kolumn wpisanej na sztywno, więc każda migracja dokładająca
           kolumnę musi stać ZA nią. Inaczej przebudowa zgubiłaby nową kolumnę.
           Dokładając kolejną kolumnę, dopisz ją też do tamtej listy. */
        [
            'id'    => 'kapitan',
            'nazwa' => 'Oznaczenie kapitana drużyny',
            'opis'  => 'Bez tej kolumny panel nie ma gdzie zapisać zaznaczonego kapitana.',

            'potrzebna' => static function (): bool {
                return !kolumna_istnieje('zawodnicy', 'kapitan');
            },

            'zastosuj' => static function (PDO $db): void {
                /* ADD COLUMN rozumieją oba silniki, więc tu obywa się bez
                   przepisywania tabeli. Domyślne 0 wypełnia istniejące wiersze. */
                $db->exec(
                    sterownik_bazy() === 'mysql'
                        ? 'ALTER TABLE zawodnicy ADD COLUMN kapitan TINYINT(1) NOT NULL DEFAULT 0'
                        : 'ALTER TABLE zawodnicy ADD COLUMN kapitan INTEGER NOT NULL DEFAULT 0'
                );
            },
        ],
        [
            'id'    => 'role',
            'nazwa' => 'Role kont w panelu',
            'opis'  => 'Bez tej kolumny każde konto jest administratorem, '
                     . 'więc trener zobaczyłby zawodników, aktualności i publikację na stronie.',

            'potrzebna' => static function (): bool {
                return !kolumna_istnieje('administratorzy', 'rola');
            },

            'zastosuj' => static function (PDO $db): void {
                /* Domyślne 'administrator' jest tu istotne: istniejące konta
                   powstały, gdy ról nie było, i muszą zachować pełny dostęp.
                   Odwrotne domyślne odcięłoby Karola od własnego panelu. */
                $db->exec(
                    sterownik_bazy() === 'mysql'
                        ? "ALTER TABLE administratorzy ADD COLUMN rola ENUM('administrator','trener') NOT NULL DEFAULT 'administrator'"
                        : "ALTER TABLE administratorzy ADD COLUMN rola TEXT NOT NULL DEFAULT 'administrator' CHECK (rola IN ('administrator','trener'))"
                );
            },
        ],

        [
            'id'    => 'sklady',
            'nazwa' => 'Tabela na ustawienia składu',
            'opis'  => 'Panel trenera nie ma gdzie zapisać ustawionego składu — '
                     . 'po odświeżeniu strony praca przepada.',

            'potrzebna' => static function (): bool {
                return !tabela_istnieje('sklady');
            },

            'zastosuj' => static function (PDO $db): void {
                /* Ustawienie trzymamy jako JSON, a nie wiersz na pozycję.
                   Liczba i nazwy pól zależą od formacji, więc osobna tabela
                   wymagałaby kolumny na nazwę slotu i tak kończyłaby jako
                   worek na tekst — tylko droższy w odczycie. Walidacją
                   zajmuje się PHP przy zapisie, bo i tak musi sprawdzić,
                   czy zawodnik istnieje i czy slot należy do formacji. */
                $db->exec(
                    sterownik_bazy() === 'mysql'
                        ? "CREATE TABLE sklady (
                               id            INT AUTO_INCREMENT PRIMARY KEY,
                               nazwa         VARCHAR(160) NOT NULL DEFAULT '',
                               formacja      VARCHAR(20)  NOT NULL,
                               ustawienie    TEXT         NOT NULL,
                               zaktualizowano DATETIME    NOT NULL,
                               utworzono     DATETIME     NOT NULL
                           ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
                        : "CREATE TABLE sklady (
                               id            INTEGER PRIMARY KEY AUTOINCREMENT,
                               nazwa         TEXT NOT NULL DEFAULT '',
                               formacja      TEXT NOT NULL,
                               ustawienie    TEXT NOT NULL,
                               zaktualizowano TEXT NOT NULL,
                               utworzono     TEXT NOT NULL
                           )"
                );
            },
        ],
        [
            'id'    => 'sklad-publikacja',
            'nazwa' => 'Zatwierdzanie składu do pokazania na stronie',
            'opis'  => 'Bez tej kolumny panel nie odróżnia roboczego ustawienia '
                     . 'od tego, które trener zatwierdził — a tylko zatwierdzone '
                     . 'ma prawo zobaczyć ktokolwiek poza panelem.',

            'potrzebna' => static function (): bool {
                return tabela_istnieje('sklady')
                    && !kolumna_istnieje('sklady', 'opublikowany');
            },

            'zastosuj' => static function (PDO $db): void {
                /* Domyślne 0: skład zapisany przed wprowadzeniem publikacji
                   był roboczy i nie ma powodu, żeby nagle pojawił się na
                   stronie tylko dlatego, że doszła kolumna. */
                $db->exec(
                    sterownik_bazy() === 'mysql'
                        ? 'ALTER TABLE sklady ADD COLUMN opublikowany TINYINT(1) NOT NULL DEFAULT 0'
                        : 'ALTER TABLE sklady ADD COLUMN opublikowany INTEGER NOT NULL DEFAULT 0'
                );
                $db->exec(
                    sterownik_bazy() === 'mysql'
                        ? 'ALTER TABLE sklady ADD COLUMN opublikowano DATETIME NULL'
                        : 'ALTER TABLE sklady ADD COLUMN opublikowano TEXT'
                );
            },
        ],
        [
            'id'    => 'sklad-rezerwowi',
            'nazwa' => 'Ławka rezerwowych w składzie',
            'opis'  => 'Bez tej kolumny panel nie ma gdzie zapisać zawodników '
                     . 'posadzonych na ławce — zostaje sama wyjściowa jedenastka.',

            'potrzebna' => static function (): bool {
                return tabela_istnieje('sklady')
                    && !kolumna_istnieje('sklady', 'rezerwowi');
            },

            'zastosuj' => static function (PDO $db): void {
                /* Lista identyfikatorów w JSON-ie, tak jak ustawienie: kolejność
                   na ławce jest decyzją trenera i tabela z kolumną „miejsce"
                   niczego by tu nie ułatwiła.

                   Kolumna wchodzi jako NULL, bo MySQL przed 8.0.13 nie przyjmuje
                   DEFAULT dla TEXT-a. Istniejące składy mają więc pustą ławkę
                   zapisaną jako NULL — PHP czyta to tak samo jak '[]'. */
                $db->exec(
                    sterownik_bazy() === 'mysql'
                        ? 'ALTER TABLE sklady ADD COLUMN rezerwowi TEXT NULL'
                        : 'ALTER TABLE sklady ADD COLUMN rezerwowi TEXT'
                );
            },
        ],
        [
            'id'    => 'sklad-na-mecz',
            'nazwa' => 'Skład przypisany do spotkania',
            'opis'  => 'Bez tego panel trzyma jeden skład naraz — nie da się '
                     . 'przygotować ustawienia na dwie kolejki naprzód.',

            'potrzebna' => static function (): bool {
                return tabela_istnieje('sklady')
                    && !kolumna_istnieje('sklady', 'mecz_klucz');
            },

            'zastosuj' => static function (PDO $db): void {
                /* Klucz, podpis i termin spotkania przy składzie. Podpis
                   i termin trzymamy obok klucza, żeby panel umiał nazwać mecz
                   także wtedy, gdy wypadnie już z terminarza — inaczej stary
                   skład stałby się bezimienny. */
                foreach ([
                    'mecz_klucz' => ['VARCHAR(120) NULL', 'TEXT'],
                    'mecz_opis'  => ['VARCHAR(160) NULL', 'TEXT'],
                    'mecz_data'  => ['DATETIME NULL', 'TEXT'],
                ] as $kolumna => $typy) {
                    if (kolumna_istnieje('sklady', $kolumna)) {
                        continue;
                    }

                    $db->exec('ALTER TABLE sklady ADD COLUMN ' . $kolumna . ' '
                        . (sterownik_bazy() === 'mysql' ? $typy[0] : $typy[1]));
                }

                /* Jeden skład po staremu wisi bez przypisania. Podpinamy go pod
                   najbliższe spotkanie z terminarza — to ten mecz, na który był
                   układany, więc trener zastaje go tam, gdzie go zostawił. */
                require_once __DIR__ . '/przebieg.php';

                $mecz = mecz_biezacy();
                $wiersz = $db->query('SELECT id FROM sklady WHERE mecz_klucz IS NULL ORDER BY id LIMIT 1')
                             ->fetchColumn();

                if ($mecz && $wiersz) {
                    $db->prepare('UPDATE sklady SET mecz_klucz = ?, mecz_opis = ?, mecz_data = ? WHERE id = ?')
                       ->execute([$mecz['klucz'], $mecz['opis'], $mecz['data_iso'], $wiersz]);
                }
            },
        ],

        [
            'id'    => 'przebieg-na-mecz',
            'nazwa' => 'Przebieg przypisany do spotkania',
            'opis'  => 'Bez tego gole i zmiany leżą w tabeli bez meczu — i po '
                     . 'przeskoku terminarza doklejają się do następnej kolejki.',

            'potrzebna' => static function (): bool {
                return tabela_istnieje('zdarzenia')
                    && !kolumna_istnieje('zdarzenia', 'mecz_klucz');
            },

            'zastosuj' => static function (PDO $db): void {
                $db->exec(
                    sterownik_bazy() === 'mysql'
                        ? 'ALTER TABLE zdarzenia ADD COLUMN mecz_klucz VARCHAR(120) NULL'
                        : 'ALTER TABLE zdarzenia ADD COLUMN mecz_klucz TEXT'
                );

                /* Zdarzenia, które już są, należą do ostatnio prowadzonego
                   meczu — czyli do składu, przy którym stoi godzina pierwszego
                   gwizdka. Bez tego zostałyby bez przypisania i zniknęłyby
                   z panelu, choć mecz się odbył. */
                $sklad = $db->query(
                    "SELECT mecz_klucz FROM sklady
                      WHERE mecz_klucz IS NOT NULL AND start_meczu IS NOT NULL
                      ORDER BY start_meczu DESC LIMIT 1"
                )->fetchColumn();

                if ($sklad) {
                    $db->prepare('UPDATE zdarzenia SET mecz_klucz = ? WHERE mecz_klucz IS NULL')
                       ->execute([$sklad]);
                }
            },
        ],

        [
            'id'    => 'polowa-przebiegu',
            'nazwa' => 'Połowa przy zdarzeniu meczu',
            'opis'  => 'Bez tego nie da się odróżnić doliczonego czasu pierwszej '
                     . 'połowy od początku drugiej — oś czasu pisze „48" zamiast „45+3".',

            'potrzebna' => static function (): bool {
                return (tabela_istnieje('zdarzenia') && !kolumna_istnieje('zdarzenia', 'polowa'))
                    || (tabela_istnieje('sklady') && !kolumna_istnieje('sklady', 'polowa'));
            },

            'zastosuj' => static function (PDO $db): void {
                /* Przy zdarzeniu kolumna zostaje pusta dla wpisów, które już
                   są: nie wiemy o nich nic ponad minutę, a zgadywanie na stałe
                   zakłamałoby historię. Puste czyta się regułą „ponad 45 to
                   druga połowa" — czyli dokładnie tak, jak wyglądały dotąd. */
                if (tabela_istnieje('zdarzenia') && !kolumna_istnieje('zdarzenia', 'polowa')) {
                    $db->exec(
                        sterownik_bazy() === 'mysql'
                            ? 'ALTER TABLE zdarzenia ADD COLUMN polowa TINYINT NULL'
                            : 'ALTER TABLE zdarzenia ADD COLUMN polowa INTEGER'
                    );
                }

                /* Przy składzie trzymamy połowę, która właśnie trwa — ustawia
                   ją pierwszy gwizdek i wznowienie po przerwie. Stąd bierze ją
                   każde nowe zdarzenie. */
                if (tabela_istnieje('sklady') && !kolumna_istnieje('sklady', 'polowa')) {
                    $db->exec(
                        sterownik_bazy() === 'mysql'
                            ? 'ALTER TABLE sklady ADD COLUMN polowa TINYINT NOT NULL DEFAULT 1'
                            : 'ALTER TABLE sklady ADD COLUMN polowa INTEGER NOT NULL DEFAULT 1'
                    );
                }
            },
        ],

        [
            'id'    => 'rola-wynik',
            'nazwa' => 'Konta do obsługi wyniku',
            'opis'  => 'Bez tego baza nie przyjmie roli „wynik", więc nie da się '
                     . 'założyć konta, które prowadzi wyłącznie przebieg meczu.',

            'potrzebna' => static function (): bool {
                return kolumna_istnieje('administratorzy', 'rola')
                    && strpos(definicja_roli(), "'wynik'") === false;
            },

            'zastosuj' => static function (PDO $db): void {
                if (sterownik_bazy() === 'mysql') {
                    $db->exec(
                        "ALTER TABLE administratorzy
                            MODIFY rola ENUM('administrator','trener','wynik')
                            NOT NULL DEFAULT 'administrator'"
                    );

                    return;
                }

                /* SQLite nie zmienia warunku CHECK w miejscu — trzeba przepisać
                   tabelę. Kolejność jak przy pozycjach: najpierw dane do nowej,
                   dopiero potem znika stara, żeby przerwanie w połowie nie
                   zostawiło panelu bez żadnego konta. */
                $db->exec(
                    "CREATE TABLE administratorzy_nowe (
                        id           INTEGER PRIMARY KEY AUTOINCREMENT,
                        login        TEXT    NOT NULL UNIQUE,
                        hash_hasla   TEXT    NOT NULL,
                        rola         TEXT    NOT NULL DEFAULT 'administrator'
                                             CHECK (rola IN ('administrator','trener','wynik')),
                        utworzono    TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
                        ostatnie_logowanie TEXT
                    )"
                );
                $db->exec(
                    'INSERT INTO administratorzy_nowe (id, login, hash_hasla, rola, utworzono, ostatnie_logowanie)
                        SELECT id, login, hash_hasla, rola, utworzono, ostatnie_logowanie FROM administratorzy'
                );
                $db->exec('DROP TABLE administratorzy');
                $db->exec('ALTER TABLE administratorzy_nowe RENAME TO administratorzy');
            },
        ],

        [
            'id'    => 'przebieg-meczu',
            'nazwa' => 'Przebieg meczu: gole i zmiany',
            'opis'  => 'Bez tego trener nie ma gdzie zapisać bramek ani zmian, '
                     . 'a zakładka „Szczegóły" na stronie meczu zostaje pusta.',

            'potrzebna' => static function (): bool {
                return !tabela_istnieje('zdarzenia')
                    || (tabela_istnieje('sklady') && !kolumna_istnieje('sklady', 'stan'))
                    || (tabela_istnieje('sklady') && !kolumna_istnieje('sklady', 'start_meczu'))
                    || (tabela_istnieje('sklady') && !kolumna_istnieje('sklady', 'przerwa_od'));
            },

            'zastosuj' => static function (PDO $db): void {
                /* Zdarzenia dostają własną tabelę, a nie kolejny JSON w składzie:
                   tu wiersze dochodzą pojedynczo w trakcie meczu i znikają
                   pojedynczo po pomyłce, więc kasowanie jednego ma być jednym
                   DELETE, a nie odczytem, przepisaniem i zapisem całej listy
                   — przy dwóch otwartych telefonach drugi zapis cofnąłby pierwszy.

                   Bez kluczy obcych: usunięcie zawodnika z kadry nie ma kasować
                   historii meczu, a panel i tak czyta nazwiska po id i pomija
                   te, których już nie ma. */
                if (!tabela_istnieje('zdarzenia')) {
                    $db->exec(
                        sterownik_bazy() === 'mysql'
                            ? "CREATE TABLE zdarzenia (
                                   id           INT AUTO_INCREMENT PRIMARY KEY,
                                   typ          VARCHAR(16) NOT NULL,
                                   minuta       INT         NOT NULL,
                                   polowa       TINYINT     NULL,
                                   zawodnik_id  INT         NULL,
                                   zawodnik2_id INT         NULL,
                                   utworzono    DATETIME    NOT NULL
                               ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
                            : "CREATE TABLE zdarzenia (
                                   id           INTEGER PRIMARY KEY AUTOINCREMENT,
                                   typ          TEXT    NOT NULL,
                                   minuta       INTEGER NOT NULL,
                                   polowa       INTEGER,
                                   zawodnik_id  INTEGER,
                                   zawodnik2_id INTEGER,
                                   utworzono    TEXT    NOT NULL
                               )"
                    );
                }

                /* Stan meczu siedzi przy składzie, bo to ten sam mecz: jeden
                   zapisany skład to jedno spotkanie, które się jeszcze nie
                   zaczęło, trwa albo się skończyło. */
                if (tabela_istnieje('sklady') && !kolumna_istnieje('sklady', 'stan')) {
                    $db->exec(
                        sterownik_bazy() === 'mysql'
                            ? "ALTER TABLE sklady ADD COLUMN stan VARCHAR(10) NOT NULL DEFAULT 'przed'"
                            : "ALTER TABLE sklady ADD COLUMN stan TEXT NOT NULL DEFAULT 'przed'"
                    );
                }

                /* Godzina pierwszego gwizdka. Służy wyłącznie do podpowiadania
                   minuty przy wpisywaniu zdarzeń — panel nie zna przerwy ani
                   doliczonego czasu, więc trener zawsze może minutę poprawić. */
                if (tabela_istnieje('sklady') && !kolumna_istnieje('sklady', 'start_meczu')) {
                    $db->exec(
                        sterownik_bazy() === 'mysql'
                            ? 'ALTER TABLE sklady ADD COLUMN start_meczu DATETIME NULL'
                            : 'ALTER TABLE sklady ADD COLUMN start_meczu TEXT'
                    );
                }

                /* Chwila zatrzymania stopera — na przerwę. Trzymamy moment,
                   a nie „ile już stoi": po wznowieniu przesuwamy pierwszy
                   gwizdek o długość przerwy i wracamy do jednego działania
                   (teraz minus start), zamiast sumować kawałki. */
                if (tabela_istnieje('sklady') && !kolumna_istnieje('sklady', 'przerwa_od')) {
                    $db->exec(
                        sterownik_bazy() === 'mysql'
                            ? 'ALTER TABLE sklady ADD COLUMN przerwa_od DATETIME NULL'
                            : 'ALTER TABLE sklady ADD COLUMN przerwa_od TEXT'
                    );
                }
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
