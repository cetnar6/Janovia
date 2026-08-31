<?php
/**
 * Wypisuje gotowe polecenie SQL zakładające konto administratora.
 *
 * Po co osobne narzędzie, skoro jest utworz-admina.php: tamten zapisuje konto
 * do bazy, do której akurat ma połączenie. Uruchomiony na Twoim komputerze
 * trafi więc do lokalnego SQLite, a nie do MySQL na serwerze — a pakiet Perso
 * na OVH nie daje powłoki, więc nie da się go tam uruchomić.
 *
 * Ten skrypt niczego nie zapisuje. Liczy tylko hash i wypisuje SQL, który
 * wklejasz do phpMyAdmin na serwerze.
 *
 *     php admin/hash-hasla.php Adminjanovia
 *
 * Hasło wpisujesz interaktywnie i nie widać go podczas pisania. Nie trafia
 * ani do historii poleceń, ani do żadnego pliku.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Ten skrypt uruchamia się wyłącznie z terminala.');
}

$login = trim((string) ($argv[1] ?? ''));

if ($login === '') {
    exit("Użycie: php admin/hash-hasla.php <login>\n");
}

if (!preg_match('/^[A-Za-z0-9._-]{3,40}$/', $login)) {
    exit("Login: 3–40 znaków, tylko litery, cyfry, kropka, podkreślnik lub myślnik.\n");
}

function zapytaj(string $pytanie): string
{
    echo $pytanie;
    system('stty -echo');
    $odp = trim((string) fgets(STDIN));
    system('stty echo');
    echo "\n";
    return $odp;
}

$haslo = zapytaj('Hasło dla „' . $login . '”: ');

if (strlen($haslo) < 10) {
    exit("Hasło musi mieć co najmniej 10 znaków.\n");
}

if (zapytaj('Powtórz hasło: ') !== $haslo) {
    exit("Hasła się różnią.\n");
}

/* Ostrzeżenie, nie blokada — decyzja należy do Ciebie. Panel jest publicznie
   dostępny pod /admin/, więc nazwa klubu i rok założenia z herbu to pierwsze,
   co sprawdzi ktoś próbujący się dostać. */
$slabe = ['janovia', 'janowiec', '1998', 'admin', 'haslo', 'password'];
$male  = mb_strtolower($haslo);
foreach ($slabe as $s) {
    if (str_contains($male, $s)) {
        // klamry są konieczne: PHP dopuszcza w nazwach zmiennych bajty spoza
        // ASCII, więc bez nich wciągnąłby zamykający cudzysłów „”” do nazwy
        // i podstawił pustą wartość — ostrzeżenie zapalałoby się zawsze
        echo "UWAGA: hasło zawiera „{$s}” — łatwe do odgadnięcia dla tej strony.\n\n";
        break;
    }
}

$hash = password_hash($haslo, PASSWORD_DEFAULT);

// hash bcrypt zawiera $ i /, ale nigdy apostrofu, więc bezpiecznie wchodzi
// w pojedyncze cudzysłowy SQL bez dodatkowego escapowania
echo "Wklej to w phpMyAdmin, w bazie kosmetejanovia, zakładka SQL:\n\n";
echo "INSERT INTO administratorzy (login, hash_hasla)\n";
echo "VALUES ('" . $login . "', '" . $hash . "')\n";
echo "ON DUPLICATE KEY UPDATE hash_hasla = VALUES(hash_hasla);\n\n";
echo "Dla bazy lokalnej (SQLite) użyj zamiast tego:\n\n";
echo "INSERT OR REPLACE INTO administratorzy (login, hash_hasla)\n";
echo "VALUES ('" . $login . "', '" . $hash . "');\n";
