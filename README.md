# AI FAQ Generator

Wtyczka WordPress z **generatorem FAQ zawężonym do tematu strony**: gość pyta na
publicznej podstronie `/faqgenerator`, a odpowiedź powstaje **wyłącznie w temacie
treści tej strony** (RAG + embeddingi Gemini) — pytania off-topic są odrzucane.
Do tego **dane strukturalne JSON-LD (FAQPage)** zgodne ze Schema.org.

> **Status:** w budowie · **v0.4.0** — Krok 3 (warstwa AI za interfejsem:
> `ProviderInterface`, `GeminiProvider`, `ProviderFactory` + generyczny transport HTTP
> `AIFAQ\Http`; generacja `gemini-2.5-flash`, embeddingi `gemini-embedding-001` @ 768).
> Wcześniej: Krok 1 v2 (moduły `src/`, 4 tabele, trasa `/faqgenerator`).
> Dalej: Krok 4 — warstwa danych / Indexer.

## Założenia
- **Dwa miejsca działania** — kokpit wp-admin (dla właściciela) oraz publiczna
  podstrona `/faqgenerator` (dla każdego gościa).
- **RAG na żywo** — pytanie → trafne fragmenty treści → odpowiedź AI ograniczona
  do nich → **cache + rate-limit** (ochrona klucza/kosztu), bramka tematu.
- **BYOK** — właściciel strony wpisuje własny (darmowy) klucz API w Ustawieniach.
- **Warstwa „Provider"** — domyślnie Gemini, z możliwością dołożenia innych dostawców.

## Struktura kodu (v2)
Autoloader PSR-4-lite: przestrzeń `AIFAQ\` → katalog `src/`.
```
src/Core/      Plugin, Settings, Activator, Deactivator, Router
src/Data/      Schema (4 tabele) + repozytoria + Migrator
src/Http/      HttpClient (interfejs) + WpHttpClient — generyczny transport HTTP
src/Providers/ ProviderInterface, GeminiProvider, ProviderFactory — warstwa AI (BYOK)
src/Admin/     Menu + views/ (Dashboard, Ustawienia, Historia)
src/Rest/ (Krok 7) · src/PublicUi/ (Krok 8) · src/App/ (Krok 9)
```
Tabele: `wp_aifaq_knowledge` (fragmenty+wektory), `wp_aifaq_qa_log` (dziennik pytań),
`wp_aifaq_cache` (dedup odpowiedzi), `wp_aifaq_faq` (FAQ pod SEO).

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
