# Co znalazł audyt

Streszczenie wyniku dla czytelnika. Pełny raport z dowodem przy każdej pozycji:
[zrodla/RAPORT-AUDYTU.txt](zrodla/RAPORT-AUDYTU.txt).

**Zakres:** obie wtyczki, gałąź `main`, commit `66e5ad4`, data 2026-09-07.

---

## Wynik liczbowo

| Waga | Ile | Co to znaczy |
|---|---|---|
| **KRYTYCZNY** | 1 | Bezpośrednia, nieodwracalna utrata danych |
| **DUŻY** | 13 | Realny skutek dla właściciela witryny albo gościa |
| **ŚREDNI** | 27 | Głównie brak gałęzi błędu i progi znaczące co innego niż ich kotwica |
| **MAŁY** | 7 | Rozjazdy bez skutku wykonawczego |
| **Razem** | **48** | |

Po podziale wg **substancji**, a nie wg ścieżki pliku: **35 pozycji w kodzie,
który dostaje klient**, i **13 w testach**. Ten drugi podział jest ważniejszy,
niż wygląda — błąd w teście nie psuje produktu dziś, ale zdejmuje ochronę
z kodu, który popsuje się jutro.

---

## Dwanaście powtarzających się wzorców

Pozycje nie były przypadkowe. Układały się w wzorce, i to one mówią więcej niż
pojedyncze znaleziska:

| | Wzorzec | Na czym polega |
|---|---|---|
| A | Wersja bez migracji | Ścieżka aktualizacji podnosi numer schematu, nie uruchomiwszy przeniesienia danych |
| B | Próg bez skutku | Ochrona nie działa dokładnie tam, gdzie miała chronić |
| C | Funkcja nie robi tego, co deklaruje | Docblock obiecuje, kod wykonuje mniej |
| D | Test zielony przy zepsutym kodzie | Asercja o zbyt szerokim zasięgu albo nieistniejąca |
| E | Bramka inna niż zadeklarowana | Uprawnienie w kodzie ≠ uprawnienie w dokumencie |
| F | Zmiana stanu przed walidacją | Żądanie odrzucone kosztuje tyle, co przyjęte |
| G | Cichy wynik | Sukces nieodróżnialny od pustki albo od błędu |
| H | Próg znaczy co innego | Kotwica wskazuje inny mechanizm niż opisuje reguła |
| I | Brak testu progu albo tezy | Liczba z dokumentacji nie jest przypięta żadną asercją |
| J | Harness i narzędzia pomiarowe | Błędy w kodzie, który mierzy, a nie w mierzonym |
| K | Styk dwóch wtyczek | Stan i zachowanie tam, gdzie jedna wtyczka może dotknąć drugiej |
| L | Licznik i praca frontu | Koszt rosnący z liczbą elementów, mierzony niepoprawnie |

---

## Trzy pozycje rozpisane

### 1. Retencja kasowała najnowsze zamiast najstarszych (KRYTYCZNY)

`src/Data/QaLogRepository.php:131-141` — jedyna pozycja o nieodwracalnej
utracie danych.

Dziennik pytań gości ma limit liczby wierszy. Sprzątanie wyznaczało granicę
kasowania **po kluczu głównym `id`**, a nie po dacie zdarzenia:

```sql
SELECT id ... ORDER BY id DESC LIMIT 1 OFFSET %d      -- granica
DELETE ... WHERE id <= %d                             -- kasowanie
```

Dopóki `id` rośnie razem z datą, to działa. Przestaje w jednym konkretnym
przypadku: **po migracji historii**. Migrator wstawia wiersze z historycznymi
datami, ale z najwyższymi dostępnymi `id`. Od tej chwili „najwyższe `id`"
znaczy „najstarsza treść".

Skutek: pierwsze sprzątanie zachowuje wpisy zmigrowane (stare) i kasuje
**realnie najnowsze pytania gości**. Dziennik jest jedynym miejscem tych
danych — nie ma skąd ich odtworzyć.

Dowód nie był rozumowaniem. Grep `created_at` po pliku: 10 trafień, w bloku
131-141 **zero**. Grep `ORDER BY`: 4 trafienia, tylko jedno sortuje po `id`.
Rozjazd porządków wskazany w dwóch miejscach naraz: `Migrator.php:65` wstawia
historyczne daty do wierszy o najwyższym `id`, a `QaLogRepository.php:54`
pozwala podać własną datę. Weryfikator powtórzył zakres przez `git show`
i `cat -A`: cytat zgodny znak w znak.

