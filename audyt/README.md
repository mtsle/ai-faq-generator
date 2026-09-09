# Dział audytu

Ten katalog dokumentuje **audyt jakości obu wtyczek z tego repozytorium** —
AI FAQ Generator (korzeń repo) i AI News Portal (podkatalog `ai-news-portal/`) —
oraz naprawy, które z niego wynikły.

Audyt nie był przeglądem „na oko". Był osobnym procesem z własnym kontraktem,
własnymi rolami i twardą zasadą: **audyt tylko znajduje błędy, nigdy ich nie
naprawia**. Naprawa była decyzją podjętą później i osobno.

Wynik w jednym zdaniu: **48 potwierdzonych pozycji** (1 krytyczna, 13 dużych,
27 średnich, 7 małych), rozszerzonych przy naprawie do **58 pozycji**, wszystkie
zamknięte — każda z naprawą, testem, zabezpieczeniem w kodzie i dowodem, że ten
test naprawdę działa.

---

## Co tu jest

| Plik | O czym mówi |
|---|---|
| [ARCHITEKTURA.md](ARCHITEKTURA.md) | Jak audyt był zbudowany: pięć faz, role, lockstep, kontrakt wymiany. Odpowiada na pytanie „skąd wiadomo, że to nie było zgadywanie". |
| [RAPORT-AUDYTU.md](RAPORT-AUDYTU.md) | Co znaleziono. 48 pozycji, wagi, powtarzające się wzorce błędów i trzy przykłady rozpisane do końca. |
| [NAPRAWY.md](NAPRAWY.md) | Co naprawiono i jak to udowodniono. Reguła czterech rzeczy na pozycję, 12 faz napraw, 118 dowodów mutacyjnych. |
| [KONTROLE-AUTOMATYCZNE.md](KONTROLE-AUTOMATYCZNE.md) | Co sprawdza się samo, przy każdym pushu. Jak uruchomić to u siebie. |
| [ZAKRES-SPRAWDZONY.md](ZAKRES-SPRAWDZONY.md) | Co było objęte audytem, a co świadomie NIE — i dlaczego. |
| [zrodla/](zrodla/) | Surowe artefakty przebiegu: oryginalny raport, plan akcji, dziennik fal, mandaty autorów. Bez obróbki. |

Dokumenty `.md` są opisem dla czytelnika. `zrodla/` to materiał, z którego
powstały — gdyby ktoś chciał sprawdzić, czy opis nie jest ładniejszy od faktów.

---

## Jak to jest zorganizowane — trzy poziomy

Jakość tego projektu trzyma się na trzech poziomach, które robią różne rzeczy
i łapią różne błędy.

**Poziom 1 — testy, które chodzą same.**
91 zestawów testowych, uruchamianych przy każdym pushu przez GitHub Actions.
Wtyczka 1: 62 zestawy. Wtyczka 2: 29 zestawów i **2305 asercji**, liczonych
co do jednej — runner nie zalicza segmentu, jeśli liczba wykonanych asercji nie
zgadza się z zadeklarowaną. To brzmi drobiazgowo, dopóki nie zobaczy się, do
czego służy: test, który po cichu przestał cokolwiek sprawdzać, dalej świeci
na zielono. Kontrakt liczbowy to wyłapuje.

**Poziom 2 — audyt wieloagentowy.**
To, co opisuje ten katalog. Osobny przebieg, osobne role, wynik wydawany przez
re-audyt, nie przez audyt. Szuka tego, czego testy z definicji nie znajdą:
błędów w samych testach, rozjazdów między dokumentacją a kodem, ścieżek, których
nikt nie napisał.

**Poziom 3 — naprawa z dowodem.**
Każda naprawa musiała pokazać, że jej strażnik działa: cofnięcie poprawki na
kopii poza repozytorium musiało przestawić test z zielonego na czerwony.
Bez tego pozycja nie była uznana za zamkniętą.

---

## Ponowny audyt — dlaczego jedno przejście nie wystarcza

Najważniejsza decyzja architektoniczna tego procesu: **wynik ostateczny wydaje
re-audyt, a nie audyt**.

Audyt przechodzi obszar i zamyka go. Dopiero wtedy na ten sam obszar wchodzi
re-audyt — z własnymi agentami, znający zgłoszenia poprzednika, z zadaniem
szukania głębiej. Nigdy dwa sektory na tym samym obszarze naraz (lockstep).

Liczby pokazują, po co: **audyt zgłosił 36 rzeczy, re-audyt 51**. Drugie
przejście po tym samym kodzie, z wiedzą o wynikach pierwszego, znalazło ich
więcej — nie mniej. Pierwsze przejście uczy się projektu; dopiero drugie widzi.

Ta sama zasada zadziałała potem przy naprawach, na mniejszą skalę i już
bez agentów. Kilka razy **mutacja obaliła moją własną, świeżo napisaną
poprawkę**: strażnik przechodził, ale z niewłaściwego powodu. Przykłady stoją
wprost w [NAPRAWY.md](NAPRAWY.md) — bo to najciekawsza część tej pracy,
nie wstydliwa.

---

## Kalibracja — dlaczego „nic nie znaleziono" tutaj coś znaczy

Proces, który zawsze coś znajduje, jest bezużyteczny tak samo jak proces, który
nigdy nic nie znajduje. Dlatego w tym przebiegu **kalibrację robiono wprost**:

- **Dwa obszary zamknęły się z zerem pozycji** (`architekt`, `konrad`) — i to
  jest wynik, nie porażka. Ich mandaty stoją w [zrodla/MANDATY.txt](zrodla/MANDATY.txt).
- **Weryfikator odrzucał zgłoszenia.** Z 51 zgłoszeń re-audytu jedno zostało
  odrzucone, jedno zostawione jako otwarte pytanie o zasadę. Odrzucenie ma
  w raporcie uzasadnienie: weryfikator wykazał, że opisana ścieżka jest
  **nieosiągalna**, więc pozycja nie weszła do wyniku mimo poprawnych liczb.
- **Zgłoszenie bez cytatu z kodu i bez dowodu będącego czynnością nie istniało.**
  „Wygląda podejrzanie" nie było zgłoszeniem.
- **Projekt po audycie musiał być identyczny jak przed.** Zmierzone: te same
  wartości progów, stałych, wersji i schematu bazy przed i po
  (`WARTOSCI IDENTYCZNE — projekt nietknięty`), na tym samym commicie `66e5ad4`.
  Jedyną różnicą, jaką audyt zostawił po sobie, była wiedza o błędach.

---

## Czego tu nie ma

Nie ma tu marketingu. Kilka znalezisk było niewygodnych — na przykład to, że
**jeden z testów przechodził na zielono przy celowo zepsutym kodzie
produkcyjnym**, co audyt udowodnił wykonawczo, a nie przez rozumowanie.
Takie pozycje są opisane tak samo dokładnie jak reszta, bo one najwięcej mówią
o tym, czy proces działa.
