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
- **Wewnętrzny limiter `rag_rate_limit` jest niedostrojony do realiów dostawcy.** Domyślne
  30 zapytań/**godzinę** na gościa (`RateLimiter::WINDOW = 3600`) przepuszcza wielokrotnie więcej,
  niż wynosi darmowa kwota Gemini (rzędu 20 żądań/**dobę** na model). Kalibracja → Krok 20.
- **`gemini-2.5-pro` i `gemini-2.0-flash` mogą mieć na darmowym kluczu przydział zero.** Kokpit
  oferuje je w wyborze modelu jako równorzędne; klient, który przełączy się na „jakość", może
  dostać błąd na każde pytanie. Oznaczenie modeli bez darmowej kwoty → Krok 20.
- **Reindeks jest synchroniczny.** Krok 19 wprowadził budżet czasowy i tempo (F1-lite), więc duża
  witryna wymaga kilku kliknięć „Zaindeksuj treść"; reindeks w tle (cron) → Krok 20.
- **Ścieżka wycofania (downgrade) nie została przetestowana end-to-end** — patrz akapit niżej.

## Jakość odpowiedzi RAG (Krok 19, v0.22.0)

Krok 19 usunął przyczynę jednozdaniowych odpowiedzi i fałszywych odmów. Przyczyna była jedna:
model `gemini-2.5-flash` **myśli**, a tokeny rozumowania liczą się do `maxOutputTokens`. Przy sufcie
500 potrafiło zostać **5 tokenów na odpowiedź**, więc model oddawał sentinel odmowy mimo pełnego
pokrycia tematu. Zmierzone na żywej witrynie: przed zmianą `thoughts` 117–476 na pytanie, po zmianie
**0**.

**Nowe klucze ustawień:** `rag_threshold_hard` (próg twardy, domyślnie **0.65** — wartość zmierzona,
nie zgadnięta), `rag_thinking_budget` (`0` = myślenie wyłączone, `-1` = dynamiczne, `128–24576`
= jawny budżet), `rag_contact_hint` (dane kontaktowe wstrzykiwane przy częściowym pokryciu).

> **`rag_contact_hint` jest domyślnie PUSTE.** Bez wypełnienia bot przy częściowym pokryciu odeśle
> tylko ogólnie do zakładki Kontakt. Wypełnij je zaraz po instalacji — to krok konfiguracyjny,
> nie opcja.

**Nowe opcje `wp_options`:** `aifaq_index_signature` (autoload `no`) — podpis metody, którą policzono
bazę wektorów; `aifaq_cache_flushed_for` (autoload `yes`) — jednorazowy flush cache'u per wersja.
**Obie kasowane w `uninstall.php`.**

Wtyczka pokazuje w kokpicie **komunikat migracji**, gdy baza wektorów została policzona starszą
metodą — wystarczy kliknąć „Zaindeksuj treść". Doszło też ponawianie żądań przy `429`/`503`
z odczytem opóźnienia z ciała odpowiedzi (Gemini nie wysyła nagłówka `Retry-After`).

> **Downgrade.** Po cofnięciu wtyczki do wersji wcześniejszej niż 0.22.0 — i po każdym uruchomieniu
> „Zaindeksuj treść" na tamtej wersji — uruchom „Zaindeksuj treść" raz jeszcze po powrocie na 0.22.0.
> Baza wektorów jest liczona inną metodą i wtyczka nie ma jak wykryć, że przeliczyła ją starsza wersja.

**Ograniczenie kalibracji:** domyślny próg twardy `0.65` skalibrowano na **jednym** korpusie
(69 fragmentów). Na witrynie o innym profilu treści może wymagać korekty w ustawieniach.

### Filtry rozszerzeń (17, wszystkie pod `function_exists`)

`aifaq_rag_debug` · `aifaq_thinking_budget` · `aifaq_ask_min_tokens` · `aifaq_truncation_guard` ·
`aifaq_topk_filter` · `aifaq_context_order` · `aifaq_system_instruction` · `aifaq_sentinel_strict` ·
`aifaq_embed_task` · `aifaq_http_retry` · `aifaq_index_budget` · `aifaq_threshold_hard` ·
`aifaq_index_pace` · `aifaq_prompt_legacy` · `aifaq_index_complete` · `aifaq_blocked_as_refusal` ·
`aifaq_min_threshold`

Plus dwa zastane sprzed Kroku 19: `aifaq_content_sources`, `aifaq_skip_post` (**razem 19 nazw**).
Ustawienie wszystkich siedemnastu w tryb „wyłączony" odtwarza zachowanie v0.21.1 co do bajtu —
z tego korzysta bench pomiarowy.

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
