"""Odchudzanie obrazow dokumentacji — etap 8.9 Kroku 8.

POWOD: katalog `instrukcje/` wazyl 30 MB, a kazda przebudowa dokumentacji
dokladala tyle do historii gita NA STALE. Zrzuty szly jako bezstratny PNG,
takze te, ktore w calosci sa fotografia — a fotografia w PNG jest 6-8 razy
ciezsza niz ta sama fotografia w JPEG, przy roznicy niewidocznej w druku.

ZASADA: **treść dokumentów sie nie zmienia**. Zmienia sie wylacznie sposob
zapisu obrazu. Zadne zdanie, tabela ani zrzut nie znika.

Dwa rodzaje obrazow, dwa sposoby:

  * FOTOGRAFIA (duzo kolorow — zrzut ze zdjeciami kategorii) -> JPEG.
    Paleta 256 dawalaby tu pasy na gradientach.
  * INTERFEJS (kilka tysiacy kolorow — panel, tabela, formularz) -> PNG
    z paleta 256 bez ditheringu. Tekst zostaje ostry co do piksela,
    bo w plaskim interfejsie i tak nie ma wiecej odcieni.

Rozroznienie jest MIERZONE (liczba kolorow), nie wpisane z listy nazw —
nowy zrzut trafi do wlasciwej sciezki bez dopisywania go tutaj.

Kadry ida do dokumentu, wiec dostaja sufit szerokosci: przy szerokosci
kolumny druku 2000 px to i tak ~300 dpi. Zrzuty surowe sufitu NIE dostaja,
bo sa zrodlem dla `kadruj.py` — kadr wycina z nich fragment i kazdy
brakujacy piksel bylby widoczny.

IDEMPOTENTNY: mozna uruchamiac wielokrotnie. Plik juz odchudzony jest
pomijany, a `meta.json` (sciezki zrodel dla `kadruj.py`) jest aktualizowany
przy kazdej zmianie rozszerzenia.

KOLEJNOSC W PIPELINE:
    klient-screeny.mjs  ->  kadruj.py  ->  odchudz-obrazy.py  ->  buduj-pdf.mjs

Uruchomienie:  python instrukcje/narzedzia/odchudz-obrazy.py [--sucho]
Kod wyjscia: 0 = OK, 1 = blad.
"""

import json
import os
import sys

from PIL import Image

BAZA = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
ZRZUTY = os.path.join(BAZA, 'zrzuty')
KADRY = os.path.join(ZRZUTY, 'kadry')
META = os.path.join(ZRZUTY, 'meta.json')

# Powyzej tylu kolorow uznajemy obraz za fotografie. Zmierzone na komplecie
# zrzutow: interfejs miesci sie w 4632 kolorach, fotografia zaczyna sie
# od 115 153. Prog lezy w srodku tej przepasci, wiec nie jest czuly.
PROG_FOTO = 20000

JAKOSC_KADR = 88   # kadr idzie do dokumentu
JAKOSC_ZRZUT = 92  # zrzut surowy jest zrodlem kadru — wyzej, bo bedzie ciety
MAX_SZEROKOSC_KADRU = 2000
PALETA = 256


def kolory(obraz):
    """Liczba unikalnych kolorow albo None, gdy jest ich bardzo duzo."""
    wynik = obraz.getcolors(maxcolors=PROG_FOTO)
    return None if wynik is None else len(wynik)


def odchudz(sciezka, jakosc, max_szerokosc):
    """Przetwarza jeden plik. Zwraca (nowa_sciezka, bylo, jest) albo None."""
    bylo = os.path.getsize(sciezka)
    obraz = Image.open(sciezka).convert('RGB')
    foto = kolory(obraz) is None

    if max_szerokosc and obraz.width > max_szerokosc:
        wysokosc = round(obraz.height * max_szerokosc / obraz.width)
        obraz = obraz.resize((max_szerokosc, wysokosc), Image.LANCZOS)
        zmieniony = True
    else:
        zmieniony = False

    if foto:
        cel = os.path.splitext(sciezka)[0] + '.jpg'
        # `subsampling=0` — bez tego JPEG rozmywa krawedzie tekstu w zrzucie.
        obraz.save(cel, 'JPEG', quality=jakosc, optimize=True, progressive=True, subsampling=0)
        if cel != sciezka:
            os.remove(sciezka)
    else:
        cel = sciezka
        if not zmieniony and paleta_juz_zalozona(sciezka):
            return None
        obraz.quantize(colors=PALETA, method=Image.MEDIANCUT, dither=Image.NONE).save(
            cel, 'PNG', optimize=True
        )

    jest = os.path.getsize(cel)
    if jest >= bylo and cel == sciezka:
        return None
    return (cel, bylo, jest)


