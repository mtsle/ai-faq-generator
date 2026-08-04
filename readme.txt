=== AI FAQ Generator ===
Requires at least: 6.4
Tested up to: 7.0.2
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Generator FAQ zawężony do tematu Twojej strony: gość pyta, a odpowiedź powstaje wyłącznie z treści Twojej witryny.

== Description ==

Wtyczka robi dwie rzeczy, które da się używać osobno.

**Bot pytań na publicznej podstronie.** Odwiedzający wpisuje pytanie i dostaje odpowiedź
zbudowaną **wyłącznie z treści Twojej witryny**. Wtyczka wcześniej indeksuje strony i wpisy,
zamieniając je na fragmenty z wektorami znaczeniowymi. Pytanie spoza tematu witryny jest
odrzucane zamiast zmyślane — to jest sedno tej wtyczki i powód, dla którego nie jest to
zwykły czat z modelem językowym.

**Generator FAQ w kokpicie.** Podajesz temat i krótki opis, a wtyczka układa gotowe pary
pytanie–odpowiedź. Możesz je edytować, usuwać i wyeksportować w pięciu formatach: HTML,
bloki Gutenberga, Elementor, JSON oraz JSON-LD `FAQPage` (dane strukturalne rozpoznawane
przez wyszukiwarki). Jest też panel „AI FAQ" w edytorze wpisu — układa FAQ z treści
artykułu, nad którym właśnie pracujesz, i wstawia je na jego koniec.

= Wymaga własnego klucza API (model BYOK) =

**Wtyczka nie zawiera żadnego klucza API i bez klucza nie działa.** Klucz do Google Gemini
zakładasz samodzielnie i wpisujesz w Ustawieniach wtyczki. Klucz należy do Ciebie, rozliczasz
się z niego bezpośrednio z Google, a wtyczka nigdzie go nie wysyła poza wywołania do API
dostawcy.

Darmowy przydział Gemini to około 20 żądań na dobę na model. Dlatego wtyczka ma własny
**dobowy sufit witryny** — domyślnie 12 pytań na dobę — oraz limit na pojedynczego gościa,
domyślnie 10 pytań na godzinę. Bez tych bezpieczników jeden zautomatyzowany ruch wyczerpałby
pulę do południa i kolejni odwiedzający dostawaliby wyłącznie błąd. Oba limity zmienisz
w Ustawieniach.

= Korzystanie z usługi zewnętrznej (Google Gemini) =

To jest zależność od usługi zewnętrznej i warto ją znać przed instalacją.

Wtyczka łączy się z **Google Gemini API** pod adresem
`https://generativelanguage.googleapis.com/`. Wysyłane są tam:

* **treść indeksowanych stron i wpisów** — przy uruchomieniu indeksowania bazy wiedzy,
  żeby policzyć wektory znaczeniowe;
* **pytania zadawane przez odwiedzających** wraz z pasującymi fragmentami Twojej treści —
  przy każdej odpowiedzi, która nie jest wzięta z pamięci podręcznej;
* **temat i opis podany w generatorze FAQ** — przy generowaniu par pytanie–odpowiedź.

Nic z tego nie dzieje się w tle bez Twojej wiedzy: indeksowanie uruchamiasz przyciskiem,
a bot odpowiada wyłącznie na pytania faktycznie zadane na Twojej stronie.

Zasady dostawcy:

* Regulamin Gemini API: https://ai.google.dev/gemini-api/terms
* Polityka prywatności Google: https://policies.google.com/privacy

= Dane osobowe i dziennik pytań =

Wtyczka zapisuje w bazie WordPressa **dziennik pytań odwiedzających**. Wraz z pytaniem
zapisywany jest **skrót (hash) adresu IP** — nie sam adres — używany wyłącznie do
rozpoznania, czy ten sam gość nie przekracza limitu. Dziennik służy Tobie: pokazuje,
o co ludzie realnie pytają i czego na stronie brakuje.

**Retencja jest domyślnie wyłączona.** Obie opcje czyszczenia historii mają wartość
domyślną `0`, czyli „nie kasuj nic" — dane rosną, dopóki sam nie ustawisz limitu liczby
wierszy albo liczby dni. Po przekroczeniu 50 000 wpisów wtyczka przypomni o tym
komunikatem w kokpicie. Włączenie retencji kasuje wiersze **trwale**, z pominięciem kosza.

