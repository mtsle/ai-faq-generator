# AI FAQ Generator

Wtyczka WordPress z **generatorem FAQ zawężonym do tematu strony**: gość pyta na
publicznej podstronie `/faqgenerator`, a odpowiedź powstaje **wyłącznie w temacie
treści tej strony** (RAG + embeddingi Gemini) — pytania off-topic są odrzucane.
Do tego **dane strukturalne JSON-LD (FAQPage)** zgodne ze Schema.org.

> **Status:** w budowie · Kroki 0–13 gotowe (dwie połówki produktu):
> **RAG** (`/faqgenerator`: Indexer + Retriever + TopicGuard + Answerer, REST `aifaq/v1`,
> front rola-aware, dziennik pytań gości) **+ generator FAQ w kokpicie**
> (Krok 11: `Faq\FaqGenerator` — temat→pary Q&A jako structured JSON, tabela `wp_aifaq_generations`;
> Krok 12: REST `/admin/generate-faq` + `/admin/generations`;
> **Krok 13: ekran „Narzędzie FAQ" — formularz Temat/Opis/Liczba + tabela par z Edytuj/Usuń/Kopiuj**).
> Dalej: Krok 14 — eksport (HTML/Gutenberg/Elementor/JSON) + JSON-LD FAQPage.
> Pełne README z instrukcjami — Krok 17 (v1.0.0).

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
src/Admin/     Menu + FaqToolPage + views/ (Dashboard, Generator, Narzędzie FAQ, Ustawienia, Historia)
src/Rest/ (Krok 7) · src/PublicUi/ (Krok 8) · src/App/ (Krok 9-10)
src/Faq/  (Krok 11) FaqGenerator — kreatywny generator par Q&A (osobny od RAG)
```
Tabele (schema v4): `wp_aifaq_knowledge` (fragmenty+wektory), `wp_aifaq_qa_log`
(dziennik pytań gości), `wp_aifaq_cache` (dedup odpowiedzi), `wp_aifaq_faq`
(FAQ pod SEO — uśpione), `wp_aifaq_generations` (historia generowań + snapshot par).
Migracja schematu jest automatyczna (porównanie `AIFAQ_DB_VERSION` na `plugins_loaded`).

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
