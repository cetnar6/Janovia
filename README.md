# Janovia Janowiec — strona klubu

Statyczna strona klubu. Tabela i terminarz odświeżają się automatycznie raz dziennie
z serwisu [90minut.pl](http://www.90minut.pl/liga/1/liga15094.html).

## Pliki

| Plik | Do czego służy |
|---|---|
| `index.html` | strona |
| `style.css` | style |
| `script.js` | animacje, przewijanie, wczytywanie danych ligowych |
| `update_liga.py` | pobiera tabelę i terminarz, zapisuje `data/liga.json` |
| `data/liga.json` | dane ligowe — nadpisywane przez skrypt, nie edytować ręcznie |
| `png/` | herb klubu |
| `.github/workflows/update-liga.yml` | codzienne uruchomienie skryptu na GitHubie |
| `pl.janovia.liga.plist` | to samo, ale lokalnie na macOS (launchd) — opcjonalne |

## Skąd biorą się dane

```
90minut.pl  →  update_liga.py  →  data/liga.json  →  strona
```

Przeglądarka nie może sięgnąć do 90minut.pl bezpośrednio (blokuje to CORS), dlatego
dane pobiera skrypt, a strona czyta gotowy plik JSON. Gdy pliku brakuje, na stronie
zostaje treść wpisana na sztywno w `index.html` — nic się nie psuje.

## Ręczne odświeżenie

```bash
python3 update_liga.py            # pobierz i zapisz
python3 update_liga.py --dry-run  # tylko pokaż wynik
```

## Podgląd lokalny

```bash
python3 -m http.server 4173
```

Strona: <http://localhost:4173>. Otwieranie `index.html` przez `file://` też działa,
ale wtedy przeglądarka nie wczyta `data/liga.json` i zobaczysz treść zapasową.

## Zmiana ligi

W `update_liga.py` na górze:

```python
URL = "http://www.90minut.pl/liga/1/liga15094.html"
NASZ_KLUB = "Janovia Janowiec"
```

Po zmianie sezonu 90minut nadaje rozgrywkom nowy numer — wtedy trzeba podmienić `URL`.
