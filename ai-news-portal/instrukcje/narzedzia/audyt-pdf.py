# -*- coding: utf-8 -*-
"""
ETAP 8.4 (Instrukcje) — kontrola kompletu PDF-ow PRZED oddaniem.

Trzy rzeczy, ktore przy wtyczce 1 wyszly za pozno:
  1. Numer wersji i licencja rozjechaly sie z kodem (PDF-y mowily 0.33.0 po v1.0.0).
  2. Ta sama liczba znaczyla co innego w dwoch dokumentach (przydzial 20 vs sufit 12).
  3. Wymagane ostrzezenia po prostu w dokumencie nie stanely.

Uruchomienie:  python faq-generator/ai-faq-generator/ai-news-portal/instrukcje/narzedzia/audyt-pdf.py
Kod wyjscia:   0 = czysto, 1 = sa uchybienia.
"""

import collections
import glob
import os
import re
import sys

import fitz

INSTRUKCJE = r'c:\Users\matot\Desktop\strona1\faq-generator\ai-faq-generator\ai-news-portal\instrukcje'
WTYCZKA = (r'c:\Users\matot\Desktop\strona1\faq-generator\ai-faq-generator'
           r'\ai-news-portal\ai-news-portal.php')

# Frazy, ktore MUSZA paść — wymog z planu Kroku 8, etap 4.
WYMAGANE = [
    ('adres hurtowego zarzadzania', r'edit\.php\?post_type=ainp_article'),
    ('ostrzezenie o kasowaniu artykulow', r'[Oo]dinstalowanie wtyczki kasuje wszystkie'),
    ('dezaktywacja nic nie rusza', r'wy..czenie wtyczki nie rusza'),
    ('instrukcja dodania linku do menu', r'Wygl.d\s*.\s*Menu'),
]

# Frazy, ktorych byc NIE MOZE.
ZAKAZANE = [
    ('licencja z "or later"', r'or[\s\-]later'),
    ('stary numer wersji wtyczki 1', r'\b0\.33\.0\b'),
    ('niepodstawiony znacznik', r'\{\{[A-Z_]+\}\}'),
]


def z_naglowka(pole):
    with open(WTYCZKA, encoding='utf-8') as f:
        naglowek = f.read(4096)
    m = re.search(r'^\s*\*\s*' + pole + r':\s*(.+?)\s*$', naglowek, re.M)
    return m.group(1) if m else None


# Stale z KODU, ktore dokumenty cytuja liczbowo. Klucz: (plik, nazwa stalej),
# wartosc: postac, w jakiej liczba ma stac w tekscie dokumentu.
# To jest kontrola WOBEC KODU, nie miedzy dokumentami: przy wtyczce 1 wszystkie
# piec PDF-ow bylo ze soba zgodnych i wszystkie piec klamalo.
STALE = [
    ('src/Runner.php',  'TICK_BUDGET',    ['20 s']),
    ('src/Runner.php',  'PREPARE_BUDGET', ['15 s']),
    ('src/Runner.php',  'PUBLISH_BUDGET', ['30 s']),
    ('src/Runner.php',  'PREPARE_BATCH',  ['10']),
    ('src/Runner.php',  'AI_BATCH',       ['3']),
    ('src/Runner.php',  'MAX_ATTEMPTS',   ['3']),
    ('src/Runner.php',  'STALE_SECONDS',  ['900 s']),
    ('src/Gemini.php',  'MATERIAL_MAX',   ['12 000']),
    ('src/Gemini.php',  'TIMEOUT_MAX',    ['30 s']),
    ('src/Gemini.php',  'TIMEOUT_MIN',    ['8 s']),
    ('src/Http.php',    'TIMEOUT_FEED',   ['10 s']),
    ('src/Http.php',    'TIMEOUT_ARTICLE',['15 s']),
    ('src/Article.php', 'MIN_FEED_CHARS', ['1200']),
    ('src/Article.php', 'MAX_CONTENT_CHARS', ['20 000']),
    ('src/Validator.php', 'MIN_TITLE',    ['5']),
    ('src/Validator.php', 'MAX_TITLE',    ['140']),
    ('src/Validator.php', 'MIN_LEAD',     ['20']),
    ('src/Validator.php', 'MAX_LEAD',     ['400']),
    ('src/Validator.php', 'MIN_CONTENT',  ['800']),
    ('src/Admin.php',   'LOCK_TTL',       ['120 s']),
]

WTYCZKA_DIR = os.path.dirname(WTYCZKA)


