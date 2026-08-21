# -*- coding: utf-8 -*-
"""ETAP 8.4 (Instrukcje) — OPIS czterech schematow AI News Portal.

Kazdy schemat wychodzi jako `.drawio` (do edycji) i `.svg` (do dokumentacji) —
patrz `silnik_schematow.py`. Tresc pochodzi z KODU, nie z pamieci:
  * warstwy i klasy      — `src/*.php`
  * kolejnosc potoku     — `Runner::tick()`, `Runner::prepare_batch()`, `publish_batch()`
  * kolumny tabeli       — `Plugin::create_table()`
  * stale czasu i limity — `public const` w `Runner`, `Gemini`, `Http`

URUCHOMIENIE (Git Bash):
  python faq-generator/ai-faq-generator/ai-news-portal/instrukcje/narzedzia/schematy-def.py

Tekst zrodla bez polskich znakow (konsola Git Bash); ETYKIETY na schematach — z polskimi.
"""

import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from silnik_schematow import Schemat, OUT   # noqa: E402


# ===========================================================================
# 1. ARCHITEKTURA RUNTIME
# ===========================================================================
def architektura():
    s = Schemat("01-architektura-runtime",
                "AI News Portal — architektura wykonawcza",
                1780, 1000,
                "Jedna wtyczka, jedna tabela, jeden klucz API. Materiał wchodzi z kanałów RSS, "
                "wychodzi jako artykuł we własnym typie treści.")

    s.kontener("k_zewn", "Świat zewnętrzny", 20, 90, 300, 330)
    s.wezel("rss", ["Kanały RSS", "4 adresy domyślne", "Settings::default_sources()"],
            40, 130, 260, 74, "zewn")
    s.wezel("strony", ["Strony źródłowe", "doczytywane, gdy kanał", "podał samą zapowiedź"],
            40, 224, 260, 74, "zewn")
    s.wezel("gemini", ["Gemini API", "v1beta :generateContent", "wymuszony schemat odpowiedzi"],
            40, 318, 260, 74, "zewn")

    s.kontener("k_siec", "Warstwa sieci", 350, 90, 330, 330)
    s.wezel("http", ["Http", "wp_safe_remote_get", "limity: feed 4 MB / art. 1 MB",
                     "timeouty: 10 s / 15 s"], 370, 130, 290, 92, "rag")
    s.wezel("feed", ["Feed", "RSS 2.0 i Atom", "content:encoded, dc:date"], 370, 242, 290, 74, "rag")
    s.wezel("klient_ai", ["Gemini (klasa)", "licznik dobowy, sufit 20", "materiał ≤ 12 000 znaków"],
            370, 336, 290, 74, "rag")

    s.kontener("k_logika", "Logika portalu", 710, 90, 360, 470)
    s.wezel("runner", ["Runner", "tick() — trzy fazy pod zamkiem", "budżet czasu 25 s"],
            730, 130, 320, 74, "rag")
    s.wezel("filter", ["Filter", "słowa wykluczające + wymagane", "działa PRZED modelem"],
            730, 224, 320, 74, "decyzja")
    s.wezel("dedup", ["Dedup", "po adresie i po treści", "SHA-256, 8 KB próbki"], 730, 318, 320, 74, "decyzja")
    s.wezel("validator", ["Validator", "tytuł 5–140, lead 20–400", "treść ≥ 800, kategoria z listy"],
            730, 412, 320, 74, "decyzja")
    s.wezel("publisher", ["Publisher", "wpis + termin, idempotentnie", "kasuje artykuł powitalny"],
            730, 486, 320, 56, "rag")

    s.kontener("k_wp", "WordPress", 1100, 90, 340, 470)
    s.wezel("cron", ["WP-Cron", "zdarzenie ainp_tick", "co godzinę, przy ruchu"], 1120, 130, 300, 74, "wp")
    s.wezel("cpt", ["Typ treści ainp_article", "+ taksonomia ainp_topic", "archiwum /centrum-wiedzy/"],
            1120, 224, 300, 74, "wp")
    s.wezel("tabela", ["Tabela wp_ainp_items", "kolejka materiałów", "statusy new … done"],
            1120, 318, 300, 74, "dane")
    s.wezel("opcje", ["Opcje ainp_*", "ustawienia, klucz, licznik", "zamek przebiegu"],
            1120, 412, 300, 74, "dane")
    s.wezel("portal", ["Portal + szablony", "archiwum, artykuł, kategoria", "zero JavaScriptu"],
            1120, 486, 300, 56, "wp")

    s.kontener("k_kokpit", "Obsługa", 20, 460, 300, 200)
    s.wezel("wlasciciel", ["Właściciel witryny", "administrator"], 40, 500, 260, 56, "aktor")
    s.wezel("panel", ["Kokpit: 2 pozycje", "Materiały · Ustawienia", "+ edit.php?post_type=…"],
            40, 576, 260, 74, "wp")

    s.wezel("gosc", ["Gość", "czyta Centrum Wiedzy"], 1120, 596, 300, 56, "aktor")

    s.krawedz("rss", "http", "pobranie")
    s.krawedz("strony", "http", "doczytanie")
    s.krawedz("http", "feed", "")
    s.krawedz("gemini", "klient_ai", "odpowiedź JSON")
    s.krawedz("feed", "runner", "pozycje")
    s.krawedz("runner", "filter", "")
    s.krawedz("filter", "dedup", "")
    s.krawedz("dedup", "klient_ai", "materiał")
    s.krawedz("klient_ai", "validator", "")
    s.krawedz("validator", "publisher", "przeszło")
    s.krawedz("publisher", "cpt", "artykuł")
    s.krawedz("cron", "runner", "ainp_tick")
    s.krawedz("runner", "tabela", "stan kolejki")
    s.krawedz("runner", "opcje", "zamek, licznik")
    s.krawedz("cpt", "portal", "")
    s.krawedz("portal", "gosc", "")
    s.krawedz("wlasciciel", "panel", "")
    s.krawedz("panel", "runner", "3 przyciski", True)

    s.legenda = [("aktor", "Człowiek"), ("zewn", "Zasób poza witryną"),
                 ("rag", "Logika wtyczki"), ("decyzja", "Bramka — decyduje, czy iść dalej"),
                 ("wp", "Integracja z WordPressem"), ("dane", "Trwałe dane w bazie")]
    return s


