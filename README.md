# AI FAQ Generator

Wtyczka WordPress z **generatorem FAQ zawężonym do tematu strony**: gość pyta na
publicznej podstronie `/faqgenerator`, a odpowiedź powstaje **wyłącznie w temacie
treści tej strony** (RAG + embeddingi Gemini) — pytania off-topic są odrzucane.
Do tego **dane strukturalne JSON-LD (FAQPage)** zgodne ze Schema.org.

> **Status: v1.0.0 — produkt domknięty.** Wszystkie Kroki 0–23 zamknięte.
> Zakres ze zlecenia (pkt 1–8) zamknięty od Kroku 16; Krok 23 („domknięcie") zakończony
> 2026-08-03. Etap „audyt" **świadomie zdjęty z zakresu** decyzją właściciela projektu —
> nie został wykonany i nie jest ukryty pod tym numerem wersji.
>
> **Połówka generator (kokpit)** — gotowa do oddania klientowi: `Faq\FaqGenerator` (temat→pary Q&A
> jako structured JSON) · REST `/admin/generate-faq` · ekran „Narzędzie FAQ" · eksport do 5 formatów
> (`Faq\Exporter`, w tym JSON-LD `FAQPage`) · Historia generowań w dwóch miejscach ·
> metabox „AI FAQ" w edytorze wpisu.
>
> **Połówka RAG (front)** — `/faqgenerator` + shortcode `[aifaq_generator]` + automatyczna podstrona;
> Indexer z **kaskadą trzech źródeł treści + filtrem balastu** (K17: `post_content` +
> postmeta/ACF + crawl-jako-gość, składane w `CompositeContentSource`) · Retriever + TopicGuard
> + Answerer · dziennik pytań gości.
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
> **Trzy wydania spoza numeracji Kroków (v0.24.0 – v0.26.0):** SEO podstrony dostrojone do
> witryny (`Seo\SiteProfile`) · odinstalowanie bez śladu, pilnowane testem-strażnikiem ·
> audyt bezpieczeństwa całej wtyczki (7 poprawek).
>
> **Krok 21 (v0.27.0, v0.28.0):** zabezpieczenie podstrony — nagłówki bezpieczeństwa i **CSP
> z nonce, bez `unsafe-inline`** na trasie standalone, zamknięty prompt injection z treści strony.
>
> **Krok 22 (v0.29.0):** dziesięć długów sprzed domknięcia — reindeks wznawia się sam przez
> WP-Cron, miernik zużycia sufitu na Dashboardzie, limiter na `/admin/generate-faq`, cache odmów
> off-topic, realny `score` przy trafieniu cache (`AIFAQ_DB_VERSION` 4→5).
>
> **Krok 23 (v0.30.0 – v1.0.0) — ZAMKNIĘTY:** debug+split (dekompozycja `RestController`) ·
> RWA · testy segmenty · testy obciążeniowe (`DB_VERSION` 5→6) · testy while · dokumentacja ·
> **Kompatybilność Batch** (README zderzone z kodem: 107 twierdzeń, 8 rozjazdów naprawionych) ·
> **LICENSE / `readme.txt`** · **domknięcie v1.0.0**. Szczegóły niżej, w sekcji „Kroki 21–23".

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
src/Rag/  (Krok 6) RagService — potok pytania gościa · Retriever · TopicGuard · Answerer
          · RateLimiter (limit gościa + dobowy sufit witryny)
src/Admin/     Menu + FaqToolPage + views/ (Dashboard, Generator, Narzędzie FAQ, Ustawienia, Historia)
          IndexController — indeksowanie, zamek reindeksu, wznowienie przez WP-Cron (K22)
          (K16) PostMetaBox · (K18) PageNotice · komunikaty: IndexNotice, MenuNotice,
          EditorNotice, FreshnessNotice (K23 e2 — sygnał „treść zmieniła się od indeksowania")
src/Rest/ (Krok 7) 15 tras `aifaq/v1` — publiczne `/ask` + 14x `/admin/*`.
          Rozbite w K23 etap 1 (RestController 1385 -> 427 linii, dziś 378): fasada RestController ·
          RouteRegistrar (rejestracja tras) · AskService · AdminService · GeneratorService ·
          PublishService · GuestIdentity (ip_hash/proxy) · PairsInput (normalizacja par Q&A)
src/PublicUi/ (Krok 8) GeneratorPage · (K17) Shortcode — `[aifaq_generator]` + automatyczna podstrona
              (K18) PageGuard — stan podstrony, samo-naprawa, zamek atomowy
              (K20) MenuGuard — pozycja w menu nawigacji, adopcja cudzego linku
              (v0.24.0) PageSchema — JSON-LD podstrony · (K21) SecurityHeaders — nagłówki + CSP
src/App/  (Krok 9-10) AppShell + HistoryPanel · (K15) GenerationsPanel
          (K18) FaqToolPanel — JEDNO źródło markupu narzędzia FAQ (kokpit + front)
src/Index/ (Krok 5, rozbud. K17) Chunker, Indexer, EmbeddingBatcher + kaskada źródeł: WpContentSource,
          PostMetaContentSource, RenderedContentSource, CrawlQueue, BoilerplateFilter, CompositeContentSource
src/Faq/  (Krok 11) FaqGenerator — kreatywny generator par Q&A (osobny od RAG)
          (Krok 14) Exporter — pary Q&A → 5 formatów eksportu (HTML/Gutenberg/Elementor/JSON/JSON-LD)
          (K17) PublicFaq — FAQ opublikowane na podstronie (opcja + snapshot poprzedniej wersji)
src/Seo/  (v0.24.0) SiteProfile — temat witryny wyprowadzony z bazy wiedzy, liczony przy indeksowaniu
```
Tabele (**`AIFAQ_DB_VERSION` = 6**): `wp_aifaq_knowledge` (fragmenty+wektory),
`wp_aifaq_qa_log` (dziennik pytań gości), `wp_aifaq_cache` (dedup odpowiedzi +
kolumna `score` od v0.29.0), `wp_aifaq_faq` (**uśpiona — patrz niżej**),
`wp_aifaq_generations` (historia generowań + snapshot par).
`qa_log` i `generations` dostały w Kroku 23 etap 4 złożony `KEY created_id (created_at,id)`
pod paginację (`ORDER BY created_at DESC, id DESC` na samym `created_at` robił filesort —
do **31× wolniej**, zmierzone na 20 000 wierszy) — to właśnie ta migracja podniosła
`AIFAQ_DB_VERSION` z 5 na 6.
Migracja schematu jest automatyczna (porównanie `AIFAQ_DB_VERSION` na `plugins_loaded`).

> **`wp_aifaq_faq` nie jest przez nic czytana.** Krok 23 etap 5 usunął ostatni martwy kod,
> który jej dotykał (`Data\FaqRepository`); opublikowane FAQ idzie przez opcję
> `aifaq_public_faq` i klasę `Faq\PublicFaq`. **Tabela i stała `Schema::T_FAQ` zostają
> świadomie** — skasowanie ich oznaczałoby migrację z `DROP TABLE` tuż przed v1.0.0.

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
  ⚠️ Ekran „Narzędzie FAQ" wymaga `manage_options` — **dla Redaktora i Autora metabox jest
  jedynym dostępem do generatora** (patrz „Kto co widzi").
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
| `ok` | wszystko gra | „Otwórz podstronę «Generator FAQ»" + „Nie pokazuj więcej" (zamykalny na stałe) |
| `missing` | jeszcze nie powstała | „Utwórz podstronę teraz" |
| `failed` | nie udało się utworzyć | „Spróbuj ponownie" + treść błędu i licznik „(prób: N)"; ponowienie z backoffem, auto-stop po 3 próbach |
| `trashed` | trafiła do kosza | „Przywróć podstronę" (slug wraca bez sufiksu `__trashed`) |
| `not_public` | jest szkicem / prywatna | „Opublikuj podstronę" |
| `no_shortcode` | ktoś usunął shortcode z treści | „Otwórz w edytorze" |
| `slug_taken` | pod tym adresem jest cudza strona | „Otwórz ustawienia" |
| `deleted` | **właściciel usunął ją trwale** | „Utwórz podstronę ponownie" |

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
- **Nonce `wp_rest` wygasa i nie odnawia się sam na froncie.** W kokpicie metabox czyta nonce
  **przy każdym wywołaniu** z `wpApiSettings` (odświeża go Heartbeat), a od v0.29.0 cztery pliki
  JS rozpoznają 401/403 osobno i mówią wprost, że wygasła sesja — `indexer.js` własnym stringiem
  `sessionExpired`, pozostałe komunikatem WordPressa z `data.message` — zamiast generycznego
  „coś poszło nie tak". Automatycznego odnowienia na podstronie **nadal nie ma** — obejście to `F5`.
- **Wtyczka NIE UTWORZY menu nawigacji za klienta.** Jeżeli motyw nie ma żadnego menu przypiętego do
  lokalizacji, wtyczka **wyłącznie o tym informuje** w kokpicie. Powód jest zmierzony, nie ostrożnościowy:
  wiele motywów (w tym motyw Czarodziejskiego Dworku) renderuje nawigację funkcją `fallback_cb`,
  która **przestaje działać w chwili przypięcia jakiegokolwiek menu** — automatyczne utworzenie menu
  skasowałoby klientowi całą widoczną nawigację. Link dokładamy wyłącznie do menu, które **już istnieje**.
- **Motywy blokowe (FSE) są poza zakresem.** Nawigację renderuje tam blok `core/navigation`
  (`wp_navigation`), który klasycznych menu nie czyta; wtyczka rozpoznaje taki motyw i mówi wprost,
  że linku nie doda (`PublicUi\MenuGuard`, reguła zerowa). **Nadal otwarte** — obsługa
  `wp_navigation` nie weszła ani w Kroku 21, ani w 22; do decyzji po v1.0.0.
- **Po zmianie motywu pozycja zostaje w menu motywu starego** — wtyczka tego nie diagnozuje.
- **Limiter gościa jest „best-effort", nie atomowy.** Bez zewnętrznego cache'u obiektowego (Redis,
  Memcached) odczyt i zapis licznika nie są jedną operacją, więc równoległe żądania mogą przepuścić
  pojedyncze zapytanie ponad limit. Twardszym zabezpieczeniem kwoty jest **dobowy sufit witryny**,
  ale i on nie jest w pełni atomowy — patrz dług **D2** niżej.
- **Właściciel podlega tym samym bramkom co gość.** Pytanie zadane z panelu idzie tą samą trasą
  `POST /ask`, więc przechodzi przez limiter gościa i przez dobowy sufit witryny — **żadna z tych
  dwóch bramek nie zwalnia `manage_options`** (`Rag\RagService::budget_allows()` sprawdza wyłącznie,
  czy sufit jest włączony i czy licznik go nie dobił; `Rag\RateLimiter::allow()` — wyłącznie limit
  i licznik kubełka). Pytania właściciela realnie zjadają pulę **i realnie potrafią go odbić**.
  Poza `/ask` limitowana jest jeszcze jedna trasa: `POST /admin/generate-faq` (**10/h per
  użytkownik**). Pozostałe trasy `/admin/*` własnego limitu nie mają — w całym kodzie istnieją
  dokładnie **dwa** limitery.
- **Włączenie „zaufanego proxy" jednorazowo resetuje bieżące limity** — zmienia się źródło adresu IP,
  więc `ip_hash` każdego gościa jest liczony na nowo. Świadoma nieciągłość, nie błąd.
- **Ścieżka wycofania (downgrade) nie została przetestowana end-to-end** — patrz akapit niżej.
  Krok 20 wykonał wyłącznie „tanią próbę" (podmiana podpisu indeksu → powrót na wariant legacy
  + stan `stale` w kokpicie); pełnej próby z fizycznym cofnięciem plików **nadal nie było**.
- **Retriever liczy podobieństwo liniowo (`O(N)`, brak ANN).** Zmierzone w Kroku 23 etap 4:
  czas na rekord jest płaski aż do 50 000 fragmentów, ale całość to wtedy **5,8 s** na pytanie.
  Do kilku tysięcy fragmentów bez znaczenia, wyżej boli. Od etapu 5 wtyczka **ostrzega w kokpicie
  po przekroczeniu 5000 fragmentów** (`Retriever::SCALE_WARN_CHUNKS`) zamiast milczeć.
- **Dobowy sufit kosztu nie jest atomowy** (dług **D2**). Realna atomowość tylko przy trwałym
  cache obiektowym (Redis/Memcached) — mitygacja `wp_cache_add`+`wp_cache_incr` weszła w Kroku 23
  etap 1. Bez takiego cache'u zmierzono przekroczenie: limit 10, 25 równoległych żądań → 25
  przyjętych. Pełna naprawa wymaga migracji schematu i **jest świadomie odłożona na po v1.0.0**.
- **Gość nie widzi źródeł odpowiedzi** (dług **D5**). Właściciel je widzi. Pokazanie ich gościowi
  to zmiana kontraktu REST `/ask`, czyli nowa funkcja, nie bugfix — **decyzja odłożona**.
- **Brak wstrzykiwania zależności w `GeneratorService`, `PublishService`, `CrawlQueue`**
  (dług **D7**). Refaktor tuż przed v1.0.0 uznany za zły moment; ta sama logika stoi za
  pozostawieniem grubych klas (`RagService`, `CrawlQueue`, `MenuGuard`, `PageGuard`,
  `GeminiProvider`, `Settings`) w obecnej postaci.
- **Retencja dziennika i historii jest opt-in** — domyślnie wtyczka **nie kasuje nic**.
  Od Kroku 23 etap 5 mówi o tym wprost po przekroczeniu **50 000 wpisów**, zamiast rosnąć w ciszy.

## Kroki 21–23 — droga do v1.0.0

### Bezpieczeństwo podstrony (Krok 21, v0.27.0 + v0.28.0)

`PublicUi\SecurityHeaders` rozpoznaje **dwa różne poziomy zaufania** i wysyła dwa zestawy nagłówków:
trasa standalone `/faqgenerator` to w 100% nasz dokument (CSP z **nonce zamiast `unsafe-inline`**
w `script-src`; `style-src` to samo `'self'`), a `/generator-faq` żyje wewnątrz motywu klienta
i dostaje zestaw łagodniejszy — inaczej CSP łamałaby cudzy motyw. Wcześniej obie trasy nie
wysyłały **żadnego** nagłówka bezpieczeństwa.

> **Granica obowiązywania.** „Bez `unsafe-inline`" dotyczy ścieżki normalnej. Gdy nonce z jakiegoś
> powodu jest pusty, `SecurityHeaders.php:183` **świadomie schodzi na `'self' 'unsafe-inline'`** —
> lepszy słabszy CSP niż CSP, który wygasi własny panel na białą stronę. To zabezpieczenie
> degradacyjne, nie luka.

Strażnik `tests/krok21-csp-inline-guard-test.php` pilnuje statycznie, żeby żaden plik renderujący
panel właściciela nie dostał atrybutu `onclick=` / `style=` — przeglądarka blokuje je bezwzględnie
i **po cichu**, bez błędu PHP, widoczne dopiero w konsoli klienta. Zamknięty też prompt injection
wstrzykiwany **treścią indeksowanej strony** (`Rag\Answerer`).

### Trzy wydania spoza numeracji Kroków (v0.24.0 – v0.26.0)

**v0.24.0** — SEO podstrony dostrojone do witryny
(`Seo\SiteProfile` wyprowadza temat z bazy wiedzy, jednym wywołaniem API, wyłącznie przy
indeksowaniu); **v0.25.0** — odinstalowanie bez śladu plus test-strażnik, który nie pozwoli tej
luce otworzyć się ponownie (lista kluczy w `uninstall.php` cicho zostawała w tyle za kodem);
**v0.26.0** — audyt bezpieczeństwa całej wtyczki, 7 poprawek (m.in. publiczne `/ask` pisało do
bazy z pominięciem limitera przy trafieniu w cache).

### Długi sprzed domknięcia (Krok 22, v0.29.0)

Dziesięć pozycji wybranych wprost z listy „co jeszcze warto zrobić przed domknięciem":
**reindeks wznawia się sam** przez WP-Cron, gdy przerwie go budżet czasu (`aifaq_reindex_continue`
— właściciel dużej witryny nie klika „Zaindeksuj" N razy); **miernik zużycia sufitu** na
Dashboardzie (wcześniej limit był niewidoczny do chwili wyczerpania); **limiter na
`/admin/generate-faq`** (10/h — dotąd generator FAQ nie miał żadnego throttlingu, a zjada tę samą
pulę co `/ask`); **cache odmów off-topic** (powtórzone pytanie spoza tematu nie płaci embeddingu
drugi raz); **realny `score` przy trafieniu cache** (dotąd log zapisywał zmyślone `1.0`) —
`AIFAQ_DB_VERSION` 4→5, migracja addytywna.

### Domknięcie v1.0.0 (Krok 23, v0.30.0 – v1.0.0) — ZAMKNIĘTY

| etap | co zrobił |
|---|---|
| 1. Debug + split ✅ | audyt sześcioma subagentami → 10 potwierdzonych napraw (m.in. **kosz/usunięcie wpisu nie czyścił bazy RAG** — gość dostawał dezinformację z linkiem do 404), dekompozycja `RestController`, publikacja FAQ zawężona do Redaktora |
| 2. RWA ✅ | dwa audyty „na realnym świecie": tytuł dołączany do **każdego** fragmentu po podziale (dotąd kotwiczył tylko `chunk_index=0`), sygnał „treść zmieniła się od indeksowania", 5 poprawek P1 przed wydaniem |
| 3. Testy segmenty ✅ | 11 segmentów, **157 nowych asercji**; złapany bug produkcyjny: generator nie przycinał świeżo wygenerowanych par przed zapisem |
| 4. Testy obciążeniowe ✅ | 7 segmentów; lock reindeksu był TOCTOU (**3 z 10** procesów zdobywało go naraz → 1/10), paginacja bez pasującego indeksu (**do 31× szybciej**), `DB_VERSION` 5→6 |
| 5. Testy while ✅ | przejście całego cyklu życia produktu **na żywo**, 151 asercji; `uninstall.php` zostawiał jeden z dwóch cronów, skutki zapisu ustawień siedziały w `if ( is_admin() )` (nowy slug dawał 404 bezterminowo); wsad 8 napraw, w tym **zamek publikacji FAQ** (HTTP 409 zamiast cichego nadpisania) |
| 6. Dokumentacja ✅ | pięć dokumentów PDF dla klienta i informatyka (**poza tym repo**, w katalogu `instrukcje/` obok wtyczki) + 37 zrzutów ekranu i 5 diagramów Draw.io |
| 7. Kompatybilność Batch ✅ | całe README zderzone z wykonywalnym kodem: **107 twierdzeń**, z tego 8 mijało się z implementacją — wszystkie naprawione. Najpoważniejsze: tabela uprawnień obiecywała Redaktorowi ekran „Narzędzie FAQ", który wymaga `manage_options`; README zaniżało liczbę filtrów (19 zamiast 24); zdanie „właściciel nie jest blokowany przez sufit" było nieprawdą |
| 8. LICENSE / readme.txt ✅ | `LICENSE` z dosłownym tekstem GNU GPL v2 (339 linii) i `readme.txt` dla klienta — z ujawnieniem usługi zewnętrznej (Google Gemini), modelu BYOK i dziennika z hashem IP |
| 9. Audyt ❌ | **NIE WYKONANY — świadomie zdjęty z zakresu** decyzją właściciela projektu (2026-08-03). Nie jest to etap „zaliczony po cichu": osobnego audytu przed v1.0.0 nie było. Warto pamiętać, że pełny audyt bezpieczeństwa całej wtyczki przeszedł wcześniej, w **v0.26.0**, a etapy 1–5 tego Kroku były w praktyce ciągiem audytów (red team, wydajność, architektura, obciążenie, cykl życia na żywo) |
| 10. Domknięcie ✅ | `AIFAQ_VERSION` → **1.0.0**, tag i release |

**Testy.** W `tests/` leży **60 zestawów** spinanych własnym runnerem (`zasoby/run-tests.sh` —
**poza tym repo**, w katalogu roboczym projektu obok wtyczki): **60/60 przechodzi**. Runner nie
jest PHPUnitem — to zwykłe skrypty PHP z atrapami WordPressa, uruchamiane bez bazy danych.
Osobno `tests/load/` (**14 plików**, Krok 23 etap 4) wymaga żywego środowiska i **nie wchodzi**
do tej liczby.

> **Zasada z Kroku 23:** asercja ilościowa zapisywana jest jako `=== N`, nigdy `> 0`.
> Słabe asercje przeżyły w tym projekcie dwa Kroki z prawdziwym bugiem pod spodem.

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
| metabox „AI FAQ" w edytorze wpisu, `POST /admin/generate-faq`, `POST /admin/export` | **`publish_posts`** (Redaktor, Autor) |
| Publikacja/cofnięcie publikacji FAQ na publicznej podstronie: `POST /admin/faq/publish`, `POST /admin/faq/unpublish` | **`edit_others_posts`** (Redaktor — Autor generuje/eksportuje, ale nie publikuje) |
| **Ekran „Narzędzie FAQ" w kokpicie**, **wszystkie zakładki panelu na podstronie**, Ustawienia, klucz API, indeksowanie, dziennik pytań gości, historia generowań i **wszystkie pozostałe trasy `/admin/*`** | `manage_options` (Administrator) |
| `POST /ask` (pytanie gościa) | publiczne |

Administrator przechodzi zawsze. Cap narzędzia zmienia filtr `aifaq_tool_capability` — obejmuje
**metabox w edytorze** (`Admin\PostMetaBox`, `Admin\EditorNotice`) i **trasy REST** narzędzia,
więc te dwa nie mają jak się rozjechać.

> ⚠️ **Redaktor i Autor sięgają po narzędzie FAQ wyłącznie przez metabox w edytorze wpisu.**
> Ekran „Narzędzie FAQ" w kokpicie jest rejestrowany na `Menu::CAPABILITY` = `manage_options`
> (`src/Admin/Menu.php:78`), a zakładki panelu na podstronie stoją za `App\AppShell::is_owner()`,
> czyli też `manage_options` (`src/App/AppShell.php:41`). Filtr `aifaq_tool_capability` **na żadne
> z tych dwóch miejsc nie działa**. To decyzja kontraktu Kroku 20, pilnowana asercją
> „`Menu::CAPABILITY` nietknięte" w `tests/krok20-capy-test.php` — nie przeoczenie.

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

Plus dwa zastane sprzed Kroku 19: `aifaq_content_sources`, `aifaq_skip_post`.
Ustawienie wszystkich siedemnastu w tryb „wyłączony" odtwarza zachowanie v0.21.1 co do bajtu —
z tego korzysta bench pomiarowy.

Późniejsze Kroki dołożyły **pięć kolejnych, spoza tej listy**:
`aifaq_tool_capability` (`src/Rest/RestController.php`, `src/Admin/EditorNotice.php` — cap
narzędzia) · `aifaq_security_headers` (`src/PublicUi/SecurityHeaders.php`) ·
`aifaq_jsonld_node` (`src/PublicUi/PageSchema.php`) · `aifaq_widget_heading`
(`src/PublicUi/GeneratorPage.php`) · `aifaq_nocache_for_owner` (`src/PublicUi/Shortcode.php`).

**Razem w kodzie jest 24 filtrów `aifaq_*`**, nie 19 — siedemnaście z Kroku 19, dwa zastane
i pięć powyższych.

## Wymagania (dev)
- WordPress **7.x** (środowisko dev: **7.0.2**), minimum obsługiwane **6.4** · PHP 8.x
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
**GPLv2** — wyłącznie wersja 2, **bez klauzuli „or later"** (decyzja świadoma). Zadeklarowana
w dwóch miejscach tym samym ciągiem: nagłówek `ai-faq-generator.php` (`License: GPLv2`)
i `readme.txt`. `License URI` w obu: `https://www.gnu.org/licenses/gpl-2.0.html`.

- **`LICENSE`** — pełny, dosłowny tekst GNU GPL v2 (339 linii). Sam dokument licencji zostaje
  nietknięty, bo GPL tego wymaga („changing it is not allowed"). **Tekst licencji jest ten sam
  dla wariantu „only" i „or later"** — różnicę niesie wyłącznie nota w nagłówku programu,
  dlatego to tam, a nie w `LICENSE`, zapisano brak klauzuli „or later".
- **Skutek wyboru „only":** kodu tej wtyczki nie da się w przyszłości połączyć z kodem na
  licencji GPLv3. Przy wtyczce dystrybuowanej wyłącznie do jednego klienta nie ma to znaczenia
  praktycznego; przy ewentualnej publikacji w katalogu WordPress.org byłoby odstępstwem od
  tamtejszej konwencji („GPLv2 or later").
- **Nota copyright** — `Copyright (C) 2026 mtsle`, umieszczona w nagłówku
  `ai-faq-generator.php`, czyli tam, gdzie każe ją umieścić aneks GPL („How to Apply These
  Terms"), a nie wewnątrz pliku licencji.
- **Kod stron trzecich: brak.** Cała zawartość `assets/` (5 plików CSS, 7 JS) jest własna —
  zero bibliotek, fontów i ikon, więc GPL obejmuje całość bez wyjątków.

`readme.txt` jest dokumentem **dla klienta, nie dla katalogu WordPress.org** — stąd brak pól
`Contributors`, `Donate link` i `Tags`, oraz polska proza przy angielskich nazwach sekcji.

## Dokumentacja dla klienta

Instrukcje dla odbiorcy (pięć dokumentów PDF: instrukcja wprowadzająca dla klienta, instrukcja
dla informatyka, wymagania niefunkcjonalne, format danych, instrukcje systemowe) powstały
w **Kroku 23 etap 6** i leżą **poza tym repozytorium** — w katalogu `instrukcje/` w folderze
roboczym projektu, razem ze źródłami HTML i diagramami Draw.io. Repozytorium zawiera wyłącznie
kod wtyczki; ten `README.md` jest dokumentacją **dla programisty**, nie dla klienta.
