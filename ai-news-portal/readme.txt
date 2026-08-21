=== AI News Portal ===
Requires at least: 6.5
Tested up to: 7.0.2
Requires PHP: 8.1
Stable tag: 0.7.0
License: GPLv2
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Portal wiedzy, który prowadzi się sam: wtyczka pobiera wpisy z kanałów RSS, odsiewa te nie na temat, przepisuje pozostałe modelem Gemini i publikuje jako własne artykuły.

== Description ==

Wtyczka zakłada na Twojej stronie **Centrum Wiedzy** pod adresem `/centrum-wiedzy/` i sama je
zapełnia. Cykl jest w całości automatyczny: pobranie pozycji z kanałów RSS, odsianie tych spoza
tematu, dobranie pełnej treści, przepisanie jej modelem Gemini na samodzielny artykuł
i opublikowanie go we własnym typie treści. Właściciel witryny wkleja własny klucz API
i nie musi robić nic więcej.

Trzy zabezpieczenia, na których opiera się całość: **nie powstają duplikaty** (o powtórkę pyta
baza danych, nie kod), **błędna odpowiedź modelu nie trafia na stronę** (kontrakt odpowiedzi jest
walidowany przed publikacją), **typowy błąd nie zatrzymuje portalu** (pozycja wraca w kolejnym
przebiegu).

Wtyczka jest **samodzielna**: nie ma żadnej zależności od drugiej wtyczki z tej samej paczki
(AI FAQ Generator), nie dzieli z nią kodu, opcji ani tabel.

= Co dostaje odwiedzający =

* **Archiwum** `/centrum-wiedzy/` — siatka kart ze zdjęciem kategorii, tytułem, zajawką i datą,
  po dziesięć kart na stronę.
* **Przyciski kategorii** — pokazują także kategorie jeszcze puste, żeby układ portalu był
  widoczny od pierwszego dnia.
* **Wyszukiwarka** na własnym parametrze `ainp_s`, więc nie miesza się z wyszukiwarką witryny.
* **Strona artykułu** z metryczką, treścią i **ramką źródła** z odnośnikiem `rel="nofollow noopener"`.

Front jest celowo ubogi technicznie: **zero JavaScriptu**, jeden punkt załamania układu, a arkusz
stylów **nie ustawia kroju pisma, koloru tekstu ani tła strony** — te rzeczy zostają dla motywu.
Szablony motywu mają pierwszeństwo przed szablonami wtyczki.

= Wymaga własnego klucza API (model BYOK) =

**Wtyczka nie zawiera żadnego klucza API i bez klucza nie opublikuje żadnego artykułu.** Klucz do
Google Gemini zakładasz samodzielnie i wpisujesz w Ustawieniach wtyczki. Klucz należy do Ciebie,
rozliczasz się z niego bezpośrednio z Google, a wtyczka nigdzie go nie wysyła poza wywołania do API
dostawcy. Trzymany jest w osobnej opcji, **zawsze bez autoładowania**, nigdy nie wraca do
formularza i jedzie do modelu w nagłówku `x-goog-api-key`, nie w adresie URL.

Darmowy przydział Gemini to około 20 żądań na dobę. Dlatego wtyczka ma własny **dobowy sufit
wywołań** — domyślnie 20, do ustawienia w zakresie od 1 do 1000. Slot rezerwowany jest **przed**
wysłaniem żądania, więc timeout albo zerwane połączenie też kosztuje wywołanie; bez tego można by
sufit obejść. Licznik zeruje się o północy czasu witryny.

= Korzystanie z usługi zewnętrznej (Google Gemini) =

To jest zależność od usługi zewnętrznej i warto ją znać przed instalacją.

Wtyczka łączy się z **Google Gemini API** pod adresem
`https://generativelanguage.googleapis.com/`. Wysyłany jest tam **materiał pobrany z kanału RSS
lub ze strony źródłowej** — tytuł, adres i treść — razem z listą Twoich kategorii, po to żeby model
napisał z niego nowy artykuł. Nie są wysyłane żadne dane odwiedzających Twoją witrynę: wtyczka nie
reaguje na ich działania, tylko na własny harmonogram i na przyciski w kokpicie.

