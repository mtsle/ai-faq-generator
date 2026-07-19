# AI FAQ Generator

Wtyczka WordPress z **generatorem FAQ zawężonym do tematu strony**: gość pyta na
publicznej podstronie `/faqgenerator`, a odpowiedź powstaje **wyłącznie w temacie
treści tej strony** (RAG + embeddingi Gemini) — pytania off-topic są odrzucane.
Do tego **dane strukturalne JSON-LD (FAQPage)** zgodne ze Schema.org.

> **Status:** w budowie · **Kroki 0–18 gotowe** (dwie połówki produktu).
> **Zakres ze zlecenia (pkt 1–8) jest ZAMKNIĘTY** od Kroku 16.
>
> **Połówka generator (kokpit)** — gotowa do oddania klientowi: `Faq\FaqGenerator` (temat→pary Q&A
> jako structured JSON) · REST `/admin/generate-faq` · ekran „Narzędzie FAQ" · eksport do 5 formatów
> (`Faq\Exporter`, w tym JSON-LD `FAQPage`) · Historia generowań w dwóch miejscach ·
> metabox „AI FAQ" w edytorze wpisu.
>
> **Połówka RAG (front)** — `/faqgenerator` + shortcode `[aifaq_generator]` + automatyczna podstrona;
> Indexer z **kaskadą czterech źródeł treści** (K17: `post_content` + postmeta/ACF + crawl-jako-gość
> + filtr balastu) · Retriever + TopicGuard + Answerer · dziennik pytań gości.
>
> **Krok 18 (v0.21.0):** podstrona `/generator-faq/` dostała **szóstą zakładkę „Narzędzie FAQ"**
> z eksportem (`App\FaqToolPanel` — jedno źródło markupu dla kokpitu i frontu), a mechanizm
> powstawania podstrony przestał cicho zawodzić (`PublicUi\PageGuard` + `Admin\PageNotice`).
>
> Dalej: **Krok 19** — jakość odpowiedzi bota RAG · **Krok 20** — ostatnie funkcje dla klienta
> (automatyczny link w menu, capy dla Redaktora) · **Krok 21** — v1.0.0 (audyt, RWA, instrukcje).

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
src/Data/      Schema (5 tabel) + repozytoria + Migrator
src/Http/      HttpClient (interfejs) + WpHttpClient — generyczny transport HTTP
src/Providers/ ProviderInterface, GeminiProvider, ProviderFactory — warstwa AI (BYOK)
src/Admin/     Menu + FaqToolPage + views/ (Dashboard, Generator, Narzędzie FAQ, Ustawienia, Historia)
src/Rest/ (Krok 7) 13 tras `aifaq/v1` — publiczne `/ask` + `/admin/*`
src/PublicUi/ (Krok 8) GeneratorPage · (K17) Shortcode — `[aifaq_generator]` + automatyczna podstrona
              (K18) PageGuard — stan podstrony, samo-naprawa, zamek atomowy
src/App/  (Krok 9-10) AppShell + HistoryPanel · (K15) GenerationsPanel
          (K18) FaqToolPanel — JEDNO źródło markupu narzędzia FAQ (kokpit + front)
src/Index/ (Krok 5, rozbud. K17) Chunker, Indexer + kaskada źródeł: WpContentSource,
          PostMetaContentSource, RenderedContentSource, CrawlQueue, BoilerplateFilter, CompositeContentSource
src/Faq/  (Krok 11) FaqGenerator — kreatywny generator par Q&A (osobny od RAG)
          (Krok 14) Exporter — pary Q&A → 5 formatów eksportu (HTML/Gutenberg/Elementor/JSON/JSON-LD)
