# Kontrole automatyczne

Co sprawdza się samo, bez proszenia, przy każdym pushu i każdym pull requeście.

---

## Dwa runnery

Każda wtyczka ma własny runner. Oba są zwykłymi skryptami `bash` — bez PHPUnit,
bez Composera, bez bazy danych i bez WordPressa. Testy stawiają atrapy funkcji
rdzenia i badają **zachowanie prawdziwych klas** projektu.

```bash
bash .github/ci/testy-wtyczka1.sh     # AI FAQ Generator
bash .github/ci/testy-wtyczka2.sh     # AI News Portal
```

Wymagania: PHP 8.2 z rozszerzeniem `mbstring`. Nic więcej.

| | Wtyczka 1 | Wtyczka 2 |
|---|---|---|
| Segmenty | 54 | 15 |
| Zestawy | **62** po naprawach (przed: 60) | 29 |
| Kryterium zaliczenia | kod wyjścia zestawu | **dokładna równość liczby asercji** |
| Asercje | nieliczone globalnie | **2306** po naprawach (przed: 2083) |

---

## Dlaczego wtyczka 2 liczy asercje co do jednej

To najważniejsza pojedyncza decyzja w tych kontrolach i warto ją wytłumaczyć.

Runner wtyczki 2 nie pyta „czy zestaw zakończył się sukcesem". Pyta: **czy
wykonał dokładnie tyle asercji, ile zadeklarowano**. Segment z wynikiem
`80/81` jest niezaliczony tak samo jak segment z błędem.

Powód wyszedł z prawdziwej wpadki wcześniej w tym projekcie: test przeszedł,
bo asercja brzmiała `rowCount > 0` zamiast `=== N`. Defekt przeżył dwa pełne
kroki budowy pod zielonym runnerem. Od tamtej pory obowiązuje zasada: **asercje
ilościowe zawsze `=== N`**, a runner pilnuje, żeby żaden zestaw nie przestał
po cichu sprawdzać części swojej roboty.

Ta sama myśl wraca w podłogach pokrycia wewnątrz plików: kilka zestawów ma
własną asercję „wykonano dokładnie N asercji". Wycięcie połowy pliku daje wtedy
czerwień, a nie mniejszą zieleń.

---

## Rodzaje kontroli

**Behawioralne.** Większość. Uruchamiają prawdziwe klasy na atrapach i badają,
co się stało: czy drugie równoczesne żądanie dostaje odmowę, czy wersja
schematu urosła po nieudanej migracji, ile żądań poszło do modelu.

**Strukturalne.** Tam, gdzie zachowania nie da się wywołać bez żywego
WordPressa albo MySQL-a. Czytają kod **bez komentarzy** — bo asercja spełniona
przez komentarz to fałszywa zieleń, i w tym projekcie zdarzyło się to dwa razy.

**Liczące.** Osobna kategoria, która okazała się najcenniejsza. Zamiast
porównywać zdania, test **wylicza wartość ze źródła** i zderza ją z deklaracją:

- strażnik odinstalowania skanuje wszystkie `set_transient()` w kodzie, wyciąga
  największy czas życia (rozwiązując stałe klas i wyrażenia typu `12 * 3600`)
  i porównuje z liczbą zadeklarowaną w `uninstall.php`;
- strażnik harnessu liczy zbiór składowych `$wpdb` faktycznie używanych
  w `src/Data` i wymaga ich wszystkich od adaptera pomiarowego;
- strażnik frontu **liczy odczyty dysku**, przechwytując `file_exists`
  w przestrzeni nazw wtyczki — nie mierzy czasu, bo czas na Windows jest zbyt
  ziarnisty, żeby cokolwiek dowieść.

To ten trzeci rodzaj wykrył, że prawdziwy najdłuższy czas życia transientu
wynosi 24 godziny, a nie 12, jak mówiło zgłoszenie audytu.

**Rozdzielność wtyczek.** Klient dostaje jedną paczkę z dwiema wtyczkami, więc
osobna kontrola pilnuje, żeby odinstalowanie jednej nie tknęło danych drugiej.
Nie pyta „czy w pliku pada obcy prefiks" — bierze **każdy wzorzec `LIKE`** ze
skryptu odinstalowania i sprawdza go wobec prawdziwych kluczy sąsiada. Sam
napis nie musiałby się pojawić, żeby dane zniknęły: wystarczy zbyt szeroki
wzorzec.

---

## Ciągła integracja

[`.github/workflows/testy.yml`](../.github/workflows/testy.yml) — dwa zadania,
oba na `ubuntu-latest`, PHP 8.2 z `mbstring`. Uruchamiane przy pushu na gałęzie
główne i przy każdym pull requeście do `main`.

Runnery są celowo **przenośne**: ten sam skrypt chodzi lokalnie na Windows
i w CI na Ubuntu. Kryterium zaliczenia jest w obu miejscach identyczne.

---

## Harness obciążeniowy

Katalog `tests/load/` zawiera skrypty pomiarowe wymagające żywego MySQL-a,
uruchomionego środowiska lokalnego i wielu procesów systemowych — mierzą
współbieżność, skalę bazy i realny HTTP.

**Żaden runner ich nie uruchamia** i to się nie zmienia. Audyt znalazł w nich
siedem pozycji, więc powstał osobny zestaw czysto statyczny, który te skrypty
**czyta zamiast uruchamiać**: sprawdza, że gałąź obronna istnieje i ma właściwy
kształt. Ograniczenie jest powiedziane wprost w nagłówku tego pliku — dowodzi
kształtu, nie działania na żywym MySQL-u.
