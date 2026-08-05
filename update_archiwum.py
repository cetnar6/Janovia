#!/usr/bin/env python3
"""
Pobiera archiwalne tabele ligowe klubu z 90minut.pl (strona skarb.php) i zapisuje
je do data/archiwum.json, skąd czyta je podstrona archiwum.html.

Skarb.php wylicza wszystkie rozgrywki, w jakich klub kiedykolwiek brał udział —
stąd jedno pobranie tej strony wystarcza, żeby poznać adresy tabel ligowych
z każdego sezonu (ten sam format, który już umie czytać update_liga.py).
Sezony zakończone są zapisywane raz i nie pobierane ponownie; sezon bieżący
jest odświeżany przy każdym uruchomieniu.

Użycie:
    python3 update_archiwum.py            # normalny przebieg
    python3 update_archiwum.py --dry-run  # wypisz wynik, nie zapisuj pliku
"""

import json
import os
import re
import sys
from datetime import datetime

from update_liga import pobierz, czysc, nazwa_ligi, parsuj_tabele

ID_KLUB = 22352
SKARB_URL = "http://www.90minut.pl/skarb.php?id_klub={}&id_sezon=97".format(ID_KLUB)
NASZ_KLUB = "Janovia Janowiec"

BASE = os.path.dirname(os.path.abspath(__file__))
OUT = os.path.join(BASE, "data", "archiwum.json")


def znajdz_sezony_ligowe(html):
    """
    Z listy 'Rozgrywki z udziałem' wyciąga tylko rozgrywki ligowe
    (pomija Puchar Polski) — po jednej tabeli na sezon.
    """
    wzor = re.compile(
        r'<a href="(/liga/1/liga\d+\.html)" class="main">'
        r"(\d{4}/\d{2}) - \s*(.*?)</a>",
        re.S,
    )

    sezony = []
    for m in wzor.finditer(html):
        opis = czysc(m.group(3))
        if "puchar" in opis.lower():
            continue
        sezony.append({
            "sezon": m.group(2),
            "zrodlo": "http://www.90minut.pl" + m.group(1),
            "opis": opis,
        })

    return sezony


def pozycja_klubu(tabela):
    for w in tabela:
        if w["klub"] == NASZ_KLUB:
            return w
    return None


def main():
    dry = "--dry-run" in sys.argv

    stare = {}
    if os.path.isfile(OUT):
        with open(OUT, encoding="utf-8") as f:
            for s in json.load(f).get("sezony", []):
                stare[s["zrodlo"]] = s

    html = pobierz(SKARB_URL)
    znalezione = znajdz_sezony_ligowe(html)

    if not znalezione:
        raise SystemExit("Nie znalazłem żadnego sezonu ligowego — 90minut zmienił układ strony?")

    biezacy = max(znalezione, key=lambda s: s["sezon"])["zrodlo"]

    sezony = []
    for s in znalezione:
        if s["zrodlo"] != biezacy and s["zrodlo"] in stare:
            sezony.append(stare[s["zrodlo"]])
            continue

        html_ligi = pobierz(s["zrodlo"])
        tabela = parsuj_tabele(html_ligi)

        if not tabela:
            print("  pominięto (pusta tabela):", s["zrodlo"], file=sys.stderr)
            continue

        sezony.append({
            "sezon": s["sezon"],
            "liga": nazwa_ligi(html_ligi) or s["opis"],
            "zrodlo": s["zrodlo"],
            "bierzacy": s["zrodlo"] == biezacy,
            "nasza_pozycja": pozycja_klubu(tabela),
            "tabela": tabela,
        })

    # najnowszy sezon pierwszy
    sezony.sort(key=lambda s: s["sezon"], reverse=True)

    dane = {
        "klub": NASZ_KLUB,
        "zaktualizowano": datetime.now().isoformat(timespec="seconds"),
        "sezony": sezony,
    }

    tekst = json.dumps(dane, ensure_ascii=False, indent=2)

    if dry:
        print(tekst)
        return

    os.makedirs(os.path.dirname(OUT), exist_ok=True)
    with open(OUT, "w", encoding="utf-8") as f:
        f.write(tekst + "\n")

    print("Zapisano {}: {} sezonów.".format(os.path.relpath(OUT, BASE), len(sezony)))


if __name__ == "__main__":
    main()
