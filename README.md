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
> **Krok 19 (v0.22.0):** jakość odpowiedzi bota RAG — przyczyną „biednych" odpowiedzi był budżet
> rozumowania modelu zjadający limit tokenów wyjścia; progi skalibrowane pomiarem.
>
> **Krok 20 (v0.23.0):** dostępność u klienta — **link do generatora w menu nawigacji**, capy dla
> Redaktora/Autora, retencja historii, kalibracja limitera + **dobowy sufit witryny**, parser limitów
> dostawcy po `quotaId` i naprawa wyłącznika obwodu, wykrycie zagłodzenia workerów PHP przy crawlu.
>
> Dalej: **Krok 21** — v1.0.0 (audyt, RWA, instrukcja wdrożenia).

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

> Cztery pozycje z tej listy **zamknął Krok 20 (v0.23.0)** i zostały stąd skreślone:
> metabox tylko dla administratora · historia rosnąca bez ograniczeń · niedostrojony limiter ·
> modele z przydziałem zero. Opis tego, co je zastąpiło, jest w sekcji
> „Dostępność, uprawnienia i limity (Krok 20)".

- **Gałąź klasycznego edytora (TinyMCE) nie ma pokrycia testem na żywej instancji** — środowisko dev
  nie ma wtyczki Classic Editor; ta ścieżka jest pokryta wyłącznie testem statycznym.
- **„Ponownie wygeneruj" z zakładki na froncie prowadzi do kokpitu** (`wp-admin`), nie przełącza
  zakładki na miejscu — konsekwencja zasady „zero zmian w `assets/js/*`" w Kroku 18.
- **Nonce `wp_rest` nie odświeża się bez przeładowania** — podstrona otwarta przez noc może zwrócić
  403 przy generowaniu; obejście to `F5`. Do Kroku 21.
- **Reindeks jest synchroniczny.** Krok 19 wprowadził budżet czasowy i tempo (F1-lite), więc duża
  witryna wymaga kilku kliknięć „Zaindeksuj treść"; reindeks w tle (cron) → Krok 21.
- **Wtyczka NIE UTWORZY menu nawigacji za klienta.** Jeżeli motyw nie ma żadnego menu przypiętego do
  lokalizacji, wtyczka **wyłącznie o tym informuje** w kokpicie. Powód jest zmierzony, nie ostrożnościowy:
  wiele motywów (w tym motyw Czarodziejskiego Dworku) renderuje nawigację funkcją `fallback_cb`,
  która **przestaje działać w chwili przypięcia jakiegokolwiek menu** — automatyczne utworzenie menu
  skasowałoby klientowi całą widoczną nawigację. Link dokładamy wyłącznie do menu, które **już istnieje**.
- **Motywy blokowe (FSE) są poza zakresem.** Nawigację renderuje tam blok `core/navigation`
  (`wp_navigation`), który klasycznych menu nie czyta; wtyczka rozpoznaje taki motyw i mówi wprost,
  że linku nie doda. Obsługa `wp_navigation` → Krok 21.
- **Po zmianie motywu pozycja zostaje w menu motywu starego** — wtyczka tego nie diagnozuje.
- **Limiter gościa jest „best-effort", nie atomowy.** Bez zewnętrznego cache'u obiektowego (Redis,
  Memcached) odczyt i zapis licznika nie są jedną operacją, więc równoległe żądania mogą przepuścić
  pojedyncze zapytanie ponad limit. Twardym zabezpieczeniem kwoty jest **dobowy sufit witryny**, nie ten licznik.
- **Ścieżka administratora nie jest limitowana** — limiter i sufit dotyczą gości. Właściciel
  **zwiększa** licznik dobowy (jego pytania realnie zjadają pulę), ale nie jest przez niego blokowany.
- **Włączenie „zaufanego proxy" jednorazowo resetuje bieżące limity** — zmienia się źródło adresu IP,
  więc `ip_hash` każdego gościa jest liczony na nowo. Świadoma nieciągłość, nie błąd.
- **Ścieżka wycofania (downgrade) nie została przetestowana end-to-end** — patrz akapit niżej.
  Krok 20 wykonał wyłącznie „tanią próbę" (podmiana podpisu indeksu → powrót na wariant legacy
  + stan `stale` w kokpicie); pełna próba z fizycznym cofnięciem plików → Krok 21.

## Dostępność, uprawnienia i limity (Krok 20, v0.23.0)

### Link do generatora w menu nawigacji

Po aktywacji wtyczka **dokłada pozycję „Generator FAQ"** do menu przypiętego do lokalizacji
nawigacyjnej motywu (kolejność preferencji: `primary` → `main` → `header` → `menu-1` → `top`).
Bez tego gość nie miał jak trafić na podstronę generatora — istniała, ale nie prowadził do niej
żaden odnośnik.