Poza Gemini wtyczka łączy się z **adresami kanałów RSS, które sam wpiszesz w Ustawieniach**,
oraz — gdy treść z kanału jest za krótka — ze **stroną źródłową danej pozycji**. Innych połączeń
nie wykonuje.

Zasady dostawcy:

* Regulamin Gemini API: https://ai.google.dev/gemini-api/terms
* Polityka prywatności Google: https://policies.google.com/privacy

= Portal nie pracuje bez ruchu na stronie =

To jest najczęstsze źródło wrażenia, że wtyczka nie działa, więc lepiej wiedzieć o tym od razu.

Automat opiera się na **WP-Cron**, a ten w WordPressie rusza **przy żądaniach HTTP**. Witryna
bez odwiedzin nie opublikuje nic, choćby harmonogram był ustawiony poprawnie. Na stronie, która
nie ma jeszcze ruchu, są dwa wyjścia: klikać przyciski na ekranie **Materiały** albo podpiąć crona
systemowego po stronie hostingu.

= Czego wtyczka nie robi =

* **Nie dodaje odnośnika do menu nawigacji.** Nigdy nie dotyka menu witryny — odnośnik dodajesz
  ręcznie (instrukcja w sekcji Instalacja).
* **Nie tworzy żadnej Strony WordPressa.** Archiwum jest archiwum typu treści i powstaje samo.
* **Nie pobiera zdjęć ze stron źródłowych.** Powód jest prawny, nie techniczny: cudze zdjęcia mają
  swoje licencje. Karta pokazuje zdjęcie kategorii z paczki wtyczki.
* **Nie obsługuje kanałów RSS 1.0 (RDF)** ani stron renderowanych JavaScriptem — takie pozycje
  kończą jako *pominięte*.
* **Nie obsługuje trybu wielowitrynowego** przy odinstalowaniu.

= Nagłówki bezpieczeństwa i dwie drogi ich wyłączenia =

Wtyczka wysyła uzupełniające nagłówki bezpieczeństwa **wyłącznie na swoich widokach frontu**
(strona artykułu, archiwum, ramka embed, kanał RSS własnych treści) i **nigdy w kokpicie**.
Polityka jest **uzupełniająca, nigdy nadpisująca**: jeśli nagłówek już istnieje, wtyczka go nie
wysyła. Nagłówek `Content-Security-Policy` ogranicza się celowo do `frame-ancestors`, bo stronę
renderuje motyw klienta i wtyczka nie ma prawa narzucać reguł o źródłach skryptów i stylów.

Gdyby nagłówki kolidowały z konfiguracją serwera albo z innym rozwiązaniem, **są dwie drogi
wyłączenia — obie udokumentowane i objęte testami**:

1. filtr **`ainp_security_headers`** — zwrócenie pustej tablicy wyłącza nagłówki dla danego żądania;
2. stała **`AINP_NO_SECURITY_HEADERS`** ustawiona w `wp-config.php` — dla kogoś, kto nie chce
   pisać PHP.

= Odinstalowanie =

**UWAGA — usunięcie wtyczki kasuje BEZPOWROTNIE wszystkie wygenerowane artykuły.** Znikają wpisy
(razem ze szkicami i zawartością kosza), kategorie artykułów, cała kolejka materiałów, wszystkie
opcje wtyczki razem z kluczem API oraz zaplanowane zadanie. **Nie ma to żadnego przełącznika
i nie da się tego cofnąć.**

**Samo wyłączenie wtyczki niczego nie kasuje** — usuwa tylko zaplanowane zadanie, a artykuły
i kolejka zostają nietknięte. Kasowanie robi dopiero **„Usuń"** na liście wtyczek. Kto chce
zachować treści, powinien je wcześniej wyeksportować (*Narzędzia → Eksport*) albo przenieść
do zwykłych wpisów.

Usuwanie jest **bez śladu**: zabiera również metadane osierocone po wpisach i kategoriach
skasowanych wcześniej ręką właściciela.

= Kto co może =

* **Administrator** — wszystko: oba ekrany wtyczki, klucz API, ustawienia, przyciski przebiegu.
  Oba ekrany wymagają uprawnienia `manage_options`.
* **Pozostałe role** — nie widzą wtyczki. Opublikowane artykuły są zwykłymi wpisami własnego typu
  treści, więc dostęp do ich edycji rządzi się zwykłymi uprawnieniami WordPressa.
