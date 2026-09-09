# Co naprawiono i jak to udowodniono

Audyt tylko znajdował. Naprawa była osobną decyzją i osobną pracą — prowadzoną
już bez agentów, ręcznie, z własnym kontraktem.

---

## Zakres: 58 pozycji, nie 48

Do 48 pozycji raportu doszło **10 pozycji UZUP** — znalezionych nie przez audyt,
tylko przy czytaniu kodu w trakcie naprawiania. Kilka z nich to bliźniaki
zgłoszonych wad, leżące obok w tym samym pliku.

Najlepszy przykład — **UZUP-10**. Pozycja krytyczna dotyczyła retencji dziennika
Q&A liczonej po `id` zamiast po dacie. Przy naprawie okazało się, że **dokładnie
tę samą wadę ma sprzątanie historii generowań**: granica po `ORDER BY id DESC`,
podczas gdy sąsiednia metoda w tym samym pliku sortuje po `created_at`. Ten sam
plik miał dwa różne pojęcia „najnowszy". Wada była uśpiona — budziło ją dopiero
podanie własnej daty przy zapisie, co API tej klasy dopuszcza.

Komplet policzony z dysku, nie przepisany: identyfikatory z commitów naprawczych
pokrywają się co do jednego z pozycjami punktowanymi raportu.

```
47 pojedynczych pozycji raportu
+ 1 pozycja scalona (dwa zgłoszenia, jedno znalezisko)
+ 10 pozycji UZUP
= 58
```

---

## Reguła czterech rzeczy

Kontrakt napraw. **Każda** zamknięta pozycja musiała mieć jednocześnie:

1. **Naprawę** — realną zmianę usuwającą przyczynę.
2. **Strażnika** — asercję w istniejącym zestawie testów, która **zaczerwieni
   się po cofnięciu tej naprawy**.
3. **Zabezpieczenie** — warunek, bramkę albo gałąź błędu w kodzie, przez którą
   ten tryb awarii nie może wrócić po cichu. **Komentarz nie jest
   zabezpieczeniem.**
4. **Dowód mutacyjny** — cofnięcie naprawy na kopii **poza repozytorium** musi
   przestawić strażnika z PASS na FAIL. Bez tego pozycja nie jest zamknięta.

Do tego dwie zasady twarde:

- **Zero odłożonych.** Zakaz słów „TODO", „na razie", „w kolejnym kroku",
  „poza zakresem" jako statusu wyjściowego dla którejkolwiek z 58 pozycji.
- **Liczba asercji może tylko rosnąć.** Nie wolno usunąć istniejącej asercji,
  żeby runner był zielony — wolno ją zmienić, jawnie i z uzasadnieniem
  w kodzie. Zdarzyło się dwa razy: asercje utrwalały defekt, który właśnie
  naprawialiśmy.

Punkt 4 jest tym, który odróżnia tę pracę od „poprawiłem i przechodzi".
**Zielony runner nie jest dowodem.** Dowodem jest runner, który potrafi
zaczerwienić się na żądanie.

---

## Dwanaście faz

| Faza | Commit | Czego dotyczy |
|---|---|---|
| F1 | `4a16abf` | Retencja dziennika Q&A liczona po dacie, nie po kluczu głównym *(pozycja krytyczna)* |
| F2 | `01f5251` | Wersja schematu W1 rośnie dopiero po udanej migracji danych |
| F3 | `870393d` | Budżety czasu i licznik wywołań modelu (W2) |
| F4 | `b87ba73` | Bramki treści W2: sufit znaków, encje, czyszczenie tekstu, prolog XML, Atom |
| F5 | `cb0d5ba` | Ruch sieciowy W2: `robots.txt` i odstęp między żądaniami do hosta |
| F6 | `a10989f` | Cykl życia i publikacja W2: wersja schematu, terminy, zapisy |
| F7 | `93b397d` | Bramki, stan i progi W1 — jedenaście pozycji w ośmiu plikach |
| F8 | `8900420` | Kotwice dokumentacyjne dostawcy — trzy pozycje, w których mylił OPIS, nie kod |
| F9 | `e08883c` | Odinstalowanie obu wtyczek: sieć, stronicowanie, deklaracja TTL |
| F10 | `4e24380` | Ślepota testów — siedem asercji zielonych przy zepsutym kodzie |
| F11 | `45b2c93` | Harness obciążeniowy — siedem gałęzi obronnych i strażnik, którego nie było |
| F12 | `8b34a4f` | Praca frontu W2 — cztery odczyty dysku na każdą kartę listy |

Commity są pisane tak, żeby same się tłumaczyły: co było źle, dlaczego to
działało do tej pory, co dokładnie zmieniono i czym to jest pilnowane.