Zasady, które warto znać:

- **Wtyczka nigdy nie tworzy menu ani go nie przypina** — gdy menu nie ma, pokazuje komunikat w kokpicie
  (powód w „Ograniczeniach").
- **Cudzej pozycji nie kasujemy.** Jeżeli link do podstrony dodał wcześniej klient ręcznie, wtyczka
  go **adoptuje** i oznacza jako nieswój (`owned = '0'`) — deaktywacja go nie tknie.
- **Deaktywacja kasuje pozycję**, ale tylko tę, którą wtyczka **sama utworzyła**.
- **Ręczne usunięcie linku jest respektowane na stałe** — nie wraca ani po odświeżeniu, ani po
  reaktywacji. Przywraca go wyłącznie świadome kliknięcie „utwórz ponownie" w komunikacie kokpitu.
- **Wyłączenie przełącznika „Link w menu" nie kasuje istniejącej pozycji** — automat przestaje się
  nią tylko interesować. Kasuje ją dopiero deaktywacja.
- **Odinstalowanie wtyczki bez wcześniejszej deaktywacji** też sprząta pozycję (znowu: tylko własną).

### Kto co widzi (model uprawnień)

| element | wymagane uprawnienie |
|---|---|
| narzędzie „Narzędzie FAQ", metabox „AI FAQ" w edytorze, `POST /admin/generate-faq`, `POST /admin/export` | **`publish_posts`** (Redaktor, Autor) |
| Ustawienia, klucz API, indeksowanie, dziennik pytań gości, historia generowań i **wszystkie pozostałe trasy `/admin/*`** | `manage_options` (Administrator) |
| `POST /ask` (pytanie gościa) | publiczne |

Administrator przechodzi zawsze. Cap narzędzia zmienia filtr `aifaq_tool_capability` — działa
jednocześnie na interfejs i na trasę REST, więc nie da się ich rozjechać.

### Nowe ustawienia

| ustawienie | domyślnie | co robi |
|---|---|---|
| **Link w menu** (`menu_link_enabled`) | włączony | dokładanie pozycji do menu |
| **Lokalizacja menu** (`menu_location`) | auto | wymuszenie konkretnej lokalizacji motywu |
| **Etykieta** (`menu_label`) | „Generator FAQ" | tekst pozycji (do 60 znaków) |
| **Historia: ile wierszy trzymać** (`generations_keep_rows`) | **0 = nie kasuj nic** | retencja historii generowań |
| **Historia: ile dni trzymać** (`generations_keep_days`) | **0 = nie kasuj nic** | j.w., wymiar niezależny |
| **Okno limitu** (`rag_rate_window`) | godzina | godzina albo doba |
| **Limit pytań na gościa** (`rag_rate_limit`) | **10** (było 30) | w oknie jak wyżej |
| **Dobowy sufit witryny** (`rag_daily_budget`) | **12** | łączna liczba pytań na dobę; `0` = wyłączony (klucz płatny) |
| **Zaufany proxy** (`rag_trusted_proxy`) | wyłączony | czytaj IP z `CF-Connecting-IP` / `X-Forwarded-For` |

> **Retencja jest opt-in.** Obie wartości domyślne to `0`, czyli „nie kasuj nic". Włączenie kasuje
> wiersze **trwale**, bez kosza.

> **Dlaczego sufit dobowy.** Darmowy przydział Gemini to **20 żądań na dobę na model** (zmierzone
> prosto z API). Bez sufitu jeden bot wyczerpywał pulę do południa i wszyscy kolejni goście
> dostawali błąd. Pule `generateContent` i `embedContent` są **odrębne**.

### Limity dostawcy — rozróżnienie doby od minuty

Wtyczka czyta z odpowiedzi 429 pole `quotaId` i rozróżnia limit **dobowy** od **minutowego**:
przy dobowym **nie ponawia w ogóle** (podawane przez API `retryDelay: 8s` jest wtedy mylące — pula
wraca dopiero następnego dnia) i wycisza dostawcę na godzinę; przy minutowym ponawia z opóźnieniem
z odpowiedzi. Wyłącznik obwodu jest liczony **osobno dla każdej puli i modelu**.

### Crawl: zagłodzenie procesów PHP

Jeżeli pobieranie stron kończy się serią timeoutów, wtyczka wykonuje jedną sondę i mówi wprost,
czy problem to **za mała liczba procesów PHP** (witryna nie obsługuje żądania do samej siebie),
czy **strony są nieosiągalne**. Nieudane adresy trafiają na listę „do ponowienia" (do 3 prób,
nie częściej niż raz na godzinę), a na Dashboardzie jest przycisk „Ponów nieudane strony".

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