def paleta_juz_zalozona(sciezka):
    """PNG zapisany jako paleta = odchudzony wczesniej, drugi raz nic nie da."""
    with Image.open(sciezka) as im:
        return im.mode == 'P'


def przetworz(katalog, jakosc, max_szerokosc, etykieta):
    """Przetwarza wszystkie PNG w katalogu. Zwraca (zmiany, bylo, jest)."""
    if not os.path.isdir(katalog):
        print('  BRAK KATALOGU: ' + katalog)
        return ({}, 0, 0)

    zmiany, suma_bylo, suma_jest = {}, 0, 0
    print('=== %s ===' % etykieta)
    for nazwa in sorted(os.listdir(katalog)):
        if not nazwa.lower().endswith('.png'):
            continue
        sciezka = os.path.join(katalog, nazwa)
        wynik = odchudz(sciezka, jakosc, max_szerokosc)
        if wynik is None:
            print('  %-42s bez zmian' % nazwa)
            continue
        cel, bylo, jest = wynik
        suma_bylo += bylo
        suma_jest += jest
        if os.path.basename(cel) != nazwa:
            zmiany[nazwa] = os.path.basename(cel)
        print('  %-42s %7.0f -> %7.0f KB  %s' % (
            nazwa, bylo / 1024, jest / 1024,
            'JPEG' if cel.endswith('.jpg') else 'PNG-%d' % PALETA,
        ))
    return (zmiany, suma_bylo, suma_jest)


def popraw_meta(zmiany):
    """`kadruj.py` bierze sciezki zrodel z meta.json — musza nadazyc za rozszerzeniem."""
    if not zmiany or not os.path.isfile(META):
        return 0
    with open(META, encoding='utf-8') as f:
        meta = json.load(f)
    poprawione = 0
    for wpis in meta.values():
        stara = wpis.get('plik', '')
        nazwa = os.path.basename(stara.replace('\\', '/'))
        if nazwa in zmiany:
            wpis['plik'] = stara[: -len(nazwa)] + zmiany[nazwa]
            poprawione += 1
    if poprawione:
        with open(META, 'w', encoding='utf-8') as f:
            json.dump(meta, f, ensure_ascii=False, indent=2)
    return poprawione


def main():
    if '--sucho' in sys.argv:
        print('TRYB SUCHY: policzone, nic nie zapisane.')
        for katalog, etykieta in ((KADRY, 'kadry'), (ZRZUTY, 'zrzuty surowe')):
            waga = sum(
                os.path.getsize(os.path.join(katalog, n))
                for n in os.listdir(katalog)
                if n.lower().endswith(('.png', '.jpg'))
            )
            print('  %-16s %6.2f MB' % (etykieta, waga / 1048576))
        return 0

    zmiany_k, bylo_k, jest_k = przetworz(
        KADRY, JAKOSC_KADR, MAX_SZEROKOSC_KADRU, 'kadry (ida do dokumentow)'
    )
    zmiany_z, bylo_z, jest_z = przetworz(
        ZRZUTY, JAKOSC_ZRZUT, None, 'zrzuty surowe (zrodlo dla kadruj.py)'
    )

    poprawione = popraw_meta(zmiany_z)
    if poprawione:
        print('meta.json: poprawiono sciezki dla %d zrzutow' % poprawione)

    bylo, jest = bylo_k + bylo_z, jest_k + jest_z
    if bylo == 0:
        print('\nNic do zrobienia — wszystko juz odchudzone.')
        return 0

    print('\nRAZEM: %.2f MB -> %.2f MB (%.0f%% mniej)' % (
        bylo / 1048576, jest / 1048576, 100 * (bylo - jest) / bylo
    ))
    print('PAMIETAJ: po tym kroku przebuduj PDF-y (buduj-pdf.mjs).')
    return 0


if __name__ == '__main__':
    sys.exit(main())