* **Odwiedzający** — czytają Centrum Wiedzy. Wtyczka nie przyjmuje od nich żadnych danych poza
  frazą wyszukiwania.

== Installation ==

1. Wgraj katalog `ai-news-portal` do `wp-content/plugins/` albo zainstaluj paczkę ZIP przez
   *Wtyczki → Dodaj nową → Wyślij wtyczkę na serwer*.
2. Włącz wtyczkę na liście wtyczek. Przy pierwszym włączeniu powstają: tabela materiałów, typ
   treści z archiwum `/centrum-wiedzy/`, **siedem kategorii** i **jeden artykuł demo**, żeby
   archiwum nie było puste. Adres działa od razu, bez zapisywania Bezpośrednich odnośników.
3. Wejdź w *AI News Portal → Ustawienia* i wklej swój klucz Google Gemini. Sprawdź przy okazji
   listę kanałów RSS — świeża instalacja ma **cztery domyślne**.
4. Wejdź w *AI News Portal → Materiały* i kliknij kolejno **„Pobierz teraz"**,
   **„Przygotuj treści"** i **„Opublikuj teraz"**. Pierwszy artykuł powstaje w tym ostatnim kroku.
   Artykuł demo znika sam po pierwszej udanej publikacji.
5. **Dodaj odnośnik do Centrum Wiedzy w menu.** Wtyczka nigdy nie rusza menu witryny, więc trzeba
   to zrobić raz ręcznie: *Wygląd → Menu*, metabox **„Artykuły"**, zakładka **„Zobacz wszystko"**,
   pozycja **„Centrum Wiedzy"**. Jeśli metaboksu nie widać, włącz go w **Opcjach ekranu**.
6. Otwórz `/centrum-wiedzy/` i sprawdź, czy artykuł jest na miejscu.

Dalej portal pracuje sam: **co godzinę**, o ile witryna ma ruch (patrz sekcja o WP-Cronie wyżej).

**Hurtowe zarządzanie artykułami** — masowa edycja, kosz, przywracanie — idzie przez adres
`edit.php?post_type=ainp_article`. Prowadzi do niego również odnośnik „Zarządzaj opublikowanymi
artykułami" na ekranie Materiałów. Typ treści celowo nie ma własnej pozycji w menu kokpitu.

== Frequently Asked Questions ==

= Wtyczka jest włączona, ale nic się nie publikuje. Dlaczego? =

Najczęściej z jednego z trzech powodów. Po pierwsze — brak ruchu na stronie: WP-Cron rusza przy
żądaniach HTTP, więc witryna bez odwiedzin nie zrobi nic. Po drugie — brak klucza API: bez niego
przycisk „Opublikuj teraz" jest nieaktywny. Po trzecie — pusta kolejka: przycisk publikuje
wyłącznie to, co już w niej jest, więc na świeżej instalacji trzeba najpierw kliknąć
„Pobierz teraz" i „Przygotuj treści".

= Połowa pozycji ma status „pominięty". Czy to błąd? =

Nie, to filtr przy pracy. Kolumna **„Powód"** mówi dokładnie, co odsiało pozycję: konkretne słowo
wykluczające, brak słowa wymaganego, duplikat, za mało treści albo błąd modelu. Odsiewanie jest
tanie — dzieje się przed wywołaniem modelu, więc nie zużywa puli.

= Czym różnią się słowa wykluczające od wymaganych? =

Lista wykluczająca odpowiada na pytanie, czy pozycja jest o czymś, czego nie chcemy, i przeszukuje
także treść. Lista wymagana odpowiada na pytanie, czy pozycja w ogóle jest o naszym temacie,
i patrzy **wyłącznie na tytuł i zajawkę**. Porównanie jest niewrażliwe na wielkość liter i polskie
znaki, ale **nie rozpoznaje odmiany** — każdą formę wyrazu trzeba wpisać osobno. **Puste pole słów
wymaganych wyłącza tę bramkę.**

= Muszę płacić za API? =

Nie musisz. Wtyczka jest przystosowana do darmowego przydziału Gemini i pilnuje go własnym sufitem
dobowym. Przy większym apetycie na artykuły darmowa pula może nie wystarczyć — wtedy decyzja
o płatnym kluczu należy do Ciebie.

= Czy artykuły są kopiami cudzych tekstów? =

