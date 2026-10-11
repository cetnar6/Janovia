/* =========================================
   JANOVIA — interakcje, animacje i dane z 90minut.pl
   ========================================= */

(function () {
    'use strict';

    var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var NASZ_KLUB = 'Janovia Janowiec';
    var STADION_JANOVIA = 'Janowiec 66, 39-312 Janowiec';

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
    var stanOdliczania = document.getElementById('countdown-stan');
    var wynikOdliczania = document.getElementById('countdown-wynik');
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
            /* W trakcie meczu sekcja pokazuje wynik i minutę — zegar
               odliczania nie ma wtedy do czego odliczać. */
            if (odliczanieNaZywo()) { return; }

            clock.hidden = false;

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
       5. KARUZELE — STRZAŁKI I AUTOPRZEWIJANIE
       ========================================= */

    /* Szerokość jednego kroku mierzymy jako odległość między początkami dwóch
       sąsiednich kart. To jedyna miara odporna na wszystko naraz: gap podany
       w rem, padding pasa, zaokrąglenia subpikselowe. Liczenie „szerokość
       karty + gap" potrafi się rozjechać z rzeczywistym rozstawem. */
    function szerokoscKroku(rail) {
        var karty = rail.children;
        if (!karty.length) { return 0; }

        if (karty.length > 1) {
            return karty[1].offsetLeft - karty[0].offsetLeft;
        }

        return karty[0].getBoundingClientRect().width;
    }

    function przesunKaruzele(rail, kierunek) {
        var krok = szerokoscKroku(rail);
        if (!krok) { return; }

        rail.scrollBy({
            left: krok * kierunek,
            behavior: reduced ? 'auto' : 'smooth'
        });
    }

    document.querySelectorAll('[data-rail]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var rail = document.querySelector('[data-rail-id="' + btn.getAttribute('data-rail') + '"]');
            if (!rail || !rail.firstElementChild) { return; }

            przesunKaruzele(rail, parseInt(btn.getAttribute('data-dir'), 10));
        });
    });

    /* Szerokość wygaszenia krawędzi pasa — mniej więcej krążek strzałki
       plus oddech, żeby karta zdążyła zblednąć, zanim pod niego wjedzie. */
    var WYGASZENIE = '86px';

    /* Strzałka, która nic nie robi, wygląda na zepsutą. Na końcu pasa gaśnie,
       a gdy karty mieszczą się w całości (szeroki ekran, mało kart) — znika
       cała nawigacja. */
    function odswiezStrzalki(rail) {
        var nav = rail.parentElement && rail.parentElement.querySelector('.railnav');
        if (!nav) { return; }

        // 2 px zapasu na zaokrąglenia przy nietypowym powiększeniu strony
        var zapas = 2;
        var przewijalny = rail.scrollWidth - rail.clientWidth > zapas;

        nav.hidden = !przewijalny;
        if (!przewijalny) { return; }

        var naPoczatku = rail.scrollLeft <= zapas;
        var naKoncu = rail.scrollLeft + rail.clientWidth >= rail.scrollWidth - zapas;

        var wstecz = nav.querySelector('[data-dir="-1"]');
        var naprzod = nav.querySelector('[data-dir="1"]');

        if (wstecz) { wstecz.disabled = naPoczatku; }
        if (naprzod) { naprzod.disabled = naKoncu; }

        /* Wygaszamy tę krawędź, zza której coś jeszcze wyjeżdża — tam stoi
           aktywna strzałka i tam karta wchodzi pod krążek. Na początku i na
           końcu pasa nie ma czego gasić. */
        rail.style.setProperty('--fade-l', naPoczatku ? '0px' : WYGASZENIE);
        rail.style.setProperty('--fade-r', naKoncu ? '0px' : WYGASZENIE);
    }

    document.querySelectorAll('.rail').forEach(function (rail) {
        var klatka = 0;

        function odswiezWKlatce() {
            if (klatka) { return; }
            klatka = requestAnimationFrame(function () {
                klatka = 0;
                odswiezStrzalki(rail);
            });
        }

        rail.addEventListener('scroll', odswiezWKlatce, { passive: true });
        window.addEventListener('resize', odswiezWKlatce);

        /* Karty powstają dopiero po pobraniu JSON-a, a wtedy zmienia się
           scrollWidth — bez tego strzałki zostałyby w stanie policzonym
           na treści zapasowej z HTML-a. */
        if (window.MutationObserver) {
            new MutationObserver(odswiezWKlatce).observe(rail, { childList: true });
        }

        odswiezStrzalki(rail);
    });

    /* ---------- autoprzewijanie karuzeli drużyny ----------
       Ruch jest schodkowy: co kilka sekund o dokładnie jednego zawodnika.
       Do równej krawędzi dociąga scroll-snap z CSS, więc karty nigdy nie
       zatrzymują się w połowie. Po dojechaniu do końca pas wraca na start. */

    var AUTO_KROK = 4000;        // odstęp między krokami
    var AUTO_WZNOWIENIE = 9000;  // cisza po ręcznej interakcji, zanim ruszy samo

    function autoprzewijanie(rail) {
        /* reduced-motion: użytkownik prosił system o brak animacji — sam
           ruszający się pas jest dokładnie tym, czego wtedy nie chce. */
        if (!rail || reduced) { return; }

        var timer = null;
        var wstrzymane = false;   // kursor na karuzeli albo ręczna interakcja
        var widoczna = false;     // sekcja w polu widzenia

        function przewijalna() {
            return rail.scrollWidth - rail.clientWidth > 1;
        }

        function krok() {
            if (wstrzymane || !widoczna || document.hidden || !przewijalna()) { return; }

            // 2 px zapasu na zaokrąglenia przy nietypowym powiększeniu strony
            var naKoncu = rail.scrollLeft + rail.clientWidth >= rail.scrollWidth - 2;

            if (naKoncu) {
                rail.scrollTo({ left: 0, behavior: 'smooth' });
            } else {
                przesunKaruzele(rail, 1);
            }
        }

        function start() {
            if (timer === null) { timer = setInterval(krok, AUTO_KROK); }
        }

        function stop() {
            clearInterval(timer);
            timer = null;
        }

        /* Ręczny gest ma pierwszeństwo: pas staje i daje chwilę na obejrzenie
           tego, do czego użytkownik sam dojechał, zanim wróci do swojego rytmu. */
        var wznowienie = null;

        function wstrzymajNaChwile() {
            wstrzymane = true;
            clearTimeout(wznowienie);
            wznowienie = setTimeout(function () { wstrzymane = false; }, AUTO_WZNOWIENIE);
        }

        // najechanie na karuzelę zatrzymuje ją na stałe — inaczej karta
        // uciekałaby spod kursora w trakcie oglądania zawodnika
        rail.addEventListener('mouseenter', function () { wstrzymane = true; });
        rail.addEventListener('mouseleave', function () {
            clearTimeout(wznowienie);
            wstrzymane = false;
        });

        rail.addEventListener('focusin', function () { wstrzymane = true; });
        rail.addEventListener('focusout', function () { wstrzymane = false; });

        rail.addEventListener('pointerdown', wstrzymajNaChwile);
        rail.addEventListener('wheel', wstrzymajNaChwile, { passive: true });
        rail.addEventListener('touchstart', wstrzymajNaChwile, { passive: true });

        document.querySelectorAll('[data-rail="team"]').forEach(function (btn) {
            btn.addEventListener('click', wstrzymajNaChwile);
        });

        // poza ekranem nie ma po co pracować ani przesuwać pasa w tle
        if (window.IntersectionObserver) {
            new IntersectionObserver(function (wpisy) {
                widoczna = wpisy[0].isIntersecting;
                widoczna ? start() : stop();
            }, { threshold: 0.2 }).observe(rail);
        } else {
            widoczna = true;
            start();
        }
    }

    autoprzewijanie(document.querySelector('[data-rail-id="team"]'));

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
               '<td class="table__crest">' + przyciskHerbu(r.klub, 'xs') + '</td>' +
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

            /* Dane lecą w atrybutach, nie w title. Natywny dymek przeglądarki
               wychodzi dopiero po sekundzie, zawsze przy kursorze i nie da się
               go ostylować — własny (patrz pokazDymek) pokazuje to samo od razu
               i w barwie wyniku. aria-label zostaje dla czytników ekranu. */
            pola += '<i class="forma__box forma__box--' + klucz + '" tabindex="0"' +
                    ' data-slowo="' + esc(slowo) + '"' +
                    ' data-rezultat="' + esc(m.rezultat) + '"' +
                    ' data-rywal="' + esc(m.rywal) + '"' +
                    ' data-gdzie="' + esc(m.gdzie) + '"' +
                    ' data-kolejka="' + esc(m.kolejka) + '"' +
                    ' aria-label="' + esc(opis) + '"></i>';
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

    /* Klucz do sortowania „nadchodzących". Mecz bez terminu (kolejki wiosenne,
       których 90minut jeszcze nie rozpisało) i mecz z datą nie do odczytania
       trafiają na koniec listy — inaczej pchałyby się przed najbliższe
       spotkanie i to one wychodziłyby na karuzelę i odliczanie. */
    function czasSortowania(m) {
        var czas = m && m.data_iso ? new Date(m.data_iso).getTime() : NaN;
        return isNaN(czas) ? Infinity : czas;
    }

    /* Mecz bez czytelnej daty zostaje — lepiej pokazać go z „—" niż po cichu
       zgubić pozycję z terminarza. */
    function czyPrzyszly(m) {
        var koniec = koniecTerminu(m);
        return isNaN(koniec) || koniec > Date.now();
    }

    /* Dopisek spod meczu na 90minut („w pierwotnym terminie (13 września,
       11:00) odwołany"). Bez niego przełożone spotkanie wygląda jak zwykły
       termin w listopadzie i nic nie tłumaczy, czemu kolejka 4 gra się po
       jedenastej. Gdy uwaga jest, ale nie umiemy jej odczytać, zostaje sama
       treść od 90minut — zawsze mówi więcej niż nic. */
    function uwagaMeczu(m, klasa, krotko) {
        if (!m || !m.uwaga) { return ''; }

        var tresc = m.uwaga;

        /* Na kafelku w karuzeli cały dopisek łamie się na dwie linijki
           i zbija herby w dół względem sąsiednich kart — tam wystarczy
           sam pierwotny dzień, bez godziny. Pełne zdanie zostaje na
           podstronie z terminarzem, gdzie jest na nie miejsce. */
        if (krotko && m.przelozony) {
            tresc = m.termin_pierwotny
                ? 'przełożony z ' + m.termin_pierwotny.split(',')[0]
                : 'termin przełożony';
        }

        return '<span class="' + klasa + (m.przelozony ? ' is-przelozony' : '') + '">' +
                   esc(tresc) +
               '</span>';
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

        return '<article class="fixture' + (m.przelozony ? ' is-przelozony' : '') + '" ' +
               'data-reveal style="--d:' + (i * 80) + 'ms">' +
               '<strong class="fixture__date">' + dzien + '</strong>' +
               '<span class="fixture__league">' + esc(liga) + '</span>' +
               uwagaMeczu(m, 'fixture__uwaga', true) +
               '<div class="fixture__match">' +
                   '<div class="fixture__side">' +
                       przyciskHerbu(m.gospodarz, 'sm') +
                       '<b class="fixture__team">' + esc(skrot(m.gospodarz)) + '</b>' +
                       rysujForme(m.gospodarz, dane.forma) +
                   '</div>' +
                   '<span class="fixture__vs">vs</span>' +
                   '<div class="fixture__side">' +
                       przyciskHerbu(m.gosc, 'sm') +
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

    /* ---------- DYMEK Z WYNIKIEM NAD KWADRACIKIEM FORMY ---------- */

    var dymek = null;

    function zbudujDymek() {
        if (dymek) { return dymek; }

        dymek = document.createElement('div');
        dymek.className = 'dymek';
        dymek.hidden = true;
        document.body.appendChild(dymek);

        return dymek;
    }

    /* Kwadracik, nad którym aktualnie wisi dymek — trzymamy go, żeby przy
       przewijaniu przeliczyć pozycję zamiast gasić. Lenis przewija płynnie
       i sypie zdarzeniami scroll jeszcze długo po puszczeniu palca; gaszenie
       na scroll kasowało dymek ułamek sekundy po stuknięciu w kwadracik. */
    var dymekBox = null;
    var dymekKlatka = 0;

    function ustawDymek() {
        if (!dymekBox || !dymek || dymek.hidden) { return; }

        /* Wymiary mierzymy po odsłonięciu — element z hidden ma zerowe.
           Liczymy w position: fixed, więc współrzędne z getBoundingClientRect
           pasują wprost. To celowe: Lenis przesuwa stronę transformem, przez
           co pageYOffset i tak nie zgadza się z tym, co widać na ekranie. */
        var r = dymekBox.getBoundingClientRect();

        // kwadracik wyjechał poza ekran — dymek nie ma już czego pilnować
        if (r.bottom < 0 || r.top > window.innerHeight) {
            schowajDymek();
            return;
        }

        var lewo = r.left + r.width / 2 - dymek.offsetWidth / 2;
        var gora = r.top - dymek.offsetHeight - 10;

        // kwadraciki skrajnych kart stoją przy krawędzi okna — dosuwamy dymek,
        // żeby nie wystawał poza ekran i nie rozciągał strony w poziomie
        lewo = Math.max(8, Math.min(lewo, window.innerWidth - dymek.offsetWidth - 8));

        // nad pierwszym rzędem kart bywa za mało miejsca — wtedy pod spodem
        var pod = gora < 8;
        dymek.classList.toggle('dymek--pod', pod);
        if (pod) { gora = r.bottom + 10; }

        dymek.style.left = lewo + 'px';
        dymek.style.top = gora + 'px';

        /* Strzałka musi celować w kwadracik, a nie w środek dymka: przy
           krawędzi okna dymek jest dosunięty i te dwa punkty się rozjeżdżają.
           Zostawiamy 12 px zapasu, żeby nie wyszła poza zaokrąglony róg. */
        var srodek = r.left + r.width / 2;
        var strzalka = Math.max(12, Math.min(srodek - lewo, dymek.offsetWidth - 12));
        dymek.style.setProperty('--strzalka', strzalka + 'px');
    }

    function pokazDymek(box) {
        var slowo = box.getAttribute('data-slowo');
        if (!slowo) { return; }

        var d = zbudujDymek();
        var klucz = box.classList.contains('forma__box--z') ? 'z'
                  : (box.classList.contains('forma__box--p') ? 'p' : 'r');

        d.innerHTML =
            '<span class="dymek__wynik dymek__wynik--' + klucz + '">' +
                esc(slowo) + ' ' + esc(box.getAttribute('data-rezultat')) +
            '</span>' +
            '<span class="dymek__rywal">' + esc(box.getAttribute('data-rywal')) + '</span>' +
            '<span class="dymek__meta">' + esc(box.getAttribute('data-gdzie')) +
                ' · kolejka ' + esc(box.getAttribute('data-kolejka')) + '</span>';

        d.hidden = false;
        dymekBox = box;
        ustawDymek();
    }

    function schowajDymek() {
        dymekBox = null;
        if (dymek) { dymek.hidden = true; }
    }

    /* Przeliczenie zbite do jednej klatki: przewijanie potrafi wystrzelić
       kilkadziesiąt zdarzeń na sekundę, a pozycja i tak zmienia się raz
       na odświeżenie ekranu. */
    function przesunDymek() {
        if (!dymekBox || dymekKlatka) { return; }

        dymekKlatka = requestAnimationFrame(function () {
            dymekKlatka = 0;
            ustawDymek();
        });
    }

    /* Delegacja, bo kwadraciki powstają dopiero po pobraniu danych.
       mouseover/mouseout zamiast mouseenter/mouseleave — te drugie nie bąbelkują. */
    document.addEventListener('mouseover', function (e) {
        var box = e.target.closest ? e.target.closest('.forma__box') : null;
        if (box) { pokazDymek(box); }
    });

    document.addEventListener('mouseout', function (e) {
        var box = e.target.closest ? e.target.closest('.forma__box') : null;
        if (box) { schowajDymek(); }
    });

    // ta sama podpowiedź dla klawiatury
    document.addEventListener('focusin', function (e) {
        var box = e.target.closest ? e.target.closest('.forma__box') : null;
        if (box) { pokazDymek(box); }
    });

    document.addEventListener('focusout', schowajDymek);

    window.addEventListener('scroll', przesunDymek, true);
    window.addEventListener('resize', przesunDymek);

    /* Na dotyku nie ma mouseout — stuknięcie w kwadracik pokazuje dymek przez
       mouseover, ale nic go potem nie gasi. Gasimy przy stuknięciu obok. */
    document.addEventListener('click', function (e) {
        var box = e.target.closest ? e.target.closest('.forma__box') : null;
        if (!box) { schowajDymek(); }
    });

    /* ---------- PANEL DRUŻYNY (klik w herb na karcie meczu) ---------- */

    var DANE_LIGI = null;
    var panelDruzyny = null;
    var ostatniHerb = null;

    /* Herb na karcie jest przyciskiem, nie samym obrazkiem: ma być dostępny
       z klawiatury i czytelny dla czytnika ekranu jako „pokaż mecze”. */
    function przyciskHerbu(nazwa, rozmiar) {
        return '<button type="button" class="crest-btn" data-team="' + esc(nazwa) + '"' +
               ' aria-label="Pokaż mecze drużyny ' + esc(nazwa) + '">' +
               herb(nazwa, rozmiar) +
               '</button>';
    }

    /* Wynik "3-4" (u 90minut bywa też "3:4") z perspektywy wskazanej drużyny. */
    function wynikDla(m, nazwa) {
        var bramki = String(m.wynik).split(/[-:]/);
        var u = parseInt(bramki[0], 10);
        var ich = parseInt(bramki[1], 10);

        if (isNaN(u) || isNaN(ich)) { return null; }

        var nasze = m.gospodarz === nazwa ? u : ich;
        var obce = m.gospodarz === nazwa ? ich : u;

        return nasze > obce ? 'z' : (nasze < obce ? 'p' : 'r');
    }

    /* Mecze jednej drużyny liczymy z wszystkie_mecze, a nie z forma[]:
       tamta lista ma pięć pozycji bez dat i tylko dla rozegranych spotkań,
       a tu potrzebny jest również najbliższy termin. */
    function meczeDruzyny(nazwa) {
        var wszystkie = (DANE_LIGI && DANE_LIGI.wszystkie_mecze) || [];

        var swoje = wszystkie.filter(function (m) {
            return m.gospodarz === nazwa || m.gosc === nazwa;
        });

        var rozegrane = swoje.filter(function (m) { return m.wynik; })
            .sort(function (a, b) { return (b.kolejka || 0) - (a.kolejka || 0); });

        /* Sam brak wyniku nie znaczy „przed nami": po kolejce mecz wisi bez
           rezultatu do czasu, aż 90minut go uzupełni. czyPrzyszly odsiewa te
           spotkania tak samo, jak robi to karuzela i odliczanie — inaczej
           panel pokazywałby jako najbliższy mecz sprzed kilku dni. */
        var przyszle = swoje.filter(function (m) { return !m.wynik && czyPrzyszly(m); })
            .sort(function (a, b) {
                var ta = czasSortowania(a);
                var tb = czasSortowania(b);

                // dwa nieznane terminy dają Infinity - Infinity, czyli NaN;
                // komparator musi wtedy zwrócić coś spójnego, stąd kolejka
                if (ta === tb) { return (a.kolejka || 0) - (b.kolejka || 0); }
                return ta < tb ? -1 : 1;
            });

        /* „Runda" to połowa sezonu: przy 12 zespołach 22 kolejki, czyli 1-11
           i 12-22. Liczymy ją z danych, a nie na sztywno — po zmianie liczby
           drużyn (albo ligi) podział przestawi się sam. */
        var ostatnia = 0;
        for (var i = 0; i < wszystkie.length; i++) {
            if (wszystkie[i].kolejka > ostatnia) { ostatnia = wszystkie[i].kolejka; }
        }
        var polowa = Math.ceil(ostatnia / 2);

        function runda(m) {
            return m.kolejka && m.kolejka > polowa ? 2 : 1;
        }

        /* Pokazujemy wszystko, co zostało do końca bieżącej rundy. Bieżąca
           jest ta, w której wypada najbliższy mecz — więc gdy jesień się
           skończy, panel sam przeskoczy na wiosnę, bez zmiany w kodzie. */
        var teraz = przyszle.length ? runda(przyszle[0]) : 0;

        return {
            rozegrane: rozegrane.slice(0, 5),
            nastepne: przyszle.filter(function (m) { return runda(m) === teraz; }),
            runda: teraz,
            rund: polowa ? 2 : 0
        };
    }

    /* Nie "wierszMeczu" — ta nazwa jest już zajęta przez wiersz terminarza
       niżej w pliku, a deklaracje funkcji się hoistują i wygrywa ostatnia.
       Panel dostawał wtedy wiersz terminarza: dwa herby zamiast jednego,
       drugi argument ignorowany i szerokość rozpychająca okno. */
    function wierszPanelu(m, nazwa) {
        var rywal = m.gospodarz === nazwa ? m.gosc : m.gospodarz;
        var uSiebie = m.gospodarz === nazwa;
        var data = m.data_iso ? new Date(m.data_iso) : null;

        var kiedy = data
            ? pad(data.getDate()) + '.' + pad(data.getMonth() + 1)
            : '—';

        var prawa = m.wynik
            ? '<span class="druzyna__wynik druzyna__wynik--' + (wynikDla(m, nazwa) || 'r') + '">' +
                  esc(m.wynik) +
              '</span>'
            : '<span class="druzyna__wynik druzyna__wynik--brak">' +
                  /* Godzina, dopóki 90minut jej nie poda — wtedy numer kolejki.
                     Kreska w tym miejscu nie mówiła nic, a kolejka pozwala
                     odnieść mecz do terminarza. */
                  (data && !m.data_przyblizona
                      ? pad(data.getHours()) + ':' + pad(data.getMinutes())
                      : (m.kolejka ? 'kol. ' + m.kolejka : '—')) +
              '</span>';

        /* Ten sam znak i ta sama zasada co w terminarzu: klika się cały
           wiersz, ikona mówi tylko, że jest w co kliknąć. */
        var szczegoly = szczegolyMeczu(m);

        var klikalny = szczegoly
            ? ' is-klikalny" data-mecz="' + esc(kluczMeczu(m)) + '"' +
              ' role="button" tabindex="0"' +
              ' aria-label="Skład i przebieg meczu z ' + esc(skrot(rywal)) + '"'
            : '"';

        return '<li class="druzyna__mecz' + klikalny + '>' +
                   '<span class="druzyna__kiedy">' + esc(kiedy) + '</span>' +
                   przyciskHerbu(rywal, 'xs') +
                   '<span class="druzyna__rywal">' + esc(skrot(rywal)) +
                       '<small>' + (uSiebie ? 'u siebie' : 'wyjazd') + '</small>' +
                   '</span>' +
                   /* Znak i wynik w jednej komórce: znak po lewej od wyniku,
                      żeby kolorowy kafelek wyniku kończył wiersz. Miejsce na
                      znak jest w każdym wierszu, także bez przebiegu — inaczej
                      wyniki straciłyby wspólną oś. */
                   '<span class="druzyna__prawa">' +
                       '<span class="wynik__znak' + (szczegoly ? '' : ' is-pusty') + '"' +
                           ' aria-hidden="true">' +
                           (szczegoly
                               ? '<svg viewBox="0 0 24 24">' +
                                     '<circle cx="5" cy="7" r="1.6"/><rect x="9" y="6" width="11" height="2" rx="1"/>' +
                                     '<circle cx="5" cy="12" r="1.6"/><rect x="9" y="11" width="11" height="2" rx="1"/>' +
                                     '<circle cx="5" cy="17" r="1.6"/><rect x="9" y="16" width="7" height="2" rx="1"/>' +
                                 '</svg>'
                               : '') +
                       '</span>' +
                       prawa +
                   '</span>' +
               '</li>';
    }

    function zbudujPanelDruzyny() {
        if (panelDruzyny) { return panelDruzyny; }

        panelDruzyny = document.createElement('div');
        panelDruzyny.className = 'druzyna';
        panelDruzyny.hidden = true;
        panelDruzyny.innerHTML =
            '<div class="druzyna__tlo" data-zamknij-druzyne></div>' +
            /* data-lenis-prevent jest konieczne: Lenis przewija stronę własnym
               obsługiwaniem kółka i bez tego połyka gest, zanim dojdzie on do
               panelu — lista meczów stała w miejscu mimo overflow-y: auto.
               Ten sam atrybut nosi .modal__tresc przy postach. */
            '<div class="druzyna__okno" data-lenis-prevent role="dialog" aria-modal="true" ' +
                'aria-labelledby="druzyna-nazwa">' +
                '<button type="button" class="druzyna__zamknij" data-zamknij-druzyne ' +
                    'aria-label="Zamknij">&times;</button>' +
                '<div class="druzyna__naglowek"></div>' +
                '<div class="druzyna__tresc"></div>' +
            '</div>';

        panelDruzyny.addEventListener('click', function (e) {
            if (e.target.hasAttribute('data-zamknij-druzyne')) { zamknijDruzyne(); }
        });

        document.body.appendChild(panelDruzyny);

        return panelDruzyny;
    }

    function otworzDruzyne(nazwa) {
        var p = zbudujPanelDruzyny();
        var zestaw = meczeDruzyny(nazwa);

        var wTabeli = ((DANE_LIGI && DANE_LIGI.tabela) || []).filter(function (r) {
            return r.klub === nazwa;
        })[0];

        p.querySelector('.druzyna__naglowek').innerHTML =
            herb(nazwa, 'md') +
            '<div class="druzyna__kto">' +
                '<h3 id="druzyna-nazwa">' + esc(nazwa) + '</h3>' +
                (wTabeli
                    ? '<p class="druzyna__pozycja">' +
                          wTabeli.poz + '. miejsce · ' + wTabeli.pkt + ' pkt · ' +
                          'bramki ' + esc(wTabeli.bramki) +
                      '</p>'
                    : '') +
            '</div>';

        var tresc = podpowiedzOSzczegolach(zestaw.rozegrane);

        tresc += '<h4 class="druzyna__tytul">Ostatnie mecze</h4>';

        tresc += zestaw.rozegrane.length
            ? '<ul class="druzyna__lista">' +
                  zestaw.rozegrane.map(function (m) { return wierszPanelu(m, nazwa); }).join('') +
              '</ul>'
            : '<p class="druzyna__pusto">Brak rozegranych meczów w tym sezonie.</p>';

        var nazwaRundy = zestaw.runda === 2 ? 'rundy wiosennej' : 'rundy jesiennej';

        tresc += '<h4 class="druzyna__tytul">' +
                     (zestaw.nastepne.length
                         ? 'Do końca ' + nazwaRundy
                         : 'Następne mecze') +
                 '</h4>';

        tresc += zestaw.nastepne.length
            ? '<ul class="druzyna__lista">' +
                  zestaw.nastepne.map(function (m) { return wierszPanelu(m, nazwa); }).join('') +
              '</ul>'
            : '<p class="druzyna__pusto">Brak kolejnych spotkań w terminarzu.</p>';

        p.querySelector('.druzyna__tresc').innerHTML = tresc;

        p.hidden = false;
        blokujPrzewijanie(true);
        p.querySelector('.druzyna__zamknij').focus();
    }

    function zamknijDruzyne() {
        if (!panelDruzyny || panelDruzyny.hidden) { return; }

        panelDruzyny.hidden = true;
        blokujPrzewijanie(false);

        if (ostatniHerb) {
            ostatniHerb.focus();
            ostatniHerb = null;
        }
    }

    // delegacja: karty powstają dopiero po pobraniu data/liga.json
    document.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('.crest-btn') : null;
        if (!btn) { return; }

        // karuzela nasłuchuje kliknięć na kafelku — bez tego panel otwierałby
        // się razem z przewinięciem do sąsiedniej karty
        e.preventDefault();
        e.stopPropagation();

        schowajDymek();

        /* Herb rywala w otwartym panelu przerzuca na jego drużynę. Punktu
           powrotu wtedy NIE nadpisujemy: Escape ma oddać fokus tam, skąd
           panel w ogóle wyszedł, a nie na przycisk, który właśnie zniknął
           przy przerysowaniu listy. */
        if (!panelDruzyny || !panelDruzyny.contains(btn)) { ostatniHerb = btn; }

        otworzDruzyne(btn.getAttribute('data-team'));
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { zamknijDruzyne(); }
    });

    /* ---------- STRONA MECZU (mecz.html) ---------- */

    var SKLAD = null;
    var PRZEBIEG = null;
    var WYNIKI = null;
    var MAMY = { liga: false, sklad: false, przebieg: false };

    /**
     * Klucz spotkania — ten sam, który panel zapisuje w przebiegu meczu.
     *
     * Po nim sprawdzamy, czy wynik w data/przebieg.json dotyczy meczu, który
     * właśnie pokazujemy. Bez tego rezultat poprzedniej kolejki przyklejał się
     * do następnego spotkania w chwili, gdy terminarz przeskakiwał dalej.
     */
    function kluczMeczu(m) {
        if (!m) { return null; }

        return (m.kolejka === undefined || m.kolejka === null ? '-' : m.kolejka) +
               '|' + m.gospodarz + '|' + m.gosc;
    }

    function przebiegTegoMeczu(m) {
        return !!PRZEBIEG && !!PRZEBIEG.mecz && PRZEBIEG.mecz === kluczMeczu(m);
    }

    var SKROT_POZYCJI = {
        'bramkarz':  'BR',
        'obrońca':   'OB',
        'pomocnik':  'PM',
        'napastnik': 'NA'
    };

    /* Boisko rysujemy z data/sklad.json, które niesie nie tylko nazwiska, ale
       i współrzędne pól. Dzięki temu nie musimy tu znać listy formacji —
       gdybyśmy ją znali, każda zmiana ustawienia w panelu wymagałaby zmiany
       także tutaj i boisko rozjechałoby się z tym, co widzi trener. */
    /**
     * Ile bramek ma na koncie ten zawodnik.
     *
     * Liczymy z przebiegu meczu, a nie ze składu: skład mówi, kto gra,
     * przebieg — co się wydarzyło. Dopasowujemy po identyfikatorze, a gdy
     * któryś z plików jest starszy i go nie niesie — po imieniu i nazwisku.
     * Samo nazwisko by nie wystarczyło: dwóch Pulaków w składzie to u nas
     * normalny stan.
     */
    function bramkiZawodnika(z, zdarzenia) {
        zdarzenia = zdarzenia || (PRZEBIEG && PRZEBIEG.zdarzenia);

        if (!z || !zdarzenia) { return 0; }

        var ile = 0;

        zdarzenia.forEach(function (e) {
            if (e.typ !== 'gol' || !e.kto) { return; }

            var pasuje = z.id && e.kto.id
                ? z.id === e.kto.id
                : e.kto.nazwisko === z.nazwisko && e.kto.imie === z.imie;

            if (pasuje) { ile++; }
        });

        return ile;
    }

    /** Sama grafika piłki — do osi czasu i do plakietek przy strzelcach. */
    function pilka() {
        return '<svg class="pilka" viewBox="0 0 24 24" aria-hidden="true">' +
                   '<circle cx="12" cy="12" r="11"/>' +
                   '<path class="pilka__latka" d="M13.7 9.65 12.4 5.63 15.82 3.14 19.24 5.63 17.94 9.65Z' +
                       'M14.76 12.9 18.18 10.41 21.61 12.9 20.3 16.92 16.07 16.92Z' +
                       'M12 14.9 15.42 17.39 14.12 21.41 9.88 21.41 8.58 17.39Z' +
                       'M9.24 12.9 7.93 16.92 3.7 16.92 2.39 12.9 5.82 10.41Z' +
                       'M10.3 9.65 6.06 9.65 4.76 5.63 8.18 3.14 11.6 5.63Z"/>' +
               '</svg>';
    }

    /* Piłka przy nazwisku — jedna na bramkę, do trzech. Wyżej liczba wygrywa
       z kreskami, bo pięć kółek obok twarzy przestaje być informacją. */
    function pilkiZawodnika(z, zdarzenia) {
        var ile = bramkiZawodnika(z, zdarzenia);

        if (!ile) { return ''; }

        var ikona = pilka();

        /* Trzy piłki to górna granica czytelności przy krążku na murawie —
           czwarta zaczyna wychodzić poza kafelek i wpadać pod sąsiada.
           Nadwyżkę dopisujemy liczbą, bo „+2" zajmuje tyle co pół piłki. */
        var widoczne = Math.min(ile, 3);

        return '<span class="gole" title="Bramki: ' + ile + '">' +
                   new Array(widoczne + 1).join(ikona) +
                   (ile > 3 ? '<b>+' + (ile - 3) + '</b>' : '') +
               '</span>';
    }

    /* Twarz albo inicjały — ten sam zapis na murawie i na ławce. Zastępnik
       nie jest ozdobnikiem: skład bywa ogłaszany, zanim ktokolwiek wgra
       zdjęcie, a pusty krążek czyta się jak błąd ładowania. */
    function twarzZawodnika(z, klasa) {
        if (z.zdjecie) {
            return '<img class="' + klasa + '" src="' + esc(z.zdjecie) + '" alt="" loading="lazy">';
        }

        return '<span class="' + klasa + ' ' + klasa + '--brak">' +
                   esc((z.imie.charAt(0) + z.nazwisko.charAt(0)).toUpperCase()) +
               '</span>';
    }

    function kafelekSkladu(pole, zdarzenia) {
        var z = pole.zawodnik;

        if (!z) {
            // panel nie pozwala zatwierdzić niepełnego składu, ale gdyby pole
            // mimo to przyszło puste — lepiej puste kółko niż dziura w siatce
            return '<div class="sklad__pole sklad__pole--puste" style="left:' + pole.x +
                   '%;top:' + pole.y + '%"><span>' + esc(pole.etykieta) + '</span></div>';
        }

        return '<div class="sklad__pole" style="left:' + pole.x + '%;top:' + pole.y + '%">' +
                   twarzZawodnika(z, 'sklad__twarz') +
                   pilkiZawodnika(z, zdarzenia) +
                   '<span class="sklad__numer">' + (z.numer ? z.numer : '–') + '</span>' +
                   '<span class="sklad__nazwisko">' + esc(z.nazwisko) +
                       (z.kapitan ? '<i class="sklad__opaska" title="Kapitan">C</i>' : '') +
                   '</span>' +
               '</div>';
    }

    function rysujMurawe(sklad, zdarzenia) {
        sklad = sklad || SKLAD;

        return '<div class="murawa">' +
                   '<div class="murawa__linie" aria-hidden="true">' +
                       '<span class="murawa__srodek"></span>' +
                       '<span class="murawa__kolo"></span>' +
                       '<span class="murawa__pole16 murawa__pole16--dol"></span>' +
                       '<span class="murawa__pole16 murawa__pole16--gora"></span>' +
                   '</div>' +
                   sklad.pola.map(function (p) {
                       return kafelekSkladu(p, zdarzenia);
                   }).join('') +
               '</div>';
    }

    /**
     * Ławka rezerwowych: kolumna kafelków obok boiska, takich samych jak
     * w panelu trenera.
     *
     * Kafelek, a nie samo nazwisko z numerem: twarz mówi kibicowi, kto
     * wchodzi, szybciej niż nazwisko, a plakietka pozycji — co ławka
     * realnie zabezpiecza.
     *
     * Kolejność jest ta z panelu — trener układa ją tak, jak zamierza
     * zmieniać, więc sortowanie po numerach zgubiłoby informację.
     */
    function pasRezerwowych(sklad, zdarzenia) {
        sklad = sklad || SKLAD;

        var lawka = (sklad && sklad.rezerwowi) || [];

        if (!lawka.length) { return ''; }

        return '<section class="lawkarez" data-reveal>' +
                   '<h3 class="lawkarez__tytul">Ławka rezerwowych</h3>' +
                   '<div class="lawkarez__lista">' + lawka.map(function (z) {
                       return '<div class="rezerwowy">' +
                                  '<span class="rezerwowy__numer">' +
                                      (z.numer ? z.numer : '–') +
                                  '</span>' +
                                  twarzZawodnika(z, 'rezerwowy__twarz') +
                                  '<span class="rezerwowy__kto">' +
                                      '<strong>' + esc(z.nazwisko) +
                                          (z.kapitan ? '<i class="sklad__opaska" title="Kapitan">C</i>' : '') +
                                          pilkiZawodnika(z, zdarzenia) +
                                      '</strong>' +
                                      '<small>' + esc(z.imie) + '</small>' +
                                  '</span>' +
                                  '<span class="rezerwowy__poz">' +
                                      esc(SKROT_POZYCJI[z.pozycja] || '') +
                                  '</span>' +
                              '</div>';
                   }).join('') + '</div>' +
               '</section>';
    }

    /**
     * Termin pisany w całości: dzień tygodnia słownie, pełna nazwa miesiąca
     * i rok. Na karcie w karuzeli miejsca jest tyle, że musi wystarczyć
     * „ND 11.10", ale tu jest cała strona i skrót tylko zmuszałby do
     * doszyfrowywania, o który dzień chodzi.
     *
     * Gdy 90minut nie poda jeszcze godziny, podaje samo okno kolejki
     * („10-11 października”). Wyciągamy z niego drugi dzień i piszemy oba
     * słownie — zmyślenie dokładnej daty byłoby gorsze niż przyznanie,
     * że termin jeszcze nie jest ustalony.
     */
    function terminSlownie(m) {
        var data = m.data_iso ? new Date(m.data_iso) : null;

        if (!data || isNaN(data.getTime())) {
            return m.kolejka_opis || 'termin nieustalony';
        }

        var dzien = DNI_TYGODNIA[data.getDay()];
        var miesiac = MIESIACE[data.getMonth()];

        if (!m.data_przyblizona) {
            return dzien + ', ' + data.getDate() + ' ' + miesiac + ' ' + data.getFullYear() +
                   ', godz. ' + pad(data.getHours()) + ':' + pad(data.getMinutes());
        }

        var zakres = String(m.kolejka_opis || '').match(/(\d{1,2})\s*[-–]\s*(\d{1,2})/);
        var ile = zakres ? parseInt(zakres[2], 10) - parseInt(zakres[1], 10) : 0;

        /* Zakres przez granicę miesiąca („31-1 listopada”) dałby różnicę
           ujemną — wtedy odpuszczamy i zostaje sam pierwszy dzień. */
        if (ile > 0 && ile < 7) {
            var drugi = new Date(data.getTime());
            drugi.setDate(drugi.getDate() + ile);

            return dzien + ' lub ' + DNI_TYGODNIA[drugi.getDay()] + ', ' +
                   data.getDate() + '–' + drugi.getDate() + ' ' +
                   MIESIACE[drugi.getMonth()] + ' ' + drugi.getFullYear() +
                   ' · godzina nieustalona';
        }

        return dzien + ', ' + data.getDate() + ' ' + miesiac + ' ' + data.getFullYear() +
               ' · godzina nieustalona';
    }

    /**
     * Środek karty spotkania: „vs" przed pierwszym gwizdkiem, wynik potem.
     *
     * Wynik mieszka tutaj, a nie w zakładce „Szczegóły": to pierwsza rzecz,
     * po którą kibic wchodzi na stronę, a zakładka z osią czasu odpowiada
     * już na drugie pytanie — jak do tego wyniku doszło.
     */
    function srodekKarty(m) {
        var nasz = przebiegTegoMeczu(m);
        var trwa = nasz && PRZEBIEG.stan === 'trwa';
        var poMeczu = nasz && PRZEBIEG.stan === 'koniec';

        if (!trwa && !poMeczu) {
            return '<span class="spotkanie__vs">vs</span>';
        }

        var uSiebie = m.gospodarz === NASZ_KLUB;
        var my = PRZEBIEG.wynik ? PRZEBIEG.wynik.my : 0;
        var oni = PRZEBIEG.wynik ? PRZEBIEG.wynik.oni : 0;
        var czas = zegarMeczu();
        var pauza = trwa && !!PRZEBIEG.pauza;

        return '<div class="spotkanie__wynik' + (trwa && !pauza ? ' spotkanie__wynik--zywo' : '') + '">' +
                   '<span class="spotkanie__stan">' +
                       (trwa ? (pauza ? 'Przerwa' : 'Na żywo') : 'Po meczu') +
                       (trwa && czas !== null
                           ? ' · <b class="tablica__zegar" id="zegar">' + czas + '</b>'
                           : '') +
                   '</span>' +
                   '<strong>' + (uSiebie ? my : oni) + ':' + (uSiebie ? oni : my) + '</strong>' +
               '</div>';
    }

    /** Karta z danymi spotkania: herby, termin, miejsce, forma obu drużyn. */
    function kartaSpotkania(m, dane) {
        var kiedy = terminSlownie(m);

        var gdzie = m.gospodarz === NASZ_KLUB ? 'u siebie' : 'wyjazd';

        return '<article class="spotkanie">' +
                   '<div class="spotkanie__druzyny">' +
                       '<div class="spotkanie__strona">' +
                           przyciskHerbu(m.gospodarz, 'md') +
                           '<b>' + esc(m.gospodarz) + '</b>' +
                           rysujForme(m.gospodarz, dane.forma) +
                       '</div>' +
                       srodekKarty(m) +
                       '<div class="spotkanie__strona">' +
                           przyciskHerbu(m.gosc, 'md') +
                           '<b>' + esc(m.gosc) + '</b>' +
                           rysujForme(m.gosc, dane.forma) +
                       '</div>' +
                   '</div>' +

                   '<dl class="spotkanie__dane">' +
                       '<div><dt>Termin</dt><dd>' + esc(kiedy) + '</dd></div>' +
                       '<div><dt>Rozgrywki</dt><dd>' +
                           esc(dane.liga || 'Klasa B') +
                           (m.kolejka ? ' · kolejka ' + m.kolejka : '') +
                       '</dd></div>' +
                       '<div><dt>Miejsce</dt><dd>' +
                           esc(gdzie) +
                           ' <a class="spotkanie__mapa" href="' + linkMapy(m.gospodarz) + '"' +
                               ' target="_blank" rel="noopener">pokaż na mapie</a>' +
                       '</dd></div>' +
                   '</dl>' +

                   uwagaMeczu(m, 'spotkanie__uwaga') +
               '</article>';
    }

    /* ---------- zakładki: skład / szczegóły ---------- */

    function pokazZakladke(nazwa) {
        Array.prototype.forEach.call(document.querySelectorAll('.zakladka'), function (btn) {
            var wybrana = btn.getAttribute('data-panel') === nazwa;
            btn.classList.toggle('is-aktywna', wybrana);
            btn.setAttribute('aria-selected', wybrana ? 'true' : 'false');

            var panel = document.getElementById(btn.getAttribute('aria-controls'));
            if (panel) { panel.hidden = !wybrana; }
        });
    }

    function wlaczZakladki() {
        var przyciski = document.querySelectorAll('.zakladka');
        if (!przyciski.length) { return; }

        Array.prototype.forEach.call(przyciski, function (btn, i) {
            btn.addEventListener('click', function () {
                pokazZakladke(btn.getAttribute('data-panel'));
            });

            /* Strzałki w zakładkach to nie ozdoba: tego oczekuje każdy, kto
               chodzi po stronie klawiaturą, a role="tablist" wprost to obiecuje. */
            btn.addEventListener('keydown', function (e) {
                if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') { return; }

                e.preventDefault();

                var krok = e.key === 'ArrowRight' ? 1 : -1;
                var nastepny = przyciski[(i + krok + przyciski.length) % przyciski.length];

                pokazZakladke(nastepny.getAttribute('data-panel'));
                nastepny.focus();
            });
        });
    }

    /* ---------- szczegóły: gole i zmiany ---------- */

    /** Nazwisko z numerem; brak zawodnika w kadrze nie może wywrócić wiersza. */
    function ktoZdarzenia(z) {
        if (!z) { return 'zawodnik spoza kadry'; }

        return (z.numer ? z.numer + '. ' : '') + z.imie + ' ' + z.nazwisko;
    }

    /* Strzałki zmiany: wchodzący w górę na zielono, schodzący w dół na
       czerwono — ten sam znak, który kibic zna z tabel wyników. */
    var IKONA_ZMIANY =
        '<svg class="zdarz__strzalki" viewBox="0 0 24 24" aria-hidden="true">' +
            '<path class="zdarz__wchodzi" d="M8 20V7M8 7 4.5 10.5M8 7l3.5 3.5"/>' +
            '<path class="zdarz__schodzi" d="M16 4v13m0 0 3.5-3.5M16 17l-3.5-3.5"/>' +
        '</svg>';

    /**
     * Wiersz osi czasu, ułożony po tej stronie, po której padło zdarzenie.
     *
     * Strony są te same, co na tablicy nad nią: gospodarz z lewej, gość
     * z prawej. Gdyby nasze zdarzenia zawsze szły w lewo, wynik na tablicy
     * i kolejność w osi czasu kłóciłyby się przy meczu na wyjeździe.
     */
    function wierszZdarzenia(z, rywal, uSiebie) {
        var nasze = z.typ !== 'gol_rywala';
        var poLewej = uSiebie ? nasze : !nasze;

        var kto;
        var dopisek = '';

        if (z.typ === 'gol') {
            kto = ktoZdarzenia(z.kto);
            dopisek = z.asysta ? '(' + ktoZdarzenia(z.asysta) + ')' : '';
        } else if (z.typ === 'gol_rywala') {
            kto = rywal;
        } else {
            kto = ktoZdarzenia(z.wchodzi);
            dopisek = ktoZdarzenia(z.schodzi);
        }

        var ikona = z.typ === 'zmiana' ? IKONA_ZMIANY : pilka();

        /* Wynik po bramce w kolejności z tablicy: gospodarz – gość. */
        var wynik = '';

        if (z.wynik) {
            wynik = '<b class="zdarz__wynik">' +
                        (uSiebie ? z.wynik.my : z.wynik.oni) + ' - ' +
                        (uSiebie ? z.wynik.oni : z.wynik.my) +
                    '</b>';
        }

        var opis = '<span class="zdarz__kto">' +
                       '<strong>' + esc(kto) + '</strong>' +
                       (dopisek ? '<small>' + esc(dopisek) + '</small>' : '') +
                   '</span>';

        return '<li class="zdarz zdarz--' + z.typ +
                   (poLewej ? ' zdarz--lewa' : ' zdarz--prawa') + '">' +
                   '<span class="zdarz__minuta">' + (z.minuta | 0) + '\u2032</span>' +
                   '<span class="zdarz__znak">' + ikona + wynik + '</span>' +
                   opis +
               '</li>';
    }

    /**
     * Tablica wyniku i oś czasu meczu.
     *
     * Kolejność drużyn bierzemy z terminarza, nie z pliku przebiegu: panel
     * trenera zna tylko własne bramki, a kibic czyta wynik w tej kolejności,
     * w jakiej widzi go na tablicy i w tabeli — gospodarz z lewej.
     */
    /* Ile sekund od pierwszego gwizdka. Sufit jest po to, żeby mecz, którego
       nikt nie zakończył w panelu, nie pokazywał rano tysiąca minut. */
    function sekundyMeczu() {
        if (!PRZEBIEG || PRZEBIEG.stan !== 'trwa' || !PRZEBIEG.start) { return null; }


        var start = new Date(PRZEBIEG.start).getTime();

        if (isNaN(start)) { return null; }

        /* Na przerwie zegar stoi: liczymy do chwili, w której trener go
           zatrzymał, więc kibic widzi 45:00, a nie czas trwania przerwy. */
        var pauza = PRZEBIEG.pauza ? new Date(PRZEBIEG.pauza).getTime() : NaN;
        var koniec = isNaN(pauza) ? Date.now() : pauza;

        return Math.max(0, Math.min(130 * 60, Math.floor((koniec - start) / 1000)));
    }

    /* Minuty bez godzin: po dziewięćdziesiątej leci 91:00, a nie 1:31:00 —
       tak samo, jak zegar na stadionie i jak stoper w panelu trenera. */
    function zegarMeczu() {
        var sekundy = sekundyMeczu();

        if (sekundy === null) { return null; }

        var s = sekundy % 60;

        return Math.floor(sekundy / 60) + ':' + (s < 10 ? '0' + s : s);
    }

    /* Zegar tyka co sekundę, ale sekcja przerysowuje się rzadko — dlatego
       wskaźnik szukamy przy każdym tyknięciu zamiast trzymać referencję,
       która po przerysowaniu wskazywałaby element wyrzucony z dokumentu. */
    var pulsZegara = null;

    function pilnujZegara() {
        if (!PRZEBIEG || PRZEBIEG.stan !== 'trwa') {
            if (pulsZegara) { window.clearInterval(pulsZegara); pulsZegara = null; }
            return;
        }

        if (pulsZegara) { return; }

        pulsZegara = window.setInterval(function () {
            var zegar = document.getElementById('zegar');
            var czas = zegarMeczu();

            if (zegar && czas) { zegar.textContent = czas; }
        }, 1000);
    }

    function rysujSzczegoly() {
        var box = document.getElementById('mecz-szczegoly');
        if (!box) { return; }

        var mecz = (DANE_LIGI && DANE_LIGI.nadchodzace && DANE_LIGI.nadchodzace[0]) || null;
        var rywal = mecz
            ? (mecz.gospodarz === NASZ_KLUB ? mecz.gosc : mecz.gospodarz)
            : 'Rywal';

        var nasz = przebiegTegoMeczu(mecz);
        var znacznik = document.getElementById('znacznik-zywo');
        var trwa = nasz && PRZEBIEG.stan === 'trwa';
        var pauza = trwa && !!PRZEBIEG.pauza;

        if (znacznik) {
            znacznik.hidden = !trwa;
            znacznik.textContent = pauza ? 'przerwa' : 'na żywo';
            znacznik.classList.toggle('is-pauza', pauza);
        }

        var zdarzenia = (nasz && PRZEBIEG.zdarzenia) || [];

        if (!nasz || (PRZEBIEG.stan === 'przed' && !zdarzenia.length)) {
            box.innerHTML = '<p class="sklad__brak">' +
                'Mecz jeszcze się nie zaczął. Bramki, ich strzelcy i zmiany ' +
                'pojawią się tutaj na żywo, gdy tylko sędzia odgwiżdże początek.</p>';
            return;
        }

        var u_siebie = !mecz || mecz.gospodarz === NASZ_KLUB;

        /* Sama oś czasu: wynik stoi już na karcie spotkania nad zakładkami
           i powtarzanie go tutaj kazałoby czytać to samo dwa razy. */
        box.innerHTML = zdarzenia.length
            ? '<ol class="oczas">' + zdarzenia.map(function (z) {
                  return wierszZdarzenia(z, rywal, u_siebie);
              }).join('') + '</ol>'
            : '<p class="sklad__brak">Na razie bez bramek i zmian.</p>';
    }

    /* Mecz trwa krócej niż dwie godziny, a trener wpisuje gola z opóźnieniem
       kilkunastu sekund — minuta odświeżania jest wyczuwalnie „na żywo",
       a nie zamienia strony w odpytywacz serwera. Pętla kręci się tylko
       wtedy, gdy mecz naprawdę trwa. */
    var pulsPrzebiegu = null;

    function pilnujPrzebiegu() {
        var trwa = !!PRZEBIEG && PRZEBIEG.stan === 'trwa';

        if (!trwa) {
            if (pulsPrzebiegu) { window.clearInterval(pulsPrzebiegu); pulsPrzebiegu = null; }
            return;
        }

        if (pulsPrzebiegu) { return; }

        pulsPrzebiegu = window.setInterval(function () {
            pobierzJSON('data/przebieg.json').then(function (dane) {
                PRZEBIEG = dane;

                /* Przerysowujemy całą sekcję, nie same szczegóły: po bramce
                   przybywa piłka przy krążku na boisku, a ten siedzi
                   w zakładce obok. */
                sprobujStroneMeczu();
                odswiezOdliczanie();
                pilnujPrzebiegu();
            });
        }, 60000);
    }

    function rysujPrzebieg(dane) {
        PRZEBIEG = dane;
        MAMY.przebieg = true;

        sprobujStroneMeczu();

        /* Strona główna nie ma karty meczu, ale ma odliczanie, które w trakcie
           spotkania zamienia się w tablicę wyniku — i odświeżanie, żeby kolejna
           bramka doszła sama. */
        odswiezOdliczanie();
        pilnujPrzebiegu();
    }

    /* Strona składa się z trzech źródeł: terminarza (liga.json), składu
       (sklad.json) i przebiegu (przebieg.json). Rysujemy dopiero, gdy
       wszystkie odpowiedziały — brak składu czy przebiegu to normalny stan,
       nie powód, żeby wstrzymywać dane o meczu. */
    function sprobujStroneMeczu() {
        var karta = document.getElementById('mecz-karta');

        if (!karta || !MAMY.liga || !MAMY.sklad || !MAMY.przebieg) { return; }

        var mecz = (DANE_LIGI && DANE_LIGI.nadchodzace && DANE_LIGI.nadchodzace[0]) || null;
        var opis = document.getElementById('mecz-opis');
        var box = document.getElementById('mecz-sklad');

        if (mecz) {
            karta.innerHTML = kartaSpotkania(mecz, DANE_LIGI);

            if (opis) {
                opis.textContent = skrot(mecz.gospodarz) + ' — ' + skrot(mecz.gosc);
            }

            document.title = skrot(mecz.gospodarz) + ' — ' + skrot(mecz.gosc) +
                             ' · Janovia Janowiec';
        } else {
            karta.innerHTML = '<p class="spotkanie__pusto">' +
                'Terminarz nie przewiduje w tej chwili kolejnego spotkania.</p>';
            if (opis) { opis.textContent = 'Brak zaplanowanych meczów.'; }
        }

        if (!box) { return; }

        box.innerHTML = SKLAD && SKLAD.pola && SKLAD.pola.length
            ? '<p class="sklad__meta">' +
                  (SKLAD.opis ? esc(SKLAD.opis) + ' · ' : '') +
                  'ustawienie ' + esc(SKLAD.formacja) +
              '</p>' +
              '<div class="skladsiatka">' + rysujMurawe() + pasRezerwowych() + '</div>'
            : '<p class="sklad__brak">Skład na ten mecz nie został jeszcze ogłoszony. ' +
              'Pojawi się tutaj, gdy trener go zatwierdzi.</p>';

        rysujSzczegoly();
        pilnujZegara();
        pilnujPrzebiegu();

        obserwuj(box.querySelectorAll('[data-reveal]'));
    }

    /* Przycisk pod odliczaniem leży w index.html z atrybutem hidden i odsłania
       się dopiero tutaj. Brak pliku (trener nic nie zatwierdził) to normalny
       stan — pobierzJSON zwraca wtedy null. */
    function rysujSklad(dane) {
        SKLAD = dane && dane.pola && dane.pola.length ? dane : null;
        MAMY.sklad = true;

        var btn = document.getElementById('pokaz-sklad');

        if (btn && SKLAD) { btn.hidden = false; }

        sprobujStroneMeczu();
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

    /**
     * Dokłada do terminarza wyniki meczów zakończonych w panelu.
     *
     * Ten sam mecz siedzi w danych w kilku listach (kolejka, pełny terminarz,
     * ostatnie spotkanie), więc poprawiamy każdą — inaczej wynik pojawiłby się
     * w jednym miejscu, a kreska została w drugim.
     */
    function uzupelnijWynikiZPanelu(dane, wyniki) {
        if (!wyniki || !wyniki.mecze) { return; }

        var listy = [
            dane.wszystkie_mecze,
            dane.nadchodzace,
            dane.ostatnia_kolejka && dane.ostatnia_kolejka.mecze,
            dane.ostatni ? [dane.ostatni] : null
        ];

        listy.forEach(function (lista) {
            if (!lista) { return; }

            lista.forEach(function (m) {
                if (m.wynik) { return; }

                var nasz = wyniki.mecze[kluczMeczu(m)];

                if (nasz && nasz.wynik) {
                    m.wynik = nasz.wynik;
                    m.wynik_z_panelu = true;
                }
            });
        });
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
                przyciskHerbu(m.gospodarz, 'md') +
                '<span class="vs">vs</span>' +
                przyciskHerbu(m.gosc, 'md');
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
                           ' · ' + opisKolejki + ' · ' + kiedy +
                           (m.przelozony ? ' · termin przełożony' : '');
    }

    /* Wywoływane też z zegara, gdy termin minie przy otwartej stronie. */
    /**
     * Mecz prowadzony w panelu wygrywa z odliczaniem.
     *
     * Odliczanie zna tylko godzinę z terminarza, a ta bywa przybliżona
     * („17-18 października") albo minie, zanim sędzia gwizdnie — i wtedy
     * strona główna zaczynała odliczać do NASTĘPNEGO meczu, choć ten trwał.
     * Trener w panelu wie lepiej, więc gdy przebieg mówi „trwa", pokazujemy
     * wynik i minutę zamiast zegara.
     */
    function odliczanieNaZywo() {
        if (!clock || !PRZEBIEG) { return false; }

        var mecz = meczeDoOdliczania[0];

        if (!mecz || !przebiegTegoMeczu(mecz)) { return false; }

        var trwa = PRZEBIEG.stan === 'trwa';
        var poMeczu = PRZEBIEG.stan === 'koniec';

        if (!trwa && !poMeczu) { return false; }

        var uSiebie = mecz.gospodarz === NASZ_KLUB;
        var my = PRZEBIEG.wynik ? PRZEBIEG.wynik.my : 0;
        var oni = PRZEBIEG.wynik ? PRZEBIEG.wynik.oni : 0;
        var czas = zegarMeczu();
        var pauza = trwa && !!PRZEBIEG.pauza;

        pokazOdliczanie(true);
        clock.hidden = true;
        ustawTrakcie(trwa && !pauza);

        if (znacznikTrwa) { znacznikTrwa.hidden = false; }

        if (stanOdliczania) {
            stanOdliczania.textContent = poMeczu
                ? 'Po meczu'
                : (pauza ? 'Przerwa' : 'Na żywo') + (czas !== null ? ' · ' + czas : '');
        }

        if (wynikOdliczania) {
            wynikOdliczania.hidden = false;
            wynikOdliczania.firstElementChild.textContent =
                (uSiebie ? my : oni) + ':' + (uSiebie ? oni : my);
        }

        wypiszMecz(mecz);

        return true;
    }

    function odswiezOdliczanie() {
        if (!clock) { return; }

        if (odliczanieNaZywo()) { return; }

        clock.hidden = false;

        if (wynikOdliczania) { wynikOdliczania.hidden = true; }
        if (stanOdliczania) { stanOdliczania.textContent = 'Mecz w trakcie'; }

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

    /* ---------- przebieg rozegranego meczu ---------- */

    var panelPrzebiegu = null;
    var ostatniaIkona = null;

    function zbudujPanelPrzebiegu() {
        if (panelPrzebiegu) { return panelPrzebiegu; }

        panelPrzebiegu = document.createElement('div');
        panelPrzebiegu.className = 'przebiegok';
        panelPrzebiegu.hidden = true;
        panelPrzebiegu.setAttribute('role', 'dialog');
        panelPrzebiegu.setAttribute('aria-modal', 'true');
        panelPrzebiegu.setAttribute('aria-label', 'Przebieg meczu');

        panelPrzebiegu.innerHTML =
            '<div class="przebiegok__tlo" data-zamknij-przebieg></div>' +
            /* data-lenis-prevent jest konieczne: Lenis przewija stronę własnym
               kodem i połyka kółko myszy także nad oknem — bez tego listy
               zdarzeń dłuższej niż ekran nie dało się przewinąć. */
            '<div class="przebiegok__karta" data-lenis-prevent>' +
                '<button class="przebiegok__zamknij" type="button"' +
                    ' data-zamknij-przebieg aria-label="Zamknij">&times;</button>' +
                '<div class="przebiegok__tresc"></div>' +
            '</div>';

        panelPrzebiegu.addEventListener('click', function (e) {
            if (e.target.hasAttribute('data-zamknij-przebieg')) { zamknijPrzebieg(); }
        });

        document.body.appendChild(panelPrzebiegu);

        return panelPrzebiegu;
    }

    /* Składy rozegranych meczów wiszą w osobnym pliku i ważą dziesięć razy
       tyle co wyniki — ściągamy je dopiero, gdy ktoś otworzy okno meczu,
       i trzymamy do końca wizyty. */
    var SKLADY = null;
    var skladyWDrodze = null;

    function wczytajSklady() {
        if (SKLADY) { return Promise.resolve(SKLADY); }

        if (!skladyWDrodze) {
            skladyWDrodze = pobierzJSON('data/sklady.json').then(function (dane) {
                SKLADY = dane || { mecze: {} };
                return SKLADY;
            });
        }

        return skladyWDrodze;
    }

    function otworzPrzebieg(m) {
        var wpis = szczegolyMeczu(m);

        if (!wpis) { return; }

        var p = zbudujPanelPrzebiegu();
        var uSiebie = m.gospodarz === NASZ_KLUB;
        var rywal = uSiebie ? m.gosc : m.gospodarz;
        var wynik = (m.wynik || '0-0').split('-');

        p.querySelector('.przebiegok__tresc').innerHTML =
            '<div class="przebiegok__naglowek">' +
                '<span class="przebiegok__klub">' + herb(m.gospodarz, 'sm') +
                    '<b>' + esc(skrot(m.gospodarz)) + '</b></span>' +
                '<strong class="przebiegok__wynik">' +
                    esc(wynik[0]) + ':' + esc(wynik[1] || '0') +
                '</strong>' +
                '<span class="przebiegok__klub">' + herb(m.gosc, 'sm') +
                    '<b>' + esc(skrot(m.gosc)) + '</b></span>' +
            '</div>' +
            '<p class="przebiegok__kiedy">' +
                (m.kolejka ? 'Kolejka ' + m.kolejka + ' · ' : '') +
                esc(m.termin || m.kolejka_opis || '') +
            '</p>' +
            '<div class="zakladki zakladki--okno" role="tablist" aria-label="Skład i szczegóły meczu">' +
                '<button class="zakladka is-aktywna" type="button" role="tab"' +
                    ' aria-selected="true" data-widok="przebieg">Szczegóły</button>' +
                '<button class="zakladka" type="button" role="tab"' +
                    ' aria-selected="false" data-widok="sklad">Skład</button>' +
            '</div>' +
            '<div class="przebiegok__widok" data-widok="przebieg">' +
                '<ol class="oczas">' +
                    wpis.zdarzenia.map(function (z) {
                        return wierszZdarzenia(z, rywal, uSiebie);
                    }).join('') +
                '</ol>' +
            '</div>' +
            '<div class="przebiegok__widok" data-widok="sklad" hidden>' +
                '<p class="sklad__brak">Wczytuję skład…</p>' +
            '</div>';

        p.hidden = false;
        blokujPrzewijanie(true);
        p.querySelector('.przebiegok__zamknij').focus();

        /* Skład dociągamy od razu po otwarciu, a nie dopiero po kliknięciu
           zakładki — plik jest mały, a przełączanie ma być natychmiastowe. */
        wczytajSklady().then(function (sklady) {
            var widok = p.querySelector('[data-widok="sklad"].przebiegok__widok');

            if (!widok) { return; }

            var sklad = sklady.mecze ? sklady.mecze[kluczMeczu(m)] : null;

            widok.innerHTML = sklad
                ? rysujMurawe(sklad, wpis.zdarzenia) +
                  pasRezerwowych(sklad, wpis.zdarzenia)
                : '<p class="sklad__brak">Skład na ten mecz nie został ogłoszony.</p>';

            /* Ławka nosi data-reveal, bo na stronie meczu wjeżdża przy
               przewijaniu. Tutaj nie ma czego obserwować — treść powstaje
               już po otwarciu okna, często pod zakładką, która jeszcze nie
               jest widoczna, więc obserwator nigdy by jej nie odsłonił
               i ławka zostawała przezroczysta. Pokazujemy ją od razu. */
            Array.prototype.forEach.call(widok.querySelectorAll('[data-reveal]'),
                function (el) { el.classList.add('is-in'); });
        });
    }

    /* Przełączanie zakładek w oknie meczu — delegacja, bo okno powstaje
       dopiero przy pierwszym kliknięciu ikony. */
    document.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('.zakladki--okno .zakladka') : null;

        if (!btn) { return; }

        var okno = btn.closest('.przebiegok');
        var wybrany = btn.getAttribute('data-widok');

        Array.prototype.forEach.call(okno.querySelectorAll('.zakladki--okno .zakladka'), function (b) {
            var aktywna = b === btn;
            b.classList.toggle('is-aktywna', aktywna);
            b.setAttribute('aria-selected', aktywna ? 'true' : 'false');
        });

        Array.prototype.forEach.call(okno.querySelectorAll('.przebiegok__widok'), function (w) {
            w.hidden = w.getAttribute('data-widok') !== wybrany;
        });
    });

    function zamknijPrzebieg() {
        if (!panelPrzebiegu || panelPrzebiegu.hidden) { return; }

        panelPrzebiegu.hidden = true;

        /* Okno meczu bywa otwierane z panelu drużyny. Gdy ten został pod
           spodem, strona ma zostać zablokowana — inaczej tło zaczyna się
           przewijać pod wciąż otwartym panelem. */
        if (!panelDruzyny || panelDruzyny.hidden) {
            blokujPrzewijanie(false);
        }

        if (ostatniaIkona) {
            ostatniaIkona.focus();
            ostatniaIkona = null;
        }
    }

    function otworzZWiersza(wiersz) {
        var klucz = wiersz.getAttribute('data-mecz');
        var mecz = ((DANE_LIGI && DANE_LIGI.wszystkie_mecze) || []).filter(function (m) {
            return kluczMeczu(m) === klucz;
        })[0];

        if (!mecz) { return; }

        ostatniaIkona = wiersz;
        otworzPrzebieg(mecz);
    }

    /* Delegacja, bo wiersze meczów powstają dopiero po pobraniu terminarza. */
    document.addEventListener('click', function (e) {
        if (!e.target.closest) { return; }

        /* Herb otwiera panel drużyny i ma pierwszeństwo — bez tego kliknięcie
           w tarczę otwierałoby dwa okna naraz. */
        if (e.target.closest('.crest-btn')) { return; }

        var wiersz = e.target.closest('[data-mecz].wynik, [data-mecz].druzyna__mecz');

        if (wiersz) { otworzZWiersza(wiersz); }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' && e.key !== ' ') { return; }

        var wiersz = e.target.closest
            ? e.target.closest('[data-mecz].wynik, [data-mecz].druzyna__mecz')
            : null;

        if (!wiersz) { return; }

        e.preventDefault();
        otworzZWiersza(wiersz);
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { zamknijPrzebieg(); }
    });

    /**
     * Zdanie nad listą: „kliknij mecz z ikoną".
     *
     * Pokazujemy je tylko wtedy, gdy w tej liście naprawdę jest co otworzyć —
     * podpowiedź do funkcji, której nie ma, jest gorsza niż jej brak.
     */
    function podpowiedzOSzczegolach(mecze) {
        var ile = (mecze || []).filter(szczegolyMeczu).length;

        if (!ile) { return ''; }

        return '<p class="wyniki__podpowiedz">' +
                   '<span class="wynik__znak" aria-hidden="true">' +
                       '<svg viewBox="0 0 24 24">' +
                           '<circle cx="5" cy="7" r="1.6"/><rect x="9" y="6" width="11" height="2" rx="1"/>' +
                           '<circle cx="5" cy="12" r="1.6"/><rect x="9" y="11" width="11" height="2" rx="1"/>' +
                           '<circle cx="5" cy="17" r="1.6"/><rect x="9" y="16" width="7" height="2" rx="1"/>' +
                       '</svg>' +
                   '</span>' +
                   'Kliknij mecz z tym znakiem, żeby zobaczyć skład i przebieg spotkania.' +
               '</p>';
    }

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
        lista.insertAdjacentHTML('beforebegin', podpowiedzOSzczegolach(k.mecze));
    }

    /* ---------- pełny terminarz (podstrona terminarz.html) ---------- */

    /* Termin pojedynczego meczu, pokazywany przed gospodarzem.
       Przy dacie przybliżonej data_iso to północ pierwszego dnia okna, więc
       godziny nie ma co wypisywać — zostaje sam dzień, a okno („22-23 sierpnia")
       i tak widnieje w nagłówku kolejki. */
    function terminMeczu(m) {
        /* Data przełożonego meczu niczym się nie różni od zwykłej, a to ona
           rzuca się w oczy przy przeglądaniu terminarza — stąd znacznik nad
           nią. Pełny dopisek z 90minut leci pod wierszem, ale bez prefiksu
           łatwo go przeoczyć i wyjść na mecz w listopadzie zamiast we wrześniu. */
        var prefiks = m.przelozony
            ? '<em class="wynik__przelozony" title="' + esc(m.uwaga || '') + '">przeł.</em>'
            : '';

        if (!m.data_iso) { return '<span class="wynik__termin">' + prefiks + '—</span>'; }

        var d = new Date(m.data_iso);
        if (isNaN(d.getTime())) { return '<span class="wynik__termin">' + prefiks + '—</span>'; }

        var dzien = DNI_SKROT[d.getDay()] + ' ' + pad(d.getDate()) + '.' + pad(d.getMonth() + 1);

        if (m.data_przyblizona) {
            return '<span class="wynik__termin">' + prefiks + '<b>' + dzien + '</b></span>';
        }

        return '<span class="wynik__termin">' +
                   prefiks +
                   '<b>' + dzien + '</b>' +
                   '<i>' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + '</i>' +
               '</span>';
    }

    /** Przebieg spotkania zapisany w panelu — albo null, gdy go nie prowadzono. */
    function szczegolyMeczu(m) {
        var wpis = WYNIKI && WYNIKI.mecze ? WYNIKI.mecze[kluczMeczu(m)] : null;

        return wpis && wpis.zdarzenia && wpis.zdarzenia.length ? wpis : null;
    }

    function wierszMeczu(m) {
        var nasz = m.gospodarz === NASZ_KLUB || m.gosc === NASZ_KLUB;

        /* Znak stoi tuż przy wyniku, a nie na końcu wiersza — należy do tego
           meczu, nie do rzędu. Miejsce na niego rezerwujemy w KAŻDYM wierszu,
           także bez przebiegu: inaczej wiersz ze znakiem byłby szerszy od
           sąsiadów i herby straciłyby wspólną oś. */
        var znak = szczegolyMeczu(m)
            ? '<svg viewBox="0 0 24 24">' +
                  '<circle cx="5" cy="7" r="1.6"/><rect x="9" y="6" width="11" height="2" rx="1"/>' +
                  '<circle cx="5" cy="12" r="1.6"/><rect x="9" y="11" width="11" height="2" rx="1"/>' +
                  '<circle cx="5" cy="17" r="1.6"/><rect x="9" y="16" width="7" height="2" rx="1"/>' +
              '</svg>'
            : '';

        var srodek = '<span class="wynik__srodek">' +
                         (m.wynik
                             ? '<span class="wynik__rezultat">' +
                                   esc(m.wynik.replace('-', ' : ')) + '</span>'
                             : '<span class="wynik__rezultat wynik__rezultat--brak">–</span>') +
                         '<span class="wynik__znak' + (znak ? '' : ' is-pusty') + '"' +
                             ' aria-hidden="true">' + znak + '</span>' +
                     '</span>';

        /* Klikalny jest cały wiersz, nie sama ikona — w rzędzie wysokim na
           62 px trafienie w kwadrat 27 px na telefonie to loteria. Ikona
           zostaje jako znak, że jest w co kliknąć. */
        var klikalny = szczegolyMeczu(m)
            ? ' is-klikalny" data-mecz="' + esc(kluczMeczu(m)) + '"' +
              ' role="button" tabindex="0"' +
              ' aria-label="Skład i przebieg meczu ' + esc(skrot(m.gospodarz)) +
              ' – ' + esc(skrot(m.gosc)) + '"'
            : '"';

        return '<li class="wynik' + (nasz ? ' is-us' : '') +
                   (m.uwaga ? ' wynik--uwaga' : '') + klikalny + '>' +
                   terminMeczu(m) +
                   '<span class="wynik__druzyna wynik__druzyna--gosp">' +
                       '<span>' + esc(skrot(m.gospodarz)) + '</span>' +
                       przyciskHerbu(m.gospodarz, 'xs') +
                   '</span>' +
                   srodek +
                   '<span class="wynik__druzyna">' +
                       przyciskHerbu(m.gosc, 'xs') +
                       '<span>' + esc(skrot(m.gosc)) + '</span>' +
                   '</span>' +
                   uwagaMeczu(m, 'wynik__uwaga') +
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

        var podpowiedz = podpowiedzOSzczegolach(mecze);

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
            podpowiedz +
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
                   /* Numer i opaska stoją w jednej kolumnie, jedno pod drugim.
                      Bez numeru i bez opaski nie ma czego pokazać — pusty
                      element tylko odsuwałby nazwisko od krawędzi kafelka. */
                   (z.numer !== null || z.kapitan
                       ? '<span class="player__nr">' +
                             (z.numer !== null ? '<span class="player__no">' + z.numer + '</span>' : '') +
                             /* samo „C" nic nie mówi czytnikowi ekranu ani osobie,
                                która nie zna piłkarskiej konwencji — stąd podpis */
                             (z.kapitan ? '<span class="player__kapitan" role="img" aria-label="Kapitan" title="Kapitan">C</span>' : '') +
                         '</span>'
                       : '') +
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

    /* Kolejność czytana jak ustawienie na boisku: bramka, obrona, pomoc,
       atak, a za nimi ławka. Role sztabu są tu wypisane osobno, bo wyznaczają
       porządek wewnątrz sekcji „Sztab" — trener przed masażystą. */
    var POZYCJE_KOLEJNOSC = [
        'bramkarz', 'obrońca', 'pomocnik', 'napastnik',
        'trener', 'asystent trenera', 'trener bramkarzy', 'kierownik', 'masażysta', 'sztab',
    ];

    /* Role sztabu dzielą jedną sekcję. Pod nazwiskiem zostaje konkretna rola —
       „kierownik" mówi więcej niż „sztab" — ale nagłówków nie mnożymy, bo
       sekcja z jednym masażystą wyglądałaby jak pomyłka. */
    var ROLE_SZTABU = ['trener', 'asystent trenera', 'trener bramkarzy', 'kierownik', 'masażysta', 'sztab'];

    var POZYCJE_ETYKIETA = {
        bramkarz: 'Bramkarze',
        obrońca: 'Obrońcy',
        pomocnik: 'Pomocnicy',
        napastnik: 'Napastnicy',
        sztab: 'Sztab',
    };

    /* Klucz sekcji, do której trafia zawodnik: grający idą po swojej pozycji,
       cały sztab ląduje razem pod 'sztab'. */
    function grupaPozycji(pozycja) {
        return ROLE_SZTABU.indexOf(pozycja) !== -1 ? 'sztab' : pozycja;
    }

    function rysujKadre(dane) {
        var box = document.getElementById('kadra-pelna');
        if (!box) { return; }

        if (!dane || !dane.zawodnicy || !dane.zawodnicy.length) {
            box.innerHTML = '<p class="wyniki__pusto">Kadra jest chwilowo niedostępna.</p>';
            return;
        }

        var wgPozycji = {};
        dane.zawodnicy.forEach(function (z) {
            var g = grupaPozycji(z.pozycja);
            (wgPozycji[g] = wgPozycji[g] || []).push(z);
        });

        /* Wewnątrz sekcji sztabu porządek wyznacza rola, nie nazwisko —
           inaczej masażysta potrafiłby stanąć przed trenerem. Reszta sekcji
           zostaje w kolejności z eksportu, czyli po numerach. */
        if (wgPozycji.sztab) {
            wgPozycji.sztab.sort(function (a, b) {
                return POZYCJE_KOLEJNOSC.indexOf(a.pozycja) - POZYCJE_KOLEJNOSC.indexOf(b.pozycja);
            });
        }

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
            pobierzJSON('data/mecze-panelu.json'),
            pobierzJSON('data/wyniki.json')
        ]).then(function (wyniki) {
            var dane = wyniki[0];
            var panel = wyniki[1];
            if (!dane) { return; }

            /* Nasze wyniki wyprzedzają 90minut: tam rezultat wpisuje sędzia
               albo gospodarz, czasem dopiero w poniedziałek, a my wiemy swoje
               w chwili końcowego gwizdka. Uzupełniamy tylko kreski — wpisanego
               już wyniku nie ruszamy, bo tabela i tak liczy się z 90minut. */
            WYNIKI = wyniki[2];
            uzupelnijWynikiZPanelu(dane, WYNIKI);

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
                dane.nadchodzace = (dane.nadchodzace || []).concat(doGrania);
            }

            /* Sortujemy zawsze, nie tylko po doklejeniu meczów z panelu.
               Z 90minut mecze przychodzą w kolejności kolejek, a spotkanie
               przełożone na listopad stoi wtedy przed wrześniowymi i to na
               nie szłoby odliczanie. Terminy nieznane lądują na końcu. */
            dane.nadchodzace = (dane.nadchodzace || []).slice().sort(function (a, b) {
                var ta = czasSortowania(a);
                var tb = czasSortowania(b);

                /* Odejmowanie samo w sobie nie wystarczy: dwa nieznane terminy
                   dają Infinity - Infinity, czyli NaN, a komparator zwracający
                   NaN przestaje być spójny i sort układa listę byle jak. */
                if (ta === tb) { return 0; }
                return ta < tb ? -1 : 1;
            });

            /* Mecz bez wpisanego wyniku wisi w danych także po terminie —
               w panelu do czasu uzupełnienia wyniku, u 90minut do aktualizacji
               po kolejce. Odsiewamy je tutaj, żeby wszystkie sekcje niżej
               dostały już tylko to, co faktycznie przed nami. */
            dane.nadchodzace = (dane.nadchodzace || []).filter(czyPrzyszly);

            /* Panel drużyny otwiera się długo po tym wywołaniu, z kliknięcia
               w herb — musi mieć skąd wziąć terminarz i tabelę. */
            DANE_LIGI = dane;

            MAMY.liga = true;
            sprobujStroneMeczu();

            rysujTabele(dane);
            rysujKolejke(dane);
            rysujTerminarz(dane);
            rysujMecze(dane);
            rysujOdliczanie(dane);
        });

        pobierzJSON('data/archiwum.json').then(rysujArchiwum);

        // skład pokazuje się tylko wtedy, gdy trener go zatwierdził
        pobierzJSON('data/sklad.json').then(rysujSklad);

        wlaczZakladki();
        pobierzJSON('data/przebieg.json').then(rysujPrzebieg);
    }

})();
