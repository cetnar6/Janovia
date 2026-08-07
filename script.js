/* =========================================
   JANOVIA — interakcje, animacje i dane z 90minut.pl
   ========================================= */

(function () {
    'use strict';

    var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var NASZ_KLUB = 'Janovia Janowiec';
    var STADION_JANOVIA = 'Janowiec 70, 39-312 Janowiec';

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

    function onScroll() {
        var y = window.scrollY;
        var max = document.documentElement.scrollHeight - window.innerHeight;

        progress.style.transform = 'scaleX(' + (max > 0 ? y / max : 0) + ')';

        header.classList.toggle('is-stuck', y > 40);
        header.classList.toggle('is-hidden', y > lastY && y > 400);
        lastY = y;

        if (!reduced) {
            for (var i = 0; i < parallax.length; i++) {
                var el = parallax[i];
                var rect = el.parentElement.getBoundingClientRect();
                if (rect.bottom > 0 && rect.top < window.innerHeight) {
                    var speed = parseFloat(el.getAttribute('data-parallax'));
                    el.style.transform = 'translate3d(0,' + (-rect.top * speed) + 'px,0)';
                }
            }

            // hero: tytuł odjeżdża i znika, tło robi lekki najazd — efekt
            // kinowej głębi przy pierwszym przewinięciu strony
            if (heroContent) {
                var zanik = Math.min(1, y / (window.innerHeight * 0.6));
                heroContent.style.opacity = String(1 - zanik);
                heroContent.style.transform = 'translateY(' + (zanik * 50) + 'px)';
            }

            if (heroBg) {
                var najazd = 1 + Math.min(0.12, y / window.innerHeight * 0.12);
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

    onScroll();

    /* =========================================
       2. ODSŁANIANIE PRZY PRZEWIJANIU
       ========================================= */

    var revealer = null;

    if ('IntersectionObserver' in window) {
        revealer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-in');
                    revealer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.15, rootMargin: '0px 0px -8% 0px' });
    }

    function obserwuj(nodes) {
        Array.prototype.forEach.call(nodes, function (el) {
            if (revealer) { revealer.observe(el); }
            else { el.classList.add('is-in'); }
        });
    }

    obserwuj(document.querySelectorAll('[data-reveal]'));

    /* =========================================
       2b. ZDJĘCIE ZAWODNIKA — ZOOM ZA KURSOREM
       Kadr powiększa się w tym miejscu karty, nad którym akurat jest kursor,
       zamiast zawsze od środka.
       ========================================= */

    if (!reduced) {
        document.addEventListener('mousemove', function (e) {
            var karta = e.target.closest ? e.target.closest('.player') : null;
            if (!karta) { return; }

            var foto = karta.querySelector('.player__photo');
            if (!foto) { return; }

            var r = karta.getBoundingClientRect();
            foto.style.setProperty('--ox', ((e.clientX - r.left) / r.width * 100) + '%');
            foto.style.setProperty('--oy', ((e.clientY - r.top) / r.height * 100) + '%');
        });
    }

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
    var cel = clock ? new Date(clock.getAttribute('data-target')).getTime() : 0;

    function pad(n) { return n < 10 ? '0' + n : String(n); }

    if (clock) {
        var pola = {
            d: clock.querySelector('[data-unit="d"]'),
            h: clock.querySelector('[data-unit="h"]'),
            m: clock.querySelector('[data-unit="m"]'),
            s: clock.querySelector('[data-unit="s"]')
        };

        var tick = function () {
            var diff = Math.max(cel - Date.now(), 0);
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
       Plik odświeża skrypt update_liga.py raz dziennie.
       Gdy pliku brak — na stronie zostaje treść wpisana w HTML.
       ========================================= */

    var MIESIACE_SKROT = ['sty', 'lut', 'mar', 'kwi', 'maj', 'cze',
                          'lip', 'sie', 'wrz', 'paź', 'lis', 'gru'];

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

    /* rozmiar: 'xs' (tabela), 'sm' (karty meczów), 'md' (odliczanie) */
    function herb(nazwa, rozmiar) {
        var plik = HERBY[nazwa];

        if (!plik) {
            return '<span class="shield shield--' + (rozmiar || 'sm') +
                   '" data-letter="' + esc(litera(nazwa)) + '"></span>';
        }

        return '<span class="crest-plate crest-plate--' + (rozmiar || 'sm') + '">' +
               '<img src="png/' + plik + '" alt="' + esc(nazwa) + '" loading="lazy">' +
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

    function rysujMecze(dane) {
        var rail = document.querySelector('[data-rail-id="fix"]');
        if (!rail || !dane.nadchodzace || !dane.nadchodzace.length) { return; }

        rail.innerHTML = dane.nadchodzace.slice(0, 6).map(function (m, i) {
            var data = m.data_iso ? new Date(m.data_iso) : null;
            var dzien = data
                ? pad(data.getDate()) + '.' + pad(data.getMonth() + 1)
                : '—';

            var kiedy = m.data_przyblizona
                ? (m.kolejka_opis || 'termin nieznany')
                : pad(data.getHours()) + ':' + pad(data.getMinutes());

            var gdzie = m.gospodarz === NASZ_KLUB ? 'u siebie' : 'wyjazd';

            return '<article class="fixture" data-reveal style="--d:' + (i * 80) + 'ms">' +
                   '<strong class="fixture__date">' + dzien + '</strong>' +
                   '<span class="fixture__league">Klasa B · kolejka ' + m.kolejka + '</span>' +
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
        }).join('');

        obserwuj(rail.querySelectorAll('[data-reveal]'));
        wyrownajNazwy(rail);
    }

    function rysujOdliczanie(dane) {
        var nastepny = (dane.nadchodzace || [])[0];
        if (!clock || !nastepny || !nastepny.data_iso) { return; }

        cel = new Date(nastepny.data_iso).getTime();

        var teams = document.querySelector('.countdown__teams');
        if (teams) {
            teams.innerHTML =
                herb(nastepny.gospodarz, 'md') +
                '<span class="vs">vs</span>' +
                herb(nastepny.gosc, 'md');
        }

        var meta = document.querySelector('.countdown__meta');
        if (meta) {
            var data = new Date(nastepny.data_iso);
            var kiedy = nastepny.data_przyblizona
                ? nastepny.kolejka_opis
                : data.getDate() + ' ' + MIESIACE_SKROT[data.getMonth()] +
                  ', ' + pad(data.getHours()) + ':' + pad(data.getMinutes());

            meta.textContent = skrot(nastepny.gospodarz) + ' — ' + skrot(nastepny.gosc) +
                               ' · kolejka ' + nastepny.kolejka + ' · ' + kiedy;
        }
    }

    /* =========================================
       8. POSTY Z FACEBOOKA (data/facebook.json)
       Plik przygotowuje update_fb.py przez Graph API.
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
        document.body.style.overflow = 'hidden';
        modal.querySelector('.modal__zamknij').focus();
    }

    function zamknijPost() {
        if (!modal || modal.hidden) { return; }

        modal.hidden = true;
        document.body.style.overflow = '';

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
        var slowa = p.naglowek.split(' ').filter(Boolean);
        var podzial = Math.max(1, Math.floor(slowa.length * 0.6));
        var biale = esc(slowa.slice(0, podzial).join(' '));
        var zlote = esc(slowa.slice(podzial).join(' '));

        tytul.innerHTML = '<span class="line">' + biale +
            (zlote ? ' <span class="line--accent">' + zlote + '</span>' : '') +
            '</span>';
    }

    function kartaAktualnosci(p, i) {
        var tlo = p.zdjecie
            ? ' style="background-image:url(\'' + encodeURI(p.zdjecie) + '\')"'
            : '';

        return '<article class="post" role="button" tabindex="0"' +
               ' data-post="' + i + '" aria-haspopup="dialog"' +
               ' data-reveal style="--d:' + (Math.min(i, 10) * 60) + 'ms">' +
               '<div class="post__media' + (p.zdjecie ? ' post__media--foto' : ' post__media--' + ((i % 6) + 1)) + '"' + tlo + '></div>' +
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

    function wierszMeczu(m) {
        var nasz = m.gospodarz === NASZ_KLUB || m.gosc === NASZ_KLUB;

        var srodek = m.wynik
            ? '<span class="wynik__rezultat">' + esc(m.wynik.replace('-', ' : ')) + '</span>'
            : '<span class="wynik__rezultat wynik__rezultat--brak">–</span>';

        return '<li class="wynik' + (nasz ? ' is-us' : '') + '">' +
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

        function blokKolejki(nr, i) {
            var k = wgKolejki[nr];
            var rozegrana = k.mecze.some(function (m) { return m.wynik; });

            return '<article class="kolejka" data-reveal style="--d:' + Math.min(i * 40, 320) + 'ms">' +
                       '<header class="kolejka__head">' +
                           '<h3>Kolejka ' + nr + '</h3>' +
                           '<span>' + esc(k.opis || 'termin nieustalony') +
                               (rozegrana ? '' : ' · nierozegrana') +
                           '</span>' +
                       '</header>' +
                       '<ul class="wyniki">' + k.mecze.map(wierszMeczu).join('') + '</ul>' +
                   '</article>';
        }

        function runda(tytul, lista, przesuniecie) {
            if (!lista.length) { return ''; }

            return '<section class="runda">' +
                       '<h2 class="runda__tytul">' + tytul + '</h2>' +
                       lista.map(function (nr, i) {
                           return blokKolejki(nr, i + przesuniecie);
                       }).join('') +
                   '</section>';
        }

        box.innerHTML =
            runda('Runda <em>jesienna</em>', numery.slice(0, polowa), 0) +
            runda('Runda <em>wiosenna</em>', numery.slice(polowa), 0);

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
        var tlo = z.zdjecie
            ? ' style="background-image:url(\'' + encodeURI(z.zdjecie) + '\')"'
            : '';

        var kierunek = i % 2 === 0 ? 'left' : 'right';

        return '<article class="player" data-reveal="' + kierunek + '" style="--d:' + (Math.min(i, 8) * 80) + 'ms">' +
               '<div class="player__photo' + (z.zdjecie ? ' player__photo--foto' : '') + '"' + tlo + '></div>' +
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

        pobierzJSON('data/liga.json').then(function (dane) {
            if (!dane) { return; }
            rysujTabele(dane);
            rysujKolejke(dane);
            rysujTerminarz(dane);
            rysujMecze(dane);
            rysujOdliczanie(dane);
        });

        pobierzJSON('data/archiwum.json').then(rysujArchiwum);
    }

})();
