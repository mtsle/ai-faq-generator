# -*- coding: utf-8 -*-
"""
ETAP 8.4 (Instrukcje) — zlozenie WSPOLNEJ paczki dla klienta z dwiema wtyczkami.

Klient dostaje jeden folder, w srodku dwie wyraznie rozdzielone wtyczki. Numer
na poczatku nazwy podfolderu ustala kolejnosc w Eksploratorze i od razu mowi,
ktora wtyczke czytac najpierw.

    Paczka dla klienta - dwie wtyczki WordPress\\
        Co jest w tej paczce.txt
        1 - AI FAQ Generator\\      <- 5 PDF + ai-faq-generator.zip
        2 - AI News Portal\\        <- 5 PDF + ai-news-portal.zip

Pliki .zip bierzemy z katalogu, do ktorego pisze `zbuduj-zip.py`
(`faq-generator\zasoby\paczki`) — nie z kopii lezacej gdzie indziej.

Skrypt jest ODTWARZALNY: kasuje zawartosc podfolderow i kopiuje ja od nowa,
zeby paczka nigdy nie zawierala pliku z poprzedniego wydania. Sam folder
docelowy NIE jest kasowany.

URUCHOMIENIE:  python faq-generator/ai-faq-generator/ai-news-portal/instrukcje/narzedzia/zloz-paczke.py
Kod wyjscia:   0 = komplet, 1 = paczka niepelna (czegos brakuje)
"""

import os
import shutil
import sys

PULPIT = r'c:\Users\matot\Desktop'
CEL = os.path.join(PULPIT, 'Paczka dla klienta - dwie wtyczki WordPress')

WTYCZKA1 = {
    'folder': '1 - AI FAQ Generator',
    'pdf_dir': r'c:\Users\matot\Desktop\strona1\faq-generator\instrukcje',
    'zip': r'c:\Users\matot\Desktop\strona1\faq-generator\instrukcje\ai-faq-generator.zip',
}
WTYCZKA2 = {
    'folder': '2 - AI News Portal',
    'pdf_dir': r'c:\Users\matot\Desktop\strona1\faq-generator\ai-faq-generator\ai-news-portal\instrukcje',
    'zip': r'c:\Users\matot\Desktop\strona1\faq-generator\zasoby\paczki\ai-news-portal.zip',
}

CZYTAJ = """CO JEST W TEJ PACZCE
====================

Sa tu DWIE osobne wtyczki WordPressa. Kazda ma wlasny folder, wlasny plik
instalacyjny (.zip) i wlasny komplet pieciu instrukcji. Wtyczki sa niezalezne
- mozesz zainstalowac jedna, druga albo obie.


1 - AI FAQ GENERATOR
   Odpowiada na pytania odwiedzajacych, korzystajac wylacznie z tresci
   Twojej strony. Doklada tez narzedzie do ukladania zestawow FAQ.

2 - AI NEWS PORTAL
   Portal wiedzy, ktory zasila sie sam: sledzi kanaly RSS, przepisuje
   materialy wlasnymi slowami i publikuje je w Centrum Wiedzy.


OD CZEGO ZACZAC
---------------
W kazdym folderze otworz najpierw:

   "Instrukcja dla klienta (wprowadzajaca).pdf"

Prowadzi krok po kroku, z obrazkami, od wgrania pliku do dzialajacej wtyczki.


POZOSTALE CZTERY DOKUMENTY SA DLA INFORMATYKA
---------------------------------------------
   Instrukcja dla informatyka (Functional)   - co system robi
   Wymagania niefunkcjonalne (Non-functional) - jak ma sie zachowywac
   Schema - format danych                     - struktury i pola
   Instrukcje systemowe                       - praca samoczynna i awarie

Jesli oddajesz sprawe informatykowi, przekaz mu CALY folder wtyczki,
a nie pojedynczy plik.


UWAGA - WAZNE PRZY OBU WTYCZKACH
--------------------------------
Odinstalowanie wtyczki (przycisk "Usun" na liscie wtyczek) kasuje jej dane
bezpowrotnie. Samo WYLACZENIE wtyczki nie rusza niczego. Szczegoly w rozdziale
o wylaczaniu i usuwaniu w instrukcji klienta.
"""


def kopiuj_wtyczke(opis, raport):
    docelowy = os.path.join(CEL, opis['folder'])
    if os.path.isdir(docelowy):
        shutil.rmtree(docelowy)
    os.makedirs(docelowy)

    pdfy = sorted(f for f in os.listdir(opis['pdf_dir']) if f.lower().endswith('.pdf'))
    for f in pdfy:
        shutil.copy2(os.path.join(opis['pdf_dir'], f), os.path.join(docelowy, f))

    if os.path.exists(opis['zip']):
        shutil.copy2(opis['zip'], os.path.join(docelowy, os.path.basename(opis['zip'])))
        zip_ok = True
    else:
        zip_ok = False

    print('  %-24s %d PDF  %s' % (opis['folder'], len(pdfy),
                                  'ZIP OK' if zip_ok else 'ZIP BRAK'))
    if len(pdfy) != 5:
        raport.append('%s: %d dokumentow zamiast 5' % (opis['folder'], len(pdfy)))
    if not zip_ok:
        raport.append('%s: brak pliku instalacyjnego (%s)'
                      % (opis['folder'], os.path.basename(opis['zip'])))
    return zip_ok


def main():
    os.makedirs(CEL, exist_ok=True)
    raport = []

    print('paczka: %s\n' % CEL)
    kopiuj_wtyczke(WTYCZKA1, raport)
    kopiuj_wtyczke(WTYCZKA2, raport)

    # STRAZNIK: tekst jest celowo bez polskich znakow (ma sie otwierac w kazdym
    # notatniku), wiec KAZDY znak spoza ASCII to pomylka. Najgrozniejsza jest
    # cyrylica — wyglada identycznie jak lacinka i w drukowanym tekscie
    # nie da sie jej zobaczyc.
    obce = sorted({c for c in CZYTAJ if ord(c) > 127})
    if obce:
        raport.append('Co jest w tej paczce.txt: znaki spoza ASCII %s'
                      % [hex(ord(c)) for c in obce])

    with open(os.path.join(CEL, 'Co jest w tej paczce.txt'), 'w',
              encoding='utf-8-sig', newline='\r\n') as f:
        f.write(CZYTAJ)
    print('  %-24s zapisany' % 'Co jest w tej paczce.txt')

    print('')
    if raport:
        print('PACZKA NIEPELNA:')
        for r in raport:
            print('  - ' + r)
        return 1
    print('PACZKA KOMPLETNA')
    return 0


if __name__ == '__main__':
    sys.exit(main())
