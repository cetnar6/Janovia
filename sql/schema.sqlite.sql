-- Schemat bazy dla pracy lokalnej (SQLite).
-- Odpowiednik dla hostingu: sql/schema.mysql.sql
--
-- Utworzenie bazy:
--     sqlite3 dane/janovia.db < sql/schema.sqlite.sql

PRAGMA foreign_keys = ON;

-- ---------- ZAWODNICY ----------

CREATE TABLE IF NOT EXISTS zawodnicy (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    imie      TEXT    NOT NULL,
    nazwisko  TEXT    NOT NULL,
    pozycja   TEXT    NOT NULL CHECK (pozycja IN ('bramkarz','obrońca','pomocnik','napastnik',
                                                   'trener','asystent trenera','trener bramkarzy','kierownik','masażysta','sztab')),
    numer     INTEGER CHECK (numer IS NULL OR (numer BETWEEN 1 AND 99)),
    -- sama nazwa pliku z katalogu uploads/zawodnicy, nie zawartość obrazu
    zdjecie   TEXT,
    aktywny   INTEGER NOT NULL DEFAULT 1 CHECK (aktywny IN (0,1)),
    -- opaska kapitana; na stronie dokłada „C" obok nazwiska
    kapitan   INTEGER NOT NULL DEFAULT 0 CHECK (kapitan IN (0,1)),
    utworzono TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
);

-- numer może się powtórzyć dopiero po odejściu poprzedniego zawodnika
CREATE UNIQUE INDEX IF NOT EXISTS zawodnicy_numer_aktywny
    ON zawodnicy (numer) WHERE aktywny = 1 AND numer IS NOT NULL;

CREATE INDEX IF NOT EXISTS zawodnicy_sort ON zawodnicy (aktywny, numer);

-- ---------- POSTY (aktualności spoza Facebooka) ----------

CREATE TABLE IF NOT EXISTS posty (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    tytul        TEXT    NOT NULL,
    tresc        TEXT    NOT NULL,
    etykieta     TEXT    NOT NULL DEFAULT 'Klub',
    zdjecie      TEXT,
    opublikowano TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
    widoczny     INTEGER NOT NULL DEFAULT 1 CHECK (widoczny IN (0,1)),
    utworzono    TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
);

CREATE INDEX IF NOT EXISTS posty_sort ON posty (widoczny, opublikowano DESC);

-- ---------- MECZE (dodawane ręcznie, np. sparingi spoza 90minut.pl) ----------

CREATE TABLE IF NOT EXISTS mecze (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    przeciwnik TEXT   NOT NULL,
    u_siebie  INTEGER NOT NULL DEFAULT 1 CHECK (u_siebie IN (0,1)),
    termin    TEXT    NOT NULL,
    etykieta  TEXT    NOT NULL DEFAULT 'Sparing',
    -- np. "3-1"; puste, dopóki mecz się nie odbędzie
    wynik     TEXT,
    -- herb przeciwnika spoza listy znanych klubów; nazwa pliku z uploads/mecze
    herb      TEXT,
    widoczny  INTEGER NOT NULL DEFAULT 1 CHECK (widoczny IN (0,1)),
    utworzono TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
);

CREATE INDEX IF NOT EXISTS mecze_sort ON mecze (widoczny, termin);

-- ---------- SPONSORZY (przewijający się pasek na stronie głównej) ----------

CREATE TABLE IF NOT EXISTS sponsorzy (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    nazwa     TEXT    NOT NULL,
    -- podpis pod logo, np. "Partner główny"; dowolny tekst, bo kategorie
    -- sponsorów zmieniają się częściej niż kod strony
    rola      TEXT    NOT NULL DEFAULT 'Sponsor',
    -- sama nazwa pliku z katalogu uploads/sponsorzy, nie zawartość obrazu
    logo      TEXT    NOT NULL,
    -- adres strony sponsora; puste = logo bez odnośnika
    strona    TEXT,
    -- delikatna poświata pod logo — wyróżnia najważniejszych partnerów
    poswiata  INTEGER NOT NULL DEFAULT 0 CHECK (poswiata IN (0,1)),
    -- kolejność w pasku; przy równych decyduje nazwa
    kolejnosc INTEGER NOT NULL DEFAULT 0,
    widoczny  INTEGER NOT NULL DEFAULT 1 CHECK (widoczny IN (0,1)),
    utworzono TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
);

