#!/usr/bin/env bash
# CI-owa kopia runnera testów AI News Portal (wtyczka 2).
#
# ŹRÓDŁO PRAWDY dla tabeli segmentów i liczb asercji:
#   Desktop\strona1\podstrona2plugin\zasoby\run-tests-ai-news-portal.sh (poza repo).
# Zmiana liczby asercji w zestawie WYMAGA aktualizacji w OBU plikach.
# Różnice względem źródła: PHP z PATH (setup-php dostarcza mbstring), reszta 1:1.
#
# Zestaw zaliczony dopiero, gdy spełni WSZYSTKIE cztery warunki naraz:
#   1. kod wyjścia 0,
#   2. zero linii FAIL,
#   3. liczba WYKONANYCH asercji === liczba oczekiwana (dokładna równość),
#   4. zestaw wydrukował linię podsumowania (`WYNIK:` albo `WSZYSTKIE`).

PHP="${PHP:-php}"
TESTS="${AINP_TESTS_DIR:-$(dirname "$0")/../../ai-news-portal/tests}"

run_php() { "$PHP" "$1" 2>&1; }

segments=(
  "S1  Cykl zycia instalacji (Plugin.php, ai-news-portal.php)|krok1-cykl-zycia-test.php:94"
  "S2  Wejscie sieciowe (Http.php)|krok2-http-test.php:137"
  "S3  Kanaly i parsowanie (Feed.php)|krok2-feed-test.php:120"
  "S4  Tozsamosc pozycji i duplikaty (Dedup.php)|krok2-dedup-test.php:112 krok2-zapis-test.php:65 krok3-tresc-test.php:78"
  "S5  Bramki tresci (Filter.php, Article.php)|krok3-filtr-test.php:69 krok3-note-test.php:28 krok3-article-test.php:153 krok3-wymog-test.php:55 krok4-bramka-test.php:67"
  "S6  Model i kontrakt odpowiedzi (Gemini.php, Validator.php)|krok4-gemini-test.php:99 krok4-walidator-test.php:43 krok4-ponowienie-test.php:63 krok7-wejscie-test.php:27"
  "S7  Publikacja idempotentna (Publisher.php)|krok4-publikacja-test.php:114"
  "S8  Orkiestracja przebiegu i zamek (Runner.php)|krok5-przejecie-test.php:35 krok5-ponowienia-test.php:53"
  "S9  Automatyzacja czasowa (cron ainp_tick)|krok5-cron-test.php:137"
  "S10 Kokpit: akcje, nonce, uprawnienia (Admin.php, Admin_Screen.php)|krok2-panel-test.php:81 krok4-akcje-test.php:99"
  "S11 Ustawienia i klucz API (Settings.php)|krok4-ustawienia-test.php:28"
  "S12 Front Centrum Wiedzy (Portal.php, szablony)|krok6-portal-test.php:37 krok6-karty-test.php:123 etap83-szablony-test.php:76"
  "S13 Naglowki bezpieczenstwa (Security.php)|krok7-naglowki-test.php:70"
  "S14 Usuwanie bez sladu (uninstall.php)|krok1-uninstall-test.php:45 krok4-uninstall-test.php:30"
  "S15 Zgodnosc dokumentacji z kodem (README.md + readme.txt)|etap85-readme-test.php:147"
)

total_suites=0; total_fail=0; total_asercji=0; total_oczekiwanych=0
echo "============ TESTY WG SEGMENTÓW ARCHITEKTURY (AI News Portal) ============"
echo "Katalog testów: $TESTS"

if [ ! -d "$TESTS" ]; then
  echo ""
  echo "  BŁĄD: katalog testów nie istnieje: $TESTS"
  exit 1
fi

for entry in "${segments[@]}"; do
  name="${entry%%|*}"; files="${entry#*|}"
  echo ""
  echo "### $name"
  for pair in $files; do
    f="${pair%%:*}"; oczekiwane="${pair##*:}"
    total_suites=$((total_suites+1))
    total_oczekiwanych=$((total_oczekiwanych+oczekiwane))

    if [ ! -f "$TESTS/$f" ]; then
      printf "  ✗ %-34s BRAK PLIKU\n" "$f"
      total_fail=$((total_fail+1)); continue
    fi

    out="$(run_php "$TESTS/$f")"; code=$?
    ok=$(printf '%s\n' "$out"   | grep -cE '^[[:space:]]*OK[[:space:]]')
    zle=$(printf '%s\n' "$out"  | grep -cE '^[[:space:]]*FAIL[[:space:]]')
    podsum=$(printf '%s\n' "$out" | grep -cE 'WYNIK:|WSZYSTKIE')
    wykonane=$((ok+zle))
    total_asercji=$((total_asercji+wykonane))

    powod=""
    [ $code -ne 0 ]                 && powod="$powod kod-wyjscia=$code"
    [ "$zle" -ne 0 ]                && powod="$powod FAIL=$zle"
    [ "$wykonane" -ne "$oczekiwane" ] && powod="$powod asercje=$wykonane/oczekiwano=$oczekiwane"
    [ "$podsum" -eq 0 ]             && powod="$powod brak-podsumowania"

    if [ -z "$powod" ]; then
      printf "  ✓ %-34s %s asercji\n" "$f" "$wykonane"
    else
      printf "  ✗ %-34s%s\n" "$f" "$powod"
      total_fail=$((total_fail+1))
    fi
  done
done

echo ""
echo "========================================================================="
echo "Segmentów: ${#segments[@]} | Zestawów: $total_suites | Niezaliczonych: $total_fail"
echo "Asercji wykonanych: $total_asercji | oczekiwanych: $total_oczekiwanych"
if [ $total_fail -eq 0 ] && [ "$total_asercji" -eq "$total_oczekiwanych" ]; then
  echo "WYNIK: WSZYSTKIE SEGMENTY OK"; exit 0
else
  echo "WYNIK: SĄ BŁĘDY"; exit 1
fi
