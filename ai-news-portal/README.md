# AI News Portal

Wtyczka WordPress, która **prowadzi portal wiedzy o psach bez udziału człowieka**: sama pobiera
wpisy z kanałów RSS, odsiewa te nie na temat, przepisuje pozostałe modelem Gemini na własny,
samodzielny artykuł i publikuje go w **Centrum Wiedzy** pod adresem `/centrum-wiedzy/`.
Właściciel strony wkleja własny klucz API i nie musi robić nic więcej.

**Status:** numer wydania stoi w nagłówku pliku `ai-news-portal.php` (pole `Version`) — nie jest
powtarzany w tym dokumencie, żeby nie mógł się z nim rozjechać.

Wtyczka jest **samodzielna**: nie ma żadnej zależności od drugiej wtyczki z tej samej paczki
(AI FAQ Generator), nie dzieli z nią kodu, opcji ani tabel.

---

## Czego ta wtyczka NIE robi

Pięć granic, które warto znać, zanim zacznie się szukać usterki tam, gdzie jej nie ma.

1. **Nie dodaje linku do menu nawigacji.** Wtyczka nigdy nie dotyka menu witryny. Odnośnik
   do Centrum Wiedzy dodaje się ręcznie: **Wygląd → Menu**, metabox **„Artykuły"**, zakładka
   **„Zobacz wszystko"**, pozycja **„Centrum Wiedzy"** (jeśli metaboksu nie widać, trzeba go
   włączyć w **Opcjach ekranu**).
2. **Nie tworzy żadnej Strony WordPressa.** Archiwum `/centrum-wiedzy/` to archiwum typu treści,
   powstaje samo przy aktywacji. Jeśli **w chwili aktywacji** istnieje już Strona o adresie
   `centrum-wiedzy`, wtyczka pokaże w kokpicie jednorazowe ostrzeżenie o kolizji — rozstrzygnięcie
   należy do właściciela. Strona założona później nie wywoła żadnego komunikatu.
3. **Nie pracuje bez ruchu na stronie.** Automat opiera się na WP-Cron, a ten w WordPressie rusza
   przy żądaniach HTTP. Witryna bez odwiedzin nie opublikuje nic, choćby harmonogram był ustawiony.
4. **Aktualizacja plików nie odpala aktywacji.** WordPress uruchamia hak aktywacji tylko przy
   włączeniu wtyczki, więc struktura tabeli uzgadniana jest wyłącznie wtedy. Po podmianie plików
   na wersję zmieniającą tabelę trzeba **wyłączyć i włączyć wtyczkę**. Dwie rzeczy domykają się
   same przy zwykłym żądaniu i nie wymagają reaktywacji: **harmonogram**
   (`Plugin::ensure_schedule()`) i **brakujące kategorie** (`Plugin::ensure_topics()`).
5. **Nie pobiera zdjęć ze stron źródłowych.** Powód jest prawny, nie techniczny: cudze zdjęcia
   mają swoje licencje. Karta w archiwum pokazuje **zdjęcie kategorii** z paczki wtyczki,
   a artykuł nie ma obrazka wyróżniającego (typ treści nie obsługuje `thumbnail`).

---

## Pierwsze uruchomienie w pięciu krokach

1. **Wgraj i włącz.** Katalog `ai-news-portal` do `wp-content/plugins/`, potem **Wtyczki → Włącz**.
   Przy aktywacji powstają: tabela materiałów, typ treści z archiwum `/centrum-wiedzy/`,
   **siedem kategorii** i **jeden artykuł demo**, żeby archiwum nie było puste. Adres działa
   od razu, bez zapisywania Bezpośrednich odnośników.
2. **Wklej klucz API.** **AI News Portal → Ustawienia**, pole *Klucz API Gemini*. Bez klucza
   przycisk „Opublikuj teraz" jest nieaktywny. Przy okazji sprawdź listę kanałów RSS — świeża
   instalacja ma **cztery domyślne**.
3. **Kliknij „Pobierz teraz"** (**AI News Portal → Materiały**). Z kanałów przychodzą pozycje;
   część od razu ląduje jako *pominięta* z powodem w kolumnie „Powód" — tak ma być, to filtr.