Nie. Materiał źródłowy jest dla modelu punktem wyjścia, a nie treścią do przepisania słowo w słowo;
wynik jest samodzielnym artykułem, a każdy z nich ma widoczną **ramkę źródła** z odnośnikiem do
oryginału. Zdjęć ze stron źródłowych wtyczka nie pobiera w ogóle.

= Wyczyściłem listę kanałów i teraz nic nie przychodzi. Wrócą domyślne? =

Nie. Puste pole kanałów oznacza **wyłączone pobieranie**, a nie powrót do wartości domyślnych —
inaczej wtyczka wracałaby do cudzych adresów wbrew decyzji właściciela. Domyślną czwórkę dostaje
wyłącznie świeża instalacja. Inaczej zachowuje się lista kategorii: pusta jest odrzucana
i zastępowana domyślną, bo bez kategorii artykuł nie miałby gdzie trafić.

= Zmieniłem pliki wtyczki na nowszą wersję i coś nie działa =

WordPress uruchamia hak aktywacji **tylko przy włączaniu wtyczki**, więc po podmianie plików na
wersję, która zmienia strukturę tabeli, trzeba wtyczkę **wyłączyć i włączyć**. Harmonogram
i brakujące kategorie domykają się same przy zwykłym żądaniu i reaktywacji nie wymagają.

= Mam już Stronę pod adresem `centrum-wiedzy`. Co się stanie? =

Jeśli istniała **w chwili aktywacji**, wtyczka pokaże w kokpicie jednorazowe ostrzeżenie
o kolizji — rozstrzygnięcie należy do Ciebie. Strona założona później nie wywoła komunikatu,
a adres pozostanie zajęty przez nią.

= Czy da się wyłączyć nagłówki bezpieczeństwa? =

Tak, dwiema drogami: filtrem `ainp_security_headers` (pusta tablica) albo stałą
`AINP_NO_SECURITY_HEADERS` w `wp-config.php`. Opis w sekcji o nagłówkach bezpieczeństwa.

== Changelog ==

Skrót wydań. Pełna historia zmian znajduje się w repozytorium projektu.

= 0.7.0 =
* Uzupełniające nagłówki bezpieczeństwa na widokach frontu wtyczki, nigdy w kokpicie.
* Dwie udokumentowane drogi wyłączenia nagłówków: filtr i stała w `wp-config.php`.
* Audyt bezpieczeństwa: klucz API bez autoładowania, żądania wyłącznie przez bezpieczne API
  WordPressa.

= 0.6.0 =
* Centrum Wiedzy: archiwum z kartami, strona artykułu, widok kategorii, wyszukiwarka na własnym
  parametrze `ainp_s`, paginacja po dziesięć kart.
* Front bez JavaScriptu; szablony motywu mają pierwszeństwo przed szablonami wtyczki.

= 0.5.0 =
* Automatyzacja: godzinne zdarzenie `ainp_tick`, trzy fazy pod jednym atomowym zamkiem.
* Budżety czasu przycinające timeouty żądań i ręczne wznawianie pozycji nieudanych.

= 0.4.0 =
* Wywołanie modelu z wymuszonym schematem odpowiedzi i walidacja kontraktu przed publikacją.
* Publikacja idempotentna, dobowy sufit wywołań, kategoria przypisywana z listy właściciela.

= 0.3.0 =
* Filtr dwóch list — słowa wykluczające i wymagane — działający przed wywołaniem modelu.
* Dobieranie treści ze strony źródłowej, gdy kanał podaje samą zajawkę, i dedup po treści.

= 0.2.0 =
* Pobieranie kanałów RSS 2.0 i Atom, normalizacja adresów, dedup po adresie kluczem `UNIQUE`.
* Ekran „Materiały" z tabelą kolejki i powodem odsiania.

= 0.1.0 =
* Fundament: typ treści `ainp_article` z taksonomią `ainp_topic`, tabela materiałów, ustawienia,
  usuwanie bez śladu przy odinstalowaniu.

== Upgrade Notice ==

= 0.7.0 =
Aktualizacja przez podmianę plików nie uruchamia haka aktywacji. Jeśli wydanie zmienia strukturę
tabeli, wyłącz i włącz wtyczkę jeden raz — harmonogram i brakujące kategorie domkną się same.
