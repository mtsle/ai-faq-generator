# AI FAQ Generator

Lekka wtyczka WordPress do szybkiego generowania sekcji **FAQ** dla wpisów i stron
z użyciem **API AI** (domyślnie Google Gemini Flash) oraz automatycznego dodawania
**danych strukturalnych JSON-LD (FAQPage)** zgodnych ze Schema.org.

> **Status:** w budowie · **v0.2.0** — ukończone Krok 1 (szkielet: menu + 3 podstrony, tabela historii)
> i Krok 2 (Ustawienia / konfiguracja API: klucz, model, temperatura, maks. pytań, „Test połączenia").
> Dalej: Krok 3 — Provider AI (Gemini).

## Założenia
- **Prosto, szybko, bez obciążania strony** — całe AI działa w panelu (AJAX/REST);
  na froncie ląduje tylko gotowy, statyczny HTML + JSON-LD (zero zapytań do AI).
- **BYOK** — właściciel strony wpisuje własny (darmowy) klucz API w Ustawieniach.
- **Warstwa „Provider"** — domyślnie Gemini, z możliwością dołożenia innych dostawców.

## Zakres (ze zlecenia)
1. Menu „AI FAQ Generator" → Dashboard / Ustawienia / Historia
2. Konfiguracja API: klucz, model, temperatura, maks. liczba pytań, „Test połączenia"
3. Generator: Temat + Dodatkowy opis + Liczba pytań (5–20) → „Generuj FAQ"
4. Tabela wyników (Pytanie / Odpowiedź) + Edytuj / Usuń / Kopiuj
5. Eksport: HTML / Gutenberg / Elementor / JSON
6. Schema.org: FAQPage JSON-LD + Podgląd / Kopiuj
7. Historia: data / temat / liczba pytań / użytkownik + Usuń / Ponów
8. Integracja z edytorem: panel „AI FAQ" → Generuj z treści → Wstaw do wpisu

## Wymagania (dev)
- WordPress 6.x, PHP 8.x
- Node.js (obecny: v24) — do narzędzi front / wp-env
- Do lokalnego WordPressa: **Docker** (dla `@wordpress/env`) **lub** aplikacja **Local**

## Lokalny WordPress
Wariant A — wp-env (wymaga Dockera):
```bash
npx @wordpress/env start
# panel: http://localhost:8888  (admin / password)
npx @wordpress/env stop
```
Wariant B — aplikacja **Local** (Flywheel): utwórz witrynę i podlinkuj ten folder
jako wtyczkę w `wp-content/plugins/ai-faq-generator`.

## Licencja
GPL-2.0-or-later (zgodnie ze standardem wtyczek WordPress).