> Ta sama wada miała bliźniaczkę, której audyt nie zgłosił — znalazła się przy
> naprawie. Opis w [NAPRAWY.md](NAPRAWY.md).

### 2. Test przechodził przy celowo zepsutym kodzie (DUŻY)

`ai-news-portal/tests/krok4-akcje-test.php:806-813`

Asercja sprawdzała, że przy braku klucza API przycisk publikacji jest wygaszony.
Szukała atrybutu `disabled` **w całym wyrenderowanym ekranie**. Tyle że funkcja
przygotowująca ekran zeruje też liczbę nieudanych pozycji, więc drugi przycisk
(„Wznów nieudane") ma wtedy **własny** atrybut `disabled`. Pytanie o cały ekran
było więc spełnione przez cudzy przycisk.

To jedyna pozycja **udowodniona wykonawczo, nie przez rozumowanie**: weryfikator
zepsuł na kopii poza repozytorium warunek wygaszający przycisk publikacji
i uruchomił zestaw. Wynik przed mutacją: 99 asercji, 0 błędów. Po mutacji:
**99 asercji, 0 błędów**. Zepsuty kod produkcyjny dał identyczny wynik co
poprawny.

Ten sam plik dwa razy wcześniej zawężał pytanie do wyciętego formularza —
poprawny wzorzec był w nim obecny, tylko nie w tym miejscu.

### 3. Nierówność budżetów znikała na typowym hostingu (DUŻY)

`ai-news-portal/src/Runner.php:403-411`

Automat ma dwa budżety czasu: `TICK_BUDGET` 25 s i `PUBLISH_BUDGET` 30 s.
Reguła techniczna nazywa ich **nierówność** mechanizmem strażniczym — publikacja
musi mieć więcej czasu niż cały tick.

Funkcja przycinająca budżety do limitu PHP zrównywała je na każdym hostingu
z `max_execution_time` do 31 s — czyli na wartości, którą **komentarz w tym
samym pliku nazywa typową**. Mechanizm strażniczy przestawał istnieć dokładnie
tam, gdzie miał działać.

Istniejąca asercja tego nie łapała, bo sprawdzała nierówność **wyłącznie przy
limicie 0** — czyli w jedynym przypadku, w którym przycinanie w ogóle nie
zachodzi.

---

## Co zostało odrzucone

Kalibracja działała w obie strony. Z 51 zgłoszeń re-audytu:

- **49 potwierdzonych** → 48 pozycji raportu (jedna para scalona: to samo
  znalezisko widziane z dwóch plików).
- **1 odrzucone** — `RAU-R15-004`. Wszystkie liczby autora odtworzyły się co do
  trafienia, ale upadła **osiągalność ścieżki**: weryfikator wykazał, że przy
  pustej bazie wiedzy sprzątanie w ogóle się nie wykonuje, a druga droga wymaga
  stanu przeciwnego. Opisany stan szkodliwy jest nieosiągalny, więc pozycja nie
  weszła do wyniku.
- **1 otwarte** — `RAU-R02-002`, pytanie o zasadę, nie o fakt: nonce wystawiany
  za bramką będącą dopasowaniem podciągu w nazwie ekranu. Żeby to wykorzystać,
  musiałaby istnieć trzecia wtyczka o kolidującym slugu, a jedyny konsument
  tego nonce'a bramkuje się samodzielnie. Pozycja została **bez wagi**, jako
  otwarte pytanie.

Dwa obszary (`architekt`, `konrad`) zamknęły się z **zerem pozycji**.

---

## Czy audyt dotknął kodu

Nie. Zmierzone, nie zadeklarowane: te same wartości progów, stałych, wersji
i schematu bazy przed przebiegiem i po nim —
`WARTOSCI IDENTYCZNE — projekt nietknięty`, na commicie `66e5ad4`.

Jedyna rola, która wykonała mutację (weryfikator, pozycja nr 2 wyżej), robiła ją
na kopii poza repozytorium i odnotowała czyste `git status --porcelain`.