Odinstalowanie wtyczki usuwa jej dane bez śladu: własne tabele, opcje, wpisy w metadanych
użytkowników i zaplanowane zadania. Podstrony generatora wtyczka **nie kasuje** — to Twoja
treść, a nie jej.

= Kto co może =

* **Administrator** — wszystko: ustawienia, klucz API, indeksowanie, dziennik pytań,
  ekran „Narzędzie FAQ" oraz zakładki panelu na podstronie.
* **Redaktor i Autor** — generowanie i eksport FAQ przez panel „AI FAQ" w edytorze wpisu.
* **Redaktor** — dodatkowo publikowanie FAQ na publicznej podstronie.
* **Odwiedzający** — wyłącznie zadawanie pytań.

== Installation ==

1. Wgraj katalog wtyczki do `wp-content/plugins/` albo zainstaluj paczkę ZIP przez
   *Wtyczki → Dodaj nową → Wyślij wtyczkę na serwer*.
2. Włącz wtyczkę na liście wtyczek. Przy pierwszym włączeniu powstaje podstrona
   o adresie `generator-faq`, a do menu nawigacji motywu dokładany jest odnośnik
   „Generator FAQ" — o ile motyw ma jakiekolwiek menu przypięte do lokalizacji.
3. Wejdź w *AI FAQ Generator → Ustawienia* i wklej swój klucz Google Gemini.
   Użyj przycisku „Test połączenia", żeby sprawdzić, czy klucz działa.
4. Uzupełnij pole z danymi kontaktowymi. Gdy bot ma tylko częściowe pokrycie tematu,
   odsyła pytającego właśnie tam. Puste pole oznacza ogólne odesłanie do zakładki Kontakt.
5. Wejdź w *AI FAQ Generator → Dashboard* i kliknij „Zaindeksuj treść". Bez tego kroku
   baza wiedzy jest pusta i bot nie ma z czego odpowiadać.
6. Otwórz podstronę `generator-faq` i zadaj pytanie kontrolne.

Generator FAQ możesz osadzić także własnym shortcode'em `[aifaq_generator]` w dowolnym
miejscu witryny.

**Po każdej większej zmianie treści uruchom indeksowanie ponownie.** Wtyczka przypomni
o tym komunikatem, gdy wykryje, że treść zmieniła się od ostatniego indeksowania.

== Frequently Asked Questions ==

= Czy wtyczka odpowiada na dowolne pytania, jak zwykły czat? =

Nie i to jest celowe. Odpowiada wyłącznie w temacie treści Twojej witryny. Pytanie
niepowiązane z tematem strony dostaje odmowę zamiast zmyślonej odpowiedzi.

= Muszę płacić za API? =

Nie musisz. Wtyczka jest przystosowana do darmowego przydziału Gemini i ma wbudowane
limity, które go pilnują. Przy większym ruchu darmowa pula może nie wystarczyć — wtedy
decyzja o płatnym kluczu należy do Ciebie. Ustawienie dobowego sufitu na `0` wyłącza go
całkowicie i ma sens wyłącznie przy kluczu płatnym.

= Dlaczego bot odpowiada „nie wiem", chociaż informacja jest na stronie? =

Najczęściej dlatego, że baza wiedzy nie została przeliczona po dodaniu tej treści.
Uruchom „Zaindeksuj treść". Jeśli to nie pomaga, obniż próg dopasowania w Ustawieniach —
domyślna wartość jest dobrana ostrożnie, żeby wtyczka wolała się przyznać do niewiedzy,
niż zmyślić.

= Skąd wtyczka bierze treść do indeksowania? =

Z wpisów i stron, z pól dodatkowych (w tym ACF), a gdy trzeba — pobiera stronę tak, jak
widzi ją niezalogowany gość, i odsiewa powtarzalny balast: nagłówek, stopkę, menu.

= Czy wtyczka zadziała na motywie blokowym (FSE)? =

Sam generator tak. Nie doda natomiast odnośnika do nawigacji, bo motywy blokowe budują
menu inaczej. Wtyczka wykrywa taki motyw i mówi o tym wprost w kokpicie — wtedy dodajesz
odnośnik ręcznie.

= Czy wtyczka utworzy menu, jeśli motyw go nie ma? =

Nie. Gdy motyw nie ma żadnego menu przypiętego do lokalizacji, wtyczka wyłącznie o tym
informuje. Automatyczne utworzenie menu w wielu motywach wyłącza ich zapasową nawigację
i skasowałoby to, co odwiedzający widzą dziś.

