#!/usr/bin/env python3
"""
Pobiera ostatnie posty ze strony klubu na Facebooku przez Graph API
i zapisuje je do data/facebook.json. Zdjęcia ląduje lokalnie w data/fb/,
bo adresy z fbcdn.net wygasają po kilku dniach.

Token NIE jest zapisany w kodzie. Skrypt czyta go ze zmiennej środowiskowej:

    export FB_TOKEN='...'          # token dostępu do strony
    python3 update_fb.py

W GitHub Actions token siedzi w sekrecie repozytorium o tej samej nazwie.
"""

import json
import os
import re
import sys
import urllib.parse
import urllib.request
from datetime import datetime, timezone

API = "https://graph.facebook.com/v21.0"
ILE_POSTOW = 10

BASE = os.path.dirname(os.path.abspath(__file__))
OUT = os.path.join(BASE, "data", "facebook.json")
KAT_ZDJEC = os.path.join(BASE, "data", "fb")

POLA = ",".join([
    "id",
    "message",
    "created_time",
    "permalink_url",
    "full_picture",
    # subattachments to zdjęcia w środku albumu — bez tego dostajemy tylko
    # media_type="album" i jedno full_picture, a nie całą galerię
    "attachments{media_type,media,subattachments{media}}",
])


def graph(sciezka, token, cichy=False, **params):
    params["access_token"] = token
    url = API + sciezka + "?" + urllib.parse.urlencode(params)

    try:
        with urllib.request.urlopen(url, timeout=30) as r:
            return json.loads(r.read().decode("utf-8"))
    except urllib.error.HTTPError as e:
        tresc = e.read().decode("utf-8", "replace")

        if cichy:
            return None

        try:
            blad = json.loads(tresc)["error"]
            raise SystemExit(
                "Facebook odmówił ({}): {}\nTyp: {}, kod: {}".format(
                    e.code, blad.get("message"), blad.get("type"), blad.get("code")
                )
            )
        except (ValueError, KeyError):
            raise SystemExit("Facebook odmówił ({}): {}".format(e.code, tresc[:400]))


def znajdz_strone(token):
    """
    Przyjmuje token strony ALBO token użytkownika.

    Przy tokenie użytkownika /me/accounts zwraca listę stron, którymi ten
    użytkownik zarządza, razem z ich własnymi tokenami — i to ich trzeba użyć,
    bo token użytkownika wskazuje na profil prywatny, nie na fanpage.
    Zwraca (id_strony, token_strony, nazwa).
    """
    konta = graph("/me/accounts", token, cichy=True, fields="id,name,access_token", limit=50)
    strony = (konta or {}).get("data", [])

    if strony:
        wskazane = os.environ.get("FB_PAGE_ID")

        if wskazane:
            wybrana = next((s for s in strony if s["id"] == wskazane), None)
            if not wybrana:
                raise SystemExit(
                    "FB_PAGE_ID={} nie pasuje do żadnej z Twoich stron: {}".format(
                        wskazane, ", ".join(s["name"] for s in strony)
                    )
                )
        else:
            wybrana = next(
                (s for s in strony if "janovia" in s.get("name", "").lower()),
                strony[0],
            )

        if len(strony) > 1:
            print("Zarządzasz stronami: {}".format(", ".join(s["name"] for s in strony)))

        print("Strona: {} (id {})".format(wybrana["name"], wybrana["id"]))
        return wybrana["id"], wybrana.get("access_token", token), wybrana["name"]

    # brak listy stron — zakładamy, że dostaliśmy od razu token strony
    me = graph("/me", token, fields="id,name")
    print("Strona: {} (id {})".format(me.get("name"), me.get("id")))
    return me["id"], token, me.get("name")


def zrodla_zdjec(zalacznik, full_picture):
    """
    Adresy wszystkich zdjęć posta. Album trzyma je w subattachments,
    pojedyncze zdjęcie tylko w media samego załącznika; gdy Facebook
    nie zwróci żadnego z tych pól, wracamy do full_picture (miniatura).
    """
    podzalaczniki = (zalacznik.get("subattachments") or {}).get("data", [])

    if podzalaczniki:
        zrodla = []
        for s in podzalaczniki:
            src = ((s.get("media") or {}).get("image") or {}).get("src")
            if src:
                zrodla.append(src)
        if zrodla:
            return zrodla

    src = ((zalacznik.get("media") or {}).get("image") or {}).get("src")
    if src:
        return [src]

    return [full_picture] if full_picture else []