# ===========================================================================
# 2. DROGA POZYCJI OD KANALU DO ARTYKULU
# ===========================================================================
def przeplyw():
    s = Schemat("02-droga-pozycji",
                "Droga pozycji: od kanału RSS do opublikowanego artykułu",
                1500, 1180,
                "Cztery bramki odsiewają materiał ZANIM padnie pytanie do modelu. "
                "Każde wywołanie modelu kosztuje jeden slot z dobowej puli 20.")

    s.wezel("start", ["Pozycja w kanale RSS"], 560, 40, 380, 50, "zewn")
    s.wezel("norm", ["Normalizacja adresu", "usunięcie utm_*, gclid, fbclid"], 560, 118, 380, 60, "wp")
    s.wezel("dedup_url", ["Bramka 1 — powtórka adresu?", "UNIQUE na url_hash"], 540, 206, 420, 60, "decyzja")
    s.wezel("filtr", ["Bramka 2 — filtr słów", "wykluczające: tytuł + zajawka + treść",
                      "wymagane: tytuł + zajawka"], 520, 294, 460, 78, "decyzja")
    s.wezel("scrap", ["Treść z kanału za krótka?", "próg 1200 znaków"], 540, 400, 420, 60, "wp")
    s.wezel("pobierz", ["Doczytanie strony źródłowej", "ekstrakcja + canonical"], 100, 400, 360, 60, "rag")
    s.wezel("dedup_tresc", ["Bramka 3 — powtórka treści?", "SHA-256 z 8 KB tekstu"], 540, 488, 420, 60, "decyzja")
    s.wezel("wymagane", ["Bramka 4 — słowo wymagane", "sprawdzana PONOWNIE w punkcie wyboru"],
            520, 576, 460, 60, "decyzja")
    s.wezel("slot", ["Rezerwacja slotu z puli dobowej", "licznik ainp_usage, sufit 20"],
            520, 664, 460, 60, "uwaga")
    s.wezel("ai", ["Gemini — jedno wywołanie", "materiał ucięty do 12 000 znaków",
                   "odpowiedź: title, lead, content, topic"], 500, 752, 500, 78, "zewn")
    s.wezel("walid", ["Walidacja kontraktu", "długości pól, kategoria z listy"], 540, 858, 420, 60, "decyzja")
    s.wezel("publikacja", ["Publikacja w ainp_article", "wpis → termin → status done"],
            540, 946, 420, 60, "wynik")
    s.wezel("gotowe", ["Artykuł w Centrum Wiedzy"], 560, 1034, 380, 50, "wynik")

    s.wezel("skipped", ["status: pominięty", "powód zapisany w kolumnie note"], 1060, 300, 380, 60, "wynik")
    s.wezel("failed", ["status: nieudany", "TREŚĆ ZOSTAJE w bazie", "do 3 prób, potem ręczne wznowienie"],
            1060, 780, 380, 78, "uwaga")

    s.krawedz("start", "norm", "")
    s.krawedz("norm", "dedup_url", "")
    s.krawedz("dedup_url", "filtr", "nowa")
    s.krawedz("dedup_url", "skipped", "znana — odrzut")
    s.krawedz("filtr", "scrap", "przeszła")
    s.krawedz("filtr", "skipped", "słowo wyklucza")
    s.krawedz("scrap", "pobierz", "tak")
    s.krawedz("pobierz", "dedup_tresc", "")
    s.krawedz("scrap", "dedup_tresc", "nie")
    s.krawedz("dedup_tresc", "wymagane", "unikat")
    s.krawedz("dedup_tresc", "skipped", "duplikat")
    s.krawedz("wymagane", "slot", "na temat")
    s.krawedz("wymagane", "skipped", "poza tematem")
    s.krawedz("slot", "ai", "slot zajęty")
    s.krawedz("slot", "failed", "pula wyczerpana")
    s.krawedz("ai", "walid", "HTTP 200")
    s.krawedz("ai", "failed", "błąd / przekroczony czas")
    s.krawedz("walid", "publikacja", "kontrakt spełniony")
    s.krawedz("walid", "failed", "kontrakt złamany")
    s.krawedz("publikacja", "gotowe", "")
    s.krawedz("failed", "slot", "„Wznów nieudane”", True)

    s.legenda = [("decyzja", "Bramka — nic nie kosztuje"), ("uwaga", "Miejsce, w którym płacimy slotem"),
                 ("zewn", "Ruch na zewnątrz witryny"), ("wynik", "Stan końcowy pozycji")]
    return s


