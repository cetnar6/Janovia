#!/usr/bin/env python3
"""
Pobiera tabelę i terminarz z 90minut.pl i zapisuje je do data/liga.json,
skąd czyta je strona klubu. Uruchamiany raz dziennie (launchd / cron).

Użycie:
    python3 update_liga.py            # normalny przebieg
    python3 update_liga.py --dry-run  # wypisz wynik, nie zapisuj pliku
"""

import json
import os
import re
import sys
import urllib.request
from datetime import datetime
from html import unescape

URL = "http://www.90minut.pl/liga/1/liga15094.html"
NASZ_KLUB = "Janovia Janowiec"

BASE = os.path.dirname(os.path.abspath(__file__))
OUT = os.path.join(BASE, "data", "liga.json")

MIESIACE = {
    "stycznia": 1, "lutego": 2, "marca": 3, "kwietnia": 4,
    "maja": 5, "czerwca": 6, "lipca": 7, "sierpnia": 8,
    "września": 9, "wrzesnia": 9, "października": 10, "pazdziernika": 10,
    "listopada": 11, "grudnia": 12,
}


def pobierz(url):
    req = urllib.request.Request(url, headers={
        "User-Agent": "KS Janovia Janowiec - aktualizacja tabeli (1x dziennie)",
    })
    with urllib.request.urlopen(req, timeout=30) as r:
        return r.read().decode("iso-8859-2", errors="replace")


def czysc(s):
    """Usuwa tagi, encje i nadmiarowe spacje z fragmentu HTML."""
    s = re.sub(r"<[^>]+>", "", s)
    return unescape(s).replace("\xa0", " ").strip()


def sezon_lata(html):
    """Z tytułu 'Klasa B 2026/2027, grupa: ...' wyciąga (2026, 2027)."""
    m = re.search(r"(\d{4})\s*/\s*(\d{4})", html)
    if m:
        return int(m.group(1)), int(m.group(2))
    rok = datetime.now().year
    return rok, rok + 1


def nazwa_ligi(html):
    m = re.search(r"<title>(.*?)</title>", html, re.S)
    return czysc(m.group(1)) if m else ""


def parsuj_tabele(html):
    """
    Wiersz tabeli: <td><b>poz</b></td><td align=left>klub</td>
    potem M, Pkt, Z, R, P, Bramki (kolumna RAZEM).
    """
    tabela = []
    wzor = re.compile(
        r"<tr[^>]*>\s*"
        r"<td><b>(\d*)\.?</b></td>\s*"
        r'<td align="left">(.*?)</td>\s*'
        r"<td>(\d+)</td>\s*"
        r"<td><b>(-?\d+)</b></td>\s*"
        r"<td>(\d+)</td>\s*<td>(\d+)</td>\s*<td>(\d+)</td>\s*"
        r"<td>(\d+\s*-\s*\d+)</td>",
        re.S,
    )

    ostatnia_pozycja = 0
    for m in wzor.finditer(html):
        poz = int(m.group(1)) if m.group(1) else ostatnia_pozycja + 1
        ostatnia_pozycja = poz
        tabela.append({
            "poz": poz,
            "klub": czysc(m.group(2)),
            "m": int(m.group(3)),
            "pkt": int(m.group(4)),
            "z": int(m.group(5)),
            "r": int(m.group(6)),
            "p": int(m.group(7)),
            "bramki": czysc(m.group(8)).replace(" ", ""),
        })

    return tabela


