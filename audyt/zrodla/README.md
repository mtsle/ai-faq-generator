# Źródła — surowe artefakty przebiegu

Cztery pliki wygenerowane przez sam przebieg audytu, **bez obróbki**.
Dokumenty `.md` piętro wyżej są ich opisem; te pliki są materiałem, z którego
tamten opis powstał.

Format jest surowy celowo: to nie są dokumenty pisane do czytania, tylko wynik
procesu. Zostawione tak, jak wyszły, żeby dało się sprawdzić, czy opis nie jest
ładniejszy od faktów.

| Plik | Co zawiera |
|---|---|
| **RAPORT-AUDYTU.txt** | Kanoniczny raport. 48 pozycji, każda z miejscem (`plik:linie`), opisem wady, **dowodem będącym wykonaną czynnością** (grepy z liczbą trafień, odczytane zakresy, uruchomienia), skutkiem i jednozdaniowym uzasadnieniem wagi. Plus sekcje: liczby, pokrycie, porównanie wartości start/koniec, podstawa wyniku. |
| **PLAN-AKCJI.txt** | Segregacja 48 pozycji na **decyzje do podjęcia**. Dla każdej: waga, miejsce i jedno zdanie o tym, czego decyzja dotyczy. Zero napraw, zero kodu, zero „jak zrobić" — taki był kontrakt. |
| **DZIENNIK-FAL.tsv** | Znaczniki otwarcia i zamknięcia każdej z 17 fal, 2026-09-02 → 2026-09-07. |
| **MANDATY.txt** | 46 mandatów autorskich: ile plików i ile pozycji dokumentacji przypadało na każdą rolę w obu sektorach. Mianowniki we flagach ukończenia musiały być równe tym liczbom. |

---

## Jak czytać raport

Każda pozycja ma ten sam układ:

```
[RAU-Rxx-yyy]  WAGA
MIEJSCE      plik:linie (która wtyczka)
CO JEST ŹLE  jedno zdanie o mechanizmie
DOWÓD        wykonane czynności z liczbami trafień
WPŁYW        co się dzieje i komu
WAGA — DLACZEGO   jedno zdanie odnoszące się do SKUTKU
```

Identyfikator `RAU-R07-004` czyta się: pozycja re-audytu, agent R07, czwarte
jego zgłoszenie.

**Uwaga na jedną pułapkę przy liczeniu:** dwie pozycje mają wspólny nagłówek
`[RAU-R05-002 + RAU-R07-002]`, bo to jedno znalezisko widziane z dwóch plików.
Naiwne liczenie nagłówków daje 47 zamiast 48.

Na końcu raportu stoją dwie pozycje **poza punktacją**, z własnymi werdyktami —
jedna odrzucona jako nieosiągalna, jedna zostawiona jako otwarte pytanie
o zasadę. Nie wchodzą do żadnej z liczb.

---

## Czego tu nie ma

Pozostałe artefakty przebiegu — plik wymiany między sektorami (`.jsonl`,
ponad pół megabajta), baza SQLite, katalogi zgłoszeń, krytyki i weryfikacji,
zrzuty wartości start/koniec — zostały poza repozytorium. Są to dane robocze
maszynerii, nie wynik: ich objętość przewyższa kod obu wtyczek, a wszystko,
co z nich wynika, jest w raporcie.
