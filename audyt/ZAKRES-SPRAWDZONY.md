# Zakres — co sprawdzono, a czego nie

Dokument istnieje po to, żeby nie trzeba było zgadywać, jak daleko sięga wynik
audytu. Ograniczenia są tu wymienione **wprost**, nie ukryte w przypisach.

---

## Co objęto

**213 plików · 454 pozycje dokumentacji**, każda pozycja zderzona z kodem przez
oba sektory osobno.

| Obszar | Pliki | Pozycje | Audyt | Re-audyt |
|---|---:|---:|---|---|
| security | 30 | 78 | pełne | pełne |
| backend | 60 | 150 | pełne | pełne |
| performance | 42 | 94 | pełne | pełne |
| qa | 28 | 68 | pełne | pełne |
| frontend | 14 | 31 | pełne | pełne |
| bd | 14 | 23 | pełne | pełne |
| architekt | 13 | 10 | pełne | pełne |
| konrad | 12 | 0 | pełne | pełne |
| **Razem** | **213** | **454** | | |

Każdy z 30 autorów obu sektorów zamknął pracę flagą `GOTOWE` z mianownikiem
równym swojemu mandatowi. Flaga była **jedynym** znacznikiem ukończenia —
nie oświadczenie w rozmowie.

---

## Czego NIE sprawdzono

### 1. Tryb demo i gałąź `demo`

Poza zakresem. Kotwicą kodu był wyłącznie `main` na commicie `66e5ad4`.

Ślad wykonawczy tej decyzji jest widoczny do dziś: uruchomienie pełnego runnera
wtyczki 1 daje 63 zestawy przy 1 niezaliczonym, i jest nim `demo-tryb-test.php`
— plik żyjący wyłącznie na gałęzi `demo`. Kopia runnera wersjonowana w repo
tego zestawu nie zawiera i jest zielona.

**Skutek dla czytelnika:** gałąź `demo` nie dostała żadnej z 58 napraw. Jeżeli
kiedyś ruszy publiczna wystawa, przeniesienie zmian jest osobną pracą i osobną
decyzją.

### 2. Obszar `konrad` badał bez dokumentacji

12 plików obu wtyczek nie ma **ani jednej** pozycji dokumentacyjnej do zderzenia
(mandat: 0 pozycji · 12 plików). Badano je metodą własną — łamaniem założeń,
szukaniem ścieżek, których nie ma. Ich zgłoszenia noszą kotwicę „brak pozycji
w dokumentacji".

### 3. Sygnały nie są wynikiem

75 sygnałów — tropów przekazywanych między agentami — **nie ma werdyktów**
i nie zostało rozstrzygniętych jako zgłoszenia. To ślad pracy, nie wynik.
Nie wchodzą do żadnej liczby w raporcie.

### 4. Cztery sygnały utracone

Odchylenie procesu: wyścig dwóch instancji tego samego agenta o jeden plik
skasował cztery sygnały bezpowrotnie. Dotyczyły `Activator.php`/`Plugin.php`,
`Runner.php` i `Plugin.php` wtyczki 2 oraz obu `uninstall.php`.

Wszystkie cztery pliki leżą w mandatach **innych** agentów, a tamte obszary
domknęły się z pełnym pokryciem — ale uczciwie: to była strata, nie sytuacja
kontrolowana. Jest odnotowana w dzienniku odchyleń przebiegu.

### 5. Jeden obszar audytu oddał zero

`frontend` w sektorze **audytu** oddał zero zgłoszeń przy pełnym pokryciu
31/31 pozycji i 14/14 plików. Re-audyt tego samego obszaru oddał **6 zgłoszeń**,
z czego 2 weszły do raportu.

To najczystszy pojedynczy argument za tym, po co jest drugi sektor.

### 6. Audyt był statyczny

Cały przebieg to czytanie kodu, wzorce, uruchamianie **istniejących** zestawów
testowych i jedna mutacja na kopii poza repozytorium.

**Żadnej instalacji WordPressa nie postawiono i żadnego scenariusza nie
odtworzono na żywo.** Wersje inne niż `66e5ad4` nie były badane.

To ograniczenie jest realne. Audyt statyczny nie zobaczy błędu, który ujawnia
się dopiero w konkretnej konfiguracji serwera, przy konkretnej wersji MySQL-a
albo pod obciążeniem. Warstwą, która to częściowo nadrabia, są odbiory na żywej
instalacji lokalnej — prowadzone osobno, przy domykaniu każdego kroku budowy.

---

## Co zmieniło się po audycie

Audyt zamknął się z 48 pozycjami i **zerem zmian w kodzie** — to było kryterium
poprawności procesu, nie efekt uboczny.

Naprawy prowadzono osobno i objęły 58 pozycji (48 z raportu + 10 znalezionych
przy naprawianiu). Stan i dowody: [NAPRAWY.md](NAPRAWY.md).

Dwie pozycje raportu **nie należą do zakresu napraw** i nigdy nie należały:

- `RAU-R15-004` — werdykt **odrzucony**: weryfikator wykazał, że opisana ścieżka
  jest nieosiągalna.
- `RAU-R02-002` — werdykt **do rozstrzygnięcia**, bez wagi: pytanie o zasadę,
  którego wykorzystanie wymaga trzeciej wtyczki o kolidującym slugu, przy
  konsumencie bramkującym się samodzielnie.

Obie stoją w raporcie poza punktacją, z własnymi werdyktami.
