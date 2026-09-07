-- To samo co sql/migracja-sztab.mysql.sql, ale dla bazy lokalnej (SQLite).
--
-- SQLite nie umie zmienić warunku CHECK w miejscu — trzeba przepisać tabelę.
-- Kolejność ma znaczenie: dane najpierw przechodzą do nowej tabeli, dopiero
-- potem znika stara, a wszystko dzieje się w jednej transakcji.
--
-- Uruchomienie:
--     sqlite3 dane/janovia.db < sql/migracja-sztab.sqlite.sql

PRAGMA foreign_keys = OFF;

BEGIN;

CREATE TABLE zawodnicy_nowe (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    imie      TEXT    NOT NULL,
    nazwisko  TEXT    NOT NULL,
    pozycja   TEXT    NOT NULL CHECK (pozycja IN ('bramkarz','obrońca','pomocnik','napastnik','sztab')),
    numer     INTEGER CHECK (numer IS NULL OR (numer BETWEEN 1 AND 99)),
    zdjecie   TEXT,
    aktywny   INTEGER NOT NULL DEFAULT 1 CHECK (aktywny IN (0,1)),
    utworzono TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
);

INSERT INTO zawodnicy_nowe (id, imie, nazwisko, pozycja, numer, zdjecie, aktywny, utworzono)
    SELECT id, imie, nazwisko, pozycja, numer, zdjecie, aktywny, utworzono FROM zawodnicy;

DROP TABLE zawodnicy;

ALTER TABLE zawodnicy_nowe RENAME TO zawodnicy;

CREATE UNIQUE INDEX IF NOT EXISTS zawodnicy_numer_aktywny
    ON zawodnicy (numer) WHERE aktywny = 1 AND numer IS NOT NULL;

CREATE INDEX IF NOT EXISTS zawodnicy_sort ON zawodnicy (aktywny, numer);

COMMIT;

PRAGMA foreign_keys = ON;
