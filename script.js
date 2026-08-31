/* =========================================
   JANOVIA — interakcje, animacje i dane z 90minut.pl
   ========================================= */

(function () {
    'use strict';

    var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var NASZ_KLUB = 'Janovia Janowiec';
    var STADION_JANOVIA = 'Janowiec 70, 39-312 Janowiec';

    /* =========================================
       0. PŁYNNE PRZEWIJANIE (Lenis)
       Przewijanie zostaje natywne — Lenis tylko wygładza jego dojazd,
       więc window.scrollY, pasek postępu i paralaksa działają dalej bez zmian.
       ========================================= */

    var lenis = null;

    if (window.Lenis && !reduced) {
        lenis = new window.Lenis({
            // szybki start, długie wytracanie — kółko myszy „niesie" stronę
            duration: 1.05,
            easing: function (t) { return 1 - Math.pow(1 - t, 4); },
            // skoki do #kotwic przejmuje Lenis; natywne smooth jest wyłączone
            // w CSS regułą html:not(.lenis), żeby ruchy się nie nakładały
            anchors: { offset: -70, duration: 1.3 },
            autoRaf: true
        });
    }

    /* Samo body{overflow:hidden} nie zatrzyma Lenisa — on przewija niezależnie
       od przepełnienia, więc okno z postem trzeba mu wprost wstrzymać.
       Blokada na body zostaje jako zapas, gdy Lenis nie wystartował. */
    function blokujPrzewijanie(zablokuj) {
        if (lenis) { zablokuj ? lenis.stop() : lenis.start(); }
        document.body.style.overflow = zablokuj ? 'hidden' : '';
    }

    /* =========================================
       1. PASEK POSTĘPU, NAGŁÓWEK, PARALAKSA
       ========================================= */

    var progress = document.getElementById('progress');
    var header = document.getElementById('header');
    var parallax = document.querySelectorAll('[data-parallax]');
    var heroContent = document.querySelector('.hero__content');
    var heroBg = document.querySelector('.hero__bg');
    var lastY = window.scrollY;
    var ticking = false;

    /* Wysokość dokumentu i okna trzymamy w zmiennych zamiast czytać przy każdej
       klatce. Odczyt scrollHeight następujący po zapisie stylu zmusza
       przeglądarkę do natychmiastowego przeliczenia układu CAŁEJ strony —
       przy sześćdziesięciu klatkach na sekundę to najdroższa pojedyncza rzecz
       w tej funkcji. Przeliczamy je tylko wtedy, gdy naprawdę mogły się
       zmienić: po zmianie rozmiaru okna i po dociągnięciu obrazków. */
    var maxScroll = 0;
    var wysokoscOkna = 0;
    var pozycje = [];

    function przeliczWymiary() {
        wysokoscOkna = window.innerHeight;
        maxScroll = document.documentElement.scrollHeight - wysokoscOkna;
    }

    function onScroll() {
        var y = window.scrollY;

        progress.style.transform = 'scaleX(' + (maxScroll > 0 ? y / maxScroll : 0) + ')';

        header.classList.toggle('is-stuck', y > 40);
        header.classList.toggle('is-hidden', y > lastY && y > 400);
        lastY = y;

        if (!reduced) {
            /* Najpierw wszystkie odczyty, dopiero potem wszystkie zapisy.
               Przeplatanie ich w jednej pętli każe przeglądarce przeliczać
               układ przy każdym obrocie — im więcej elementów, tym gorzej. */
            var i;
            for (i = 0; i < parallax.length; i++) {
                // getBoundingClientRect daje górę i dół jednym odczytem;
                // sięganie po offsetHeight w pętli zapisu wymuszałoby kolejne
                // przeliczenie układu i zniweczyło cały zysk z rozdzielenia faz
                var r = parallax[i].parentElement.getBoundingClientRect();
                pozycje[i] = r.bottom > 0 && r.top < wysokoscOkna ? r.top : null;
            }
            for (i = 0; i < parallax.length; i++) {
                if (pozycje[i] === null) { continue; }
                var el = parallax[i];
                el.style.transform =
                    'translate3d(0,' + (-pozycje[i] * parseFloat(el.getAttribute('data-parallax'))) + 'px,0)';
            }

            /* hero: tytuł odjeżdża i znika, tło robi lekki najazd — efekt
               kinowej głębi przy pierwszym przewinięciu strony.

               Po minięciu ekranu startowego przestajemy w ogóle dotykać tych
               elementów. Wcześniej skrypt przepisywał im style przez całą
               długość strony, mimo że tytuł był już niewidoczny, a tło dawno
               poza widokiem. */
            var wHero = y < wysokoscOkna * 1.2;

            if (heroContent && (wHero || heroContent.style.opacity !== '0')) {
                var zanik = Math.min(1, y / (wysokoscOkna * 0.6));
                heroContent.style.opacity = String(1 - zanik);
                // translate3d zamiast translateY — trzyma element na warstwie
                // składanej przez kartę graficzną, bez przerysowywania tekstu
                heroContent.style.transform = 'translate3d(0,' + (zanik * 50) + 'px,0)';
            }

            if (heroBg && wHero) {
                var najazd = 1 + Math.min(0.12, y / wysokoscOkna * 0.12);
                heroBg.style.transform = 'translate3d(0,' + (-y * 0.35) + 'px,0) scale(' + najazd + ')';
            }
        }

        ticking = false;
    }

    window.addEventListener('scroll', function () {
        if (!ticking) {
            window.requestAnimationFrame(onScroll);
            ticking = true;
        }
    }, { passive: true });

    window.addEventListener('resize', function () {
        przeliczWymiary();
        onScroll();
    }, { passive: true });

    // obrazki dociągane leniwie zmieniają wysokość strony, więc pasek postępu
    // liczyłby się z nieaktualnego maksimum
    window.addEventListener('load', przeliczWymiary);

    /* Skoro przestaliśmy czytać scrollHeight w każdej klatce, ktoś musi
       zauważyć, że strona urosła. A rośnie często: po wczytaniu postów
       i zawodników z plików JSON oraz przy każdym rozwinięciu kolejki
       w terminarzu. Bez tego pasek postępu liczyłby się ze starej wysokości
       i kończył za wcześnie. */
    if (window.ResizeObserver) {
        new ResizeObserver(przeliczWymiary).observe(document.body);
    }

    przeliczWymiary();
    onScroll();

    /* =========================================
       2. ODSŁANIANIE PRZY PRZEWIJANIU
       ========================================= */

    var revealer = null;
    var revealerKaruzeli = null;

    /* Rozmyte tło pod zdjęciem posta dostaje adres dopiero teraz — patrz
       komentarz przy data-tlo w kartaAktualnosci. */
    function odsloń(el) {
        el.classList.add('is-in');

        var zTlem = el.matches('[data-tlo]') ? el : el.querySelector('[data-tlo]');
        if (zTlem) {
            zTlem.style.backgroundImage = "url('" + zTlem.getAttribute('data-tlo') + "')";
            zTlem.removeAttribute('data-tlo');
        }
    }

    if ('IntersectionObserver' in window) {
        revealer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    odsloń(entry.target);
                    revealer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.15, rootMargin: '0px 0px -8% 0px' });

        /* Karty w poziomych karuzelach (.rail) na telefonie często są tylko
           częściowo odsłonięte jako "podgląd" kolejnej — przy progu 0.15 taki
           skrawek nie wystarczał, żeby kartę w ogóle odsłonić, więc karuzela
           wyglądała jak jeden, nieprzewijalny kafelek. Próg 0 odsłania kartę
           przy pierwszym widocznym pikselu. */
        revealerKaruzeli = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    odsloń(entry.target);
                    revealerKaruzeli.unobserve(entry.target);
                }
            });
        }, { threshold: 0 });
    }

    function obserwuj(nodes) {
        Array.prototype.forEach.call(nodes, function (el) {
            var obs = el.closest('.rail') ? revealerKaruzeli : revealer;
            if (obs) { obs.observe(el); }
            else { odsloń(el); }
        });
    }

    obserwuj(document.querySelectorAll('[data-reveal]'));

    /* =========================================
       3. LICZNIKI
       ========================================= */

    function runCounter(el) {
        var target = parseInt(el.getAttribute('data-count'), 10);
        var plain = el.hasAttribute('data-plain');
        var duration = 1400;
        var start = null;

        function format(v) {
            return plain ? String(v) : v.toLocaleString('pl-PL', { useGrouping: 'always' });
        }

        function frame(now) {
            if (start === null) { start = now; }
            var p = Math.min((now - start) / duration, 1);
            var eased = 1 - Math.pow(1 - p, 3);
            el.textContent = format(Math.round(target * eased));
            if (p < 1) { window.requestAnimationFrame(frame); }
        }

        if (reduced) { el.textContent = format(target); }
        else { window.requestAnimationFrame(frame); }
    }

    var counters = document.querySelectorAll('[data-count]');

    if ('IntersectionObserver' in window) {
        var countWatcher = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    runCounter(entry.target);
                    countWatcher.unobserve(entry.target);
                }
            });
        }, { threshold: 0.5 });

        counters.forEach(function (el) { countWatcher.observe(el); });
    } else {
        counters.forEach(runCounter);
    }

    /* =========================================
       4. ODLICZANIE DO MECZU
       ========================================= */

    var clock = document.getElementById('clock');
    var sekcjaOdliczania = clock ? clock.closest('.countdown') : null;
    var znacznikTrwa = document.getElementById('countdown-trwa');
    var cel = clock ? new Date(clock.getAttribute('data-target')).getTime() : 0;
    var meczeDoOdliczania = [];

    /* Ile mecz „trwa" od pierwszego gwizdka: dwie połowy, przerwa i doliczony
       czas. Po tym oknie sekcja przechodzi na kolejne spotkanie. */
    var CZAS_MECZU_MS = 2 * 60 * 60 * 1000;

    function pad(n) { return n < 10 ? '0' + n : String(n); }

    /* Cała sekcja znika, gdy nie ma do czego odliczać — zamiast zamrożonego
       „00 00 00 00" po terminie ostatniego znanego meczu. Łapie to zarówno
       termin wpisany na sztywno w HTML (gdy JSON się nie wczyta), jak i
       stronę zostawioną otwartą na czas pierwszego gwizdka. */
    function pokazOdliczanie(widoczne) {
        if (sekcjaOdliczania) { sekcjaOdliczania.hidden = !widoczne; }
    }

    /* Na czas meczu zegar ustępuje miejsca etykiecie „Mecz w trakcie". */
    function ustawTrakcie(trwa) {
        if (sekcjaOdliczania) { sekcjaOdliczania.classList.toggle('is-trwa', trwa); }
        if (znacznikTrwa) { znacznikTrwa.hidden = !trwa; }
    }

    /* Mecz uznajemy za trwający tylko przy znanej godzinie. Przy terminie
       przybliżonym data_iso to północ pierwszego dnia okna, więc etykieta
       wisiałaby przez cały dzień, zanim ktokolwiek wyjdzie na boisko. */
    function trwajacyMecz() {
        var teraz = Date.now();

        return meczeDoOdliczania.filter(function (m) {
            if (!m.data_iso || m.data_przyblizona) { return false; }

            var start = new Date(m.data_iso).getTime();
            return teraz >= start && teraz < start + CZAS_MECZU_MS;
        })[0];
    }

    if (clock) {
        var pola = {
            d: clock.querySelector('[data-unit="d"]'),
            h: clock.querySelector('[data-unit="h"]'),
            m: clock.querySelector('[data-unit="m"]'),
            s: clock.querySelector('[data-unit="s"]')
        };

        var tick = function () {
            var diff = cel - Date.now();

            /* Termin minął przy otwartej stronie (ktoś zostawił kartę na czas
               pierwszego gwizdka) — przestawiamy zegar na kolejny mecz. */
            if (!(diff > 0) && meczeDoOdliczania.length) {
                odswiezOdliczanie();
                diff = cel - Date.now();
            }

            // warunek odwrotnie, żeby złapać też NaN z niepoprawnej daty
            if (!(diff > 0)) {
                pokazOdliczanie(false);
                return;
            }

            pokazOdliczanie(true);

            var s = Math.floor(diff / 1000);
            pola.d.textContent = pad(Math.floor(s / 86400));
            pola.h.textContent = pad(Math.floor(s / 3600) % 24);
            pola.m.textContent = pad(Math.floor(s / 60) % 60);
            pola.s.textContent = pad(s % 60);
        };

        tick();
        window.setInterval(tick, 1000);
    }

    /* =========================================
       5. STRZAŁKI KARUZEL
       ========================================= */

    document.querySelectorAll('[data-rail]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var rail = document.querySelector('[data-rail-id="' + btn.getAttribute('data-rail') + '"]');
            if (!rail || !rail.firstElementChild) { return; }

            var step = rail.firstElementChild.getBoundingClientRect().width + 20;
            rail.scrollBy({
                left: step * parseInt(btn.getAttribute('data-dir'), 10),
                behavior: reduced ? 'auto' : 'smooth'
            });
        });
    });

    /* =========================================
       6. MENU MOBILNE
       ========================================= */

    var toggle = document.getElementById('nav-toggle');

    document.querySelectorAll('.nav a').forEach(function (link) {
        link.addEventListener('click', function () { toggle.checked = false; });
    });

    /* =========================================
       7. DANE Z 90MINUT.PL (data/liga.json)
       Plik odświeża update_liga.php, uruchamiany cronem na serwerze.
       Gdy pliku brak — na stronie zostaje treść wpisana w HTML.
       ========================================= */

    var MIESIACE_SKROT = ['sty', 'lut', 'mar', 'kwi', 'maj', 'cze',
                          'lip', 'sie', 'wrz', 'paź', 'lis', 'gru'];

    var DNI_TYGODNIA = ['niedziela', 'poniedziałek', 'wtorek', 'środa',
                        'czwartek', 'piątek', 'sobota'];

    // skróty do wąskiej kolumny z terminem w terminarzu
    var DNI_SKROT = ['nd', 'pon', 'wt', 'śr', 'czw', 'pt', 'sob'];

    /* Nazwa drużyny u 90minut -> plik herbu w folderze png/.
       Klub spoza listy (np. po zmianie ligi) dostanie zastępczą tarczę z literą. */
    var HERBY = {
        'Apollo Dulcza Mała':       'ApolloDM.png',
        'Atut II Podborze':         'AtutPodborze.png',
        'Hetman Dąbrówka Wisłocka': 'HetmanDW.png',
        'Jamnica Dulcza Wielka':    'Jamnica_DW.png',
        'Janovia Janowiec':         'JanoviaJanowiec.png',
        'KS SMP Tuszyma':           'KSTuszyma.png',
        'KS Zgórsko':               'KS_Zgorsko.png',
        'LKS Wierzchowiny':         'LKSWierzchowiny.png',
        'Madras Goleszów':          'MadrasGoleszow.png',
        'Piast II Wadowice Górne':  'PiastWG.png',
        'Sokół Pień':               'SokolPien.png',
        'Sprint Żarówka':           'Sprint_Zarowka.png'
    };

    /* Posty z Facebooka często zaczynają się od emoji (np. "🏆 Podsumowanie…") —
       w dużym nagłówku hero wygląda to niechlujnie, więc tam je wycinamy. */
    var WZOR_EMOJI = new RegExp(
        '\\p{Extended_Pictographic}(\u200D\\p{Extended_Pictographic})*|\uFE0F', 'gu'
    );

    function bezEmoji(tekst) {
        return tekst.replace(WZOR_EMOJI, '').replace(/\s+/g, ' ').trim();
    }

    function skrot(nazwa) {
        return nazwa.replace(/\s*\b(I{1,3}|II)\b\s*/g, ' ').trim();
    }

    function litera(nazwa) {
        return nazwa.trim().charAt(0).toUpperCase();
    }

    function esc(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
                        .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    /* rozmiar: 'xs' (tabela), 'sm' (karty meczów), 'md' (odliczanie)

       Wpisy w HERBY to zwykle same nazwy plików z folderu png/ (klub z ligi),
       ale herb wgrany w panelu dla przeciwnika spoza listy trafia tu jako pełna
       ścieżka (uploads/mecze/...) — rozpoznajemy ją po obecności ukośnika,
       więc nie dokładamy do niej z automatu przedrostka "png/". */
    function herb(nazwa, rozmiar) {
        var plik = HERBY[nazwa];

        if (!plik) {
            return '<span class="shield shield--' + (rozmiar || 'sm') +
                   '" data-letter="' + esc(litera(nazwa)) + '"></span>';
        }

        var src = plik.indexOf('/') === -1 ? 'png/' + plik : plik;

        return '<span class="crest-plate crest-plate--' + (rozmiar || 'sm') + '">' +
               '<img src="' + src + '" alt="' + esc(nazwa) + '" loading="lazy">' +
               '</span>';
    }

    function wierszTabeli(r) {
        var klasa = r.klub === NASZ_KLUB ? ' class="is-us"' : '';
        return '<tr' + klasa + '>' +
               '<td>' + r.poz + '</td>' +
               '<td class="table__crest">' + herb(r.klub, 'xs') + '</td>' +
               '<td>' + esc(r.klub) + '</td>' +
               '<td>' + r.m + '</td>' +
               '<td>' + r.bramki + '</td>' +
               '<td>' + r.pkt + '</td>' +
               '</tr>';
    }

    function rysujTabele(dane) {
        var tbody = document.querySelector('.table tbody');
        if (!tbody || !dane.tabela || !dane.tabela.length) { return; }

        tbody.innerHTML = dane.tabela.map(wierszTabeli).join('');

        var stopka = document.getElementById('tabela-zrodlo');
        if (stopka) {
            var d = new Date(dane.zaktualizowano);
            stopka.textContent = dane.liga + ' · dane z 90minut.pl, aktualizacja ' +
                pad(d.getDate()) + '.' + pad(d.getMonth() + 1) + '.' + d.getFullYear();
        }
    }

    /* Forma: pięć kwadracików z ostatnich meczów, od najstarszego do najnowszego.
       Brakujące mecze (początek sezonu) to puste, obrysowane pola. */
    function rysujForme(nazwa, mapa) {
        var lista = (mapa || {})[nazwa] || [];
        var puste = Math.max(0, 5 - lista.length);
        var pola = '';
        var i;

        for (i = 0; i < puste; i++) {
            pola += '<i class="forma__box forma__box--brak"></i>';
        }

        for (i = 0; i < lista.length; i++) {
            var m = lista[i];
            var klucz = m.wynik === 'Z' ? 'z' : (m.wynik === 'P' ? 'p' : 'r');
            var slowo = m.wynik === 'Z' ? 'Wygrana' : (m.wynik === 'P' ? 'Porażka' : 'Remis');
            var opis = slowo + ' ' + m.rezultat + ' z ' + m.rywal +
                       ' (' + m.gdzie + ', kolejka ' + m.kolejka + ')';

            pola += '<i class="forma__box forma__box--' + klucz + '" title="' + esc(opis) + '"></i>';
        }

        var etykieta = lista.length
            ? 'Forma z ostatnich ' + lista.length + ' meczów'
            : 'Brak rozegranych meczów';

        return '<span class="forma" role="img" aria-label="' + esc(etykieta) + '">' + pola + '</span>';
    }

    /* Nazwy drużyn zajmują od jednej do trzech linii, przez co kwadraciki
       formy wypadałyby na różnych wysokościach. Mierzymy najwyższą nazwę
       w całym rzędzie kart i wyrównujemy do niej pozostałe. */
    function wyrownajNazwy(rail) {
        var nazwy = rail.querySelectorAll('.fixture__team');
        var i;

        if (!nazwy.length) { return; }

        for (i = 0; i < nazwy.length; i++) {
            nazwy[i].style.minHeight = '';
        }

        var najwyzsza = 0;
        for (i = 0; i < nazwy.length; i++) {
            najwyzsza = Math.max(najwyzsza, nazwy[i].getBoundingClientRect().height);
        }

        for (i = 0; i < nazwy.length; i++) {
            nazwy[i].style.minHeight = Math.ceil(najwyzsza) + 'px';
        }
    }

    var przeliczanie;

    window.addEventListener('resize', function () {
        window.clearTimeout(przeliczanie);
        przeliczanie = window.setTimeout(function () {
            var rail = document.querySelector('[data-rail-id="fix"]');
            if (rail) { wyrownajNazwy(rail); }
        }, 150);
    });

    var IKONA_PINEZKI =
        '<svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true">' +
        '<path fill="currentColor" fill-rule="evenodd" clip-rule="evenodd" d="M12 2C8.13 2 5 5.13 5 9c0 5.25 6.19 12.13 6.46 12.42a.75.75 0 0 0 1.08 0C12.81 21.13 19 14.25 19 9c0-3.87-3.13-7-7-7zm0 9.5A2.5 2.5 0 1 1 12 6.5a2.5 2.5 0 0 1 0 5z"/>' +
        '</svg>';

    /* Adres na mapie: dla meczów u siebie zawsze nasz stadion, dla wyjazdowych
       nie znamy dokładnego adresu przeciwnika — Maps i tak trafnie znajdzie
       boisko po nazwie klubu wpisanej jako wyszukiwanie. */
    function linkMapy(gospodarz) {
        var cel = gospodarz === NASZ_KLUB ? STADION_JANOVIA : gospodarz + ' stadion piłkarski';
        return 'https://www.google.com/maps/dir/?api=1&destination=' + encodeURIComponent(cel);
    }

    /* Do kiedy mecz ma jeszcze prawo wisieć w „nadchodzących".

       Przy przybliżonym terminie data_iso to północ pierwszego dnia okna
       („22-23 sierpnia"), a nie godzina meczu — liczymy więc do końca dnia
       drugiego, żeby spotkanie nie zniknęło z terminarza w dniu, w którym
       ma być dopiero rozegrane. */
    function koniecTerminu(m) {
        var czas = m && m.data_iso ? new Date(m.data_iso).getTime() : NaN;
        if (!m) { return NaN; }

        /* Przy dokładnej godzinie doliczamy czas gry — inaczej mecz znikałby
           z terminarza w chwili pierwszego gwizdka, a wtedy odliczanie nie
           miałoby czego oznaczyć jako trwające. */
        return m.data_przyblizona ? czas + 2 * 86400000 : czas + CZAS_MECZU_MS;
    }

    /* Mecz bez czytelnej daty zostaje — lepiej pokazać go z „—" niż po cichu
       zgubić pozycję z terminarza. */
    function czyPrzyszly(m) {
        var koniec = koniecTerminu(m);
        return isNaN(koniec) || koniec > Date.now();
    }

    /* Jedna karta meczu — używana i w karuzeli na stronie głównej,
       i w pełnej siatce na podstronie z terminarzem. */
    function kartaMeczu(m, i, dane) {
        var data = m.data_iso ? new Date(m.data_iso) : null;
        var dzien = data
            ? pad(data.getDate()) + '.' + pad(data.getMonth() + 1)
            : '—';

        var kiedy = m.data_przyblizona
            ? (m.kolejka_opis || 'termin nieznany')
            : pad(data.getHours()) + ':' + pad(data.getMinutes());

        var gdzie = m.gospodarz === NASZ_KLUB ? 'u siebie' : 'wyjazd';

        var liga = m.kolejka
            ? 'Klasa B · kolejka ' + m.kolejka
            : (m.etykieta || 'Mecz towarzyski');

        if (data && !m.data_przyblizona) {
            liga = DNI_TYGODNIA[data.getDay()] + ' · ' + liga;
        }

        return '<article class="fixture" data-reveal style="--d:' + (i * 80) + 'ms">' +
               '<strong class="fixture__date">' + dzien + '</strong>' +
               '<span class="fixture__league">' + esc(liga) + '</span>' +
               '<div class="fixture__match">' +
                   '<div class="fixture__side">' +
                       herb(m.gospodarz, 'sm') +
                       '<b class="fixture__team">' + esc(skrot(m.gospodarz)) + '</b>' +
                       rysujForme(m.gospodarz, dane.forma) +
                   '</div>' +
                   '<span class="fixture__vs">vs</span>' +
                   '<div class="fixture__side">' +
                       herb(m.gosc, 'sm') +
                       '<b class="fixture__team">' + esc(skrot(m.gosc)) + '</b>' +
                       rysujForme(m.gosc, dane.forma) +
                   '</div>' +
               '</div>' +
               '<span class="fixture__time">' +
                   kiedy + ' · ' + gdzie +
                   '<a class="fixture__mapa" href="' + linkMapy(m.gospodarz) + '" ' +
                       'target="_blank" rel="noopener" title="Pokaż lokalizację na mapie" ' +
                       'aria-label="Pokaż lokalizację meczu na mapie" onclick="event.stopPropagation()">' +
                       IKONA_PINEZKI +
                   '</a>' +
               '</span>' +
               '</article>';
    }

    function rysujMecze(dane) {
        var rail = document.querySelector('[data-rail-id="fix"]');
        if (!rail) { return; }

        var mecze = dane.nadchodzace || [];
        var sekcja = document.getElementById('mecze');

        if (!mecze.length) {
            if (sekcja) { sekcja.hidden = true; }
            return;
        }

        if (sekcja) { sekcja.hidden = false; }

        // karuzela pokazuje zajawkę; komplet terminów jest na terminarz.html
        rail.innerHTML = mecze.slice(0, 6).map(function (m, i) {
            return kartaMeczu(m, i, dane);
        }).join('');

        obserwuj(rail.querySelectorAll('[data-reveal]'));
        wyrownajNazwy(rail);
    }

    function rysujOdliczanie(dane) {
        meczeDoOdliczania = dane.nadchodzace || [];
        odswiezOdliczanie();
    }

    /* Herby i podpis pod zegarem — wspólne dla meczu nadchodzącego i trwającego. */
    function wypiszMecz(m) {
        var teams = document.querySelector('.countdown__teams');
        if (teams) {
            teams.innerHTML =
                herb(m.gospodarz, 'md') +
                '<span class="vs">vs</span>' +
                herb(m.gosc, 'md');
        }

        var meta = document.querySelector('.countdown__meta');
        if (!meta) { return; }

        var data = new Date(m.data_iso);
        var kiedy = m.data_przyblizona
            ? m.kolejka_opis
            : DNI_TYGODNIA[data.getDay()] + ', ' + data.getDate() + ' ' +
              MIESIACE_SKROT[data.getMonth()] + ', ' + pad(data.getHours()) + ':' + pad(data.getMinutes());

        var opisKolejki = m.kolejka
            ? 'kolejka ' + m.kolejka
            : (m.etykieta || 'mecz towarzyski');

        meta.textContent = skrot(m.gospodarz) + ' — ' + skrot(m.gosc) +
                           ' · ' + opisKolejki + ' · ' + kiedy;
    }

    /* Wywoływane też z zegara, gdy termin minie przy otwartej stronie. */
    function odswiezOdliczanie() {
        if (!clock) { return; }

        /* Mecz rozgrywany właśnie teraz wyprzedza odliczanie: zamiast zegara
           idzie etykieta, a celem staje się koniec spotkania — po nim sekcja
           sama przeskoczy na następny termin. */
        var trwa = trwajacyMecz();

        if (trwa) {
            cel = new Date(trwa.data_iso).getTime() + CZAS_MECZU_MS;
            pokazOdliczanie(true);
            ustawTrakcie(true);
            wypiszMecz(trwa);
            return;
        }

        ustawTrakcie(false);

        /* Zegar potrzebuje terminu z przyszłości, a nie „najbliższego" —
           mecz z przybliżoną datą zostaje na liście przez całe okno, więc
           w dniu meczu jego data_iso (północ) jest już za nami. */
        var nastepny = meczeDoOdliczania.filter(function (m) {
            return m.data_iso && new Date(m.data_iso).getTime() > Date.now();
        })[0];

        if (!nastepny) {
            cel = NaN;
            pokazOdliczanie(false);
            return;
        }

        cel = new Date(nastepny.data_iso).getTime();
        pokazOdliczanie(true);
        wypiszMecz(nastepny);
    }

    /* =========================================
       8. POSTY Z FACEBOOKA (data/facebook.json)
       Plik przygotowuje update_fb.php przez Graph API.
       Gdy pliku brak — zostają kafelki wpisane w HTML.
       ========================================= */

    function odKiedy(iso) {
        var minuty = Math.floor((Date.now() - new Date(iso).getTime()) / 60000);

        if (minuty < 60) { return 'przed chwilą'; }

        var godziny = Math.floor(minuty / 60);
        if (godziny < 24) {
            return godziny + (godziny === 1 ? ' godzinę temu' :
                              godziny < 5 ? ' godziny temu' : ' godzin temu');
        }

        var dni = Math.floor(godziny / 24);
        if (dni < 31) {
            return dni === 1 ? 'wczoraj' : dni + ' dni temu';
        }

        var d = new Date(iso);
        return d.getDate() + ' ' + MIESIACE_SKROT[d.getMonth()] + ' ' + d.getFullYear();
    }

    /* ---------- okno z pełną treścią posta ---------- */

    var POSTY = [];

    var MIESIACE = ['stycznia', 'lutego', 'marca', 'kwietnia', 'maja', 'czerwca',
                    'lipca', 'sierpnia', 'września', 'października', 'listopada', 'grudnia'];

    var modal = document.getElementById('modal');
    var ostatnioKlikniety = null;
    var GALERIA = [];
    var GALERIA_I = 0;

    /* Rysuje aktualne zdjęcie galerii i pokazuje/chowa strzałki — wywoływana
       przy otwarciu posta i po każdym kliknięciu w strzałkę. */
    function pokazZdjecieGalerii() {
        var foto = document.getElementById('modal-foto');
        var pasek = document.getElementById('modal-galeria');
        var licznik = document.getElementById('modal-licznik');

        if (!foto) { return; }

        foto.style.backgroundImage = GALERIA.length
            ? 'url("' + encodeURI(GALERIA[GALERIA_I]) + '")'
            : '';

        var wiele = GALERIA.length > 1;
        if (pasek) { pasek.hidden = !wiele; }
        if (licznik) { licznik.textContent = (GALERIA_I + 1) + ' / ' + GALERIA.length; }
    }

    function przewinGalerie(kierunek) {
        if (GALERIA.length < 2) { return; }
        GALERIA_I = (GALERIA_I + kierunek + GALERIA.length) % GALERIA.length;
        pokazZdjecieGalerii();
    }

    function pelnaData(iso) {
        var d = new Date(iso);
        return d.getDate() + ' ' + MIESIACE[d.getMonth()] + ' ' + d.getFullYear() +
               ', ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
    }

    function otworzPost(indeks) {
        var p = POSTY[indeks];
        if (!modal || !p) { return; }

        var okno = modal.querySelector('.modal__okno');
        var foto = document.getElementById('modal-foto');

        // wideo z Facebooka odtwarzamy na miejscu przez oficjalny odtwarzacz FB,
        // zamiast tylko linkować do posta — działa dla każdego publicznego wideo,
        // bo potrzebuje tylko adresu posta (permalink), bez tokenu ani API
        var maWideo = p.typ === 'video' && p.link;

        GALERIA = (p.galeria && p.galeria.length) ? p.galeria : (p.zdjecie ? [p.zdjecie] : []);
        GALERIA_I = 0;

        okno.classList.remove('modal__okno--zoom');
        okno.classList.toggle('bez-foto', !GALERIA.length && !maWideo);
        foto.classList.toggle('modal__foto--wideo', !!maWideo);

        if (maWideo) {
            var pasek = document.getElementById('modal-galeria');
            if (pasek) { pasek.hidden = true; }
            foto.style.backgroundImage = '';
            foto.innerHTML =
                '<iframe src="https://www.facebook.com/plugins/video.php?href=' +
                encodeURIComponent(p.link) + '&show_text=false&autoplay=false" ' +
                'title="' + esc(p.naglowek) + '" allow="encrypted-media; fullscreen" ' +
                'allowfullscreen frameborder="0"></iframe>';
        } else {
            foto.innerHTML = '';
            pokazZdjecieGalerii();
        }

        document.getElementById('modal-tag').textContent =
            p.typ === 'video' ? 'Wideo' : (p.typ === 'album' ? 'Galeria' : 'Facebook');
        document.getElementById('modal-data').textContent = pelnaData(p.data);
        document.getElementById('modal-tytul').textContent = p.naglowek;
        document.getElementById('modal-tekst').textContent = p.tekst || '(post bez opisu)';

        var link = document.getElementById('modal-link');
        link.href = p.link || '#';
        link.hidden = !p.link;

        modal.hidden = false;
        blokujPrzewijanie(true);
        modal.querySelector('.modal__zamknij').focus();
    }

    function zamknijPost() {
        if (!modal || modal.hidden) { return; }

        modal.hidden = true;
        blokujPrzewijanie(false);

        // usuwa iframe, żeby wideo przestało grać w tle po zamknięciu
        var foto = document.getElementById('modal-foto');
        if (foto) { foto.innerHTML = ''; }

        if (ostatnioKlikniety) {
            ostatnioKlikniety.focus();
            ostatnioKlikniety = null;
        }
    }

    if (modal) {
        modal.addEventListener('click', function (e) {
            if (e.target.hasAttribute('data-zamknij')) { zamknijPost(); }
        });

        document.getElementById('modal-poprzednie').addEventListener('click', function () {
            przewinGalerie(-1);
        });

        document.getElementById('modal-nastepne').addEventListener('click', function () {
            przewinGalerie(1);
        });

        // klik w zdjęcie powiększa okno na cały ekran; drugi klik pomniejsza.
        // Wideo ma własną obsługę kliknięć (play), więc go pomijamy.
        document.getElementById('modal-foto').addEventListener('click', function (e) {
            if (e.currentTarget.classList.contains('modal__foto--wideo')) { return; }
            if (!GALERIA.length) { return; }
            modal.querySelector('.modal__okno').classList.toggle('modal__okno--zoom');
        });

        document.addEventListener('keydown', function (e) {
            if (modal.hidden) { return; }
            if (e.key === 'Escape') { zamknijPost(); }
            if (e.key === 'ArrowLeft') { przewinGalerie(-1); }
            if (e.key === 'ArrowRight') { przewinGalerie(1); }
        });

        // delegacja: kafelki powstają dopiero po pobraniu danych
        document.addEventListener('click', function (e) {
            var kafelek = e.target.closest ? e.target.closest('.post[data-post]') : null;
            if (!kafelek) { return; }
            ostatnioKlikniety = kafelek;
            otworzPost(parseInt(kafelek.getAttribute('data-post'), 10));
        });

        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter' && e.key !== ' ') { return; }

            var kafelek = e.target.closest ? e.target.closest('.post[data-post]') : null;
            if (!kafelek) { return; }

            e.preventDefault();
            ostatnioKlikniety = kafelek;
            otworzPost(parseInt(kafelek.getAttribute('data-post'), 10));
        });
    }

    function etykietaPosta(p) {
        return p.zrodlo === 'klub' ? p.typ
             : p.typ === 'video' ? 'Wideo'
             : p.typ === 'album' ? 'Galeria'
             : 'Facebook';
    }

    /* Sekcja powitalna pokazuje nagłówek najnowszego newsa zamiast
       treści wpisanej na sztywno w HTML. */
    function rysujHero(p) {
        var tytul = document.querySelector('.hero__title');
        if (!tytul || !p) { return; }

        var tag = document.querySelector('.hero__meta .tag');
        var czas = document.querySelector('.hero__time');

        if (tag) { tag.textContent = etykietaPosta(p); }
        if (czas) { czas.textContent = odKiedy(p.data); }

        /* Pierwsza część nagłówka biała, końcówka na złoto —
           tak jak w oryginalnym, wpisanym na sztywno tytule. */
        var slowa = bezEmoji(p.naglowek).split(' ').filter(Boolean);
        var podzial = Math.max(1, Math.floor(slowa.length * 0.6));
        var biale = esc(slowa.slice(0, podzial).join(' '));
        var zlote = esc(slowa.slice(podzial).join(' '));

        tytul.innerHTML = '<span class="line">' + biale +
            (zlote ? ' <span class="line--accent">' + zlote + '</span>' : '') +
            '</span>';
    }

    function kartaAktualnosci(p, i) {
        // adres trafia i do CSS-owego url(), i do src — apostrof zamykałby url()
        var src = p.zdjecie ? encodeURI(p.zdjecie).replace(/'/g, '%27') : '';

        /* Adres tła siedzi w data-tlo, a nie od razu w stylu. Tła CSS nie
           podlegają loading="lazy", więc wpisane wprost pobierałyby się dla
           wszystkich stu kafelków naraz — samych zdjęć z Facebooka robiło to
           prawie 11 MB przy wejściu na stronę. Wstawia je dopiero obserwator,
           gdy kafelek wjeżdża w widok. */
        var tlo = src ? ' data-tlo="' + src + '"' : '';
        var foto = src
            ? '<img class="post__foto" src="' + src + '" alt="" loading="lazy">'
            : '';

        /* Znak odtwarzania na wpisach z filmem. Ma to znaczenie zwłaszcza tam,
           gdzie kafelek pokazuje samo ozdobne tło: Facebook przy rolkach podaje
           jako podgląd czarną klatkę, którą odrzucamy, więc bez tego znaku nic
           nie sugerowałoby, że pod spodem jest nagranie.
           aria-hidden, bo to ozdoba — czytnik ekranu i tak przeczyta etykietę
           „Wideo" obok tytułu. */
        var odtwarzanie = p.typ === 'video'
            ? '<span class="post__play" aria-hidden="true"></span>'
            : '';

        return '<article class="post" role="button" tabindex="0"' +
               ' data-post="' + i + '" aria-haspopup="dialog"' +
               ' data-reveal style="--d:' + (Math.min(i, 10) * 60) + 'ms">' +
               '<div class="post__media' + (p.zdjecie ? ' post__media--foto' : ' post__media--' + ((i % 6) + 1)) + '"' + tlo + '>' + foto + odtwarzanie + '</div>' +
               '<div class="post__body">' +
                   '<span class="tag tag--sm">' + esc(etykietaPosta(p)) + '</span>' +
                   '<span class="post__time">' + odKiedy(p.data) + '</span>' +
                   '<h3>' + esc(p.naglowek) + '</h3>' +
               '</div>' +
               '</article>';
    }

    /* Wpisy z panelu i posty z Facebooka trafiają do jednej listy,
       posortowanej datą od najnowszego. */
    function rysujPosty(zFacebooka, zPanelu) {
        var rail = document.querySelector('[data-rail-id="news"]');
        var siatka = document.getElementById('aktualnosci-siatka');
        if (!rail && !siatka) { return; }

        var wszystkie = []
            .concat((zPanelu && zPanelu.posty) || [])
            .concat((zFacebooka && zFacebooka.posty) || []);

        if (!wszystkie.length) { return; }

        wszystkie.sort(function (a, b) {
            return new Date(b.data) - new Date(a.data);
        });

        POSTY = wszystkie;

        if (rail) {
            rail.innerHTML = wszystkie.map(kartaAktualnosci).join('');
            obserwuj(rail.querySelectorAll('[data-reveal]'));
            rysujHero(wszystkie[0]);
        }

        // pełna siatka na podstronie aktualnosci.html — te same karty,
        // ten sam POSTY i data-post, więc kliknięcie otwiera ten sam modal
        if (siatka) {
            siatka.innerHTML = wszystkie.map(kartaAktualnosci).join('');
            obserwuj(siatka.querySelectorAll('[data-reveal]'));
        }
    }

    /* ---------- komplet wyników ostatniej kolejki ---------- */

    function rysujKolejke(dane) {
        var lista = document.getElementById('wyniki');
        var opis = document.getElementById('kolejka-opis');
        var k = dane.ostatnia_kolejka;

        if (!lista || !k || !k.mecze || !k.mecze.length) { return; }

        if (opis) {
            opis.textContent = 'Kolejka ' + k.numer + ' · ' + k.opis +
                (k.rozegrana ? '' : ' · jeszcze nierozegrana');
        }

        lista.innerHTML = k.mecze.map(wierszMeczu).join('');
    }

    /* ---------- pełny terminarz (podstrona terminarz.html) ---------- */

    /* Termin pojedynczego meczu, pokazywany przed gospodarzem.
       Przy dacie przybliżonej data_iso to północ pierwszego dnia okna, więc
       godziny nie ma co wypisywać — zostaje sam dzień, a okno („22-23 sierpnia")
       i tak widnieje w nagłówku kolejki. */
    function terminMeczu(m) {
        if (!m.data_iso) { return '<span class="wynik__termin">—</span>'; }

        var d = new Date(m.data_iso);
        if (isNaN(d.getTime())) { return '<span class="wynik__termin">—</span>'; }

        var dzien = DNI_SKROT[d.getDay()] + ' ' + pad(d.getDate()) + '.' + pad(d.getMonth() + 1);

        if (m.data_przyblizona) {
            return '<span class="wynik__termin"><b>' + dzien + '</b></span>';
        }

        return '<span class="wynik__termin">' +
                   '<b>' + dzien + '</b>' +
                   '<i>' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + '</i>' +
               '</span>';
    }

    function wierszMeczu(m) {
        var nasz = m.gospodarz === NASZ_KLUB || m.gosc === NASZ_KLUB;

        var srodek = m.wynik
            ? '<span class="wynik__rezultat">' + esc(m.wynik.replace('-', ' : ')) + '</span>'
            : '<span class="wynik__rezultat wynik__rezultat--brak">–</span>';

        return '<li class="wynik' + (nasz ? ' is-us' : '') + '">' +
                   terminMeczu(m) +
                   '<span class="wynik__druzyna wynik__druzyna--gosp">' +
                       '<span>' + esc(skrot(m.gospodarz)) + '</span>' +
                       herb(m.gospodarz, 'xs') +
                   '</span>' +
                   srodek +
                   '<span class="wynik__druzyna">' +
                       herb(m.gosc, 'xs') +
                       '<span>' + esc(skrot(m.gosc)) + '</span>' +
                   '</span>' +
               '</li>';
    }

    /* Rozwijanie i zwijanie kolejki. Całą animację robi CSS (siatka jedzie
       z 0fr do 1fr), tutaj tylko przełączamy klasę i stan dla czytników
       ekranu — dzięki temu nie trzeba niczego mierzyć ani liczyć wysokości. */
    function plynneRozwijanie(box) {
        Array.prototype.forEach.call(box.querySelectorAll('.kolejka__head'), function (przycisk) {
            przycisk.addEventListener('click', function () {
                var kolejka = przycisk.closest('.kolejka');
                var otwarta = kolejka.classList.toggle('is-otwarta');
                przycisk.setAttribute('aria-expanded', otwarta ? 'true' : 'false');
            });
        });
    }

    function rysujTerminarz(dane) {
        var box = document.getElementById('terminarz');
        var mecze = dane && dane.wszystkie_mecze;

        if (!box) { return; }

        if (!mecze || !mecze.length) {
            box.innerHTML = '<p class="wyniki__pusto">Terminarz jest chwilowo niedostępny.</p>';
            return;
        }

        var opis = document.getElementById('terminarz-opis');
        if (opis && dane.liga) {
            opis.textContent = dane.liga;
        }

        // grupujemy mecze po numerze kolejki, zachowując porządek rosnący
        var numery = [];
        var wgKolejki = {};

        mecze.forEach(function (m) {
            if (!wgKolejki[m.kolejka]) {
                wgKolejki[m.kolejka] = { opis: m.kolejka_opis, mecze: [] };
                numery.push(m.kolejka);
            }
            wgKolejki[m.kolejka].mecze.push(m);
        });

        numery.sort(function (a, b) { return a - b; });

        // pierwsza połowa numerów to runda jesienna, reszta wiosenna
        var polowa = Math.ceil(numery.length / 2);

        function czyRozegrana(nr) {
            return wgKolejki[nr].mecze.some(function (m) { return m.wynik; });
        }

        /* Na zwiniętym pasku pokazujemy sam mecz Janovii — po to, żeby dało się
           przejrzeć sezon bez rozwijania każdej kolejki. */
        function wynikJanovii(k) {
            var nasz = k.mecze.filter(function (m) {
                return m.gospodarz === NASZ_KLUB || m.gosc === NASZ_KLUB;
            })[0];

            if (!nasz || !nasz.wynik) { return ''; }

            return '<span class="kolejka__wynik">' +
                       '<span>' + esc(skrot(nasz.gospodarz)) + '</span>' +
                       '<b>' + esc(nasz.wynik.replace('-', ' : ')) + '</b>' +
                       '<span>' + esc(skrot(nasz.gosc)) + '</span>' +
                   '</span>';
        }

        function blokKolejki(nr, i, otwarta) {
            var k = wgKolejki[nr];
            var rozegrana = czyRozegrana(nr);

            var id = 'kolejka-' + nr;

            return '<article class="kolejka' + (otwarta ? ' is-otwarta' : '') +
                       '" data-reveal style="--d:' + Math.min(i * 40, 320) + 'ms">' +
                       '<button class="kolejka__head" type="button"' +
                           ' aria-expanded="' + (otwarta ? 'true' : 'false') + '"' +
                           ' aria-controls="' + id + '">' +
                           '<h3>Kolejka ' + nr + '</h3>' +
                           wynikJanovii(k) +
                           '<span>' + esc(k.opis || 'termin nieustalony') +
                               (rozegrana ? '' : ' · nierozegrana') +
                           '</span>' +
                       '</button>' +
                       '<div class="kolejka__tresc" id="' + id + '">' +
                           '<ul class="wyniki">' + k.mecze.map(wierszMeczu).join('') + '</ul>' +
                       '</div>' +
                   '</article>';
        }

        /* Otwarte zostają dwie kolejki: ostatnia rozegrana (świeże wyniki)
           i najbliższa nierozegrana (co gramy dalej). Reszta jest zwinięta,
           więc sezon mieści się na ekranie zamiast ciągnąć się przez 22 bloki.
           Przy pierwszej rozegranej kolejce nie ma czego zwijać — zostaje
           otwarta sama z siebie, bo jest jednocześnie tą ostatnią. */
        var rozegrane = numery.filter(czyRozegrana);
        var nierozegrane = numery.filter(function (nr) { return !czyRozegrana(nr); });

        var otwarte = {};
        if (rozegrane.length)    { otwarte[rozegrane[rozegrane.length - 1]] = true; }
        if (nierozegrane.length) { otwarte[nierozegrane[0]] = true; }

        function runda(tytul, lista, przesuniecie) {
            if (!lista.length) { return ''; }

            return '<section class="runda">' +
                       '<h2 class="runda__tytul">' + tytul + '</h2>' +
                       lista.map(function (nr, i) {
                           return blokKolejki(nr, i + przesuniecie, otwarte[nr]);
                       }).join('') +
                   '</section>';
        }

        box.innerHTML =
            runda('Runda <em>jesienna</em>', numery.slice(0, polowa), 0) +
            runda('Runda <em>wiosenna</em>', numery.slice(polowa), 0);

        plynneRozwijanie(box);

        obserwuj(box.querySelectorAll('[data-reveal]'));
    }

    /* ---------- archiwalne sezony (podstrona archiwum.html) ---------- */

    function blokSezonu(s, i) {
        var nasza = s.nasza_pozycja;
        var wynik = nasza
            ? nasza.poz + '. miejsce · ' + nasza.pkt + ' pkt'
            : 'brak danych o klubie';

        return '<details class="sezon" data-reveal style="--d:' + Math.min(i * 60, 320) + 'ms"' +
                   (i === 0 ? ' open' : '') + '>' +
                   '<summary class="sezon__head">' +
                       '<span class="sezon__nazwa">' + esc(s.sezon) +
                           (s.bierzacy ? ' <em>trwa</em>' : '') + '</span>' +
                       '<span class="sezon__liga">' + esc(s.liga) + '</span>' +
                       '<span class="sezon__wynik">' + esc(wynik) + '</span>' +
                   '</summary>' +
                   '<table class="table">' +
                       '<thead><tr><th>#</th><th><span class="visually-hidden">Herb</span></th>' +
                           '<th>Drużyna</th><th>M</th><th>Bramki</th><th>Pkt</th></tr></thead>' +
                       '<tbody>' + s.tabela.map(wierszTabeli).join('') + '</tbody>' +
                   '</table>' +
               '</details>';
    }

    function rysujArchiwum(dane) {
        var box = document.getElementById('archiwum');
        if (!box) { return; }

        if (!dane || !dane.sezony || !dane.sezony.length) {
            box.innerHTML = '<p class="wyniki__pusto">Archiwum jest chwilowo niedostępne.</p>';
            return;
        }

        box.innerHTML = dane.sezony.map(blokSezonu).join('');
        obserwuj(box.querySelectorAll('[data-reveal]'));
    }

    /* ---------- kadra z panelu administratora ---------- */

    function kartaZawodnika(z, i) {
        /* Zdjęcie to wycięta sylwetka na przezroczystym tle (PNG z kanałem
           alfa) — <img> osobno od .player__photo, żeby przezroczyste miejsca
           pokazywały ten sam gradientowy deseń co puste kafelki, zamiast
           czarnego tła strony. */
        var foto = z.zdjecie
            ? '<img class="player__foto" src="' + encodeURI(z.zdjecie) + '" alt="" loading="lazy">'
            : '<div class="player__zastepcze"></div>';

        var kierunek = i % 2 === 0 ? 'left' : 'right';

        return '<article class="player" data-reveal="' + kierunek + '" style="--d:' + (Math.min(i, 8) * 80) + 'ms">' +
               '<div class="player__photo">' + foto + '</div>' +
               '<div class="player__label">' +
                   '<span class="player__no">' + (z.numer !== null ? z.numer : '–') + '</span>' +
                   '<span class="player__name">' +
                       '<small>' + esc(z.imie) + '</small>' +
                       '<strong>' + esc(z.nazwisko) + '</strong>' +
                       '<em class="player__poz">' + esc(z.pozycja) + '</em>' +
                   '</span>' +
               '</div>' +
               '</article>';
    }

    function rysujZawodnikow(dane) {
        var rail = document.querySelector('[data-rail-id="team"]');
        if (!rail || !dane || !dane.zawodnicy || !dane.zawodnicy.length) { return; }

        rail.innerHTML = dane.zawodnicy.map(kartaZawodnika).join('');
        obserwuj(rail.querySelectorAll('[data-reveal]'));
    }

    /* ---------- pełna kadra wg pozycji (podstrona kadra.html) ---------- */

    var POZYCJE_KOLEJNOSC = ['bramkarz', 'obrońca', 'pomocnik', 'napastnik'];
    var POZYCJE_ETYKIETA = {
        bramkarz: 'Bramkarze',
        obrońca: 'Obrońcy',
        pomocnik: 'Pomocnicy',
        napastnik: 'Napastnicy',
    };

    function rysujKadre(dane) {
        var box = document.getElementById('kadra-pelna');
        if (!box) { return; }

        if (!dane || !dane.zawodnicy || !dane.zawodnicy.length) {
            box.innerHTML = '<p class="wyniki__pusto">Kadra jest chwilowo niedostępna.</p>';
            return;
        }

        var wgPozycji = {};
        dane.zawodnicy.forEach(function (z) {
            (wgPozycji[z.pozycja] = wgPozycji[z.pozycja] || []).push(z);
        });

        // znane pozycje w ustalonej kolejności, potem cokolwiek nietypowego na końcu
        var kolejnosc = POZYCJE_KOLEJNOSC.concat(
            Object.keys(wgPozycji).filter(function (p) { return POZYCJE_KOLEJNOSC.indexOf(p) === -1; })
        );

        box.innerHTML = kolejnosc.filter(function (p) { return wgPozycji[p]; }).map(function (p) {
            var gracze = wgPozycji[p];
            return '<section class="kadra-grupa" data-reveal>' +
                       '<h2 class="kadra-grupa__tytul">' + esc(POZYCJE_ETYKIETA[p] || p) +
                           ' <span>' + gracze.length + '</span></h2>' +
                       '<div class="kadra-siatka">' + gracze.map(kartaZawodnika).join('') + '</div>' +
                   '</section>';
        }).join('');

        obserwuj(box.querySelectorAll('[data-reveal]'));
    }

    /* ---------- pasek sponsorów z panelu administratora ---------- */

    function rysujSponsorow(dane) {
        var track = document.querySelector('.marquee__track');
        var lista = (dane && dane.sponsorzy) || [];

        /* Pusta lista to najczęściej „panel jeszcze nieużywany", a nie „klub
           stracił sponsorów" — zostawiamy wtedy pasek wpisany w index.html. */
        if (!track || !lista.length) { return; }

        var pozycje = lista.map(function (s) {
            // adres trafia i do CSS-owego url(), i do src — apostrof zamykałby url()
            var src = encodeURI(s.logo).replace(/'/g, '%27');
            var blask = s.poswiata ? ' marquee__glow' : '';

            var srodek = '<div class="marquee__logo' + blask + '" style="--logo:url(\'' + src + '\')">' +
                             '<img src="' + src + '" alt="' + esc(s.nazwa) + '">' +
                         '</div>' +
                         '<span>' + esc(s.rola) + '</span>';

            /* Adres jest sprawdzany już w panelu, ale trzymamy drugą kontrolę
               tutaj: gdyby do JSON-a trafiło kiedyś "javascript:", kliknięcie
               logo wykonałoby obcy kod. Do href wpuszczamy tylko http(s). */
            if (s.strona && /^https?:\/\//i.test(s.strona)) {
                return '<a class="marquee__item" href="' + esc(s.strona) + '"' +
                           ' target="_blank" rel="noopener noreferrer"' +
                           ' title="Przejdź na stronę: ' + esc(s.nazwa) + '">' +
                           srodek +
                       '</a><i></i>';
            }

            return '<div class="marquee__item">' + srodek + '</div><i></i>';
        }).join('');

        /* Ile kopii listy wrzucić w tor.

           Pasek przesuwa się o szerokość jednego zestawu i wraca na start —
           żeby ruch był niewidoczny, zestaw musi wypełniać ekran. Przy dwóch
           kopiach i kilku logo (np. czterech) zestaw był węższy niż ekran
           i po jego przejechaniu w pasku zostawała pusta luka. Liczbę kopii
           dobieramy więc do szerokości okna, z jednym zapasem. */
        var naZestaw = lista.length * 2;    // każda pozycja to kafelek + separator

        function ulozPasek() {
            track.innerHTML = pozycje + pozycje;   // dwie kopie wystarczą do pomiaru

            var dzieci = track.children;
            if (dzieci.length <= naZestaw) { return; }

            // odległość między początkiem zestawu a początkiem następnego —
            // zawiera odstęp między pozycjami, więc pętla domyka się bez skoku
            var szerZestawu = dzieci[naZestaw].offsetLeft - dzieci[0].offsetLeft;
            if (szerZestawu <= 0) { return; }

            var szerEkranu = (track.parentElement || document.body).clientWidth;
            var kopii = Math.max(2, Math.ceil(szerEkranu / szerZestawu) + 1);

            var html = '';
            for (var i = 0; i < kopii; i++) { html += pozycje; }
            track.innerHTML = html;

            track.style.setProperty('--przesuniecie', szerZestawu + 'px');
            // stała prędkość ok. 60 px/s, niezależnie od liczby sponsorów
            track.style.setProperty('--czas-paska', (szerZestawu / 60).toFixed(2) + 's');
        }

        ulozPasek();

        /* Poppins zmienia szerokość podpisów, więc po jego wczytaniu trzeba
           przeliczyć — inaczej pomiar bierze wymiary fontu zastępczego. */
        if (document.fonts && document.fonts.ready) {
            document.fonts.ready.then(ulozPasek);
        }

        var przeliczanieePaska;
        window.addEventListener('resize', function () {
            window.clearTimeout(przeliczanieePaska);
            przeliczanieePaska = window.setTimeout(ulozPasek, 200);
        });
    }

    /* Brak pliku nie jest błędem — sekcja zostaje przy treści wpisanej w HTML,
       więc strona działa nawet zanim ktokolwiek uruchomi panel czy skrypty. */
    function pobierzJSON(sciezka) {
        return window.fetch(sciezka, { cache: 'no-cache' })
            .then(function (r) {
                if (!r.ok) { throw new Error('HTTP ' + r.status); }
                return r.json();
            })
            .catch(function (err) {
                console.info('Pomijam ' + sciezka + ' (' + err.message + ')');
                return null;
            });
    }

    if (window.fetch) {
        Promise.all([
            pobierzJSON('data/facebook.json'),
            pobierzJSON('data/posty.json')
        ]).then(function (wyniki) {
            rysujPosty(wyniki[0], wyniki[1]);
        });

        pobierzJSON('data/zawodnicy.json').then(function (dane) {
            rysujZawodnikow(dane);
            rysujKadre(dane);
        });

        pobierzJSON('data/sponsorzy.json').then(rysujSponsorow);

        Promise.all([
            pobierzJSON('data/liga.json'),
            pobierzJSON('data/mecze-panelu.json')
        ]).then(function (wyniki) {
            var dane = wyniki[0];
            var panel = wyniki[1];
            if (!dane) { return; }

            if (panel && panel.mecze && panel.mecze.length) {
                // herb wgrany w panelu dla przeciwnika spoza listy klubów ligowych —
                // rejestrujemy go w HERBY, żeby herb() znalazł go po nazwie drużyny
                panel.mecze.forEach(function (m) {
                    if (m.przeciwnik && m.herb_przeciwnika) {
                        HERBY[m.przeciwnik] = m.herb_przeciwnika;
                    }
                });

                // mecze dodane ręcznie w panelu (sparingi itp.) dołączają do
                // terminarza obok ligowych — nierozegrane trafiają do
                // "nadchodzące", posortowane razem z resztą po dacie
                var doGrania = panel.mecze.filter(function (m) { return !m.wynik; });
                dane.nadchodzace = (dane.nadchodzace || []).concat(doGrania).sort(function (a, b) {
                    return new Date(a.data_iso) - new Date(b.data_iso);
                });
            }

            /* Mecz bez wpisanego wyniku wisi w danych także po terminie —
               w panelu do czasu uzupełnienia wyniku, u 90minut do aktualizacji
               po kolejce. Odsiewamy je tutaj, żeby wszystkie sekcje niżej
               dostały już tylko to, co faktycznie przed nami. */
            dane.nadchodzace = (dane.nadchodzace || []).filter(czyPrzyszly);

            rysujTabele(dane);
            rysujKolejke(dane);
            rysujTerminarz(dane);
            rysujMecze(dane);
            rysujOdliczanie(dane);
        });

        pobierzJSON('data/archiwum.json').then(rysujArchiwum);
    }

})();