def pobierz_zdjecie(url, nazwa):
    """Zapisuje zdjęcie lokalnie i zwraca ścieżkę względną albo None."""
    if not url:
        return None

    os.makedirs(KAT_ZDJEC, exist_ok=True)
    plik = os.path.join(KAT_ZDJEC, nazwa + ".jpg")
    wzgledna = "data/fb/" + nazwa + ".jpg"

    if os.path.exists(plik) and os.path.getsize(plik) > 0:
        return wzgledna

    try:
        req = urllib.request.Request(url, headers={"User-Agent": "KS Janovia Janowiec"})
        with urllib.request.urlopen(req, timeout=30) as r:
            dane = r.read()
    except Exception as e:
        print("  nie pobrałem zdjęcia:", e, file=sys.stderr)
        return None

    with open(plik, "wb") as f:
        f.write(dane)

    return wzgledna


def naglowek(tekst, limit=95):
    """Pierwsze zdanie posta jako tytuł kafelka."""
    if not tekst:
        return "Post na Facebooku"

    tekst = re.sub(r"\s+", " ", tekst).strip()
    kropka = re.search(r"[.!?](\s|$)", tekst)

    if kropka and kropka.start() < limit:
        return tekst[: kropka.start() + 1]

    if len(tekst) <= limit:
        return tekst

    uciete = tekst[:limit].rsplit(" ", 1)[0]
    return uciete + "…"


def sprzataj_zdjecia(aktualne):
    """Usuwa pliki po postach, których już nie pokazujemy."""
    if not os.path.isdir(KAT_ZDJEC):
        return

    for plik in os.listdir(KAT_ZDJEC):
        if plik.endswith(".jpg") and "data/fb/" + plik not in aktualne:
            os.remove(os.path.join(KAT_ZDJEC, plik))


def main():
    token = os.environ.get("FB_TOKEN", "").strip()

    if not token:
        raise SystemExit(
            "Brak tokenu. Ustaw zmienną FB_TOKEN, np.:\n"
            "    export FB_TOKEN='twoj_token'\n"
            "    python3 update_fb.py"
        )

    dry = "--dry-run" in sys.argv
    strona, token_strony, nazwa_strony = znajdz_strone(token)

    odp = graph("/" + strona + "/posts", token_strony, fields=POLA, limit=ILE_POSTOW)
    surowe = odp.get("data", [])

    if not surowe:
        raise SystemExit(
            "Strona „{}” nie zwróciła żadnych postów.\n"
            "Najczęstsze przyczyny:\n"
            "  - token nie ma uprawnienia pages_read_engagement\n"
            "  - to token profilu prywatnego, nie fanpage'a\n"
            "  - na stronie faktycznie nie ma opublikowanych postów".format(nazwa_strony)
        )

    posty = []
    uzyte = set()

    for p in surowe:
        tekst = p.get("message", "")
        nazwa_pliku = p["id"].replace("_", "-")

        zalaczniki = p.get("attachments", {}).get("data", [])
        pierwszy = zalaczniki[0] if zalaczniki else {}
        typ = pierwszy.get("media_type")

        zrodla = zrodla_zdjec(pierwszy, p.get("full_picture"))

        galeria = []
        for i, zrodlo in enumerate(zrodla):
            # dopisek numeru tylko gdy zdjęć jest więcej niż jedno —
            # pojedyncze zdjęcie zostaje pod tą samą nazwą co dotychczas
            nazwa = nazwa_pliku if len(zrodla) == 1 else nazwa_pliku + "-" + str(i)
            plik = None if dry else pobierz_zdjecie(zrodlo, nazwa)
            if plik:
                galeria.append(plik)
                uzyte.add(plik)

        posty.append({
            "id": p["id"],
            "naglowek": naglowek(tekst),
            "tekst": tekst,
            "data": p.get("created_time"),
            "link": p.get("permalink_url"),
            "zdjecie": galeria[0] if galeria else None,
            "galeria": galeria,
            "typ": typ,
        })

    dane = {
        # link buduje się z faktycznie odpytanej strony, żeby „Zobacz wszystkie”
        # nie prowadziło gdzie indziej niż źródło postów
        "zrodlo": "https://www.facebook.com/" + str(strona),
        "strona": nazwa_strony,
        "zaktualizowano": datetime.now(timezone.utc).astimezone().isoformat(timespec="seconds"),
        "posty": posty,
    }

    tekst_json = json.dumps(dane, ensure_ascii=False, indent=2)

    if dry:
        print(tekst_json)
        return

    sprzataj_zdjecia(uzyte)

    os.makedirs(os.path.dirname(OUT), exist_ok=True)
    with open(OUT, "w", encoding="utf-8") as f:
        f.write(tekst_json + "\n")

    print("Zapisano {}: {} postów, {} zdjęć.".format(
        os.path.relpath(OUT, BASE), len(posty), len(uzyte)
    ))


if __name__ == "__main__":
    main()