# ===========================================================================
# 3. DANE: TABELA, TYPY TRESCI, OPCJE
# ===========================================================================
def dane():
    s = Schemat("03-dane",
                "Dane wtyczki — jedna tabela, jeden typ treści, pięć opcji",
                1560, 900,
                "Wszystko z prefiksem ainp_. Odinstalowanie kasuje każdą z tych rzeczy — "
                "razem z artykułami.")

    s.kontener("k_tab", "Tabela własna", 20, 90, 520, 560)
    s.wezel("tabela", ["wp_ainp_items — kolejka materiałów",
                       "id · url (2048) · url_hash UNIQUE",
                       "content_hash UNIQUE NULL · title · excerpt",
                       "content (longtext) · status · note",
                       "attempts · post_id · created_at · updated_at",
                       "KEY (status, updated_at) · InnoDB"],
            40, 130, 480, 190, "dane")
    s.wezel("statusy", ["Statusy pozycji",
                        "new — czeka",
                        "processing — w obróbce (TTL 900 s)",
                        "done — powstał artykuł",
                        "skipped — odrzucona świadomie",
                        "failed — próba nieudana, treść zostaje"],
            40, 350, 480, 175, "wynik")
    s.wezel("klucze", ["Dwa klucze UNIQUE = brak duplikatów",
                       "content_hash dopuszcza NULL — pozycje",
                       "przed doczytaniem treści nie kolidują"],
            40, 550, 480, 80, "uwaga")

    s.kontener("k_tresc", "Typy treści WordPressa", 570, 90, 470, 340)
    s.wezel("cpt", ["ainp_article (typ treści)",
                    "publiczny, w edytorze blokowym",
                    "BEZ własnej pozycji w menu kokpitu",
                    "archiwum: /centrum-wiedzy/"],
            590, 130, 430, 110, "wp")
    s.wezel("tax", ["ainp_topic (taksonomia)",
                    "hierarchiczna, 7 terminów zasianych",
                    "adres: /centrum-wiedzy/kategoria/…"],
            590, 260, 430, 92, "wp")
    s.wezel("meta", ["Meta wpisu",
                     "_ainp_item_id · _ainp_source_url · _ainp_demo"],
            590, 366, 430, 50, "dane")

    s.kontener("k_opcje", "Opcje", 1070, 90, 470, 560)
    s.wezel("o_settings", ["ainp_settings", "kategorie, słowa, prompt, model,", "sufit dobowy, tryb szkicu"],
            1090, 130, 430, 74, "dane")
    s.wezel("o_sources", ["ainp_sources", "lista kanałów RSS",
                          "BRAK opcji ≠ pusta lista"], 1090, 224, 430, 74, "dane")
    s.wezel("o_key", ["ainp_key", "klucz API, autoload wyłączony"], 1090, 318, 430, 56, "dane")
    s.wezel("o_usage", ["ainp_usage", "data + licznik wywołań AI"], 1090, 394, 430, 56, "dane")
    s.wezel("o_lock", ["ainp_run_lock", "zamek przebiegu: czas.uniqid",
                       "INSERT IGNORE + przejęcie po TTL"], 1090, 470, 430, 74, "uwaga")
    s.wezel("o_reszta", ["Pozostałe: ainp_topics_seeded,",
                         "ainp_topic_children, ainp_slug_collision"],
            1090, 564, 430, 56, "dane")

    s.wezel("uninstall", ["uninstall.php — kasuje WSZYSTKO powyżej",
                          "wpisy i ich meta · terminy · tabela (DROP)",
                          "opcje z jawnej listy + zamiatanie po prefiksie ainp_",
                          "transienty _transient_ainp_* · zdarzenie ainp_tick"],
            420, 700, 720, 120, "zewn")

    s.krawedz("tabela", "cpt", "post_id")
    s.krawedz("cpt", "tax", "kategoria")
    s.krawedz("cpt", "meta", "")
    s.krawedz("tabela", "uninstall", "")
    s.krawedz("cpt", "uninstall", "")
    s.krawedz("o_lock", "uninstall", "")

    s.legenda = [("dane", "Trwałe dane"), ("wp", "Twór WordPressa"),
                 ("wynik", "Zbiór wartości"), ("uwaga", "Miejsce wrażliwe"),
                 ("zewn", "Operacja nieodwracalna")]
    return s


