# Praca w tym repozytorium

W repozytorium żyją **dwie niezależne wtyczki WordPress** — układ katalogów, wersje i mapę
dokumentacji opisuje [README.md](README.md). Ten plik mówi, jak się tu pracuje: co musisz mieć,
jak przechodzi zmiana, i czego wymagamy od każdej poprawki, zanim uznamy ją za skończoną.

Jedna zasada jest ważniejsza od reszty i wszystko inne z niej wynika:

> **Każda naprawa dostaje asercję, która zaczerwieni się po jej cofnięciu.**
> Zielony przebieg testów nie jest dowodem, że coś naprawiłeś — jest dowodem, że nic nie zepsułeś.

## Spis treści

- [Zanim zaczniesz](#zanim-zaczniesz)
- [Przepływ pracy](#przepływ-pracy)
- [Wersjonowanie i tagi](#wersjonowanie-i-tagi)
- [Konwencje commitów](#konwencje-commitów)
- [Testy: jak dopisać asercję](#testy-jak-dopisać-asercję)
- [Dowód mutacyjny](#dowód-mutacyjny)
- [Definicja ukończenia](#definicja-ukończenia)
- [Zasady twarde](#zasady-twarde)
- [Pułapki środowiska](#pułapki-środowiska)
- [Zgłaszanie błędów](#zgłaszanie-błędów)

## Zanim zaczniesz

Potrzebny jest **PHP CLI z rozszerzeniem `mbstring`** i `bash`. To wszystko. Testy nie wymagają
bazy danych, sieci ani zainstalowanego WordPressa — zamiast nich chodzą atrapy, więc pełny
przebieg trwa kilkanaście sekund.

```bash
bash .github/ci/testy-wtyczka1.sh
bash .github/ci/testy-wtyczka2.sh
```

Oba runnery biorą interpreter ze zmiennej `PHP`, a domyślnie wołają `php` z `PATH`:

```bash
PHP=/sciezka/do/php bash .github/ci/testy-wtyczka1.sh
```

Oczekiwany wynik to `WYNIK: WSZYSTKIE SEGMENTY OK` z obu runnerów. Cokolwiek innego jest
awarią, także wtedy, gdy „to na pewno nie moja zmiana".

Żywy WordPress przyda się dopiero do odbioru zmian widocznych dla użytkownika — warianty
uruchomienia opisuje sekcja „Lokalny WordPress" w [README.md](README.md).

## Przepływ pracy

```
gałąź robocza  ->  commit(y)  ->  push  ->  pull request do main  ->  CI zielone  ->  merge
```

1. **Gałąź robocza od `main`.** Nazwa opisuje zadanie, nie osobę.
2. **Commituj po zamkniętych całościach**, nie po plikach. Każdy commit ma zostawiać
   repozytorium w stanie spójnym: nowa klasa wchodzi razem ze swoim `require_once`, nowa
   asercja razem z podniesioną liczbą w tabeli runnera.
3. **Przed pushem uruchom oba runnery.** Nie jeden — oba. Zmiana we wtyczce 1 potrafi ruszyć
   strażnika, który pilnuje rozdzielności wtyczek.
4. **Pull request do `main`.** Opis mówi, co się zmienia i **czym to udowodniono**.
5. **CI musi być zielone.** Czerwone CI nie jest do obejścia, tylko do naprawienia.
6. **Merge dopiero po zielonym CI.**

`main` jest linią wydawniczą. Gałąź `demo-1.1.0` niesie tryb demonstracyjny publicznej wystawy
i **nie jest przeznaczona do scalenia** z `main` — ma własny numer wersji i celowo żyje obok.

## Wersjonowanie i tagi

Obie wtyczki idą według SemVer, **niezależnie od siebie**, i mają własne prefiksy tagów:

| Wtyczka | Prefiks tagu | Przykład |
|---|---|---|
| AI FAQ Generator | `aifaq-` | `aifaq-v1.1.0` |
| AI News Portal | `ai-news-portal-` | `ai-news-portal-v1.1.0` |

Tagi `v0.1.0`–`v1.0.0` **bez prefiksu** to historia wtyczki 1 sprzed rozdzielenia. Nie nadawaj
już nowych tagów w tej formie.

Numer wersji wtyczki 1 ma **cztery źródła** i wszystkie muszą podawać to samo:

1. pole `Version:` w nagłówku `ai-faq-generator.php`,
2. stała `AIFAQ_VERSION` w tym samym pliku,
3. `Stable tag:` w `readme.txt`,
4. najnowszy wpis changeloga w `readme.txt`.

Pilnuje tego [`tests/wersja-spojnosc-test.php`](tests/wersja-spojnosc-test.php), więc rozjazd
przewraca testy, a nie wychodzi dopiero u klienta. Wtyczka 2 ma własną bramkę tej samej klasy
w [`ai-news-portal/tests/etap85-readme-test.php`](ai-news-portal/tests/etap85-readme-test.php).

**Numeru wersji nie wpisuj do treści dokumentów.** Źródła instrukcji stoją na znaczniku
`{{WERSJA}}`, który podstawia builder z nagłówka wtyczki. Liczba wpisana ręcznie tworzy drugie
źródło prawdy — ten błąd wyszedł już dwa razy i za każdym razem PDF-y u klienta kłamały.

## Konwencje commitów

Piszemy po polsku. **Temat commita opisuje SKUTEK, nie czynność.** Historia ma się czytać jak
dziennik projektu, a nie jak lista plików.

Prawdziwe przykłady z tego repozytorium:

```
F1: retencja dziennika Q&A liczona po dacie, nie po kluczu głównym
F10: ślepota testów — siedem asercji, które świeciły na zielono przy zepsutym kodzie
F13 (2/5): scalenie main po PR #2 — README przestaje przeczyć własnemu repo
Higiena README: dwa nieaktualne twierdzenia dopasowane do kodu
```

Zły temat: `poprawki`, `fix testów`, `update README`. Dobry temat da się przeczytać za rok
i wiedzieć, co się zmieniło i dlaczego.

**Ciało commita niesie dowody.** Zmierzone liczby, nie wrażenia:

- co dokładnie było źle i od kiedy,
- jaka asercja tego pilnuje,
- jaka mutacja przestawiła ją z zielonej na czerwoną,
- wynik obu runnerów po zmianie.

Jeśli poprawiasz własny wcześniejszy błąd, napisz to wprost. Sprostowanie w historii jest
warte więcej niż gładki opis.

## Testy: jak dopisać asercję

**Liczba asercji może tylko rosnąć.** Usunięcie istniejącej asercji po to, żeby runner był
zielony, jest niedopuszczalne. Wolno ją **zmienić** — jawnie i z uzasadnieniem w kodzie, obok
samej asercji. Zdarzyło się to dwa razy i za każdym razem powód był ten sam: asercja utrwalała
defekt, który właśnie naprawialiśmy.

Księgowość różni się między wtyczkami:

| | Wtyczka 1 | Wtyczka 2 |
|---|---|---|
| Kryterium runnera | kod wyjścia zestawu | kod wyjścia **oraz** dokładna liczba asercji |
| Co zrobić po dodaniu asercji | nic w tabeli runnera | podnieść liczbę w [`.github/ci/testy-wtyczka2.sh`](.github/ci/testy-wtyczka2.sh) |

> [!IMPORTANT]
> Runner wtyczki 2 zalicza segment **dopiero przy dokładnej równości**. Dodajesz jedną asercję
> i nie podnosisz liczby — segment jest czerwony. To celowe: test, który po cichu przestał
> cokolwiek sprawdzać, dalej świeciłby na zielono.

Dodatkowo **23 zestawy wtyczki 1 mają własną podłogę pokrycia w środku pliku** (`$ran >= N`).
Jeśli dokładasz do takiego zestawu asercję, podnieś tam N w tym samym commicie.

Nowy zestaw testów wpina się jako segment do **obu kopii runnera** — tej w `.github/ci/`
i tej roboczej, poza repozytorium. Zmiana listy zestawów w jednej kopii bez drugiej jest
błędem, o którym mówi wprost komentarz na górze runnera.

## Dowód mutacyjny

To jest ta część, której nie da się obejść.

**Cofnij swoją naprawę na kopii repozytorium poza katalogiem roboczym i uruchom testy.**
Asercja, którą dopisałeś, musi przejść z PASS na FAIL. Jeśli nie przechodzi, to nie jest
strażnik — to dekoracja, a naprawa nie jest udowodniona.

Zasady, które wynikły z realnych pomyłek w tym repozytorium:

- **Mutacja musi naprawdę zmieniać to, co mierzy.** Zdarzyło się „udowodnić" normalizację
  końców wierszy na pliku, który już był znormalizowany — zielony wynik nie dowodził niczego.
  Sprawdź kontrolę negatywną: czy kod **przed** poprawką faktycznie czerwienieje na tej samej
  mutacji.
- **Każde ramię bramki złożonej dostaje własną mutację.** Bramka z dwoma warunkami, obalona
  jedną mutacją, ma jedno ramię niedowiedzione.
- **Obrona w głąb utrudnia dowód.** Gdy naprawa ma bramkę wyjściową, cofnięcie pojedynczej
  gałęzi niczego nie zmieni, bo bramka nadal łapie. Wtedy dowód behawioralny wymaga cofnięcia
  całej naprawy, a pojedyncza gałąź zostaje pod asercją strukturalną. Puszczaj oba warianty.
- **Trzy fałszywe zielenie w tym repozytorium złapały dopiero mutacje**, już po naprawie:
  wzorzec spełniany przez komentarz, wycinek pliku sięgający dalej niż zamierzano, i granica
  słowa łapiąca to samo słowo w innym kontekście.

## Definicja ukończenia

Zmiana jest skończona, gdy **wszystkie** punkty są spełnione:

- [ ] Naprawa usuwa przyczynę, a nie objaw.
- [ ] Istnieje asercja, która zaczerwieni się po cofnięciu naprawy.
- [ ] Istnieje **zabezpieczenie w kodzie** — warunek, bramka albo gałąź błędu, przez którą ten
      tryb awarii nie może wrócić po cichu. Komentarz nie jest zabezpieczeniem.
- [ ] Dowód mutacyjny wykonany, z wynikiem opisanym w commicie.
- [ ] Liczby asercji zaktualizowane tam, gdzie trzeba (runner wtyczki 2, podłogi pokrycia).
- [ ] Oba runnery zielone.
- [ ] Dokumentacja mówi to samo co kod — README, `readme.txt` i changelog są częścią zmiany,
      nie osobnym zadaniem na potem.

Zakazane jako stan wyjściowy: „TODO", „na razie", „w kolejnym kroku", „poza zakresem".
Jeśli czegoś świadomie nie robisz, napisz to wprost w opisie zmiany, razem z powodem.

## Zasady twarde

1. **Wtyczki są w pełni rozdzielne.** Żadnego wspólnego kodu, opcji ani tabel. Wtyczka 1 nie
   dotyka danych wtyczki 2 i odwrotnie. Pilnuje tego strażnik, który zderza literały
   z `uninstall.php` z prawdziwymi kluczami drugiej wtyczki — napis `ainp` nie musiałby się
   nigdzie pojawić, żeby jej dane zniknęły.
2. **Nie przenoś ani nie przemianowuj katalogów wtyczek.** Na te ścieżki celują junctiony
   żywej instalacji, a WordPress skanuje `plugins/` tylko dwa poziomy w głąb.
3. **Klucz API należy do właściciela witryny.** Nigdy nie zapisuj cudzego klucza w repo,
   w teście ani w przykładzie. Wtyczka celowo zdejmuje autoload z opcji, która go niesie.
4. **Zmiana schematu bazy albo `uninstall.php` to osobna decyzja**, nie efekt uboczny.
   Wersja schematu rośnie **dopiero po udanej migracji**, nigdy przed.
5. **Sprzątanie obejmuje całą sieć wielowitrynową.** Pojedynczy strzał zostawia w większej
   sieci resztę nieposprzątaną po cichu — listę witryn bierz stronicowaniem.
6. **Dokumentacja jest zderzana z kodem, nie przepisywana.** Liczba w README, która nie da się
   wyliczyć ze źródeł, prędzej czy później skłamie. Jeśli podajesz liczbę, dodaj asercję, która
   ją policzy.
7. **Strażnik musi dać się uruchomić wszędzie tam, gdzie chodzą testy.** Asercja czytająca plik
   spoza repozytorium jest w CI martwa — cztery zestawy padły z tego powodu przy pierwszym
   zderzeniu z GitHub Actions.

## Pułapki środowiska

Lista rzeczy, które już kosztowały czas. Wszystkie są prawdziwe i wszystkie się powtórzą.

- **PHP bywa poza `PATH`.** Na Windowsie z aplikacją Local interpreter leży w katalogu usług
  Local i trzeba mu jawnie wskazać katalog rozszerzeń oraz `mbstring`. Runnery przyjmują
  ścieżkę zmienną `PHP`, więc najprościej podać im mały skrypt opakowujący.
- **Zielono u siebie nie znaczy zielono w CI.** Testy chodzą na Linuksie. Złapaliśmy tu
  asercję zależną od **kolejności plików zwracanej przez system** (`===` na tablicach porównuje
  także kolejność kluczy — sortuj przed porównaniem) oraz strażników czytających katalog spoza
  repozytorium. Przed PR-em uruchom runnery w kopii repo przeniesionej poza katalog projektu.
- **Git normalizuje końce wierszy przy pobraniu.** Porównanie plików „co do bajtu" potrafi
  zapalić się na świeżym klonie, choć treść jest identyczna. Porównuj treść znormalizowaną.
- **Generujesz kod skryptem? Sprawdź wynik w pliku.** Zjedzony backslash daje kod składniowo
  poprawny i semantycznie pusty — `str_replace` z dosłownym znakiem nowej linii zamiast
  sekwencji ucieczki nie robi nic i wygląda dobrze.
- **Atrapy `$wpdb` mają tylko te metody, których dotyka kod produkcyjny.** Sięgasz w kodzie po
  nową metodę — dołóż ją do atrapy, inaczej dostaniesz błąd krytyczny w teście.
- **Testy wtyczki 2 żyją w bloku `namespace AINP`** i mają jawne `require_once` tylko tych klas,
  których dotykał dotąd kod produkcyjny. Nowa klasa użyta z kodu wymaga dopisania obu rzeczy,
  inaczej test kończy się `Class not found`.

## Zgłaszanie błędów

Zwykłe błędy i propozycje zmian zgłaszaj przez **GitHub Issues**. Opis, który da się odtworzyć,
jest wart więcej niż dokładna diagnoza: wersja WordPressa i PHP, wersja wtyczki, kroki, wynik
oczekiwany i faktyczny.

**Luki bezpieczeństwa zgłaszaj prywatnie do właściciela repozytorium, nie publicznym issue.**
Publiczne zgłoszenie działającej luki wystawia wszystkie żywe instalacje, zanim powstanie
poprawka.