= Usunąłem podstronę generatora. Wróci sama? =

Nie, jeśli usunąłeś ją trwale — automat nie kasuje decyzji właściciela witryny. W kokpicie
pojawi się komunikat z przyciskiem „Utwórz podstronę ponownie".

= Czy da się zmienić uprawnienia do narzędzia FAQ? =

Tak, filtrem `aifaq_tool_capability`. Obejmuje panel w edytorze wpisu i trasy REST
narzędzia. Ekran „Narzędzie FAQ" w kokpicie pozostaje przy uprawnieniach administratora.

== Changelog ==

Skrót najważniejszych wydań. Pełna historia zmian znajduje się w repozytorium projektu.

= 1.0.0 =
* **Wydanie domykające produkt.** Zakres ze zlecenia zamknięty, wtyczka gotowa do oddania.
* Bez zmian funkcjonalnych względem 0.34.0 — to domknięcie numeru wersji, a nie nowe funkcje.
* Schemat bazy bez zmian; aktualizacja z każdej wersji 0.2x–0.34 nie wymaga żadnych działań.

= 0.34.0 =
* Pierwsze wydanie z plikami `LICENSE` (pełny tekst GNU GPL v2) i `readme.txt`.
* Testy obciążeniowe: usunięty wyścig przy blokadzie ponownego indeksowania, paginacja
  historii przyspieszona nawet 31-krotnie na dużych zbiorach.
* Przejście całego cyklu życia wtyczki na żywej witrynie: zamek publikacji FAQ (dwie
  równoczesne publikacje nie nadpisują się już po cichu), odinstalowanie usuwa oba
  zaplanowane zadania, zapis ustawień odświeża adresy także poza kokpitem.
* Dokumentacja zderzona z kodem — poprawione m.in. opisy uprawnień do narzędzia FAQ
  oraz działania dobowego limitu.

= 0.33.0 =
* Komplet testów przekrojowych produktu: 11 obszarów, 157 nowych asercji.
* Poprawka: generator nie przycinał świeżo utworzonych par przed zapisem.

= 0.32.0 =
* Pięć poprawek przed wydaniem: generator FAQ liczony do dobowego sufitu witryny,
  serwerowe przycinanie opisu, limit par przy publikacji, czytelny błąd przy nieudanym
  indeksowaniu, wymuszenie pomijania pamięci podręcznej na podstronie.

= 0.31.0 =
* Tytuł strony dołączany do każdego fragmentu przy indeksowaniu — poprawia trafność
  odpowiedzi dla treści z dalszej części długich stron.
* Komunikat „treść zmieniła się od ostatniego indeksowania".

= 0.30.0 =
* Uporządkowanie warstwy REST i zawężenie publikacji FAQ do uprawnień Redaktora.
* Usunięcie wpisu do kosza czyści go teraz również z bazy wiedzy bota.

= 0.29.0 =
* Indeksowanie wznawia się samo, gdy przerwie je budżet czasu — bez wielokrotnego
  klikania na dużych witrynach.
* Miernik zużycia dobowego sufitu na Dashboardzie.
* Limit 10 generowań FAQ na godzinę na użytkownika.
* Pamięć podręczna odmów spoza tematu — powtórzone pytanie nie zużywa ponownie puli API.

= 0.28.0, 0.27.0 =
* Nagłówki bezpieczeństwa i polityka CSP dla podstrony generatora.
* Zamknięta podatność na wstrzyknięcie instrukcji przez treść indeksowanej strony.

= 0.26.0, 0.25.0 =
* Audyt bezpieczeństwa całej wtyczki.
* Odinstalowanie nie zostawia już żadnych danych w bazie.

= 0.24.0 =
* Dane strukturalne i opis podstrony generatora dostrojone do tematu witryny.

= 0.23.0 =
* Odnośnik do generatora w menu nawigacji, uprawnienia dla Redaktora i Autora,
  dobowy sufit witryny, retencja historii.

= 0.22.0 =
* Kalibracja jakości odpowiedzi bota — koniec z jednozdaniowymi odpowiedziami
  i fałszywymi odmowami.

== Upgrade Notice ==

= 0.22.0 =
Po aktualizacji uruchom „Zaindeksuj treść" jeden raz. Baza wektorów liczona jest
dokładniejszą metodą i wymaga jednorazowego przeliczenia.