CREATE INDEX IF NOT EXISTS sponsorzy_sort ON sponsorzy (widoczny, kolejnosc);

-- ---------- ADMINISTRATORZY ----------

CREATE TABLE IF NOT EXISTS administratorzy (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    login        TEXT    NOT NULL UNIQUE,
    -- wynik password_hash(), nigdy hasło jawnie
    hash_hasla   TEXT    NOT NULL,
    -- trener widzi wyłącznie ekran składu, „wynik" wyłącznie przebieg meczu;
    -- administrator cały panel
    rola         TEXT    NOT NULL DEFAULT 'administrator'
                         CHECK (rola IN ('administrator','trener','wynik')),
    utworzono    TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
    ostatnie_logowanie TEXT
);

-- ---------- SKŁADY (panel trenera) ----------

-- Ustawienie trzymamy jako JSON, nie wiersz na pozycję: liczba i nazwy pól
-- zależą od formacji, więc osobna tabela i tak skończyłaby jako worek na
-- tekst. Co wolno zapisać, pilnuje PHP przy zapisie — musi i tak sprawdzić,
-- czy zawodnik istnieje i czy pole należy do wybranej formacji.
CREATE TABLE IF NOT EXISTS sklady (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    -- spotkanie z terminarza, na które ustawiono ten skład:
    -- klucz „kolejka|gospodarz|gość", podpis i termin (do nazwania meczu,
    -- gdy wypadnie już z terminarza)
    mecz_klucz     TEXT,
    mecz_opis      TEXT,
    mecz_data      TEXT,
    -- opis meczu wpisywany ręcznie, np. „Janovia — Sokół Pień, 11.10"
    nazwa          TEXT NOT NULL DEFAULT '',
    formacja       TEXT NOT NULL,
    -- mapa: identyfikator pola => identyfikator zawodnika
    ustawienie     TEXT NOT NULL,
    -- lista identyfikatorów: ławka rezerwowych, w kolejności ustalonej przez trenera
    rezerwowi      TEXT NOT NULL DEFAULT '[]',
    -- tylko zatwierdzony skład trafia do data/sklad.json i na stronę klubu
    opublikowany   INTEGER NOT NULL DEFAULT 0 CHECK (opublikowany IN (0,1)),
    opublikowano   TEXT,
    -- przed | trwa | koniec — jeden zapisany skład to jedno spotkanie
    stan           TEXT NOT NULL DEFAULT 'przed' CHECK (stan IN ('przed','trwa','koniec')),
    -- godzina pierwszego gwizdka; podpowiada minutę przy wpisywaniu zdarzeń
    start_meczu    TEXT,
    -- chwila zatrzymania stopera (przerwa); NULL = mecz leci
    przerwa_od     TEXT,
    zaktualizowano TEXT NOT NULL,
    utworzono      TEXT NOT NULL
);

-- ---------- PRZEBIEG MECZU ----------

-- Gole i zmiany dopisywane na żywo. Osobne wiersze, nie JSON przy składzie:
-- w trakcie meczu zdarzenia dochodzą i znikają pojedynczo, więc kasowanie
-- pomyłki ma być jednym DELETE, a nie przepisaniem całej listy.
-- Bez kluczy obcych do zawodników: skreślenie kogoś z kadry nie może
-- wymazywać historii meczu.
CREATE TABLE IF NOT EXISTS zdarzenia (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    -- gol | gol_rywala | zmiana
    typ          TEXT    NOT NULL CHECK (typ IN ('gol','gol_rywala','zmiana')),
    minuta       INTEGER NOT NULL,
    -- gol: strzelec; zmiana: wchodzący
    zawodnik_id  INTEGER,
    -- gol: asystujący; zmiana: schodzący
    zawodnik2_id INTEGER,
    utworzono    TEXT    NOT NULL
);