# ===========================================================================
# 4. PRZEBIEG CYKLICZNY — TRZY FAZY POD JEDNYM ZAMKIEM
# ===========================================================================
def przebieg():
    s = Schemat("04-przebieg-cykliczny",
                "Przebieg cykliczny — trzy fazy pod jednym zamkiem",
                1560, 940,
                "ainp_tick, co godzinę, tylko przy ruchu na witrynie. Budżet 25 s dzielony "
                "¼ / ¼ / reszta. Przyciski panelu wołają te same fazy z własnymi budżetami.")

    s.wezel("zdarzenie", ["WP-Cron: ainp_tick", "powtarzalne, hourly"], 80, 60, 360, 60, "wp")
    s.wezel("zamek", ["Zamek ainp_run_lock",
                      "INSERT IGNORE — kto pierwszy, ten jedzie",
                      "przejęcie po TTL 120 s (CAS po tokenie)"],
            80, 148, 360, 78, "uwaga")
    s.wezel("zajety", ["Zamek zajęty → przebieg kończy się", "bez pracy i bez błędu"],
            80, 254, 360, 60, "wynik")

    s.kontener("k_fazy", "Trzy fazy, zawsze w tej kolejności", 500, 100, 700, 470)
    s.wezel("f1", ["FAZA 1 — pobranie",
                   "kanały z listy, do 100 pozycji na kanał",
                   "dedup po adresie, zapis jako new",
                   "działka czasu: ¼ budżetu · ZERO wywołań AI"],
            520, 144, 660, 108, "rag")
    s.wezel("f2", ["FAZA 2 — przygotowanie",
                   "partia 10 pozycji: filtr słów, doczytanie treści,",
                   "dedup po treści",
                   "działka: ¼ budżetu · ZERO wywołań AI"],
            520, 270, 660, 108, "rag")
    s.wezel("f3", ["FAZA 3 — publikacja",
                   "do 3 pozycji, każda = 1 wywołanie AI",
                   "walidacja kontraktu, wpis, termin",
                   "działka: reszta budżetu"],
            520, 396, 660, 108, "zewn")

    s.wezel("budzet", ["Budżet czasu przycina timeout żądania",
                       "poniżej 8 s wywołania NIE zaczynamy —",
                       "ucięte żądanie i tak spaliłoby slot"],
            1240, 208, 300, 110, "decyzja")
    s.wezel("recover", ["Odzyskiwanie zawieszonych",
                        "processing starszy niż 900 s wraca do new"],
            1240, 360, 300, 80, "wp")

    s.wezel("koniec", ["Zwolnienie zamka — WARUNKOWE",
                       "kasujemy tylko własny wpis (token)"],
            520, 620, 660, 60, "uwaga")
    s.wezel("log", ["Ślad przebiegu w transiencie",
                    "ainp_last_tick, ważny 24 h — to on zasila ramkę",
                    "„Ostatni automatyczny przebieg” w panelu"],
            520, 708, 660, 78, "dane")

    s.wezel("przyciski", ["Panel: te same fazy, inne budżety",
                          "„Pobierz teraz” · „Przygotuj treści” (15 s)",
                          "„Opublikuj teraz” (30 s) · „Wznów nieudane”"],
            80, 620, 360, 100, "wynik")

    s.krawedz("zdarzenie", "zamek", "")
    s.krawedz("zamek", "zajety", "nie udało się")
    s.krawedz("zamek", "f1", "zamek zdobyty")
    s.krawedz("f1", "f2", "")
    s.krawedz("f2", "f3", "")
    s.krawedz("f3", "koniec", "")
    s.krawedz("f3", "budzet", "ile czasu zostało?")
    s.krawedz("zamek", "recover", "", True)
    s.krawedz("koniec", "log", "")
    s.krawedz("przyciski", "zamek", "ten sam zamek", True)

    s.legenda = [("wp", "Mechanizm WordPressa"), ("rag", "Faza bez kosztu"),
                 ("zewn", "Faza płatna slotami"), ("uwaga", "Ochrona przed wyścigiem"),
                 ("decyzja", "Ochrona kosztu"), ("wynik", "Uruchomienie ręczne"),
                 ("dane", "Ślad przebiegu")]
    return s


def main():
    os.makedirs(OUT, exist_ok=True)
    for buduj in (architektura, przeplyw, dane, przebieg):
        s = buduj()
        s.zapisz()
        print("  %-28s %d x %d" % (s.nazwa, s.szer, s.wys))
    print("\nschematow: 4  ->  %s" % OUT)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
