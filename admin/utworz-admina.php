<?php
/**
 * Zakłada konto administratora. Uruchamiany z terminala, nie z przeglądarki:
 *
 *     php admin/utworz-admina.php karol
 *
 * Hasło podajesz interaktywnie — nie trafia do historii poleceń ani do repozytorium.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Ten skrypt uruchamia się wyłącznie z terminala.');
}

require_once __DIR__ . '/inc/db.php';

$login = trim((string) ($argv[1] ?? ''));

if ($login === '') {
    exit("Użycie: php admin/utworz-admina.php <login>\n");
}

echo 'Hasło dla „' . $login . "”: ";
system('stty -echo');                 // hasło nie pokazuje się podczas wpisywania
$haslo = trim((string) fgets(STDIN));
system('stty echo');
echo "\n";

if (strlen($haslo) < 10) {
    exit("Hasło musi mieć co najmniej 10 znaków.\n");
}

echo 'Powtórz hasło: ';
system('stty -echo');
$powtorka = trim((string) fgets(STDIN));
system('stty echo');
echo "\n";

if ($haslo !== $powtorka) {
    exit("Hasła się różnią.\n");
}

$hash = password_hash($haslo, PASSWORD_DEFAULT);

$stmt = baza()->prepare('SELECT id FROM administratorzy WHERE login = ?');
$stmt->execute([$login]);

if ($stmt->fetch()) {
    baza()->prepare('UPDATE administratorzy SET hash_hasla = ? WHERE login = ?')
          ->execute([$hash, $login]);
    echo "Hasło konta „$login” zostało zmienione.\n";
} else {
    baza()->prepare('INSERT INTO administratorzy (login, hash_hasla) VALUES (?, ?)')
          ->execute([$login, $hash]);
    echo "Konto „$login” utworzone.\n";
}
