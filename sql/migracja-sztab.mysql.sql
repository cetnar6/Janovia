-- Dopuszcza pozycję 'sztab' w istniejącej bazie na hostingu.
--
-- Schemat w sql/schema.mysql.sql zakłada bazę tworzoną od zera; ta migracja
-- jest dla bazy, która już działa. Kolumna pozycja to ENUM, więc bez tej
-- zmiany panel odrzuca zapis trenera z błędem "Data truncated for column".
--
-- Uruchomienie: phpMyAdmin → zakładka SQL → wklej poniższe → Wykonaj.
-- Albo:
--     mysql -u uzytkownik -p nazwa_bazy < sql/migracja-sztab.mysql.sql
--
-- Rozszerzenie listy ENUM nie rusza istniejących wierszy i można je
-- uruchomić drugi raz bez szkody.

ALTER TABLE zawodnicy
    MODIFY pozycja ENUM('bramkarz','obrońca','pomocnik','napastnik','sztab') NOT NULL;
