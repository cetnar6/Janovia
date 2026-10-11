/**
 * Układanie składu: przeciąganie zawodników z listy na boisko.
 *
 * Dlaczego Pointer Events, a nie HTML5 drag & drop: to drugie nie istnieje
 * na ekranach dotykowych — a skład ustawia się zwykle na telefonie, przy
 * boisku, nie przy biurku. Pointer Events obsługują mysz, palec i rysik
 * tym samym kodem.
 *
 * Samo przeciąganie nie wystarcza: obok działa wybieranie stuknięciem
 * (zawodnik, potem pole). To nie jest zapasowe rozwiązanie dla słabych
 * przeglądarek, tylko jedyna droga dla kogoś, kto steruje klawiaturą —
 * i zwyczajnie wygodniejsza, gdy trzeba przestawić jedną osobę.
 *
 * Ten sam skrypt obsługuje dwa ekrany. Na „Składzie" układa się ustawienie.
 * W „Przebiegu meczu" boisko pokazuje, kto gra TERAZ, a przeciągnięcie
 * rezerwowego na grającego zapisuje zmianę zamiast przestawiać skład —
 * i nic poza oknem zmiany nie rusza stanu po stronie serwera. Przed
 * pierwszym gwizdkiem zostaje sam podgląd, bez żadnych gestów.
 */