src/Admin/ (K16) PostMetaBox · (K18) PageNotice — komunikaty o stanie podstrony
```
Tabele (schema v4): `wp_aifaq_knowledge` (fragmenty+wektory), `wp_aifaq_qa_log`
(dziennik pytań gości), `wp_aifaq_cache` (dedup odpowiedzi), `wp_aifaq_faq`
(FAQ pod SEO — uśpione), `wp_aifaq_generations` (historia generowań + snapshot par).
Migracja schematu jest automatyczna (porównanie `AIFAQ_DB_VERSION` na `plugins_loaded`).

## Zakres (ze zlecenia)
1. Menu „AI FAQ Generator" → Dashboard / Ustawienia / Historia
2. Konfiguracja API: klucz, model, temperatura, maks. liczba pytań, „Test połączenia"
3. Generator: Temat + Dodatkowy opis + Liczba pytań (5–20) → „Generuj FAQ"
4. Tabela wyników (Pytanie / Odpowiedź) + Edytuj / Usuń / Kopiuj ✅ (Krok 13)
5. Eksport: HTML / Gutenberg / Elementor / JSON ✅ (Krok 14)
6. Schema.org: FAQPage JSON-LD + Podgląd / Kopiuj / Pobierz ✅ (Krok 14)
7. Historia: data / temat / liczba pytań / użytkownik + Usuń / Ponów ✅ (Krok 15)
8. Integracja z edytorem: panel „AI FAQ" → Generuj z treści → Wstaw do wpisu ✅ (Krok 16)

## Panel „AI FAQ" w edytorze wpisu (Krok 16)
Metabox na ekranie edycji wpisu i strony (`post` / `page`). Bierze **tytuł i treść prosto z edytora**
— także niezapisane zmiany — i układa z nich pary Q&A, które jednym kliknięciem trafiają na koniec treści.

- **Gdzie go szukać:** w edytorze blokowym metaboksy żyją w zwijanej szufladzie **„Meta Boxes" na dole
  ekranu** (zachowanie rdzenia WordPressa, nie wtyczki) — trzeba ją raz rozwinąć.
- **Jak działa:** „Generuj z treści wpisu" → tabela par (każdą można usunąć) → „Wstaw do wpisu"
  wstawia bloki `wp:heading` + `wp:paragraph` **na końcu** treści. Wtyczka **nie zapisuje wpisu za Ciebie**.
- **Nie dubluje Narzędzia FAQ:** pełny warsztat (edycja par, 5 formatów eksportu, JSON-LD) jest na
  ekranie „Narzędzie FAQ"; metabox to szybka ścieżka „artykuł → FAQ w artykule".
- **Bez nowych tras REST** — konsumuje istniejące `/admin/generate-faq` i `/admin/export`.
- Treść wpisu jest przycinana do **6000 znaków** przed wysłaniem do modelu (koszt i limity kontekstu);
  gdy do tego dojdzie, metabox mówi o tym wprost.

## Podstrona generatora (Krok 18)

Wtyczka **sama tworzy podstronę** o slugu `generator-faq` — bo trasa `/faqgenerator` jest wirtualna
(rewrite), więc nie ma jej w *Stronach* i klient nie doda jej do menu. Podstrona zawiera shortcode
`[aifaq_generator]` i jest **świadoma roli**:

| kto | co widzi |
|---|---|
| gość | samo pole pytania (generator RAG) |
| zalogowany właściciel | **6 zakładek**: Generator · Indeksowanie · Historia · **Narzędzie FAQ** · Historia generowań · Ustawienia |

Zakładka **„Narzędzie FAQ"** (nowość K18) to ten sam generator par Q&A i ta sama sekcja eksportu,
co ekran w kokpicie — markup ma **jedno źródło prawdy** (`App\FaqToolPanel::widget()`), więc nie ma
dwóch kopii tych samych identyfikatorów do rozjechania się.

**Gdy z podstroną coś się stanie, wtyczka mówi o tym w kokpicie.** `PublicUi\PageGuard` rozpoznaje
osiem stanów, a `Admin\PageNotice` pokazuje komunikat z działającym przyciskiem:

| stan | co się stało | co robi wtyczka |
|---|---|---|
| `ok` | wszystko gra | komunikat sukcesu z linkiem (zamykalny na stałe) |
| `missing` / `failed` | nie udało się utworzyć | przycisk „Utwórz podstronę" + treść błędu, ponowienie z backoffem |
| `trashed` | trafiła do kosza | przycisk „Przywróć" (slug wraca bez sufiksu `__trashed`) |
| `not_public` | jest szkicem / prywatna | przycisk „Opublikuj" |
| `no_shortcode` | ktoś usunął shortcode z treści | link do edytora |
| `slug_taken` | pod tym adresem jest cudza strona | link do Ustawień |
| `deleted` | **właściciel usunął ją trwale** | przycisk „Utwórz podstronę ponownie" |

> ⚠️ **Podstrona usunięta TRWALE nie wraca sama** — także po deaktywacji i ponownej aktywacji wtyczki.
> To jest **zamierzone**: automat nie ma walczyć z decyzją właściciela. Droga powrotu jest jedna —
> świadome kliknięcie „Utwórz podstronę ponownie" w komunikacie kokpitu.

**Nowe opcje w `wp_options`:** `aifaq_page_state` (stan, 6 kluczy) · `aifaq_page_ok` (tania bramka
trójstanowa) · `aifaq_page_lock` (zamek `ensure()`) · `aifaq_page_notice_dismissed`.
Wszystkie kasowane przy odinstalowaniu — **samej podstrony wtyczka nie kasuje**, bo to treść klienta.

**Nowe hooki:** `admin_notices` (pierwszy we wtyczce) · `admin_post_aifaq_page_fix` (akcje naprawcze,
za `check_admin_referer` + capem) · `trashed_post` / `untrashed_post` / `deleted_post` (reakcja na
zmianę losu podstrony) · `loop_start` (reset flagi jednokrotnego renderu shortcode'u).

## Ograniczenia (znane, świadome)
- **Metabox widzi tylko administrator** (`manage_options`). Redaktor i Autor — czyli role, które na
  typowej stronie faktycznie piszą wpisy — go nie zobaczą, bo trasy REST wtyczki wymagają tego samego
  uprawnienia. Poluzowanie capów jest zaplanowane na Krok 20.
- **Gałąź klasycznego edytora (TinyMCE) nie ma pokrycia testem na żywej instancji** — środowisko dev
  nie ma wtyczki Classic Editor; ta ścieżka jest pokryta wyłącznie testem statycznym.
- **Historia generowań rośnie bez ograniczeń** — każde kliknięcie „Generuj" (również z metaboksu)
  zapisuje wiersz ze snapshotem par. Retencja (`prune()`) to zadanie Kroku 20.
- **„Ponownie wygeneruj" z zakładki na froncie prowadzi do kokpitu** (`wp-admin`), nie przełącza
  zakładki na miejscu — konsekwencja zasady „zero zmian w `assets/js/*`" w Kroku 18.
- **Nonce `wp_rest` nie odświeża się bez przeładowania** — podstrona otwarta przez noc może zwrócić
  403 przy generowaniu; obejście to `F5`. Do Kroku 20.
- **Jakość odpowiedzi bota RAG** przy słabszym dopasowaniu jest niska (jednozdaniowe odpowiedzi,
  fałszywe odmowy) — rozpoznane, naprawa to **Krok 19**.

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
