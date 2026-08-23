# -*- coding: utf-8 -*-
"""
ETAP 8.4 (Instrukcje) — kadrowanie i oznaczanie zrzutow do instrukcji klienta.

Po co: pelny zrzut kokpitu pokazuje mnostwo rzeczy naraz i czytelnik nie wie, gdzie
patrzec. Tniemy zrzut do fragmentu opisujacego dany krok i obrysowujemy element,
w ktory ma kliknac.

Zrodlo wspolrzednych: meta.json zapisany przez `klient-screeny.mjs` (klucz "cele").

Uruchomienie:  python faq-generator/ai-faq-generator/ai-news-portal/instrukcje/narzedzia/kadruj.py
Wynik:         instrukcje/zrzuty/kadry/*.png

NASTEPNY KROK: `odchudz-obrazy.py`. Kadry zapisywane sa tutaj bezstratnym
PNG-iem, bo kadrowanie ma byc odwracalne; dopiero tamten skrypt wybiera
docelowy format (JPEG dla fotografii, paleta 256 dla interfejsu) i przycina
szerokosc do sufitu druku. Pominiecie go zostawia kadry piec razy ciezsze,
niz musza byc — i audyt `audyt-pdf.py` to wylapie.
"""

import json
import os

from PIL import Image, ImageDraw

BAZA = r'c:\Users\matot\Desktop\strona1\faq-generator\ai-faq-generator\ai-news-portal\instrukcje\zrzuty'
WYJSCIE = os.path.join(BAZA, 'kadry')
META = os.path.join(BAZA, 'meta.json')

OBRYS = (214, 69, 47)     # ceglasty — widoczny i na jasnym, i na ciemnym tle
GRUBOSC = 6
MARGINES = 12             # odstep obrysu od elementu — wiekszy przekresla sasiedni wiersz

# nazwa -> (kadr, obrysuj)
#   kadr:    None = caly zrzut
#            ('gora', px) = pas od gory
#            ('wokol', 'cel', lewo, prawo, gora, dol) = obszar wokol elementu.
#            Zapasy sa OSOBNE dla kazdej strony: lewe menu kokpitu trzeba odciac
#            mocniej niz reszte, inaczej zrzut w PDF jest nieczytelny.
PLAN = {
    # Front — caly widok goscia, bo to jest wlasnie to, co klient chce zobaczyc.
    '01-centrum-wiedzy-archiwum': (None, []),
    # Kadr wokol naglowka ekranu z ogromnym zapasem w prawo: odcina boczne menu
    # kokpitu (klient i tak go zna) i zostawia sama liste. Bez tego zrzut w PDF
    # jest tak szeroki, ze nazwy kolumn robia sie nieczytelne. Zapas u gory maly,
    # zeby nie wciagnac powiadomienia „WordPress X jest juz dostepny" z poligonu.
    '03-lista-artykulow':         (('wokol', 'naglowek', 45, 2500, 40, 760), []),
    # Kadr wokol POZYCJI, nie calego metaboksu: metabox ma proporcje 1:2 i po
    # wpasowaniu w kartke A4 napisy robily sie nieczytelne. Zapas u gory bierze
    # zakladki wraz z naglowkiem panelu („Artykuly"), a dol konczy sie tuz pod
    # obrysowana pozycja. Kazdy wiekszy zapas u dolu tnie w polowie tytul
    # nastepnego artykulu — w druku wyglada to jak blad skladu.
    # --- instalacja (klient oglada raz) ---
    # Ciasno wokol pola i przycisku: szerszy kadr lapal polowki zdan z akapitu
    # nad formularzem, co w druku wyglada jak blad skladu.
    '10-wyslij-wtyczke':          (('wokol', 'pole_pliku', 200, 250, 260, 200),
                                   ['pole_pliku', 'przycisk_zainstaluj']),
    # Sam wiersz wtyczki — bez cudzych wtyczek i powiadomien o aktualizacjach,
    # ktorych klient u siebie i tak nie zobaczy.
    '11-lista-wtyczek':           (('wokol', 'wiersz', 20, 20, 30, 30), ['wiersz']),

    # --- panel wtyczki ---
    # Sama glowa ekranu z trzema przyciskami. Nizej jest lista kilkudziesieciu
    # pozycji, ktora w druku zamienia sie w szara plame.
    '12-panel-materialy':         (('wokol', 'naglowek', 45, 300, 40, 700), []),

    # --- ustawienia: pole po polu, zawsze bez bocznego menu kokpitu ---
    '13-ustawienia-klucz':        (('wokol', 'pole', 480, 640, 240, 260), ['pole']),
    '14-ustawienia-zrodla':       (('wokol', 'pole', 480, 200, 220, 200), ['pole']),
    '15-ustawienia-kategorie':    (('wokol', 'pole', 480, 200, 120, 120), ['pole']),
    '16-ustawienia-slowa':        (('wokol', 'pole', 480, 200, 110, 110), ['pole']),
    '17-ustawienia-limit':        (('wokol', 'pole', 480, 900, 260, 320), ['pole']),

    # --- front oczami goscia: pas od gory, bez stopki motywu ---
    '20-artykul':                 (('gora', 1180), []),
    '21-kategoria':               (('gora', 1500), []),
    '22-wyszukiwanie':            (('gora', 1120), []),

    '04-menu-metabox':            (('wokol', 'pozycja_archiwum', 70, 75, 230, 40), ['pozycja_archiwum']),
}


