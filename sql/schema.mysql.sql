-- Schemat bazy dla hostingu (MySQL / MariaDB).
-- Odpowiednik lokalny: sql/schema.sqlite.sql
--
-- Import w phpMyAdmin albo:
--     mysql -u uzytkownik -p nazwa_bazy < sql/schema.mysql.sql

SET NAMES utf8mb4;

-- ---------- ZAWODNICY ----------

CREATE TABLE IF NOT EXISTS zawodnicy (
    id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    imie      VARCHAR(50)  NOT NULL,
    nazwisko  VARCHAR(60)  NOT NULL,
    pozycja   ENUM('bramkarz','obrońca','pomocnik','napastnik',
                  'trener','asystent trenera','trener bramkarzy','kierownik','masażysta','sztab') NOT NULL,
    numer     TINYINT UNSIGNED NULL,
    -- sama nazwa pliku z katalogu uploads/zawodnicy, nie zawartość obrazu
    zdjecie   VARCHAR(120) NULL,
    aktywny   TINYINT(1)   NOT NULL DEFAULT 1,
    -- opaska kapitana; na stronie dokłada „C" obok nazwiska
    kapitan   TINYINT(1)   NOT NULL DEFAULT 0,
    utworzono TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX zawodnicy_sort (aktywny, numer)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci;

-- MySQL nie zna indeksów częściowych (WHERE aktywny = 1), więc unikalność
-- numeru wśród aktywnych pilnuje kolumna wyliczana: dla zawodnika nieaktywnego
-- przyjmuje NULL, a NULL-e nie kolidują ze sobą w indeksie UNIQUE.
ALTER TABLE zawodnicy
    ADD COLUMN numer_aktywny TINYINT UNSIGNED
        GENERATED ALWAYS AS (IF(aktywny = 1, numer, NULL)) VIRTUAL,
    ADD UNIQUE KEY zawodnicy_numer_aktywny (numer_aktywny);

-- ---------- POSTY (aktualności spoza Facebooka) ----------

CREATE TABLE IF NOT EXISTS posty (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tytul        VARCHAR(160) NOT NULL,
    tresc        TEXT         NOT NULL,
    etykieta     VARCHAR(30)  NOT NULL DEFAULT 'Klub',
    zdjecie      VARCHAR(120) NULL,
    opublikowano DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    widoczny     TINYINT(1)   NOT NULL DEFAULT 1,
    utworzono    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX posty_sort (widoczny, opublikowano)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci;

-- ---------- MECZE (dodawane ręcznie, np. sparingi spoza 90minut.pl) ----------

CREATE TABLE IF NOT EXISTS mecze (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    przeciwnik VARCHAR(80)  NOT NULL,
    u_siebie   TINYINT(1)   NOT NULL DEFAULT 1,
    termin     DATETIME     NOT NULL,
    etykieta   VARCHAR(30)  NOT NULL DEFAULT 'Sparing',
    -- np. "3-1"; puste, dopóki mecz się nie odbędzie
    wynik      VARCHAR(10)  NULL,
    -- herb przeciwnika spoza listy znanych klubów; nazwa pliku z uploads/mecze
    herb       VARCHAR(120) NULL,
    widoczny   TINYINT(1)   NOT NULL DEFAULT 1,
    utworzono  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX mecze_sort (widoczny, termin)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci;

-- ---------- SPONSORZY (przewijający się pasek na stronie głównej) ----------

CREATE TABLE IF NOT EXISTS sponsorzy (
    id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nazwa     VARCHAR(80)  NOT NULL,
    -- podpis pod logo, np. "Partner główny"; dowolny tekst, bo kategorie
    -- sponsorów zmieniają się częściej niż kod strony
    rola      VARCHAR(40)  NOT NULL DEFAULT 'Sponsor',
    -- sama nazwa pliku z katalogu uploads/sponsorzy, nie zawartość obrazu
    logo      VARCHAR(120) NOT NULL,
    -- adres strony sponsora; puste = logo bez odnośnika
    strona    VARCHAR(255) NULL,
    -- delikatna poświata pod logo — wyróżnia najważniejszych partnerów
    poswiata  TINYINT(1)   NOT NULL DEFAULT 0,
    -- kolejność w pasku; przy równych decyduje nazwa
    kolejnosc INT          NOT NULL DEFAULT 0,
    widoczny  TINYINT(1)   NOT NULL DEFAULT 1,
    utworzono TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX sponsorzy_sort (widoczny, kolejnosc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci;

-- ---------- ADMINISTRATORZY ----------

CREATE TABLE IF NOT EXISTS administratorzy (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    login              VARCHAR(40)  NOT NULL UNIQUE,
    -- wynik password_hash(), nigdy hasło jawnie
    hash_hasla         VARCHAR(255) NOT NULL,
    -- trener widzi wyłącznie ekran składu, „wynik" wyłącznie przebieg meczu;
    -- administrator cały panel
    rola               ENUM('administrator','trener','wynik') NOT NULL DEFAULT 'administrator',
    utworzono          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ostatnie_logowanie DATETIME     NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci;

-- ---------- SKŁADY (panel trenera) ----------

-- Ustawienie trzymamy jako JSON, nie wiersz na pozycję: liczba i nazwy pól
-- zależą od formacji, więc osobna tabela i tak skończyłaby jako worek na
-- tekst. Co wolno zapisać, pilnuje PHP przy zapisie — musi i tak sprawdzić,
-- czy zawodnik istnieje i czy pole należy do wybranej formacji.
CREATE TABLE IF NOT EXISTS sklady (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    -- spotkanie z terminarza, na które ustawiono ten skład:
    -- klucz „kolejka|gospodarz|gość", podpis i termin (do nazwania meczu,
    -- gdy wypadnie już z terminarza)
    mecz_klucz     VARCHAR(120) NULL,
    mecz_opis      VARCHAR(160) NULL,
    mecz_data      DATETIME     NULL,
    -- opis meczu wpisywany ręcznie, np. „Janovia — Sokół Pień, 11.10"
    nazwa          VARCHAR(160) NOT NULL DEFAULT '',
    formacja       VARCHAR(20)  NOT NULL,
    -- mapa: identyfikator pola => identyfikator zawodnika
    ustawienie     TEXT         NOT NULL,
    -- lista identyfikatorów: ławka rezerwowych, w kolejności ustalonej przez trenera.
    -- NULL, a nie DEFAULT '[]', bo MySQL starszy niż 8.0.13 nie pozwala TEXT-owi
    -- na wartość domyślną; pustą ławkę PHP i tak czyta z NULL-a tak samo.
    rezerwowi      TEXT         NULL,
    -- tylko zatwierdzony skład trafia do data/sklad.json i na stronę klubu
    opublikowany   TINYINT(1)   NOT NULL DEFAULT 0,
    opublikowano   DATETIME     NULL,
    -- przed | trwa | koniec — jeden zapisany skład to jedno spotkanie
    stan           VARCHAR(10)  NOT NULL DEFAULT 'przed',
    -- godzina pierwszego gwizdka; podpowiada minutę przy wpisywaniu zdarzeń
    start_meczu    DATETIME     NULL,
    -- chwila zatrzymania stopera (przerwa); NULL = mecz leci
    przerwa_od     DATETIME     NULL,
    zaktualizowano DATETIME     NOT NULL,
    utworzono      DATETIME     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci;

-- ---------- PRZEBIEG MECZU ----------

-- Gole i zmiany dopisywane na żywo. Osobne wiersze, nie JSON przy składzie:
-- w trakcie meczu zdarzenia dochodzą i znikają pojedynczo, więc kasowanie
-- pomyłki ma być jednym DELETE, a nie przepisaniem całej listy.
-- Bez kluczy obcych do zawodników: skreślenie kogoś z kadry nie może
-- wymazywać historii meczu.
CREATE TABLE IF NOT EXISTS zdarzenia (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    -- gol | gol_rywala | zmiana
    typ          VARCHAR(16) NOT NULL,
    minuta       INT         NOT NULL,
    -- gol: strzelec; zmiana: wchodzący
    zawodnik_id  INT         NULL,
    -- gol: asystujący; zmiana: schodzący
    zawodnik2_id INT         NULL,
    utworzono    DATETIME    NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci;