def parsuj_terminarz(html, rok_jesien, rok_wiosna):
    """
    Nagłówek kolejki:  <b><u>Kolejka 3 - 5-6 września</u></b>
    Mecz: 4 komórki — gospodarz | wynik lub '-' | gość | data i godzina
    """
    mecze = []
    kolejka = None

    naglowek = re.compile(r"<b><u>\s*Kolejka\s*(\d+)\s*-\s*([^<]*)</u></b>", re.S)
    wiersz = re.compile(
        r'<td nowrap valign="top" width="180">(.*?)</td>\s*'
        r'<td nowrap valign="top" align="center" width="50">(.*?)</td>\s*'
        r'<td nowrap valign="top" width="180">(.*?)</td>\s*'
        r'<td valign="top" nowrap align="left" width="190">(.*?)</td>',
        re.S,
    )

    znaczniki = [(m.start(), m) for m in naglowek.finditer(html)]

    for i, (start, m) in enumerate(znaczniki):
        kolejka = int(m.group(1))
        etykieta = czysc(m.group(2))
        koniec = znaczniki[i + 1][0] if i + 1 < len(znaczniki) else len(html)

        for w in wiersz.finditer(html, start, koniec):
            gospodarz = czysc(w.group(1))
            wynik = czysc(w.group(2))
            gosc = czysc(w.group(3))
            termin = czysc(w.group(4))

            if not gospodarz or not gosc:
                continue

            mecze.append({
                "kolejka": kolejka,
                "kolejka_opis": etykieta,
                "gospodarz": gospodarz,
                "gosc": gosc,
                "wynik": wynik if re.match(r"^\d+\s*-\s*\d+$", wynik) else None,
                "termin": termin or None,
                # dokładny termin bywa pusty — wtedy bierzemy pierwszy dzień z nagłówka kolejki
                "data_iso": (na_iso(termin, rok_jesien, rok_wiosna)
                             or na_iso(etykieta, rok_jesien, rok_wiosna)),
                "data_przyblizona": not bool(na_iso(termin, rok_jesien, rok_wiosna)),
            })

    return mecze


def na_iso(termin, rok_jesien, rok_wiosna):
    """'9 sierpnia, 16:00' -> '2026-08-09T16:00:00'. Bez godziny -> data o 00:00."""
    if not termin:
        return None

    # obsługuje '9 sierpnia, 16:00' oraz zakres z nagłówka kolejki '22-23 sierpnia'
    m = re.match(
        r"(\d{1,2})(?:\s*-\s*\d{1,2})?\s+([a-ząćęłńóśźż]+)(?:,\s*(\d{1,2}):(\d{2}))?",
        termin, re.I,
    )
    if not m:
        return None

    miesiac = MIESIACE.get(m.group(2).lower())
    if not miesiac:
        return None

    # sezon przełamuje się na Nowy Rok: lipiec–grudzień to pierwszy rok
    rok = rok_jesien if miesiac >= 7 else rok_wiosna

    try:
        return datetime(
            rok, miesiac, int(m.group(1)),
            int(m.group(3) or 0), int(m.group(4) or 0)
        ).isoformat()
    except ValueError:
        return None


def main():
    dry = "--dry-run" in sys.argv

    html = pobierz(URL)
    rok_jesien, rok_wiosna = sezon_lata(html)

    tabela = parsuj_tabele(html)
    mecze = parsuj_terminarz(html, rok_jesien, rok_wiosna)

    if not tabela and not mecze:
        raise SystemExit("Nie udało się nic sparsować — strona 90minut zmieniła układ?")

    nasze = [m for m in mecze if NASZ_KLUB in (m["gospodarz"], m["gosc"])]
    nadchodzace = [m for m in nasze if not m["wynik"]]
    rozegrane = [m for m in nasze if m["wynik"]]

    dane = {
        "zrodlo": URL,
        "liga": nazwa_ligi(html),
        "klub": NASZ_KLUB,
        "zaktualizowano": datetime.now().isoformat(timespec="seconds"),
        "tabela": tabela,
        "nadchodzace": nadchodzace[:8],
        "ostatni": rozegrane[-1] if rozegrane else None,
        "wszystkie_mecze": mecze,
    }

    tekst = json.dumps(dane, ensure_ascii=False, indent=2)

    if dry:
        print(tekst)
        return

    os.makedirs(os.path.dirname(OUT), exist_ok=True)
    with open(OUT, "w", encoding="utf-8") as f:
        f.write(tekst + "\n")

    print("Zapisano {}: {} zespołów w tabeli, {} meczów, {} nadchodzących.".format(
        os.path.relpath(OUT, BASE), len(tabela), len(mecze), len(nadchodzace)
    ))


if __name__ == "__main__":
    main()