def wartosc_stalej(plik, nazwa):
    """Czyta `public const NAZWA = wartosc;` z pliku wtyczki."""
    sciezka = os.path.join(WTYCZKA_DIR, plik.replace('/', os.sep))
    if not os.path.exists(sciezka):
        return None
    with open(sciezka, encoding='utf-8') as f:
        tresc = f.read()
    m = re.search(r'const\s+' + nazwa + r'\s*=\s*([0-9.]+)\s*;', tresc)
    return m.group(1) if m else None


def sprawdz_stale(teksty):
    """Kazda stala musi stac w co najmniej jednym dokumencie, w zgodnej postaci."""
    uchybienia, zgodne = [], 0
    for plik, nazwa, postacie in STALE:
        w = wartosc_stalej(plik, nazwa)
        if w is None:
            uchybienia.append('nie znaleziono stalej %s w %s' % (nazwa, plik))
            continue
        goła = w.rstrip('0').rstrip('.') if '.' in w else w
        # liczba w dokumencie moze miec spacje jako separator tysiecy
        oczekiwane = set(postacie) | {goła}
        gdzie = [n for n, t in teksty.items()
                 if any(re.search(r'(?<![\w])' + re.escape(o) + r'(?![\w])', t) for o in oczekiwane)]
        # kontrola SPOJNOSCI: wartosc z kodu musi sie zgadzac z postacia w dokumencie
        liczba_z_postaci = re.sub(r'[^0-9]', '', postacie[0])
        if liczba_z_postaci != re.sub(r'[^0-9]', '', w):
            uchybienia.append('%s: kod mowi %s, dokumentacja cytuje "%s"' % (nazwa, w, postacie[0]))
            continue
        if not gdzie:
            uchybienia.append('%s = %s: zadna instrukcja tej wartosci nie podaje' % (nazwa, w))
        else:
            zgodne += 1
    return zgodne, uchybienia


# --- Sufity wagi, etap 8.9 --------------------------------------------------
#
# Katalog `instrukcje/` wazyl 30 MB, bo zrzuty szly bezstratnym PNG-iem,
# a schematy trafialy do PDF-a jako rastry 5200 px. Odchudzenie samo w sobie
# nie trzyma sie dlugo: wystarczy jedno "wrzuce oryginal, potem sie poprawi".
# Te sufity sa stroza tamtej decyzji — z zapasem okolo 30% nad pomiarem
# z 2026-08-21, wiec zwykla przebudowa dokumentacji ich nie dotknie.
SUFIT_PDF = 2_000_000        # najciezszy dokument mial 1,43 MB
SUFIT_PDF_RAZEM = 3_000_000  # piec dokumentow razem: 2,19 MB
SUFIT_OBRAZY = 8_000_000     # zrzuty + kadry + schematy: 5,9 MB
SUFIT_PNG_KADRU = 400_000    # powyzej tego kadr jest fotografia zapisana bezstratnie


def waga_katalogu(katalog, rozszerzenia):
    """Suma rozmiarow plikow o podanych rozszerzeniach, rekurencyjnie."""
    suma = 0
    for korzen, _, nazwy in os.walk(katalog):
        for nazwa in nazwy:
            if nazwa.lower().endswith(rozszerzenia):
                suma += os.path.getsize(os.path.join(korzen, nazwa))
    return suma


