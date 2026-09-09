# Architektura audytu

Jak ten audyt był zbudowany i dlaczego akurat tak. Dokument odpowiada na jedno
pytanie: **skąd wiadomo, że wynik nie jest zgadywaniem**.

---

## Zasady nadrzędne

Sześć reguł, których nie wolno było złamać żadnej roli:

1. **Audyt TYLKO znajduje błędy.** Nie naprawia, nie proponuje łatek, nie pisze
   kodu. Naprawa jest osobną decyzją, poza tym procesem.
2. **Zgłoszenie bez cytatu z kodu i bez dowodu będącego CZYNNOŚCIĄ nie istnieje.**
   Dowodem jest wykonany grep z liczbą trafień, odczytany zakres linii,
   uruchomiony test — nie wrażenie.
3. **Wagi nadaje wyłącznie jedna rola** (autor raportu). Każdy inny agent miał
   w polu `waga` literalne „do ustalenia przez audytor-raportu". Nikt nie mógł
   podbić własnego znaleziska.
4. **Krytyk nigdy nie jest słabszy od modelu autora recenzowanej pracy.**
   Recenzja słabszym modelem to teatr.
5. **Projekt po audycie ma być IDENTYCZNY jak przed.** Jedyną różnicą jest
   wiedza o błędach.
6. **Zakres zamrożony:** obie wtyczki, gałąź `main`, tryb demo poza zakresem.
   Audyt szedł PRZED mergem gałęzi `demo`, bo merge przesunąłby numery linii
   i unieważnił 454 kotwice `plik:linia` z dokumentacji wejściowej.

---

## Pięć faz

### Faza 1 — weryfikacja wstępna

Zanim ktokolwiek zaczął szukać błędów, osobny dział sprawdził **8 dokumentów
wejściowych znak w znak z kodem**: czy 454 pozycje (reguły, progi, uprawnienia,
zachowania, scenariusze awarii) faktycznie zgadzają się z plikami.

Powód jest praktyczny: *dokument, który kłamie, zatruwa każde zgłoszenie w nim
zakotwiczone*. Audytor porównujący kod z nieprawdziwą regułą produkuje
zgłoszenia, które wyglądają solidnie i są bezwartościowe.

Wynikiem fazy jest **`WARTOSCI-START`** — zrzut najważniejszych wartości
projektu (progi, stałe, wersje, schemat bazy, liczba tras, budżety czasu).
Nie spis plików: same liczby, które da się porównać.

### Faza 2 — audyt

Osiem obszarów, w każdym audytor prowadzący dział agentów, przy każdym agencie
krytyk, na końcu weryfikator zgłoszeń.

Obszary: `security`, `backend`, `bd`, `frontend`, `qa`, `performance`,
`architekt`, `konrad` (łamacz założeń — szuka wyłącznie ścieżek, **których nie
ma**: brakujących gałęzi błędu, nieobsłużonych stanów).

Wynik tej fazy to lista **robocza**. Audyt nie wydaje wyniku ostatecznego.

### Faza 3 — re-audyt (lockstep, krok za audytem)

Drugi pełny sektor wchodzi na obszar **dopiero co zamknięty** przez audyt.
Ma własnych agentów, własnych krytyków i własny proces weryfikacji. Zna
zgłoszenia poprzednika. Zadanie: szukać głębiej, mocniej pilnować wartości
i **sprawdzać kolizje** między znalezionymi błędami.

```
audyt: obszar N ──► zamknięty
                     │
                     ▼
                  re-audyt: obszar N        audyt: obszar N+1
```

Nigdy dwa sektory na tym samym obszarze naraz.

**To re-audyt wydaje wynik ostateczny.** Raport końcowy powstał wyłącznie
z pozycji sektora `reaudyt` z werdyktem `potwierdzony`. 36 zgłoszeń audytu
było materiałem wejściowym, nie wynikiem.

### Faza 4 — weryfikacja końcowa

Te same wartości co na starcie, policzone ponownie i porównane.

**Kryterium jest zero-jedynkowe:** `START = KONIEC` znaczy „projekt nietknięty".
Jakakolwiek różnica znaczy, że proces coś zgubił albo dotknął kodu — i jest
zgłaszana jako **naruszenie procesu**, nie jako drobiazg.

Wynik tego przebiegu: `WARTOSCI IDENTYCZNE — projekt nietknięty`, na tym samym
commicie `66e5ad4`. Jedyna rola, która w ogóle wykonała mutację (weryfikator),
robiła ją **na kopii poza repozytorium** i odnotowała czyste
`git status --porcelain`.