(function () {
    'use strict';

    var zrodlo = document.getElementById('dane-skladu');
    if (!zrodlo) { return; }

    var DANE = JSON.parse(zrodlo.textContent);

    var boisko = document.getElementById('boisko');
    var lawka = document.getElementById('lawka');
    var licznik = document.getElementById('licznik');
    var rezerwa = document.getElementById('rezerwa');
    var rezerwaLista = document.getElementById('rezerwa-lista');
    var licznikRezerw = document.getElementById('licznik-rezerw');
    var form = document.getElementById('sklad-form');
    var poleFormacji = document.getElementById('sklad-formacja');
    var poleUstawienia = document.getElementById('sklad-ustawienie');
    var poleRezerwowych = document.getElementById('sklad-rezerwowi');

    var MAKS_REZERW = DANE.maksRezerw || 9;

    /* Trzy tryby: 'sklad' (układanie), 'mecz' (zmiany) i 'podglad' (samo
       boisko, przed pierwszym gwizdkiem). MECZ rządzi gestami, MECZOWE —
       tym, co w ogóle rysujemy na liście i w pasie pod boiskiem. */
    var MECZ = DANE.tryb === 'mecz';
    var PODGLAD = DANE.tryb === 'podglad';
    var MECZOWE = MECZ || PODGLAD;

    var formacja = DANE.formacja;
    var ustawienie = {};          // { idPola: idZawodnika }
    var rezerwowi = [];           // id zawodników na ławce, w kolejności trenera
    var wybrany = null;           // id zawodnika wskazanego stuknięciem

    Object.keys(DANE.ustawienie || {}).forEach(function (pole) {
        ustawienie[pole] = DANE.ustawienie[pole];
    });

    (DANE.rezerwowi || []).forEach(function (id) {
        rezerwowi.push(id);
    });

    var SKROTY = {
        'bramkarz':  'BR',
        'obrońca':   'OB',
        'pomocnik':  'PM',
        'napastnik': 'NA'
    };

    /* Kolejność i nagłówki działów na ławce — ta sama, co wszędzie indziej
       na stronie klubu: od bramki do ataku. */
    var POZYCJE = [
        { klucz: 'bramkarz',  nazwa: 'Bramkarze' },
        { klucz: 'obrońca',   nazwa: 'Obrońcy' },
        { klucz: 'pomocnik',  nazwa: 'Pomocnicy' },
        { klucz: 'napastnik', nazwa: 'Napastnicy' }
    ];

    /* ---------- pomocnicze ---------- */

    function zawodnik(id) {
        for (var i = 0; i < DANE.zawodnicy.length; i++) {
            if (DANE.zawodnicy[i].id === id) { return DANE.zawodnicy[i]; }
        }
        return null;
    }

    function pola() {
        return DANE.formacje[formacja].pola;
    }

    function polePoId(id) {
        var lista = pola();
        for (var i = 0; i < lista.length; i++) {
            if (lista[i].id === id) { return lista[i]; }
        }
        return null;
    }

    /** Pole, na którym stoi dany zawodnik, albo null. */
    function gdzieStoi(idZawodnika) {
        var klucze = Object.keys(ustawienie);
        for (var i = 0; i < klucze.length; i++) {
            if (ustawienie[klucze[i]] === idZawodnika) { return klucze[i]; }
        }
        return null;
    }

    function naLawce(idZawodnika) {
        return rezerwowi.indexOf(idZawodnika) !== -1;
    }

    /** Czy ten zawodnik czeka na ławce i może jeszcze wejść (tryb meczowy). */
    function czekaNaWejscie(idZawodnika) {
        return (DANE.doZmiany || []).indexOf(idZawodnika) !== -1;
    }

    /** Czy zawodnik jest gdziekolwiek ustawiony — na boisku albo na ławce. */
    function uzyty(idZawodnika) {
        return gdzieStoi(idZawodnika) !== null || naLawce(idZawodnika);
    }

    function esc(tekst) {
        return String(tekst).replace(/&/g, '&amp;').replace(/</g, '&lt;')
                            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function nazwisko(z) {
        return z.nazwisko;
    }

    function podpis(z) {
        return (z.numer ? z.numer + '. ' : '') + z.imie + ' ' + z.nazwisko;
    }

    function inicjaly(z) {
        return (z.imie.charAt(0) + z.nazwisko.charAt(0)).toUpperCase();
    }

    /**
     * Twarz zawodnika albo inicjały, gdy zdjęcia brak.
     *
     * Zastępnik nie jest ozdobnikiem: panel trenera ma działać także zanim
     * ktokolwiek wgra zdjęcia kadry, a pusty kwadrat w miejscu twarzy czyta
     * się jak błąd ładowania. Inicjały wyglądają na decyzję.
     *
     * loading="lazy" celowo nie ma: zdjęcia są małe, a kafelek wjeżdżający
     * pod kursor z pustym miejscem na twarz wygląda na zacięty.
     */
    function awatar(z, klasa) {
        if (z.foto) {
            return '<img class="' + klasa + '" src="' + esc(z.foto) + '" alt="">';
        }

        return '<span class="' + klasa + ' ' + klasa + '--brak">' + esc(inicjaly(z)) + '</span>';
    }

    /**
     * Piłki przy zawodniku — jedna na bramkę.
     *
     * Do trzech rysujemy dosłownie: trzy piłki czyta się szybciej niż „×3"
     * i od razu widać hat-tricka. Wyżej liczba wygrywa z kreskami, bo pięć
     * kółek obok nazwiska przestaje być informacją, a zaczyna być plamą.
     */
    function pilki(id) {
        var ile = (DANE.gole && DANE.gole[id]) || 0;

        if (!ile) { return ''; }

        /* Klasyczna piłka: biały pięciokąt w środku, pięć czarnych łat
           dochodzących do obrysu. Przy czternastu pikselach samo kółko
           z kropką czytało się jak kropka, a nie jak piłka. */
        var ikona =
            '<svg class="pilka" viewBox="0 0 24 24" aria-hidden="true">' +
                '<circle cx="12" cy="12" r="11"/>' +
                '<path class="pilka__latka" d="M13.7 9.65 12.4 5.63 15.82 3.14 19.24 5.63 17.94 9.65Z' +
                    'M14.76 12.9 18.18 10.41 21.61 12.9 20.3 16.92 16.07 16.92Z' +
                    'M12 14.9 15.42 17.39 14.12 21.41 9.88 21.41 8.58 17.39Z' +
                    'M9.24 12.9 7.93 16.92 3.7 16.92 2.39 12.9 5.82 10.41Z' +
                    'M10.3 9.65 6.06 9.65 4.76 5.63 8.18 3.14 11.6 5.63Z"/>' +
            '</svg>';

        /* Trzy piłki to górna granica czytelności przy krążku na boisku —
           czwarta zaczyna wychodzić poza kafelek i wpadać pod sąsiada.
           Nadwyżkę dopisujemy liczbą, bo „+2" zajmuje tyle co pół piłki. */
        var widoczne = Math.min(ile, 3);
        var tresc = new Array(widoczne + 1).join(ikona) +
            (ile > 3 ? '<b>+' + (ile - 3) + '</b>' : '');

        return '<span class="gole" title="Bramki: ' + ile + '">' + tresc + '</span>';
    }

    /* ---------- wstawianie i zdejmowanie ---------- */

    /**
     * Wstawia zawodnika na pole. Gdy pole jest zajęte, a wstawiany stoi już
     * gdzie indziej — obaj się zamieniają. To jedyne zachowanie, które nie
     * gubi nikogo po drodze: wyrzucenie zajmującego na ławkę kasowałoby
     * decyzję, której trener nie cofał.
     */
    function wstaw(idPola, idZawodnika) {
        var zajmujacy = ustawienie[idPola];
        var skad = gdzieStoi(idZawodnika);

        if (zajmujacy === idZawodnika) { return; }

        // wejście na boisko zdejmuje z ławki: nikt nie gra i siedzi naraz
        zLawki(idZawodnika);

        if (skad) { delete ustawienie[skad]; }

        if (zajmujacy && skad) {
            ustawienie[skad] = zajmujacy;
        }

        ustawienie[idPola] = idZawodnika;
    }

    function zdejmij(idZawodnika) {
        var skad = gdzieStoi(idZawodnika);
        if (skad) { delete ustawienie[skad]; }
        zLawki(idZawodnika);
    }

    function zLawki(idZawodnika) {
        var i = rezerwowi.indexOf(idZawodnika);
        if (i !== -1) { rezerwowi.splice(i, 1); }
    }

    /**
     * Sadza zawodnika na ławce. Pełna ławka nie przyjmuje nikogo więcej —
     * to jedyne miejsce w panelu, gdzie gest zostaje bez skutku, więc mówimy
     * o tym wprost przy liczniku, a nie samym brakiem reakcji.
     */
    function naLawke(idZawodnika) {
        if (naLawce(idZawodnika)) { return; }

        if (rezerwowi.length >= MAKS_REZERW) {
            rezerwa.classList.add('is-pelna');
            window.setTimeout(function () {
                rezerwa.classList.remove('is-pelna');
            }, 900);
            return;
        }

        var skad = gdzieStoi(idZawodnika);
        if (skad) { delete ustawienie[skad]; }

        rezerwowi.push(idZawodnika);
    }

    /* ---------- rysowanie ---------- */

    function rysujBoisko() {
        // linie boiska zostają, znikają tylko pola z poprzedniej formacji
        Array.prototype.forEach.call(boisko.querySelectorAll('.pole'), function (el) {
            el.remove();
        });

        pola().forEach(function (p) {
            var id = ustawienie[p.id];
            var z = id ? zawodnik(id) : null;

            var el = document.createElement('button');
            el.type = 'button';
            el.className = 'pole pole--' + p.grupa + (z ? ' is-obsadzone' : '');
            el.style.left = p.x + '%';
            el.style.top = p.y + '%';
            el.setAttribute('data-pole', p.id);
            el.setAttribute('data-grupa', p.grupa);

            if (z) {
                el.setAttribute('data-zawodnik', String(z.id));
                el.setAttribute('aria-label', p.etykieta + ': ' + podpis(z) + '. Naciśnij, aby zdjąć.');
                el.classList.add(z.foto ? 'ma-twarz' : 'bez-twarzy');
                el.innerHTML =
                    /* Zdjęcie albo inicjały — zawsze coś w krążku, tak samo
                       jak na ławce. Numer schodzi wtedy na plakietkę w rogu,
                       bo środek jest już zajęty. */
                    awatar(z, 'pole__twarz') +
                    pilki(z.id) +
                    '<span class="pole__numer">' + (z.numer ? z.numer : '–') + '</span>' +
                    '<span class="pole__nazwisko">' + esc(nazwisko(z)) +
                        (z.kapitan ? '<i class="pole__opaska" title="Kapitan">C</i>' : '') +
                    '</span>';
            } else {
                el.setAttribute('aria-label', 'Puste pole ' + p.etykieta + '. Naciśnij, aby wstawić wybranego zawodnika.');
                el.innerHTML =
                    '<span class="pole__znak">+</span>' +
                    '<span class="pole__etykieta">' + esc(p.etykieta) + '</span>';
            }

            boisko.appendChild(el);
        });
    }

    /** Ile pól danej pozycji czeka jeszcze na obsadzenie w bieżącej formacji. */
    function wolneMiejsca() {
        var wolne = {};

        pola().forEach(function (p) {
            if (wolne[p.grupa] === undefined) { wolne[p.grupa] = 0; }
            if (!ustawienie[p.id]) { wolne[p.grupa]++; }
        });

        return wolne;
    }

    function rysujLawke() {
        /* W trakcie meczu lista to już nie „kto może zagrać", tylko „kogo
           jeszcze mam na ławce" — i tylko tacy mogą wejść. */
        if (MECZOWE) {
            var doZmiany = (DANE.doZmiany || []).map(zawodnik).filter(function (z) { return !!z; });

            if (!doZmiany.length) {
                lawka.innerHTML = '<p class="lawka__pusto">' +
                    (PODGLAD ? 'Na ławce nikogo nie ma.' : 'Wszyscy rezerwowi już weszli na boisko.') +
                    '</p>';

                return;
            }

            /* Ławka podzielona na formacje. Trener w trakcie meczu nie szuka
               nazwiska, tylko pozycji: „kogo mam jeszcze w obronie" — a przy
               dziewięciu zmiennikach odpowiedź na to pytanie czytało się
               z całej listy naraz. */
            var dzialy = '';
            var rozdane = [];

            POZYCJE.forEach(function (poz) {
                var grupa = doZmiany.filter(function (z) { return z.pozycja === poz.klucz; });

                if (!grupa.length) { return; }

                rozdane = rozdane.concat(grupa);

                dzialy += '<p class="lawka__dzial">' + esc(poz.nazwa) + '</p>' +
                          grupa.map(function (z) { return kafelekZawodnika(z, false); }).join('');
            });

            // ktoś z pozycją spoza czwórki trafia na koniec, zamiast zniknąć
            doZmiany.forEach(function (z) {
                if (rozdane.indexOf(z) === -1) { dzialy += kafelekZawodnika(z, false); }
            });

            lawka.innerHTML = dzialy;

            return;
        }

        var wolni = DANE.zawodnicy.filter(function (z) {
            return !uzyty(z.id);
        });

        if (!wolni.length) {
            lawka.innerHTML = '<p class="lawka__pusto">Wszyscy zawodnicy ' +
                              'są na boisku albo na ławce.</p>';
            return;
        }

        /* Gdy wszystkie pola danej pozycji są już obsadzone, reszta tej
           formacji schodzi na koniec listy. Trener szuka wtedy kogoś na
           pozycję, której jeszcze nie zamknął, a nie trzeciego bramkarza —
           bez tego przewijał listę obok nazwisk, których i tak nie wstawi.

           sort jest stabilny (ES2019), więc wewnątrz obu grup zostaje
           kolejność z zapytania: pozycjami, potem numerami. */
        var wolne = wolneMiejsca();

        function rezerwowy(z) {
            return (wolne[z.pozycja] || 0) === 0 ? 1 : 0;
        }

        wolni.sort(function (a, b) {
            return rezerwowy(a) - rezerwowy(b);
        });

        var pierwszyRezerwowy = -1;

        for (var i = 0; i < wolni.length; i++) {
            if (rezerwowy(wolni[i])) { pierwszyRezerwowy = i; break; }
        }

        lawka.innerHTML = wolni.map(function (z, i) {
            /* Nagłówek przed pierwszym rezerwowym. Bez niego przeskok
               nazwiska na koniec listy wygląda na gubienie zawodników. */
            var dzial = i === pierwszyRezerwowy && i > 0
                ? '<p class="lawka__dzial">Pozycje już obsadzone</p>'
                : '';

            return dzial + kafelekZawodnika(z, rezerwowy(z));
        }).join('');
    }

    function kafelekZawodnika(z, przygaszony) {
        return '<button type="button" class="zawodnik' +
                   (przygaszony ? ' is-rezerwa' : '') +
                   (wybrany === z.id ? ' is-wybrany' : '') + '"' +
                   ' data-zawodnik="' + z.id + '"' +
                   ' data-grupa="' + esc(z.pozycja) + '"' +
                   ' aria-pressed="' + (wybrany === z.id ? 'true' : 'false') + '">' +
                   '<span class="zawodnik__numer">' + (z.numer ? z.numer : '–') + '</span>' +
                   awatar(z, 'zawodnik__twarz') +
                   '<span class="zawodnik__kto">' +
                       '<strong>' + esc(z.nazwisko) +
                           (z.kapitan ? '<i class="zawodnik__opaska" title="Kapitan">C</i>' : '') +
                           pilki(z.id) +
                       '</strong>' +
                       '<small>' + esc(z.imie) + '</small>' +
                   '</span>' +
                   '<span class="zawodnik__poz">' + (SKROTY[z.pozycja] || '') + '</span>' +
               '</button>';
    }

    /**
     * Ławka rezerwowych pod boiskiem.
     *
     * Kafelek jest przyciskiem, a stuknięcie w niego zdejmuje zawodnika
     * z ławki — dokładnie tak, jak stuknięcie w obsadzone pole zdejmuje go
     * z boiska. Dwa miejsca, jedna zasada; osobny krzyżyk na kafelku byłby
     * trzecim sposobem robienia tego samego.
     */
    /** Liczebnik, żeby licznik nie mówił „3 zmiana". */
    function ileZmian(n) {
        if (n === 1) { return '1 zmiana'; }

        var ostatnia = n % 10;
        var dziesiatki = n % 100;

        return n + (ostatnia >= 2 && ostatnia <= 4 && (dziesiatki < 12 || dziesiatki > 14)
            ? ' zmiany'
            : ' zmian');
    }

    function rysujZmiany() {
        var zmiany = DANE.zmiany || [];

        rezerwaLista.innerHTML = zmiany.length
            ? zmiany.map(function (zm) {
                  var wchodzi = zawodnik(zm.wchodzi);
                  var schodzi = zawodnik(zm.schodzi);

                  return '<div class="zmiana">' +
                             '<span class="zmiana__minuta">' + zm.minuta + '\u2032</span>' +
                             (wchodzi ? awatar(wchodzi, 'rezerwowy__twarz') : '') +
                             '<span class="rezerwowy__kto">' +
                                 '<strong>' + esc(wchodzi ? wchodzi.nazwisko : '—') +
                                     (wchodzi ? pilki(wchodzi.id) : '') +
                                 '</strong>' +
                                 '<small>za ' + esc(schodzi ? schodzi.nazwisko : '—') + '</small>' +
                             '</span>' +
                         '</div>';
              }).join('')
            : '<p class="rezerwa__pusto">' +
              (PODGLAD
                  ? 'Zmiany pojawią się tutaj po rozpoczęciu meczu.'
                  : 'Jeszcze bez zmian. Przeciągnij rezerwowego z listy obok ' +
                    'na zawodnika, który ma zejść z boiska.') +
              '</p>';

        licznikRezerw.textContent = ileZmian(zmiany.length);
        licznikRezerw.className = 'rezerwa__licznik';
    }

    function rysujRezerwe() {
        if (MECZOWE) {
            rysujZmiany();
            return;
        }

        var kafelki = rezerwowi.map(function (id) {
            var z = zawodnik(id);

            if (!z) { return ''; }

            return '<button type="button" class="rezerwowy" data-zawodnik="' + z.id + '"' +
                       ' aria-label="' + esc(podpis(z)) +
                           ' na ławce. Naciśnij, aby zdjąć z ławki.">' +
                       awatar(z, 'rezerwowy__twarz') +
                       '<span class="rezerwowy__kto">' +
                           '<strong>' + esc(z.nazwisko) +
                               (z.kapitan ? '<i class="zawodnik__opaska" title="Kapitan">C</i>' : '') +
                           '</strong>' +
                           '<small>' + (z.numer ? z.numer + '. ' : '') + esc(z.imie) + '</small>' +
                       '</span>' +
                       '<span class="rezerwowy__poz">' + (SKROTY[z.pozycja] || '') + '</span>' +
                   '</button>';
        }).join('');

        /* Kafelek „posadź tu" pokazuje się dopiero, gdy ktoś jest wybrany
           stuknięciem. Pusty przycisk bez wybranego zawodnika nie miałby co
           zrobić po naciśnięciu, a przycisk, który nic nie robi, czyta się
           jak zepsuty. Dla myszy i palca drogą jest przeciąganie. */
        var zaproszenie = '';

        if (wybrany && !naLawce(wybrany) && rezerwowi.length < MAKS_REZERW) {
            zaproszenie = '<button type="button" class="rezerwa__slot" id="rezerwa-slot">' +
                              '<span class="rezerwa__plus">+</span>' +
                              'Posadź: ' + esc(zawodnik(wybrany).nazwisko) +
                          '</button>';
        } else if (!rezerwowi.length) {
            zaproszenie = '<p class="rezerwa__pusto">' +
                              'Przeciągnij tu zawodnika z listy obok albo dotknij jego, ' +
                              'a potem tego miejsca. Ławka nie jest wymagana do zatwierdzenia składu.' +
                          '</p>';
        }

        rezerwaLista.innerHTML = kafelki + zaproszenie;

        licznikRezerw.textContent = rezerwowi.length + '/' + MAKS_REZERW;
        licznikRezerw.className = 'rezerwa__licznik' +
            (rezerwowi.length >= MAKS_REZERW ? ' is-pelny' : '');
    }

    function rysuj() {
        rysujBoisko();
        rysujLawke();
        rysujRezerwe();

        var ilu = Object.keys(ustawienie).length;
        licznik.textContent = ilu + '/11';
        licznik.className = 'lawka__licznik' + (ilu === 11 ? ' is-pelny' : '');

        /* Pola ukryte są tylko na ekranie składu — w przebiegu meczu
           formularzem jest okno zmiany, nie całe ustawienie. */
        if (poleUstawienia) { poleUstawienia.value = JSON.stringify(ustawienie); }
        if (poleRezerwowych) { poleRezerwowych.value = JSON.stringify(rezerwowi); }
        if (poleFormacji) { poleFormacji.value = formacja; }

        odswiezPasekWyboru();
    }

    /* ---------- stoper meczu ---------- */

    /**
     * Ile sekund upłynęło od pierwszego gwizdka.
     *
     * Liczymy w przeglądarce, a nie na serwerze, bo inaczej stoper stałby
     * w miejscu do następnego przeładowania strony. Górne ograniczenie jest
     * po to, żeby mecz zapomniany na noc nie pokazywał rano tysiąca minut.
     */
    function sekundyMeczu() {
        if (!DANE.start) { return null; }

        var start = new Date(DANE.start).getTime();

        if (isNaN(start)) { return null; }

        /* Na przerwie liczymy do chwili jej rozpoczęcia — zegar zamarza
           zamiast biec dalej za plecami trenera. */
        var pauza = DANE.pauza ? new Date(DANE.pauza).getTime() : NaN;
        var koniec = isNaN(pauza) ? Date.now() : pauza;

        return Math.max(0, Math.min((DANE.maksMinuta || 130) * 60, Math.floor((koniec - start) / 1000)));
    }

    /**
     * Która to minuta meczu — ta, którą podpowiadamy przy golu i zmianie.
     *
     * W pierwszej sekundzie gra się „pierwszą minutę", nie zerową, więc
     * minuta jest o jeden wyższa od pełnych minut na stoperze. Stąd 80:30
     * na zegarze i 81 w okienku.
     */
    function minutaMeczu() {
        var sekundy = sekundyMeczu();

        return sekundy === null
            ? null
            : Math.min(DANE.maksMinuta || 130, Math.floor(sekundy / 60) + 1);
    }

    /* Zegarów jest dwa — w plakietce stanu i na tablicy wyniku — więc klasa,
       nie identyfikator. Oba pokazują to samo, bo liczą z tego samego gwizdka. */
    var zegary = document.querySelectorAll('.stoper');

    if (zegary.length) {
        /* Minuty bez godzin — po dziewięćdziesiątej leci 91:00, a nie 1:31:00.
           Minuty bez zera wiodącego, sekundy zawsze dwucyfrowe, jak na zegarze
           na stadionie. */
        var tyknij = function () {
            var sekundy = sekundyMeczu();

            if (sekundy === null) { return; }

            var s = sekundy % 60;
            var czas = Math.floor(sekundy / 60) + ':' + (s < 10 ? '0' + s : s);

            Array.prototype.forEach.call(zegary, function (el) {
                el.textContent = czas;
            });
        };

        tyknij();
        window.setInterval(tyknij, 1000);
    }

    /**
     * Przypomnienie o przerwie.
     *
     * Okno wychodzi raz, w oknie 48–53 minuty, i tylko gdy stoper naprawdę
     * biegnie. Nie zatrzymuje niczego samo: pierwszą połowę kończy gwizdek
     * sędziego, a nie równa liczba na zegarze w panelu. Zamknięte zostaje
     * zamknięte do końca wizyty na stronie — drugi raz w tym samym meczu
     * byłoby już marudzeniem, a nie przypomnieniem.
     */
    var oknoPrzerwy = document.getElementById('okno-przerwa');

    if (oknoPrzerwy && !DANE.pauza) {
        var pilnujPrzerwy = window.setInterval(function () {
            var sekundy = sekundyMeczu();

            if (sekundy === null || sekundy < 48 * 60) { return; }

            window.clearInterval(pilnujPrzerwy);

            if (sekundy > 53 * 60) { return; }          // wznowiona druga połowa
            if (sessionStorage.getItem('przerwa-przypomniana')) { return; }

            sessionStorage.setItem('przerwa-przypomniana', '1');
            otworzOkno(oknoPrzerwy);
        }, 5000);
    }

    /* ---------- okna (tryb meczowy) ---------- */

    /** Minuta wpisuje się sama, bo to jedyne pole wspólne dla wszystkich okien. */
    function otworzOkno(okno) {
        if (!okno) { return; }

        okno.hidden = false;

        /* Okno otwiera się na dotknięcie, a kilkadziesiąt milisekund później
           przeglądarka dosyła w to samo miejsce „duchowe" kliknięcie z dotyku.
           Trafiało ono już w świeżo otwarte okno — w tło, które je zamykało,
           albo, gorzej, w przycisk zapisu. Przez chwilę okno nie przyjmuje
           więc żadnych kliknięć; dla palca to niezauważalne, a zgubiony
           duch nie ma już czego dotknąć. */
        okno.style.pointerEvents = 'none';

        window.setTimeout(function () {
            okno.style.pointerEvents = '';
        }, 400);

        var minuta = okno.querySelector('input[type="number"]');

        if (minuta) {
            var teraz = minutaMeczu();

            minuta.value = teraz !== null ? String(teraz) : (DANE.minuta ? String(DANE.minuta) : '');
        }

        var pierwsze = okno.querySelector('select, input[type="number"]');

        if (pierwsze) {
            pierwsze.focus();

            if (pierwsze.select) { pierwsze.select(); }
        }
    }

    function zamknijOkna() {
        Array.prototype.forEach.call(document.querySelectorAll('.okno'), function (okno) {
            okno.hidden = true;
        });

        wybrany = null;
        podswietlPola(null);
        rysuj();
    }

    /* ---------- okno zmiany (tryb meczowy) ---------- */

    /**
     * Przeciągnięcie mówi wszystko poza jednym: w której minucie.
     *
     * Stąd okno — jedno pole, podpowiedziane z czasu od pierwszego gwizdka,
     * i dwa przyciski. Zapis idzie zwykłym formularzem, więc działa tak samo
     * z klawiatury i nie wymaga drugiego kanału komunikacji z serwerem.
     */
    function otworzOknoZmiany(idWchodzi, idSchodzi) {
        var okno = document.getElementById('okno-zmiany');
        if (!okno) { return; }

        var wchodzi = zawodnik(idWchodzi);
        var schodzi = zawodnik(idSchodzi);

        document.getElementById('zmiana-wchodzi').value = idWchodzi;
        document.getElementById('zmiana-schodzi').value = idSchodzi;
        document.getElementById('zmiana-opis').innerHTML =
            'Wchodzi <strong>' + esc(wchodzi ? podpis(wchodzi) : '—') + '</strong><br>' +
            'Schodzi <strong>' + esc(schodzi ? podpis(schodzi) : '—') + '</strong>';

        otworzOkno(okno);
    }

    /* ---------- zmiana formacji ---------- */

    /**
     * Przy zmianie ustawienia przenosimy zawodników po grupach: obrońcy
     * z poprzedniej obrony trafiają na nową obronę, i tak dalej. Nadmiar
     * (np. czwarty obrońca w 3-5-2) wraca na ławkę.
     *
     * Alternatywą było czyszczenie boiska przy każdej zmianie — ale trener
     * zwykle chce zobaczyć TEN SAM skład w innym ustawieniu, a nie zaczynać
     * od zera za każdym razem, gdy kliknie w inną formację.
     */
    function zmienFormacje(nowa) {
        if (MECZ || nowa === formacja || !DANE.formacje[nowa]) { return; }

        var wgGrupy = {};

        pola().forEach(function (p) {
            if (!ustawienie[p.id]) { return; }
            if (!wgGrupy[p.grupa]) { wgGrupy[p.grupa] = []; }
            wgGrupy[p.grupa].push(ustawienie[p.id]);
        });

        formacja = nowa;
        ustawienie = {};

        pola().forEach(function (p) {
            var kolejka = wgGrupy[p.grupa];
            if (kolejka && kolejka.length) {
                ustawienie[p.id] = kolejka.shift();
            }
        });

        Array.prototype.forEach.call(document.querySelectorAll('.formacja'), function (btn) {
            var wybrana = btn.getAttribute('data-formacja') === formacja;
            btn.classList.toggle('is-wybrana', wybrana);
            btn.setAttribute('aria-checked', wybrana ? 'true' : 'false');
        });

        wybrany = null;
        rysuj();
    }

    /* ---------- wybór stuknięciem ---------- */

    var pasekWyboru = document.getElementById('pasek-wyboru');

    /**
     * Pasek „wybrano kogoś" przy dolnej krawędzi telefonu.
     *
     * Na wąskim ekranie ławka leży pod boiskiem, więc po stuknięciu
     * rezerwowego trzeba przewinąć w górę — a wtedy znika z oczu jedyny ślad
     * po tym, że cokolwiek jest wybrane. Pasek jedzie razem z ekranem
     * i mówi, co się właśnie dzieje oraz jak z tego wyjść.
     */
    function odswiezPasekWyboru() {
        if (!pasekWyboru) { return; }

        var z = wybrany ? zawodnik(wybrany) : null;

        if (!z) {
            pasekWyboru.hidden = true;
            return;
        }

        pasekWyboru.querySelector('.pasek-wyboru__kto').textContent = podpis(z);
        pasekWyboru.querySelector('.pasek-wyboru__co').textContent = MECZ
            ? 'Dotknij zawodnika, który schodzi'
            : 'Dotknij pola na boisku albo ławki';

        pasekWyboru.hidden = false;
    }

    function zaznacz(id) {
        wybrany = wybrany === id ? null : id;
        podswietlPola();
        rysujLawke();
        rysujRezerwe();
        odswiezPasekWyboru();

        /* Na wąskim ekranie boisko jest nad ławką i poza widokiem — po wyborze
           przysuwamy je samo, żeby drugie stuknięcie było od razu pod ręką. */
        if (wybrany && window.innerWidth <= 900) {
            boisko.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }

    /** Pola pasujące do pozycji wybranego zawodnika — podpowiedź, nie zakaz. */
    function podswietlPola(grupa) {
        var szukana = grupa;

        if (szukana === undefined) {
            var z = wybrany ? zawodnik(wybrany) : null;
            szukana = z ? z.pozycja : null;
        }

        Array.prototype.forEach.call(boisko.querySelectorAll('.pole'), function (el) {
            var pasuje;

            if (MECZ) {
                /* W meczu świecą ci, którzy grają na tej samej pozycji co
                   wchodzący — pomocnik za pomocnika. To nadal podpowiedź,
                   nie zakaz: zdjąć wolno każdego, bo trener bierze tego,
                   kto jest zmęczony, a nie tego, kto pasuje na papierze. */
                var naPolu = el.getAttribute('data-zawodnik');
                var stojacy = naPolu ? zawodnik(parseInt(naPolu, 10)) : null;

                pasuje = !!stojacy && stojacy.pozycja === szukana;
            } else {
                pasuje = el.getAttribute('data-grupa') === szukana;
            }

            el.classList.toggle('is-pasuje', szukana !== null && pasuje);
            el.classList.toggle('is-czeka', szukana !== null);
        });

        // ławka przyjmuje każdego, więc czeka zawsze, nie tylko na swoją pozycję
        rezerwa.classList.toggle('is-czeka',
            !MECZ && szukana !== null && rezerwowi.length < MAKS_REZERW);
    }

    /* ---------- przeciąganie ---------- */

    var ciagniety = null;    // { id, duch, odX, odY, ruszyl }
    var PROG = 6;            // px, poniżej tego traktujemy gest jak stuknięcie

    /**
     * Autoprzewijanie przy krawędziach ekranu.
     *
     * Na telefonie boisko i ławka nie mieszczą się razem: ostatni rezerwowy
     * bywa dwa ekrany poniżej zawodnika, którego ma zmienić. Bez tego jedyną
     * drogą byłoby stuknięcie — a trener, który raz chwycił kafelek, próbuje
     * go dowieźć, nie zaczynać od nowa.
     */
    var STREFA_PRZEWIJANIA = 110;   // px od górnej i dolnej krawędzi
    var MAKS_KROK = 20;             // px na klatkę przy samej krawędzi
    var klatka = null;
    var kursorY = 0;

    function przewijajPrzyKrawedzi() {
        klatka = null;

        if (!ciagniety || !ciagniety.ruszyl) { return; }

        var wysokosc = window.innerHeight;
        var krok = 0;

        if (kursorY < STREFA_PRZEWIJANIA) {
            krok = -MAKS_KROK * (1 - kursorY / STREFA_PRZEWIJANIA);
        } else if (kursorY > wysokosc - STREFA_PRZEWIJANIA) {
            krok = MAKS_KROK * (1 - (wysokosc - kursorY) / STREFA_PRZEWIJANIA);
        }

        if (krok) { window.scrollBy(0, krok); }

        klatka = window.requestAnimationFrame(przewijajPrzyKrawedzi);
    }

    function zacznijPrzewijanie(y) {
        kursorY = y;

        if (!klatka) { klatka = window.requestAnimationFrame(przewijajPrzyKrawedzi); }
    }

    function przerwijPrzewijanie() {
        if (klatka) { window.cancelAnimationFrame(klatka); klatka = null; }
    }

    function startCiagniecia(e, id) {
        var zrodlowy = e.currentTarget;

        ciagniety = {
            id: id,
            od: zrodlowy,
            startX: e.clientX,
            startY: e.clientY,
            ruszyl: false,
            duch: null
        };

        zrodlowy.setPointerCapture(e.pointerId);
    }

    function stworzDucha(e) {
        var z = zawodnik(ciagniety.id);

        var duch = document.createElement('div');
        duch.className = 'duch';
        duch.innerHTML =
            awatar(z, 'duch__twarz') +
            '<span class="duch__nazwisko">' +
                (z.numer ? z.numer + '. ' : '') + esc(z.nazwisko) +
            '</span>';

        document.body.appendChild(duch);
        ciagniety.duch = duch;

        podswietlPola(z.pozycja);
        przesunDucha(e);
    }

    /* Kafelek kładzie się w stronę, w którą jedzie — jak kartka ciągnięta
       po stole. Kilka stopni wystarczy: gest ma być wyczuwalny, a nie
       zamieniać kafelek w wiatraczek. */
    var POCHYLENIE_MAX = 9;       // stopni
    var POCHYLENIE_NA_PIKSEL = 1.6;
    var prostowanie = null;

    /* Ruch poniżej progu to drżenie palca, nie gest — bez tego kafelek
       dygotałby w miejscu przy najmniejszym drgnięciu. */
    var MARTWA_STREFA = 1;        // px

    var bezRuchu = window.matchMedia
        && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function pochyl(duch, kat) {
        duch.style.transform = 'translate(-50%, -50%) rotate(' + kat.toFixed(1) + 'deg)';
    }

    function przesunDucha(e) {
        var duch = ciagniety.duch;

        duch.style.left = e.clientX + 'px';
        duch.style.top = e.clientY + 'px';

        if (bezRuchu) { return; }

        var poprzedni = ciagniety.ostatniX === undefined ? e.clientX : ciagniety.ostatniX;
        var dx = e.clientX - poprzedni;

        ciagniety.ostatniX = e.clientX;

        if (Math.abs(dx) < MARTWA_STREFA) { return; }

        pochyl(duch, Math.max(-POCHYLENIE_MAX,
                     Math.min(POCHYLENIE_MAX, dx * POCHYLENIE_NA_PIKSEL)));

        /* Po zatrzymaniu ręki nie przychodzą kolejne zdarzenia, więc kafelek
           zostałby przekrzywiony — prostujemy go sami, chwilę po ostatnim ruchu. */
        window.clearTimeout(prostowanie);

        prostowanie = window.setTimeout(function () {
            if (ciagniety && ciagniety.duch) { pochyl(ciagniety.duch, 0); }
        }, 120);
    }

    function celPod(e) {
        if (!ciagniety.duch) { return null; }

        /* Duch jedzie pod kursorem, więc bez ukrycia go elementFromPoint
           wskazywałby zawsze jego samego. */
        ciagniety.duch.style.display = 'none';
        var pod = document.elementFromPoint(e.clientX, e.clientY);
        ciagniety.duch.style.display = '';

        return pod;
    }

    function koniecCiagniecia(e) {
        if (!ciagniety) { return; }

        przerwijPrzewijanie();

        var stan = ciagniety;

        if (stan.ruszyl) {
            var pod = celPod(e);
            var pole = pod && pod.closest ? pod.closest('.pole') : null;
            var naLiscie = pod && pod.closest ? pod.closest('.lawka') : null;
            var wRezerwie = pod && pod.closest ? pod.closest('.rezerwa') : null;

            /* Mecz: jedyny gest, który coś zmienia, to rezerwowy upuszczony
               na grającego. Wszystko inne kończy się odłożeniem kafelka. */
            if (MECZ) {
                var zmieniany = pole ? pole.getAttribute('data-zawodnik') : null;

                if (stan.duch) { stan.duch.remove(); }
                ciagniety = null;
                rezerwa.classList.remove('is-cel');
                podswietlPola(null);
                rysuj();

                if (zmieniany && czekaNaWejscie(stan.id)) {
                    otworzOknoZmiany(stan.id, parseInt(zmieniany, 10));
                }

                return;
            }

            if (pole) {
                wstaw(pole.getAttribute('data-pole'), stan.id);
            } else if (wRezerwie) {
                naLawke(stan.id);
            } else if (naLiscie) {
                zdejmij(stan.id);
            }

            if (stan.duch) { stan.duch.remove(); }
            wybrany = null;
            ciagniety = null;
            rezerwa.classList.remove('is-cel');
            podswietlPola(null);
            rysuj();
            return;
        }

        // gest bez ruchu = stuknięcie
        ciagniety = null;

        if (stan.od.classList.contains('pole')) {
            obsluzStukniecieWPole(stan.od);
        } else if (stan.od.classList.contains('rezerwowy')) {
            // ta sama zasada co na boisku: stuknięcie w ustawionego = zdejmij
            if (!MECZ) {
                zLawki(stan.id);
                rysuj();
            }
        } else {
            zaznacz(stan.id);
        }
    }

    function obsluzStukniecieWPole(el) {
        var idPola = el.getAttribute('data-pole');
        var stojacy = ustawienie[idPola];

        if (MECZ) {
            // boisko jest tylko do oglądania; wyjątkiem jest wskazanie zmiany
            if (wybrany && stojacy && czekaNaWejscie(wybrany)) {
                otworzOknoZmiany(wybrany, stojacy);
            }

            return;
        }

        if (wybrany) {
            wstaw(idPola, wybrany);
            wybrany = null;
            podswietlPola(null);
            rysuj();
            return;
        }

        if (stojacy) {
            // stuknięcie w obsadzone pole bez wybranego zawodnika = zdejmij
            delete ustawienie[idPola];
            rysuj();
        }
    }

    /* ---------- zdarzenia ---------- */

    document.addEventListener('pointerdown', function (e) {
        // przed pierwszym gwizdkiem boisko jest tylko do oglądania
        if (PODGLAD) { return; }

        // tylko lewy przycisk myszy; dotyk i rysik nie ustawiają button
        if (e.pointerType === 'mouse' && e.button !== 0) { return; }

        var cel = e.target.closest ? e.target.closest('.zawodnik, .pole, .rezerwowy') : null;
        if (!cel) { return; }

        /* W meczu ciągnie się wyłącznie rezerwowych — grający nie ma dokąd
           pojechać, a gest bez skutku czyta się jak awaria. Ale stuknięcie
           w grającego musi działać, bo to druga połowa zmiany robionej
           palcem: najpierw rezerwowy, potem ten, który schodzi. */
        if (MECZ && !cel.classList.contains('zawodnik')) {
            if (cel.classList.contains('pole')) {
                e.preventDefault();
                obsluzStukniecieWPole(cel);
            }

            return;
        }

        var id = cel.getAttribute('data-zawodnik');

        if (!id) {
            // puste pole: nie ma czego ciągnąć, ale stuknięcie ma działać
            e.preventDefault();
            obsluzStukniecieWPole(cel);
            return;
        }

        e.preventDefault();
        // currentTarget w startCiagniecia ma być konkretnym kafelkiem
        startCiagniecia({ clientX: e.clientX, clientY: e.clientY,
                          pointerId: e.pointerId, currentTarget: cel }, parseInt(id, 10));
    });

    document.addEventListener('pointermove', function (e) {
        if (!ciagniety) { return; }

        if (!ciagniety.ruszyl) {
            var dx = Math.abs(e.clientX - ciagniety.startX);
            var dy = Math.abs(e.clientY - ciagniety.startY);

            if (dx < PROG && dy < PROG) { return; }

            ciagniety.ruszyl = true;
            ciagniety.od.classList.add('is-ciagniety');
            stworzDucha(e);
            return;
        }

        e.preventDefault();
        przesunDucha(e);
        zacznijPrzewijanie(e.clientY);

        var pod = celPod(e);
        var pole = pod && pod.closest ? pod.closest('.pole') : null;

        Array.prototype.forEach.call(boisko.querySelectorAll('.pole'), function (el) {
            el.classList.toggle('is-cel', el === pole);
        });

        rezerwa.classList.toggle('is-cel',
            !pole && !!(pod && pod.closest && pod.closest('.rezerwa')));
    }, { passive: false });

    document.addEventListener('pointerup', koniecCiagniecia);

    document.addEventListener('pointercancel', function () {
        przerwijPrzewijanie();

        if (!ciagniety) { return; }
        if (ciagniety.duch) { ciagniety.duch.remove(); }
        ciagniety = null;
        rezerwa.classList.remove('is-cel');
        podswietlPola(null);
        rysuj();
    });

    // klawiatura: Enter i spacja na kafelku robią to samo, co stuknięcie
    document.addEventListener('keydown', function (e) {
        if (PODGLAD || (e.key !== 'Enter' && e.key !== ' ')) { return; }

        var cel = e.target.closest ? e.target.closest('.zawodnik, .pole, .rezerwowy') : null;
        if (!cel) { return; }

        e.preventDefault();

        if (cel.classList.contains('pole')) {
            obsluzStukniecieWPole(cel);
        } else if (cel.classList.contains('rezerwowy')) {
            if (MECZ) { return; }

            zLawki(parseInt(cel.getAttribute('data-zawodnik'), 10));
            rysuj();
        } else {
            zaznacz(parseInt(cel.getAttribute('data-zawodnik'), 10));
        }
    });

    if (pasekWyboru) {
        pasekWyboru.querySelector('.pasek-wyboru__anuluj').addEventListener('click', function () {
            wybrany = null;
            podswietlPola(null);
            rysuj();
        });
    }

    /* Kafelek „posadź tu" przerysowuje się przy każdej zmianie, więc nasłuch
       siedzi na sekcji, a nie na samym przycisku. */
    rezerwa.addEventListener('click', function (e) {
        var slot = e.target.closest ? e.target.closest('.rezerwa__slot') : null;

        if (MECZ || !slot || !wybrany) { return; }

        naLawke(wybrany);
        wybrany = null;
        podswietlPola(null);
        rysuj();
    });

    Array.prototype.forEach.call(document.querySelectorAll('.formacja'), function (btn) {
        btn.addEventListener('click', function () {
            zmienFormacje(btn.getAttribute('data-formacja'));
        });
    });

    var wyczysc = document.getElementById('wyczysc');

    if (wyczysc) {
        wyczysc.addEventListener('click', function () {
            ustawienie = {};
            wybrany = null;
            podswietlPola(null);
            rysuj();
        });
    }

    /* Okna zamyka Escape, przycisk „Anuluj" i kliknięcie w tło — trzy
       wyjścia, bo trener trafia w ekran w biegu. */
    Array.prototype.forEach.call(document.querySelectorAll('.okno'), function (okno) {
        /* Tło zamyka okno dopiero wtedy, gdy palec i oderwał się, i opadł
           na tło. Samo sprawdzenie celu kliknięcia nie wystarcza: dotknięcie
           zawodnika na boisku kończy się kliknięciem już nad otwartym oknem,
           a to nie jest prośba o jego zamknięcie. */
        var zaczeteNaTle = false;

        okno.addEventListener('pointerdown', function (e) {
            zaczeteNaTle = e.target === okno;
        });

        okno.addEventListener('click', function (e) {
            if (zaczeteNaTle && e.target === okno) { zamknijOkna(); }

            zaczeteNaTle = false;
        });
    });

    Array.prototype.forEach.call(document.querySelectorAll('.okno__anuluj'), function (btn) {
        btn.addEventListener('click', zamknijOkna);
    });

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') { return; }

        var otwarte = document.querySelector('.okno:not([hidden])');

        if (otwarte) { zamknijOkna(); }
    });

    /* Plus pod herbem: dwa przyciski, dwa okna. Który jest który, wie już
       szablon — tutaj liczy się tylko, po której stronie padła bramka. */
    Array.prototype.forEach.call(document.querySelectorAll('[data-gol]'), function (btn) {
        btn.addEventListener('click', function () {
            otworzOkno(document.getElementById(
                btn.getAttribute('data-gol') === 'my' ? 'okno-gol' : 'okno-gol-rywala'
            ));
        });
    });

    /* Pole ukryte uzupełniamy przy każdym rysowaniu, ale wysyłka formularza
       klawiszem Enter z pola tekstowego potrafi ominąć ostatnie rysowanie —
       tańsze jest ustawienie go jeszcze raz niż szukanie takiej zguby. */
    if (form) {
        form.addEventListener('submit', function () {
            poleUstawienia.value = JSON.stringify(ustawienie);
            poleRezerwowych.value = JSON.stringify(rezerwowi);
            poleFormacji.value = formacja;
        });
    }

    rysuj();
})();