**Razem 118 dowodów mutacyjnych**, każdy sprawdzony `php -l` przed
uruchomieniem, każdy przestawiający wskazanego strażnika z PASS na FAIL.

---

## Kiedy mutacja obala własną naprawę

Najciekawsza część tej pracy. Kilka razy strażnik przechodził — ale
z **niewłaściwego powodu**, i wyszło to dopiero przy próbie zaczerwienienia go.

**Granica słowa nie wystarczyła.** Asercja pilnująca kompletu kolumn w zapytaniu
sprawdzała obecność podciągu, więc kolumnę `id` zaliczało `user_id` z tej samej
listy. Naprawa: pytać po granicy słowa. Mutacja usuwająca `id` z listy kolumn
**dalej przechodziła** — bo to samo zapytanie kończy się `ORDER BY created_at
DESC, id DESC`, a wzorzec trafiał w klauzulę sortowania. Prawdziwa naprawa musi
pytać o **listę kolumn między SELECT a FROM**, nie o całe zapytanie.

**Asercję spełniał komentarz.** Sprawdzenie „`run_clear()` bierze ten sam zamek"
przechodziło po wycięciu prawdziwego wywołania — spełniało je zdanie
w komentarzu tej metody: *„patrz komentarz przy `acquire_lock()`"*. Wycinek musi
iść po kodzie **bez komentarzy**.

**Wycinek sięgał za daleko.** Ta sama asercja, wcześniejsza wersja: wycinek metody
szedł do końca pliku, więc obejmował definicję `acquire_lock()` stojącą niżej.
Asercja przechodziła, spełniona cudzą linią.

**Pytanie o zmienną zamiast o warunek.** Strażnik bezpiecznika puli API
sprawdzał, czy w bloku występuje `$probe_err`. Wycięcie całej gałęzi obsługi
błędu nie zaczerwieniało go — bo zmienna nadal była **przypisywana** wyżej.

**Podciąg liczby.** `strpos( ..., '500' )` jest spełnione także przez `500000`,
więc podniesienie progu tysiąc razy przechodziło.

**Zdanie złamane na wiersze.** Asercja szukała w docbloku zdania, które w pliku
nigdy nie stoi jednym ciągiem — bo docblock łamie je prefiksem ` * `. Pytanie
o surowy tekst było spełnione **zawsze**, niezależnie od treści.

Wszystkie sześć zostało poprawionych, a mutacje, które je obaliły, zostały
w zestawach jako stałe dowody.

---

## Trzy rzeczy, których audyt nie zgłosił, a naprawa znalazła

**TTL dłuższy, niż mówiło zgłoszenie.** Audyt wskazał, że deklaracja
najdłuższego czasu życia transientu (godzina) jest nieprawdziwa, bo jeden
transient żyje 12 godzin. Rachunek pokazał **24 godziny**: transient limitera
gościa żyje tyle, ile okno limitu, a właściciel wybiera je w ustawieniach —
opcja „doba" to 86 400 s. Deklaracja nie jest już przepisana z pamięci: plik
niesie znacznik maszynowy, a test **sam wylicza** największy TTL ze wszystkich
wywołań w źródle i zderza go z tą liczbą.

**Druga ścieżka tej samej wady.** Pozycja o wersji schematu podnoszonej bez
migracji dotyczyła jednej metody. Naprawa objęła **dwie** — ta sama wada
siedziała też w kroku aktywacji wtyczki, czego zgłoszenie nie wymieniało.

**Sprzeczność w samym zakresie.** Jedna pozycja była w planie opisana jako
zmiana kodu, a w innym dokumencie jako poprawka dokumentacji. Raport miał
**obie połowy** — cichą stratę witryn w sieci wielowitrynowej i nieprawdziwą
regułę techniczną. Zrobione zostały obie.

---

## Efekt liczbowo

| | Przed audytem | Po naprawach |
|---|---|---|
| Zestawy testowe W1 | 60 | **62** |
| Zestawy testowe W2 | 29 | 29 |
| Asercje W2 (liczone co do jednej) | 2083 | **2306** |
| Pozycje otwarte z audytu | 48 | **0** |

Wzrost o 223 asercje to nie „więcej testów dla samych testów" — to strażnicy
wymagani punktem 2 kontraktu, każdy z dowodem, że potrafi zaczerwienić się
na żądanie.

---

> **Uwaga o stanie gałęzi.** Commity `4a16abf`–`8b34a4f` żyją na gałęzi
> `naprawy-przebieg-2` i trafiają na `main` osobnym pull requestem wydania.
> Ten katalog dokumentacyjny idzie wcześniej i celowo osobno — opis procesu
> i zmiana w kodzie to dwie różne rzeczy, i lepiej czytają się w dwóch
> pull requestach niż w jednym.