### Faza 5 — plan akcji

Segregacja wyniku na decyzje do podjęcia. Dla każdej pozycji: waga, miejsce
i jedno zdanie o tym, **czego dotyczy decyzja**.

Zakaz obowiązuje do końca: zero napraw, zero łatek, zero „jak to zrobić".

---

## Role

| Rola | Co robi | Czego NIE wolno jej robić |
|---|---|---|
| **kierownik** | Prowadzi przebieg, planuje fale, pilnuje lockstepu i kompletności | Nie audytuje kodu, nie nadaje wag |
| **audytor obszaru** | Prowadzi dział agentów, scala zgłoszenia bez przycinania | Sam kodu nie audytuje |
| **agent** | Faktyczne szukanie błędów w przydzielonych plikach | Nie naprawia, nie nadaje wag |
| **krytyk** | Ocenia **artefakty pracy autora**: pokrycie, formę, jakość dowodów, granice obszaru. Wydaje ACCEPT albo REJECT | Nie audytuje kodu, nie dopisuje zgłoszeń |
| **weryfikator** | **Jedyna rola orzekająca, czy problem istnieje.** Porównuje cytat z plikiem znak w znak, powtarza dowód własnymi rękami, sprawdza osiągalność ścieżki | Nie audytuje kodu, nie nadaje wag |
| **autor raportu** | Pisze raz, na końcu, z wyniku re-audytu. **Jedyna rola nadająca wagi** | Nie proponuje napraw |

Komunikacja była wąska celowo: agent rozmawia z audytorem, audytor
z kierownikiem i weryfikatorem. Nikt nie widzi całości poza kierownikiem —
żeby zgłoszenia nie zaczęły się wzajemnie potwierdzać.

Przebieg objął **46 mandatów autorskich** (spis w [zrodla/MANDATY.txt](zrodla/MANDATY.txt)),
z bibliotekami definicji ról po stronie obu sektorów.

---

## Kontrakt wymiany

Agenci nie przekazują sobie wyników w rozmowie. Wymiana idzie **plikiem**:
`WYMIANA-AUDYT-REAUDYT.jsonl` plus baza SQLite budowana ze skryptu.

Powód jest prozaiczny i dobrze pokazuje, jak takie rzeczy wychodzą w praktyce:
**sześciu z ośmiu audytorów nie ma w ogóle narzędzia do uruchamiania poleceń.**
Kontrakt musiał więc opierać się na czymś, co potrafi każda rola — na zapisie
pliku w ustalonym formacie.

Format wymusza **przedbramka**: rekord bez wymaganych pól nie wchodzi.
Jeden z takich braków był kosztowny i został wykryty na żywo przed przebiegiem:
rekord zgłoszenia nie miał pola `typ`, więc parser odkładał **każde** zgłoszenie
do `bledne_linie` — cicho i bez alarmu.

---

## Skala przebiegu

| | |
|---|---|
| Fale | **17** (2026-09-02 → 2026-09-07), dziennik w [zrodla/DZIENNIK-FAL.tsv](zrodla/DZIENNIK-FAL.tsv) |
| Zgłoszeń w wymianie | **87** — sektor audyt 36, sektor re-audyt 51 |
| Werdyktów weryfikatorów | **87** — komplet, 1:1 do zgłoszeń |
| Sygnałów | **75** — tropy przekazywane między agentami, bez werdyktu i bez wagi |
| Pozycji w raporcie | **48** (wyłącznie re-audyt, wyłącznie potwierdzone) |

Rozjazd 87 → 48 nie jest stratą. Tak wygląda proces, w którym zgłoszenie musi
przejść przez krytyka i weryfikatora, a wynik wydaje wyłącznie drugi sektor.

---

## Co bym zrobił inaczej

Uczciwie, bo to też część obrazu:

- **Przedbramka powinna powstać przed pierwszą falą, nie w trakcie.** Błąd
  z brakującym polem `typ` kosztował przebieg, zanim ktokolwiek zobaczył wynik.
- **Słownictwo kontroli jakości było wzięte z PHPUnit**, którego ten projekt
  nie używa — asercja nazywa się tu `check(`, nie `assert`. Pierwsze przejście
  szukało czegoś, czego w kodzie nie ma.
- **Liczenie defektów po ścieżce pliku jest mylące.** Poprawne kryterium to
  substancja: czy błąd siedzi w kodzie, który dostaje klient, czy w teście.
  Po tym podziale wynik to 35 pozycji w kodzie klienta i 13 w testach.