def kadr_wokol(cel, lewo, prawo, gora, dol, rozmiar):
    x0 = max(0, cel['x'] - lewo)
    y0 = max(0, cel['y'] - gora)
    x1 = min(rozmiar[0], cel['x'] + cel['w'] + prawo)
    y1 = min(rozmiar[1], cel['y'] + cel['h'] + dol)
    return (x0, y0, x1, y1)


def main():
    with open(META, encoding='utf-8') as f:
        meta = json.load(f)
    os.makedirs(WYJSCIE, exist_ok=True)

    zrobione, pominiete = 0, []
    for nazwa, (kadr, obrysuj) in PLAN.items():
        if nazwa not in meta:
            pominiete.append(nazwa + ' — brak w meta.json')
            continue
        zrodlo = meta[nazwa]['plik']
        if not os.path.exists(zrodlo):
            pominiete.append(nazwa + ' — brak pliku ' + zrodlo)
            continue

        obraz = Image.open(zrodlo).convert('RGB')
        cele = meta[nazwa]['cele']

        # Obrys NAJPIERW, na pelnym zrzucie — wspolrzedne w meta.json sa
        # liczone wzgledem calego obrazu, nie kadru.
        rys = ImageDraw.Draw(obraz)
        for label in obrysuj:
            c = cele.get(label)
            if not c:
                pominiete.append(nazwa + ' — brak celu ' + label)
                continue
            rys.rectangle(
                [c['x'] - MARGINES, c['y'] - MARGINES,
                 c['x'] + c['w'] + MARGINES, c['y'] + c['h'] + MARGINES],
                outline=OBRYS, width=GRUBOSC,
            )

        if kadr is None:
            wynik = obraz
        elif kadr[0] == 'gora':
            wynik = obraz.crop((0, 0, obraz.size[0], min(kadr[1], obraz.size[1])))
        elif kadr[0] == 'wokol':
            c = cele.get(kadr[1])
            if not c:
                pominiete.append(nazwa + ' — brak celu kadru ' + kadr[1])
                continue
            wynik = obraz.crop(kadr_wokol(c, kadr[2], kadr[3], kadr[4], kadr[5], obraz.size))
        else:
            pominiete.append(nazwa + ' — nieznany rodzaj kadru')
            continue

        cel_pliku = os.path.join(WYJSCIE, nazwa + '.png')
        wynik.save(cel_pliku)
        print('  %-30s %s' % (nazwa, '%dx%d' % wynik.size))
        zrobione += 1

    print('\nkadrow: %d' % zrobione)
    for p in pominiete:
        print('  POMINIETE: ' + p)
    return 1 if pominiete else 0


if __name__ == '__main__':
    raise SystemExit(main())
