<?php
/**
 * Zakłada konto panelu. Uruchamiany z terminala, nie z przeglądarki:
 *
 *     php admin/utworz-admina.php karol
 *     php admin/utworz-admina.php mirek trener
 *     php admin/utworz-admina.php kuba wynik
 *
 * Druga wartość to rola: „administrator" (domyślna), „trener" albo „wynik".
 * Trener widzi wyłącznie ekran składu, konto „wynik" wyłącznie przebieg
 * meczu — gole, zmiany i stoper. Żadne z nich nie ma dostępu do zawodników,
 * aktualności, sponsorów ani publikacji na stronie.
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
$rola  = trim((string) ($argv[2] ?? 'administrator'));

if ($login === '') {
    exit("Użycie: php admin/utworz-admina.php <login> [administrator|trener|wynik]\n");
}

if (!in_array($rola, ['administrator', 'trener', 'wynik'], true)) {
    exit("Rola musi być „administrator”, „trener” albo „wynik”.\n");
}

/* rtrim zamiast trim: obcinamy sam koniec linii, a nie spacje z hasła.
   Formularz logowania ich nie ścina, więc hasło ze spacją na brzegu
   zapisałoby się przycięte i nigdy nie przeszłoby przez logowanie. */
echo 'Hasło dla „' . $login . "”: ";
system('stty -echo');                 // hasło nie pokazuje się podczas wpisywania
$haslo = rtrim((string) fgets(STDIN), "\r\n");
system('stty echo');
echo "\n";

if (strlen($haslo) < 10) {
    exit("Hasło musi mieć co najmniej 10 znaków.\n");
}

echo 'Powtórz hasło: ';
system('stty -echo');
$powtorka = rtrim((string) fgets(STDIN), "\r\n");
system('stty echo');
echo "\n";

if ($haslo !== $powtorka) {
    exit("Hasła się różnią.\n");
}

$hash = password_hash($haslo, PASSWORD_DEFAULT);

$stmt = baza()->prepare('SELECT id FROM administratorzy WHERE login = ?');
$stmt->execute([$login]);

/* Kolumna roli pojawia się dopiero po migracji „role". Dopóki jej nie ma,
   zakładamy konto po staremu i mówimy o tym wprost — ciche pominięcie roli
   skończyłoby się trenerem z pełnymi uprawnieniami administratora. */
$sa_role = false;

try {
    baza()->query('SELECT rola FROM administratorzy LIMIT 1');
    $sa_role = true;
} catch (PDOException $e) {
    if ($rola !== 'administrator') {
        exit("Baza nie zna jeszcze ról. Uruchom migrację „Role kont w panelu”\n"
           . "w panelu (Migracje), a potem powtórz to polecenie.\n");
    }
}

/* Rola „wynik" doszła później niż same role — stara baza przyjmie ją
   w MySQL jako pusty ciąg (tryb nieścisły) albo odrzuci w SQLite. Lepiej
   powiedzieć to wprost, niż zostawić konto bez żadnych uprawnień. */
if ($sa_role && $rola === 'wynik') {
    require_once __DIR__ . '/inc/migracje.php';

    if (strpos(definicja_roli(), "'wynik'") === false) {
        exit("Baza nie zna jeszcze roli „wynik”. Uruchom migrację „Konta do obsługi wyniku”\n"
           . "w panelu (Migracje), a potem powtórz to polecenie.\n");
    }
}

if ($stmt->fetch()) {
    if ($sa_role) {
        baza()->prepare('UPDATE administratorzy SET hash_hasla = ?, rola = ? WHERE login = ?')
              ->execute([$hash, $rola, $login]);
    } else {
        baza()->prepare('UPDATE administratorzy SET hash_hasla = ? WHERE login = ?')
              ->execute([$hash, $login]);
    }

    echo "Hasło konta „{$login}” zostało zmienione (rola: {$rola}).\n";
} else {
    if ($sa_role) {
        baza()->prepare('INSERT INTO administratorzy (login, hash_hasla, rola) VALUES (?, ?, ?)')
              ->execute([$login, $hash, $rola]);
    } else {
        baza()->prepare('INSERT INTO administratorzy (login, hash_hasla) VALUES (?, ?)')
              ->execute([$login, $hash]);
    }

    echo "Konto „{$login}” utworzone (rola: {$rola}).\n";
}