4. **Kliknij „Przygotuj treści".** Pozycjom dobierana jest pełna treść (z kanału, a gdy ta jest
   za krótka — ze strony źródłowej) i sprawdzane są powtórzenia.
5. **Kliknij „Opublikuj teraz".** Model przepisuje materiał, wtyczka waliduje odpowiedź i publikuje
   artykuł. Po pierwszej publikacji **artykuł demo znika sam** — pod dwoma warunkami: artykuł
   wyszedł jako opublikowany (przy włączonym trybie szkiców demo zostaje) i nikt demo wcześniej
   nie edytował.

> **Uwaga:** przycisk „Opublikuj teraz" publikuje wyłącznie to, co już jest w kolejce — na świeżej
> instalacji, tuż po wklejeniu klucza, **nie zrobi nic**. Kolejkę zapełniają kroki 3 i 4 albo
> automat: zapisanie Ustawień z kluczem i niepustą listą kanałów planuje **natychmiastowy
> pojedynczy przebieg**, który sam przechodzi wszystkie trzy fazy. Potem cykl powtarza się
> **co godzinę**, o ile witryna ma ruch (patrz granica 3 wyżej).

Na koniec warto dodać odnośnik do Centrum Wiedzy w menu (granica 1 wyżej) — inaczej portal
istnieje, ale odwiedzający nie mają jak do niego trafić.

---

## Jak to działa

```
kanały RSS  →  normalizacja adresu  →  dedup po adresie (klucz UNIQUE w bazie)
            →  filtr słów WYKLUCZAJĄCYCH (tytuł + zajawka + treść)
            →  bramka słów WYMAGANYCH (tylko tytuł + zajawka)      ← tanio, bez sieci
            →  treść z kanału; gdy za krótka — pobranie strony i ekstrakcja
            →  odsiew balastu, adres kanoniczny, oczyszczenie znaczników
            →  dedup po treści (odcisk z pierwszych 8 KB)
            →  ponowna bramka słów wymaganych w punkcie wyboru
            →  Gemini: JEDNO wywołanie z wymuszonym schematem odpowiedzi
               (drugie tylko wtedy, gdy odpowiedź przyjdzie urwana albo pusta)
            →  walidacja kontraktu (pola, długości, kategoria z listy)
            →  publikacja w typie treści, idempotentnie
            →  Centrum Wiedzy
```

Trzy zabezpieczenia, na których opiera się całość: **nie powstają duplikaty**, **błędna odpowiedź
modelu nie trafia na stronę**, **typowy błąd nie zatrzymuje portalu**.

**Filtr ma dwie listy i to nie jest to samo.** Lista wykluczająca odpowiada na pytanie „czy to jest
o czymś, czego nie chcemy" i przeszukuje także treść. Lista wymagana odpowiada na pytanie „czy to
w ogóle jest o psie" i patrzy **wyłącznie na tytuł i zajawkę** — w treści słowo „pies" prędzej czy
później pada w każdym artykule z takiego kanału. Wykluczenia działają pierwsze, żeby artykuł o kocie
dostał notatkę o kocie, a nie ogólne „poza tematem". Porównanie jest niewrażliwe na wielkość liter
i polskie znaki, ale **nie rozpoznaje odmiany** — każdą formę wyrazu trzeba wpisać osobno.
**Pusta lista słów wymaganych wyłącza tę bramkę** (instalacja zachowuje się jak przed jej dodaniem).

**Treść ze strony źródłowej jest mechanizmem awaryjnym**, nie normalną drogą: wszystkie cztery
domyślne kanały podają pełną treść w `content:encoded`. Pobranie strony następuje dopiero, gdy
treść z kanału jest krótsza niż próg. Strony renderowane JavaScriptem i błędy 404 zwracane
z kodem 200 kończą jako *pominięte* — to zachowanie zamierzone. Strona, która odmawia dostępu
robotom (kod 403, typowa reakcja Cloudflare), kończy jako *nieudana*, bo odmowa jest błędem trwałym.

---

## Panel w kokpicie

