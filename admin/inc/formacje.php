<?php
/**
 * Ustawienia boiskowe dostępne w panelu trenera.
 *
 * Jedno źródło dla serwera i przeglądarki. Skrypt na stronie dostaje tę samą
 * tablicę w JSON-ie, więc nie da się doprowadzić do sytuacji, w której boisko
 * rysuje pola, których walidacja zapisu nie zna (albo odwrotnie).
 *
 * Współrzędne są w procentach prostokąta boiska: x liczone od lewej,
 * y od góry. Boisko stoi pionowo, własna bramka na dole — tak, jak trener
 * rysuje skład na kartce.
 */

declare(strict_types=1);

/**
 * Grupa pozycji dla pola. Służy tylko do podpowiedzi: pole oznaczone jako
 * obrona podświetla się przy przeciąganiu obrońcy. Panel NIE blokuje
 * wstawienia napastnika na obronę — trenerzy robią takie rzeczy świadomie
 * i panel nie jest od sprzeczania się z nimi.
 */
function formacje(): array
{
    return [
        '4-4-2' => [
            'opis'  => 'Klasyka — dwóch napastników i równa czwórka w pomocy.',
            'pola' => [
                ['id' => 'br',  'etykieta' => 'BR', 'x' => 50, 'y' => 91, 'grupa' => 'bramkarz'],

                ['id' => 'lo',  'etykieta' => 'LO', 'x' => 15, 'y' => 72, 'grupa' => 'obrońca'],
                ['id' => 'so1', 'etykieta' => 'ŚO', 'x' => 38, 'y' => 75, 'grupa' => 'obrońca'],
                ['id' => 'so2', 'etykieta' => 'ŚO', 'x' => 62, 'y' => 75, 'grupa' => 'obrońca'],
                ['id' => 'po',  'etykieta' => 'PO', 'x' => 85, 'y' => 72, 'grupa' => 'obrońca'],

                ['id' => 'lp',  'etykieta' => 'LP', 'x' => 15, 'y' => 48, 'grupa' => 'pomocnik'],
                ['id' => 'sp1', 'etykieta' => 'ŚP', 'x' => 38, 'y' => 51, 'grupa' => 'pomocnik'],
                ['id' => 'sp2', 'etykieta' => 'ŚP', 'x' => 62, 'y' => 51, 'grupa' => 'pomocnik'],
                ['id' => 'pp',  'etykieta' => 'PP', 'x' => 85, 'y' => 48, 'grupa' => 'pomocnik'],

                ['id' => 'n1',  'etykieta' => 'NAP', 'x' => 36, 'y' => 22, 'grupa' => 'napastnik'],
                ['id' => 'n2',  'etykieta' => 'NAP', 'x' => 64, 'y' => 22, 'grupa' => 'napastnik'],
            ],
        ],

        '4-3-3' => [
            'opis'  => 'Trzech z przodu, skrzydłowi wysoko przy linii.',
            'pola' => [
                ['id' => 'br',  'etykieta' => 'BR', 'x' => 50, 'y' => 91, 'grupa' => 'bramkarz'],

                ['id' => 'lo',  'etykieta' => 'LO', 'x' => 15, 'y' => 72, 'grupa' => 'obrońca'],
                ['id' => 'so1', 'etykieta' => 'ŚO', 'x' => 38, 'y' => 75, 'grupa' => 'obrońca'],
                ['id' => 'so2', 'etykieta' => 'ŚO', 'x' => 62, 'y' => 75, 'grupa' => 'obrońca'],
                ['id' => 'po',  'etykieta' => 'PO', 'x' => 85, 'y' => 72, 'grupa' => 'obrońca'],

                ['id' => 'sp1', 'etykieta' => 'DP', 'x' => 50, 'y' => 58, 'grupa' => 'pomocnik'],
                ['id' => 'sp2', 'etykieta' => 'ŚP', 'x' => 27, 'y' => 45, 'grupa' => 'pomocnik'],
                ['id' => 'sp3', 'etykieta' => 'ŚP', 'x' => 73, 'y' => 45, 'grupa' => 'pomocnik'],

                ['id' => 'ls',  'etykieta' => 'LS',  'x' => 16, 'y' => 22, 'grupa' => 'napastnik'],
                ['id' => 'n1',  'etykieta' => 'NAP', 'x' => 50, 'y' => 18, 'grupa' => 'napastnik'],
                ['id' => 'ps',  'etykieta' => 'PS',  'x' => 84, 'y' => 22, 'grupa' => 'napastnik'],
            ],
        ],

        '3-5-2' => [
            'opis'  => 'Trójka stoperów, wahadłowi schodzą do obrony i wracają.',
            'pola' => [
                ['id' => 'br',  'etykieta' => 'BR', 'x' => 50, 'y' => 91, 'grupa' => 'bramkarz'],

                ['id' => 'so1', 'etykieta' => 'ŚO', 'x' => 27, 'y' => 75, 'grupa' => 'obrońca'],
                ['id' => 'so2', 'etykieta' => 'ŚO', 'x' => 50, 'y' => 77, 'grupa' => 'obrońca'],
                ['id' => 'so3', 'etykieta' => 'ŚO', 'x' => 73, 'y' => 75, 'grupa' => 'obrońca'],

                ['id' => 'lw',  'etykieta' => 'LW', 'x' => 10, 'y' => 50, 'grupa' => 'pomocnik'],
                ['id' => 'sp1', 'etykieta' => 'ŚP', 'x' => 31, 'y' => 55, 'grupa' => 'pomocnik'],
                ['id' => 'sp2', 'etykieta' => 'ŚP', 'x' => 50, 'y' => 47, 'grupa' => 'pomocnik'],
                ['id' => 'sp3', 'etykieta' => 'ŚP', 'x' => 69, 'y' => 55, 'grupa' => 'pomocnik'],
                ['id' => 'pw',  'etykieta' => 'PW', 'x' => 90, 'y' => 50, 'grupa' => 'pomocnik'],

                ['id' => 'n1',  'etykieta' => 'NAP', 'x' => 36, 'y' => 21, 'grupa' => 'napastnik'],
                ['id' => 'n2',  'etykieta' => 'NAP', 'x' => 64, 'y' => 21, 'grupa' => 'napastnik'],
            ],
        ],
    ];
}

/** Nazwa formacji, która ma być zaznaczona, gdy zapisana nie istnieje. */
function formacja_domyslna(): string
{
    return '4-4-2';
}

/** Czy panel zna taką formację — brama wejściowa dla danych z formularza. */
function czy_znana_formacja(string $nazwa): bool
{
    return array_key_exists($nazwa, formacje());
}

/** Identyfikatory pól danej formacji; pusta tablica dla nieznanej nazwy. */
function pola_formacji(string $nazwa): array
{
    $wszystkie = formacje();

    if (!isset($wszystkie[$nazwa])) {
        return [];
    }

    return array_column($wszystkie[$nazwa]['pola'], 'id');
}
