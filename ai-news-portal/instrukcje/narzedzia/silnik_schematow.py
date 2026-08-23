#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""ETAP 8.4 (Instrukcje) — generator schematow: JEDEN opis -> `.drawio` + `.svg`.

Kazdy schemat jest opisany raz, w jednej strukturze danych, i wychodzi w dwoch postaciach:
  * `.drawio` — zrodlo do EDYCJI (wtyczka „Draw.io Integration" w VS Code albo diagrams.net),
  * `.svg`    — gotowy obrazek do wstawienia w dokumentacje (nic nie trzeba eksportowac recznie).

Dzieki temu obie postacie nie moga sie rozjechac: poprawka w opisie zmienia OBA pliki.
Wynik trafia do `instrukcje/schematy/`.

URUCHOMIENIE (Git Bash):  python faq-generator/ai-faq-generator/ai-news-portal/instrukcje/narzedzia/schematy.py

Tekst zrodla bez polskich znakow (konsola Git Bash); ETYKIETY na schematach — z polskimi.
"""

import os
import sys
from xml.sax.saxutils import escape

ROOT = r"c:\Users\matot\Desktop\strona1\faq-generator\ai-faq-generator\ai-news-portal\instrukcje"
OUT = os.path.join(ROOT, "schematy")

# --- Paleta (czytelna takze po wydruku mono) --------------------------------
STYLE = {
    "aktor":     ("#FFF4EC", "#E8590C", "#7C2D12"),
    "wp":        ("#EFF4FF", "#1D4ED8", "#1E3A8A"),
    "rag":       ("#ECFDF5", "#047857", "#064E3B"),
    "dane":      ("#F5F3FF", "#7C3AED", "#4C1D95"),
    "zewn":      ("#FEF2F2", "#B91C1C", "#7F1D1D"),
    "decyzja":   ("#FEFCE8", "#CA8A04", "#713F12"),
    "wynik":     ("#F1F5F9", "#475569", "#0F172A"),
    "uwaga":     ("#FFF7ED", "#EA580C", "#7C2D12"),
}
KONTENER = ("#F8FAFC", "#94A3B8", "#334155")

FONT = "Segoe UI, Helvetica, Arial, sans-serif"
LH = 17          # wysokosc wiersza tekstu w wezle
FS = 12.5        # rozmiar pisma w wezle


class Schemat:
    """Jeden schemat: kontenery, wezly, krawedzie. Umie sie zapisac jako .drawio i .svg."""

    def __init__(self, nazwa, tytul, szer, wys, podtytul=""):
        self.nazwa = nazwa
        self.tytul = tytul
        self.podtytul = podtytul
        self.szer = szer
        self.wys = wys
        self.kontenery = []   # (id, tytul, x, y, w, h)
        self.wezly = {}       # id -> dict
        self.kolejnosc = []
        self.krawedzie = []   # (a, b, etykieta, przerywana)
        self.legenda = []     # (typ, opis)

    def kontener(self, kid, tytul, x, y, w, h):
        self.kontenery.append((kid, tytul, x, y, w, h))
        return self

    def wezel(self, wid, linie, x, y, w, h, typ="wp"):
        self.wezly[wid] = {"linie": linie, "x": x, "y": y, "w": w, "h": h, "typ": typ}
        self.kolejnosc.append(wid)
        return self

    def krawedz(self, a, b, etykieta="", przerywana=False):
        self.krawedzie.append((a, b, etykieta, przerywana))
        return self

    # --- .drawio ----------------------------------------------------------
    def drawio(self):
        czesci = []
        czesci.append('<mxfile host="app.diagrams.net" version="24.0.0" type="device">')
        czesci.append('  <diagram name="%s" id="%s">' % (escape(self.tytul[:40]), self.nazwa))
        czesci.append('    <mxGraphModel dx="1400" dy="900" grid="1" gridSize="10" guides="1" tooltips="1" '
                      'connect="1" arrows="1" fold="1" page="1" pageScale="1" pageWidth="%d" pageHeight="%d" '
                      'math="0" shadow="0">' % (self.szer, self.wys))
        czesci.append('      <root>')
        czesci.append('        <mxCell id="0" />')
        czesci.append('        <mxCell id="1" parent="0" />')

        # UWAGA: `&#10;` (lamanie wiersza w draw.io) wstawiamy PO escapowaniu tekstu,
        # inaczej `escape()` zamieni jego `&` na `&amp;` i w edytorze pojawi sie surowy kod.
        naglowek = escape(self.tytul) + ('&#10;' + escape(self.podtytul) if self.podtytul else '')
        czesci.append('        <mxCell id="tytul" value="%s" style="text;html=1;fontSize=16;fontStyle=1;'
                      'align=left;verticalAlign=top;" vertex="1" parent="1">'
                      '<mxGeometry x="24" y="16" width="%d" height="46" as="geometry" /></mxCell>'
                      % (naglowek, self.szer - 60))

        for kid, tytul, x, y, w, h in self.kontenery:
            st = ("rounded=1;whiteSpace=wrap;html=1;fillColor=%s;strokeColor=%s;fontColor=%s;dashed=1;"
                  "verticalAlign=top;align=left;spacingLeft=12;spacingTop=6;fontSize=13;fontStyle=1;"
                  % (KONTENER[0], KONTENER[1], KONTENER[2]))
            czesci.append('        <mxCell id="%s" value="%s" style="%s" vertex="1" parent="1">'
                          '<mxGeometry x="%d" y="%d" width="%d" height="%d" as="geometry" /></mxCell>'
                          % (kid, escape(tytul), st, x, y, w, h))

        for wid in self.kolejnosc:
            n = self.wezly[wid]
            fill, stroke, fontc = STYLE.get(n["typ"], STYLE["wp"])
            st = ("rounded=1;whiteSpace=wrap;html=1;fillColor=%s;strokeColor=%s;fontColor=%s;fontSize=12;"
                  "arcSize=8;strokeWidth=2;" % (fill, stroke, fontc))
            czesci.append('        <mxCell id="%s" value="%s" style="%s" vertex="1" parent="1">'
                          '<mxGeometry x="%d" y="%d" width="%d" height="%d" as="geometry" /></mxCell>'
                          % (wid, '&#10;'.join(escape(l) for l in n["linie"]), st, n["x"], n["y"], n["w"], n["h"]))

        for i, (a, b, et, przerywana) in enumerate(self.krawedzie):
            st = ("edgeStyle=orthogonalEdgeStyle;rounded=1;html=1;strokeColor=#475569;strokeWidth=2;"
                  "fontSize=11;fontColor=#334155;endArrow=block;endFill=1;")
            if przerywana:
                st += "dashed=1;"
            czesci.append('        <mxCell id="e%d" value="%s" style="%s" edge="1" parent="1" source="%s" target="%s">'
                          '<mxGeometry relative="1" as="geometry" /></mxCell>' % (i, escape(et), st, a, b))

        czesci.append('      </root>')
        czesci.append('    </mxGraphModel>')
        czesci.append('  </diagram>')
        czesci.append('</mxfile>')
        return "\n".join(czesci)

    # --- .svg -------------------------------------------------------------
    def _kotwice(self, a, b):
        """Punkty na krawedziach dwoch prostokatow — linia laczy je najkrotsza droga."""
        na, nb = self.wezly[a], self.wezly[b]
        ax, ay = na["x"] + na["w"] / 2, na["y"] + na["h"] / 2
        bx, by = nb["x"] + nb["w"] / 2, nb["y"] + nb["h"] / 2

        def brzeg(n, cx, cy, tx, ty):
            dx, dy = tx - cx, ty - cy
            if dx == 0 and dy == 0:
                return cx, cy
            skala = []
            if dx:
                skala.append((n["w"] / 2) / abs(dx))
            if dy:
                skala.append((n["h"] / 2) / abs(dy))
            s = min(skala)
            return cx + dx * s, cy + dy * s

        p1 = brzeg(na, ax, ay, bx, by)
        p2 = brzeg(nb, bx, by, ax, ay)
        return p1, p2

    def svg(self):
        o = []
        o.append('<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d" '
                 'font-family="%s">' % (self.szer, self.wys, self.szer, self.wys, FONT))
        o.append('<defs><marker id="grot" markerWidth="10" markerHeight="8" refX="9" refY="4" orient="auto">'
                 '<path d="M0,0 L10,4 L0,8 z" fill="#475569"/></marker></defs>')
        o.append('<rect width="%d" height="%d" fill="#FFFFFF"/>' % (self.szer, self.wys))
        o.append('<text x="24" y="36" font-size="19" font-weight="700" fill="#0F172A">%s</text>' % escape(self.tytul))
        if self.podtytul:
            o.append('<text x="24" y="58" font-size="12.5" fill="#475569">%s</text>' % escape(self.podtytul))

        for kid, tytul, x, y, w, h in self.kontenery:
            o.append('<rect x="%d" y="%d" width="%d" height="%d" rx="12" fill="%s" stroke="%s" '
                     'stroke-width="2" stroke-dasharray="7 5"/>' % (x, y, w, h, KONTENER[0], KONTENER[1]))
            o.append('<text x="%d" y="%d" font-size="13" font-weight="700" fill="%s">%s</text>'
                     % (x + 14, y + 22, KONTENER[2], escape(tytul)))

        # Etykiety krawedzi rysujemy PO wezlach (nizej) — inaczej prostokat wezla
        # przykrywa opis strzalki i schemat klamie o tym, co laczy.
        etykiety = []
        for a, b, et, przerywana in self.krawedzie:
            (x1, y1), (x2, y2) = self._kotwice(a, b)
            dash = ' stroke-dasharray="6 5"' if przerywana else ''
            o.append('<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f" stroke="#475569" stroke-width="2" '
                     'marker-end="url(#grot)"%s/>' % (x1, y1, x2, y2, dash))
            if et:
                etykiety.append(((x1 + x2) / 2, (y1 + y2) / 2, et))

        for wid in self.kolejnosc:
            n = self.wezly[wid]
            fill, stroke, fontc = STYLE.get(n["typ"], STYLE["wp"])
            o.append('<rect x="%d" y="%d" width="%d" height="%d" rx="9" fill="%s" stroke="%s" stroke-width="2"/>'
                     % (n["x"], n["y"], n["w"], n["h"], fill, stroke))
            ile = len(n["linie"])
            start = n["y"] + n["h"] / 2 - (ile - 1) * LH / 2 + 4
            for i, linia in enumerate(n["linie"]):
                waga = "700" if i == 0 else "400"
                rozmiar = FS if i == 0 else FS - 1
                o.append('<text x="%.1f" y="%.1f" font-size="%.1f" font-weight="%s" fill="%s" '
                         'text-anchor="middle">%s</text>'
                         % (n["x"] + n["w"] / 2, start + i * LH, rozmiar, waga, fontc, escape(linia)))

        for mx, my, et in etykiety:
            szer_et = 7.0 * len(et) + 12
            o.append('<rect x="%.1f" y="%.1f" width="%.1f" height="19" rx="4" fill="#FFFFFF" '
                     'stroke="#CBD5E1"/>' % (mx - szer_et / 2, my - 10, szer_et))
            o.append('<text x="%.1f" y="%.1f" font-size="11" fill="#334155" text-anchor="middle">%s</text>'
                     % (mx, my + 4, escape(et)))

        if self.legenda:
            lx, ly = 24, self.wys - 26 - (len(self.legenda) - 1) * 20
            for typ, opis in self.legenda:
                fill, stroke, _ = STYLE.get(typ, STYLE["wp"])
                o.append('<rect x="%d" y="%d" width="26" height="14" rx="4" fill="%s" stroke="%s" stroke-width="2"/>'
                         % (lx, ly - 11, fill, stroke))
                o.append('<text x="%d" y="%d" font-size="11.5" fill="#334155">%s</text>' % (lx + 34, ly, escape(opis)))
                ly += 20

        o.append('</svg>')
        return "\n".join(o)

    def zapisz(self):
        with open(os.path.join(OUT, self.nazwa + ".drawio"), "w", encoding="utf8") as f:
            f.write(self.drawio())
        with open(os.path.join(OUT, self.nazwa + ".svg"), "w", encoding="utf8") as f:
            f.write(self.svg())
        print("  OK   %s.drawio + %s.svg" % (self.nazwa, self.nazwa))