Wtyczka dokłada **jedną pozycję menu głównego („AI News Portal") i dwa ekrany**: **Materiały**
i **Ustawienia**. Oba wymagają uprawnienia `manage_options`. Typ treści celowo nie ma własnej
pozycji w menu — **hurtowe zarządzanie artykułami** (masowa edycja, kosz, przywracanie) idzie
przez adres **`edit.php?post_type=ainp_article`**, do którego prowadzi też link „Zarządzaj
opublikowanymi artykułami" na ekranie Materiałów.

### Ekran „Materiały"

Cztery przyciski, w tej kolejności:

| Przycisk | Co robi | Kiedy nieaktywny |
|---|---|---|
| **Pobierz teraz** | Pobiera wszystkie kanały, zapisuje nowe pozycje, odsiewa filtrem. Nie tworzy jeszcze artykułów. | — |
| **Przygotuj treści** | Bierze partię pozycji bez odcisku treści (część ma już tekst z kanału), dobiera brakującą treść, sprawdza powtórzenia. | — |
| **Opublikuj teraz** | Wysyła materiał do modelu i publikuje gotowe artykuły. | gdy nie zapisano klucza API |
| **Wznów nieudane** | Przywraca wszystkie pozycje ze statusem *nieudany* do kolejki, zeruje licznik prób. | gdy nie ma pozycji nieudanych |

Każdy przycisk po wykonaniu pokazuje podsumowanie liczbowe, ale nie każdy ten sam: pobieranie
raportuje kanały, nowe, odsiane i duplikaty, przygotowanie dokłada informację o wyczerpanym
budżecie czasu, a pełen komplet — razem z liczbą zużytych wywołań AI — ma publikacja.
Przycisk wznawiania podaje tylko, ile pozycji wróciło do kolejki i ile z nich sięgnie po model. „Przygotuj treści"
i „Opublikuj teraz" biorą **wspólny zamek** z automatem — gdy przebieg już trwa, ekran mówi
„Przebieg już trwa — poczekaj na jego koniec i odśwież stronę." Osobny panel **„Ostatni
automatyczny przebieg"** pokazuje wynik ostatniego ticku crona, także temu, kto go nie wywołał.

Tabela materiałów pokazuje najnowsze pozycje: **Tytuł**, **Źródło**, **Status**, **Powód**,
**Data**. Statusy po polsku: *nowy*, *w trakcie*, *opublikowany*, *pominięty*, *nieudany*.
Kolumna „Powód" to miejsce, w którym widać, **dlaczego** pozycja odpadła — konkretne słowo
wykluczające, brak słowa wymaganego, duplikat, za mało treści albo błąd modelu.

### Ekran „Ustawienia"

| Pole | Uwagi |
|---|---|
| **Kanały RSS** | jeden adres na linię; puste pole = pobieranie wyłączone, **nie** powrót do domyślnych |
| **Kategorie** | jedna na linię; pusta lista jest odrzucana i zastępowana domyślną |
| **Słowa wykluczające** | po przecinku lub w liniach |
| **Słowa wymagane** | jw.; **puste pole wyłącza bramkę** |
| **Klucz API Gemini** | pole hasłowe, zawsze puste po wczytaniu; puste **nie kasuje** zapisanego klucza — do tego służy osobny znacznik „Usuń zapisany klucz" |
| **Prompt** | znaczniki `{kategorie}`, `{tytul}`, `{zrodlo}`, `{tresc}`; puste = domyślny |
| **Model** | domyślnie `gemini-2.5-flash` |
| **Sufit wywołań AI na dobę** | liczba z zakresu 1–1000, domyślnie 20 |
| **Zapisuj jako szkice zamiast publikować** | domyślnie wyłączone |

Klucz API trzymany jest w **osobnej opcji, zawsze bez autoładowania**, i nigdy nie wraca do
formularza. Do modelu jedzie w nagłówku `x-goog-api-key`, nie w adresie URL.

---

## Centrum Wiedzy — strona publiczna

| Widok | Adres |
|---|---|
| Archiwum | `/centrum-wiedzy/` |
| Artykuł | `/centrum-wiedzy/tytul-artykulu/` |
| Kategoria | `/centrum-wiedzy/kategoria/zywienie/` |
| Szukanie | `/centrum-wiedzy/?ainp_s=fraza` |

Archiwum to siatka kart: zdjęcie kategorii, nazwa kategorii, tytuł, zajawka ucięta wizualnie
do dwóch linii i data. Karta bez zdjęcia dostaje kafelek z inicjałem kategorii. Przyciski kategorii
pokazują **także kategorie puste**, żeby układ portalu był widoczny od pierwszego dnia.
Wyszukiwarka używa **własnego parametru `ainp_s`** (nie `s`), więc nie miesza się z wyszukiwarką
witryny, a fraza jest ucinana do bezpiecznej długości. Paginacja idzie po dziesięć kart, fraza
wyszukiwania jedzie razem z numerem strony. Sam przedrostek `/centrum-wiedzy/kategoria/`
przekierowuje trwale (301) na archiwum.

Strona artykułu: odnośnik powrotny, tytuł, metryczka (kategoria i data), treść, **ramka źródła**
z linkiem `rel="nofollow noopener"` i drugi odnośnik powrotny na dole. Ramka pokazuje się tylko
wtedy, gdy adres źródła jest adresem `http`/`https`.

**Zdjęcia kategorii.** Każda z siedmiu kategorii ma do trzech wariantów zdjęcia
(`<slug>.jpg`, `<slug>-2.jpg`, `<slug>-3.jpg`) w `assets/kategorie/`. Wariant wybiera licznik
liczony **osobno dla każdej kategorii i osobno dla każdego żądania**: pierwsza karta „Pielęgnacji"
na stronie dostaje wariant 1, druga 2, trzecia 3, czwarta znowu 1. Kategoria, która ma tylko jeden
plik, działa jak przed dodaniem wariantów.

**Front jest celowo ubogi technicznie.** Zero JavaScriptu, jeden punkt załamania układu,
arkusz **nie ustawia kroju pisma, koloru tekstu ani tła strony** — te rzeczy zostają dla motywu,
a kolory pomocnicze liczone są z `currentColor`, czyli dziedziczą po nim.
Szablony motywu mają **pierwszeństwo** przed szablonami wtyczki: motyw potomny → motyw nadrzędny
→ wtyczka.

---

## Automatyzacja

Zdarzenie **`ainp_tick`, powtarzalne co godzinę**, planowane przy aktywacji i domykane przy
każdym żądaniu, gdy w harmonogramie nie stoi powtarzalny tick godzinny. Jeden tick to trzy fazy w stałej kolejności:
**pobierz → przygotuj → opublikuj**, wszystkie pod **jednym atomowym zamkiem** (opcja
`ainp_run_lock`, zakładana przez `INSERT IGNORE`, zdejmowana warunkowo). Zamek starszy niż jego
czas życia jest przejmowany, więc zabity proces nie blokuje portalu na zawsze; pozycje porzucone
w stanie *w trakcie* wracają do kolejki po piętnastu minutach.

**Budżet czasu jest w tym mechanizmie ważniejszy niż liczba pozycji.** Każda faza pyta o czas
przed wzięciem kolejnej pozycji (rozpoczęta zawsze się domyka), fazy pobierania i przygotowania
dostają po ćwiartce budżetu, a resztę — zawsze co najmniej połowę — dostaje publikacja, bo tylko
ona daje klientowi artykuł. Budżet przycina też timeout wywołania modelu, a gdy zostało go mniej
niż minimum, wywołanie w ogóle się nie zaczyna i **slot z puli dobowej nie przepada**. Cały budżet
jest dodatkowo przycinany do 80% limitu czasu wykonania PHP na hostingu.

**Pula dobowa.** Slot jest rezerwowany **przed** wysłaniem żądania, więc timeout albo zerwane
połączenie też kosztuje wywołanie. Licznik zeruje się o północy czasu witryny. Pozycja, która
nie miała klucza, czasu albo puli, dostaje czytelną notatkę i czeka — nie idzie na *nieudany*.

**Błędy — dwie różne drogi.** Przy **pobieraniu strony źródłowej** błąd przejściowy (timeout, 429,
5xx) liczy próbę i pozycja wraca w kolejnym przebiegu; po wyczerpaniu prób ląduje jako *nieudana*,
ale **z zachowaną treścią** — jest z czego ponowić. Błąd trwały (404, 403, zakaz w `robots.txt`)
kończy pozycję od razu. Przy **modelu** jest inaczej: timeout, limit po stronie dostawcy i błąd
serwera **nie podbijają licznika prób i nie zmieniają statusu** — pozycja czeka na kolejny przebieg.
Na *nieudaną* schodzi tylko wtedy, gdy odpowiedź złamie kontrakt albo dwa razy z rzędu przyjdzie
urwana lub pusta. Wznowienie nieudanych jest zawsze **decyzją człowieka**, nigdy automatu.

---

## Stałe konfiguracyjne

Wartości wpisane na sztywno w kodzie (rzeczy konfigurowalne są w Ustawieniach, nie tutaj).
**Ta tabela jest pilnowana testem** `tests/etap85-readme-test.php`: liczba, która rozjedzie się
z kodem, wywala test, więc nie może przetrwać do wydania.

| Stała | Wartość | Znaczenie |
|---|---|---|
| `Runner::TICK_BUDGET` | `25` | sekund na cały automatyczny przebieg |
| `Runner::PREPARE_BUDGET` | `15` | sekund dla przycisku „Przygotuj treści" |
| `Runner::PUBLISH_BUDGET` | `30` | sekund dla przycisku „Opublikuj teraz" |
| `Runner::TICK_SHARE` | `0.25` | ćwiartka budżetu ticku na fazę pobierania i na przygotowanie |
| `Runner::PREPARE_BATCH` | `10` | pozycji na jedną fazę przygotowania |
| `Runner::AI_BATCH` | `3` | artykułów na jeden przebieg publikacji |
| `Runner::MAX_ATTEMPTS` | `3` | próby przy błędzie przejściowym, potem *nieudany* |
| `Runner::MAX_ITEMS_PER_SOURCE` | `100` | pozycji z jednego kanału na przebieg |
| `Runner::STALE_SECONDS` | `900` | sekund, po których pozycja *w trakcie* wraca do kolejki |
| `Admin::LOCK_TTL` | `120` | sekund życia zamka przebiegu |
| `Admin::ITEMS_LIMIT` | `50` | wierszy w tabeli Materiałów |
| `Gemini::MATERIAL_MAX` | `12000` | znaków materiału w prompcie — nadmiar jest ucinany |
| `Gemini::TIMEOUT_MAX` | `30` | sekund górnego timeoutu wywołania modelu |
| `Gemini::TIMEOUT_MIN` | `8` | sekund — poniżej tego wywołanie się nie zaczyna |
| `Gemini::MAX_OUTPUT_TOKENS` | `8192` | limit tokenów odpowiedzi |
| `Gemini::TEMPERATURE` | `0.4` | temperatura generacji |
| `Article::MIN_FEED_CHARS` | `1200` | znaków — poniżej tego treść z kanału uznajemy za zajawkę |
| `Article::MIN_TEXT_CHARS` | `500` | znaków — poniżej tego pozycja jest *pominięta* |
| `Article::MAX_CONTENT_CHARS` | `20000` | znaków treści zapisywanej do tabeli |
| `Validator::MIN_TITLE` | `5` | znaków tytułu |
| `Validator::MAX_TITLE` | `140` | znaków tytułu |
| `Validator::MIN_LEAD` | `20` | znaków zajawki |
| `Validator::MAX_LEAD` | `400` | znaków zajawki |
| `Validator::MIN_CONTENT` | `800` | znaków treści artykułu |
| `Portal::PER_PAGE` | `10` | kart na stronę archiwum |
| `Portal::IMAGE_VARIANTS` | `3` | warianty zdjęcia na kategorię |
| `Portal::SEARCH_MAX` | `120` | znaków frazy wyszukiwania |
| `Http::TIMEOUT_FEED` | `10` | sekund na pobranie kanału |
| `Http::TIMEOUT_ARTICLE` | `15` | sekund na pobranie strony artykułu |
| `Http::REDIRECTS` | `3` | dozwolone przekierowania |
| `Http::MIN_SECONDS` | `3` | sekund budżetu — poniżej tego żądanie się nie zaczyna |
| `Http::ROBOTS_TTL` | `43200` | sekund pamiętania werdyktu `robots.txt` |
| `Dedup::CONTENT_BYTES` | `8192` | bajtów tekstu, z których liczony jest odcisk treści |
| `Filter::MAX_HAYSTACK_BYTES` | `131072` | bajtów tekstu branych do porównania ze słowami |

---

## Dane w bazie

**Jedna tabela: `wp_ainp_items`** (prefiks witryny może być inny). Trzyma kolejkę materiałów:
adres i jego odcisk, odcisk treści, tytuł, zajawkę, treść, status, powód, licznik prób, numer
powstałego wpisu i znaczniki czasu. Dwa klucze `UNIQUE` — po adresie i po treści — sprawiają,
że o duplikat pyta **baza**, nie kod.

**Statusy pozycji:** `new` · `processing` · `skipped` · `failed` · `done`.

**Typ treści `ainp_article`** z taksonomią **`ainp_topic`** (obsługiwane pola: tytuł, edytor,
zajawka). Przy wpisie zapisywane są dwie meta: identyfikator pozycji źródłowej i adres źródła.

**Opcje wtyczki:** `ainp_settings` (ustawienia ogólne), `ainp_sources` (lista kanałów),
`ainp_key` (klucz API, bez autoładowania), `ainp_usage` (licznik dobowy), `ainp_run_lock` (zamek
przebiegu) oraz dwa znaczniki pomocnicze: `ainp_topics_seeded` i `ainp_slug_collision`.
Wszystkie zaczynają się od `ainp_` i żadnej nie dzieli z drugą wtyczką z paczki. Poza nimi wtyczka
trzyma w tej samej tabeli **transienty** (podsumowania ostatnich przebiegów i zapamiętany werdykt
`robots.txt`) — WordPress zapisuje je pod własnym przedrostkiem `_transient_`, więc przy sprzątaniu
wymagają osobnego wzorca.

---

## Nagłówki bezpieczeństwa

Wtyczka wysyła nagłówki **wyłącznie na swoich widokach frontu** (strona artykułu lub archiwum,
ramka embed, kanał RSS własnych treści) i **nigdy w kokpicie**. Polityka jest **uzupełniająca, nigdy nadpisująca**:
jeśli nagłówek już istnieje, wtyczka go nie wysyła, a wysyłkę i tak wykonuje w trybie
nienadpisującym — cudzej wartości nie da się przez nią stracić.

| Widok | Wysyłane nagłówki |
|---|---|
| Kanał (feed) | `X-Content-Type-Options`, `Referrer-Policy` |
| Ramka embed | jw. + `Permissions-Policy` (bez `X-Frame-Options` i bez CSP — ramka ma prawo być osadzana) |
| Strona | jw. + `X-Frame-Options: SAMEORIGIN` + `Content-Security-Policy: frame-ancestors 'self'` |

CSP celowo ogranicza się do `frame-ancestors`: stronę renderuje **motyw klienta**, więc wtyczka
nie ma prawa narzucać reguł o źródłach skryptów i stylów. Gdy na stronie wisi już cudze CSP,
własne jest pomijane w całości.

**Dwie drogi wyłączenia**, obie udokumentowane i przetestowane:
filtr **`ainp_security_headers`** (pusta tablica = brak nagłówków dla tego żądania) oraz stała
**`AINP_NO_SECURITY_HEADERS`** w `wp-config.php` — dla kogoś, kto nie chce pisać PHP.

**Nagłówki świadomie odrzucone** (i powód, dla którego to nie jest przeoczenie):
`Strict-Transport-Security` — obejmuje całą domenę na miesiące, decyzja nie należy do wtyczki
podstrony · `Cross-Origin-Opener-Policy` — zrywa `window.opener`, czyli cudze logowanie i okna
płatności · `Cross-Origin-Resource-Policy` — zablokowałby własną ramkę embed ·
`X-XSS-Protection` — wycofany · `X-Permitted-Cross-Domain-Policies` — dotyczy martwej technologii.

---

## Odinstalowanie

> **UWAGA — usunięcie wtyczki kasuje BEZPOWROTNIE wszystkie wygenerowane artykuły.**
> Znikają wpisy (razem ze szkicami i zawartością kosza), kategorie artykułów, cała kolejka
> materiałów, wszystkie opcje wtyczki razem z kluczem API oraz zaplanowane zadanie.
> **Nie ma to żadnego przełącznika i nie da się tego cofnąć.**

**Samo wyłączenie wtyczki niczego nie kasuje** — usuwa tylko zaplanowane zadanie, a artykuły
i kolejka zostają nietknięte. Kasowanie robi dopiero **„Usuń"** na liście wtyczek.
Kto chce zachować treści, powinien je wcześniej wyeksportować (**Narzędzia → Eksport**)
albo przenieść do zwykłych wpisów.

Usuwanie jest **bez śladu**: zabiera też metadane osierocone po wpisach skasowanych wcześniej ręką
właściciela oraz osierocone metadane po skasowanych kategoriach — własnych metadanych terminów
wtyczka nigdy nie zapisuje, więc sprząta wyłącznie wiersze, które straciły swój termin.
Nie obsługuje trybu wielowitrynowego — czyści dane witryny, na której zostało uruchomione.

---

## Struktura kodu

```
ai-news-portal.php     nagłówek wtyczki, stałe, jawna lista require, haki cyklu życia
uninstall.php          usuwanie bez śladu (uruchamiane wyłącznie przez WordPressa)
LICENSE                pełny tekst GPLv2
src/
  Settings.php         opcje i wartości domyślne (kanały, kategorie, słowa, prompt, model)
  Demo.php             tryb publicznej wystawy: nietykalny klucz, odstępy i limity na adres IP
  Http.php             pobieranie z sieci: timeouty, limity, robots.txt, bezpieczne żądania
  Feed.php             parsowanie kanałów RSS 2.0 i Atom
  Dedup.php            normalizacja adresu i odciski: po adresie i po treści
  Filter.php           dwie bramki słów: wykluczające i wymagane
  Article.php          treść z kanału albo ze strony: ekstrakcja, odsiew balastu, canonical
  Gemini.php           wywołanie modelu: prompt, wymuszony schemat, pula dobowa, timeouty
  Validator.php        kontrakt odpowiedzi modelu: pola, długości, kategoria z listy
  Publisher.php        publikacja idempotentna, kategoria, meta źródła, sprzątanie demo
  Runner.php           orkiestracja: trzy fazy, zamek, budżety czasu, statusy, ponowienia
  Portal.php           front Centrum Wiedzy: adresy, szablony, wyszukiwarka, zdjęcia kategorii
  Security.php         uzupełniające nagłówki bezpieczeństwa
  Plugin.php           cykl życia: tabela, typ treści, taksonomia, harmonogram, demo
  Admin_Screen.php     trait z widokami ekranów kokpitu
  Admin.php            menu, akcje przycisków, nonce, zapis ustawień, zamek
  templates/           archive.php · single.php · card.php · arkusz stylów portalu
assets/kategorie/      zdjęcia kategorii (do trzech wariantów na kategorię)
```

Wtyczka **nie ma autoloadera** — lista `require_once` w pliku głównym jest jedynym miejscem,
w którym klasa trafia do pamięci. `Admin_Screen.php` (trait) musi stać **przed** `Admin.php`.

---

## Tryb demo

Do publicznej wystawy, na której kokpit jest otwarty dla wszystkich. Włącza go **stała
w `wp-config.php`**, nie opcja — opcję dałoby się zmienić z tego samego otwartego kokpitu:

```php
define( 'AINP_DEMO', true );
```

Bez tej stałej klasa `Demo.php` jest martwa i wtyczka zachowuje się dokładnie tak, jak opisuje
reszta tego dokumentu. Ze stałą dochodzą cztery ograniczenia:

| Ograniczenie | Po co |
|---|---|
| Klucz API jest nietykalny — bez zapisu, podmiany i kasowania | Klucz jest jedynym zasobem, którego reset wystawy nie odtworzy |
| Model i sufit dobowy są nietykalne | Podniesiony sufit zużyłby cudzy klucz równie skutecznie, co podmiana |
| Odstęp między przebiegami, liczony na adres IP | „Pobierz teraz" i „Przygotuj treści" to praca sieciowa |
| Dzienny limit „Opublikuj teraz" na adres IP | Żeby jeden gość nie zjadł całej dobowej puli wywołań |

Blokady siedzą w kodzie obsługującym żądanie, a nie w formularzu: ukryte pole zatrzymuje
przeglądarkę, ale nie ręcznie złożony POST. Limity stoją **po** sprawdzeniu uprawnień i nonce'a,
więc żądanie bez nonce'a odpada wcześniej i nie zajmuje nikomu odstępu. Liczniki żyją
w transientach i znikają razem z resetem wystawy.

---

## Ograniczenia znane i świadome

- **Portal bez ruchu na stronie milczy** — WP-Cron rusza przy żądaniach HTTP. Na witrynie bez
  odwiedzin trzeba użyć crona systemowego albo klikać przyciski w panelu.
- **Czas odpowiedzi modelu bywa nieprzewidywalny** — zmierzono 9,7 s i 20,6 s dla tego samego
  materiału (różnica siedzi po stronie modelu, nie wtyczki). Przycisk „Opublikuj teraz" ma na to
  zapas, automatyczny tick bywa za krótki i pozycja wraca w kolejnym przebiegu.
- **Timeout kosztuje wywołanie z puli** — slot rezerwowany jest przed żądaniem, inaczej urwane
  połączenie pozwalałoby obejść sufit.
- **Krótkie okno bez kategorii** — kategoria przypisywana jest zaraz po utworzeniu wpisu, osobnym
  wywołaniem (z crona nie ma uprawnienia zrobić tego przy zapisie). Gdy przypisanie się nie uda,
  wpis wraca do szkiców, żeby treść nie przepadła i nie wisiała publicznie bez kategorii.
- **Kanały RSS 1.0 (RDF) nie są obsługiwane** — świadomie; RSS 2.0 i Atom pokrywają praktykę.
- **`robots.txt` sprawdzany jest zgrubnie** — wtyczka patrzy, czy grupa `User-agent: *` nie
  zabrania całej witryny. Nie interpretuje reguł per ścieżka.
- **Strony renderowane JavaScriptem nie dadzą się przepisać** — kończą jako *pominięte*.
- **Odinstalowanie nie obejmuje trybu wielowitrynowego.**

---

## Wymagania

| | |
|---|---|
| WordPress | 6.5 lub nowszy |
| PHP | 8.1 lub nowszy |
| Sprawdzone na | WordPress 7.0.2 |
| Klucz API | Gemini (darmowa pula wystarcza; domyślny sufit to 20 wywołań na dobę) |

## Licencja

GPLv2 — pełny tekst w pliku `LICENSE`, nota w nagłówku `ai-news-portal.php`.

## Dokumentacja dla klienta

Pięć dokumentów PDF (instrukcja wprowadzająca, instrukcja dla informatyka, format danych,
wymagania niefunkcjonalne, instrukcje systemowe) leży w katalogu `instrukcje/` repozytorium
razem ze źródłami i narzędziami, którymi są budowane. **Do paczki instalacyjnej nie wchodzą** —
klient dostaje je osobno, obok pliku ZIP.

## Testy

Testy jednostkowe (`tests/`) to samodzielne skrypty PHP: zero WordPressa, zero sieci. Uruchamia je
runner spoza repozytorium, pogrupowane w segmenty architektury; repozytorium zawiera jego CI-ową
kopię (`.github/ci/testy-wtyczka2.sh` w korzeniu repo) — woła ją workflow „Testy" na GitHubie
i działa też lokalnie. Zestaw jest zaliczony dopiero,
gdy kod wyjścia to 0, nie ma ani jednej linii `FAIL`, **liczba wykonanych asercji dokładnie równa
się oczekiwanej** i zestaw wydrukował własne podsumowanie — samo „wyszło zielone" nie wystarcza.
