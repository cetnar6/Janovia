/* =========================================
   JANOVIA — interakcje, animacje i dane z 90minut.pl
   ========================================= */

(function () {
    'use strict';

    var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var NASZ_KLUB = 'Janovia Janowiec';

    /* =========================================
       1. PASEK POSTĘPU, NAGŁÓWEK, PARALAKSA
       ========================================= */

    var progress = document.getElementById('progress');
    var header = document.getElementById('header');
    var parallax = document.querySelectorAll('[data-parallax]');
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

    function rysujTabele(dane) {
        var tbody = document.querySelector('.table tbody');
        if (!tbody || !dane.tabela || !dane.tabela.length) { return; }

        tbody.innerHTML = dane.tabela.map(function (r) {
            var klasa = r.klub === NASZ_KLUB ? ' class="is-us"' : '';
            return '<tr' + klasa + '>' +
                   '<td>' + r.poz + '</td>' +
                   '<td class="table__crest">' + herb(r.klub, 'xs') + '</td>' +
                   '<td>' + esc(r.klub) + '</td>' +
                   '<td>' + r.m + '</td>' +
                   '<td>' + r.bramki + '</td>' +
                   '<td>' + r.pkt + '</td>' +
                   '</tr>';
        }).join('');

        var stopka = document.getElementById('tabela-zrodlo');
        if (stopka) {
            var d = new Date(dane.zaktualizowano);
            stopka.textContent = dane.liga + ' · dane z 90minut.pl, aktualizacja ' +
                pad(d.getDate()) + '.' + pad(d.getMonth() + 1) + '.' + d.getFullYear();
        }
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
                   '<div class="fixture__teams">' +
                       herb(m.gospodarz, 'sm') +
                       '<span class="fixture__vs">vs</span>' +
                       herb(m.gosc, 'sm') +
                   '</div>' +
                   '<div class="fixture__names">' +
                       '<span>' + esc(skrot(m.gospodarz)) + '</span>' +
                       '<span>' + esc(skrot(m.gosc)) + '</span>' +
                   '</div>' +
                   '<span class="fixture__time">' + kiedy + ' · ' + gdzie + '</span>' +
                   '</article>';
        }).join('');

        obserwuj(rail.querySelectorAll('[data-reveal]'));
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

    if (window.fetch) {
        window.fetch('data/liga.json', { cache: 'no-cache' })
            .then(function (r) {
                if (!r.ok) { throw new Error('HTTP ' + r.status); }
                return r.json();
            })
            .then(function (dane) {
                rysujTabele(dane);
                rysujMecze(dane);
                rysujOdliczanie(dane);
            })
            .catch(function (err) {
                // brak pliku albo strona otwarta przez file:// — zostaje treść z HTML
                console.info('Dane ligowe niedostępne, używam treści statycznej:', err.message);
            });
    }

})();