def sprawdz_wage(pliki):
    """Pilnuje decyzji z etapu 8.9. Zwraca liste uchybien."""
    uchybienia = []

    razem = 0
    for sciezka in pliki:
        waga = os.path.getsize(sciezka)
        razem += waga
        if waga > SUFIT_PDF:
            uchybienia.append('%s: %.2f MB — powyzej sufitu %.2f MB (etap 8.9)' % (
                os.path.basename(sciezka), waga / 1048576, SUFIT_PDF / 1048576))
    print('  %s  PDF-y razem: %.2f MB (sufit %.2f MB)' % (
        'OK  ' if razem <= SUFIT_PDF_RAZEM else 'BLAD', razem / 1048576, SUFIT_PDF_RAZEM / 1048576))
    if razem > SUFIT_PDF_RAZEM:
        uchybienia.append('PDF-y razem %.2f MB — powyzej sufitu %.2f MB (etap 8.9)' % (
            razem / 1048576, SUFIT_PDF_RAZEM / 1048576))

    obrazy = waga_katalogu(os.path.join(INSTRUKCJE, 'zrzuty'), ('.png', '.jpg'))
    obrazy += waga_katalogu(os.path.join(INSTRUKCJE, 'schematy'), ('.png', '.jpg', '.svg'))
    print('  %s  obrazy zrodlowe: %.2f MB (sufit %.2f MB)' % (
        'OK  ' if obrazy <= SUFIT_OBRAZY else 'BLAD', obrazy / 1048576, SUFIT_OBRAZY / 1048576))
    if obrazy > SUFIT_OBRAZY:
        uchybienia.append('obrazy zrodlowe %.2f MB — powyzej sufitu %.2f MB (etap 8.9)' % (
            obrazy / 1048576, SUFIT_OBRAZY / 1048576))

    # Schematy maja isc do PDF-a WEKTOROWO. Raster jest ciezszy i gorszy
    # w powiekszeniu, a przy okazji zabiera etykietom status tekstu.
    rastry = []
    for zrodlo in glob.glob(os.path.join(INSTRUKCJE, 'zrodla', '*.html')):
        with open(zrodlo, encoding='utf-8') as f:
            if 'schematy/druk/' in f.read():
                rastry.append(os.path.basename(zrodlo))
    print('  %s  schematy wektorowo w zrodlach HTML' % ('OK  ' if not rastry else 'BLAD'))
    for nazwa in rastry:
        uchybienia.append('%s: wskazuje rastrowy schemat `schematy/druk/` zamiast SVG (etap 8.9)' % nazwa)

    ciezkie = [
        os.path.basename(p) for p in glob.glob(os.path.join(INSTRUKCJE, 'zrzuty', 'kadry', '*.png'))
        if os.path.getsize(p) > SUFIT_PNG_KADRU
    ]
    print('  %s  zaden kadr PNG nie przekracza %d KB' % (
        'OK  ' if not ciezkie else 'BLAD', SUFIT_PNG_KADRU // 1024))
    for nazwa in ciezkie:
        uchybienia.append('%s: PNG powyzej %d KB — fotografia nalezy do JPEG (etap 8.9)' % (
            nazwa, SUFIT_PNG_KADRU // 1024))

    return uchybienia


def main():
    wersja = z_naglowka('Version')
    licencja = z_naglowka('License')
    pliki = sorted(glob.glob(os.path.join(INSTRUKCJE, '*.pdf')))
    if not pliki:
        print('BLAD: brak PDF-ow w ' + INSTRUKCJE)
        return 1

    print('wersja z naglowka wtyczki: %s   licencja: %s' % (wersja, licencja))
    print('dokumentow: %d\n' % len(pliki))

    uchybienia = []
    liczby = collections.defaultdict(set)   # liczba -> zbior dokumentow
    teksty = {}

    for sciezka in pliki:
        nazwa = os.path.basename(sciezka)
        dok = fitz.open(sciezka)
        tekst = '\n'.join(dok[i].get_text() for i in range(dok.page_count))
        teksty[nazwa] = tekst
        print('%s — %d str.' % (nazwa, dok.page_count))

        if wersja and wersja not in tekst:
            uchybienia.append('%s: nie ma w tresci aktualnego numeru wersji (%s)' % (nazwa, wersja))
        for opis, wzor in ZAKAZANE:
            for trafienie in set(re.findall(wzor, tekst)):
                uchybienia.append('%s: %s — "%s"' % (nazwa, opis, trafienie))

        # Liczby wieloznaczne miedzy dokumentami: sufity, limity, przydzialy.
        for m in re.finditer(r'(?<![\w.])(\d{1,6})(?![\w.])', tekst):
            liczby[m.group(1)].add(nazwa)

    print('')
    for opis, wzor in WYMAGANE:
        gdzie = [n for n, t in teksty.items() if re.search(wzor, t)]
        if gdzie:
            print('  OK   %-38s — %s' % (opis, ', '.join(gdzie)))
        else:
            uchybienia.append('BRAK WYMAGANEJ TRESCI: %s' % opis)

    wspolne = {k: v for k, v in liczby.items() if len(v) > 1 and len(k) >= 2}
    if wspolne:
        print('\nliczby powtarzajace sie miedzy dokumentami — sprawdzic recznie,')
        print('czy wszedzie znacza to samo:')
        for k in sorted(wspolne, key=lambda x: -len(wspolne[x]))[:20]:
            print('  %-8s w %d dok.' % (k, len(wspolne[k])))
    elif len(pliki) > 1:
        print('\nbrak liczb powtarzajacych sie miedzy dokumentami')

    zgodne, braki = sprawdz_stale(teksty)
    print('')
    print('stale kodu zacytowane zgodnie: %d z %d' % (zgodne, len(STALE)))
    uchybienia.extend(braki)

    print('')
    uchybienia.extend(sprawdz_wage(pliki))

    print('')
    if uchybienia:
        print('UCHYBIENIA: %d' % len(uchybienia))
        for u in uchybienia:
            print('  - ' + u)
        return 1
    print('KONTROLA CZYSTA')
    return 0


if __name__ == '__main__':
    sys.exit(main())
