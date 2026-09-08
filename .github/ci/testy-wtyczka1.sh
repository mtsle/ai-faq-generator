#!/usr/bin/env bash
# CI-owa kopia runnera testów AI FAQ Generator (wtyczka 1).
#
# ŹRÓDŁO PRAWDY dla tabeli segmentów:
#   Desktop\strona1\faq-generator\zasoby\run-tests.sh (poza repo).
# Zmiana listy zestawów WYMAGA aktualizacji w OBU plikach.
# Różnice względem źródła: PHP z PATH (setup-php dostarcza mbstring),
# ścieżka testów względem korzenia repo. Reszta 1:1 — w tym kryterium
# zaliczenia (kod wyjścia zestawu), zgodnie z zamrożeniem wtyczki 1 na v1.0.0.

PHP="${PHP:-php}"
TESTS="$(dirname "$0")/../../tests"

run_php() { "$PHP" "$1"; }

segments=(
  "Providers/Http|krok3-provider-test.php krok6-factory-fallback-test.php"
  "Data|krok4-knowledge-repo-test.php"
  "Index|krok5-chunker-test.php krok5-contentsource-test.php krok5-batcher-test.php krok5-indexer-test.php"
  "RAG core|krok6-rag-test.php"
  "Settings (sanitize/clamp)|krok6-settings-rag-test.php"
  "REST aifaq/v1|krok7-rest-test.php"
  "Audyt v0.7.0 (0-5)|krok7-audit-fixes-test.php"
  "Front-app / AppShell (K9)|krok9-appshell-test.php"
  "Settings save/verify + REST (K9)|krok9-settings-rest-test.php"
  "Audyt K9 (cache invalidation)|krok9-audit-cache-test.php"
  "Historia / dziennik qa_log (K10)|krok10-history-test.php"
  "Generator FAQ: rdzeń + repo (K11)|krok11-faqgenerator-test.php krok11-generations-repo-test.php"
  "REST generatora (K12)|krok12-rest-generate-test.php"
  "Exporter FAQ (K14)|krok14-exporter-test.php"
  "Historia generowan (K15)|krok15-generations-rest-test.php"
  "Metabox w edytorze wpisu (K16)|krok16-metabox-test.php"
  "Kontrakt JS<->REST (nazwy pol)|js-rest-contract-test.php"
  "Zrodla tresci z bazy (K17)|krok17-db-sources-test.php"
  "Filtr balastu (K17)|krok17-boilerplate-test.php"
  "Kompozyt zrodel (K17)|krok17-composite-test.php"
  "Crawl + kolejka (K17)|krok17-crawl-test.php"
  "Ustawienia kaskady (K17)|krok17-settings-test.php"
  "Podstrona: panel narzedzia FAQ + niezawodnosc (K18)|krok18-faqtoolpanel-test.php krok18-pageguard-test.php"
  "Provider K19: budzet myslenia, MAX_TOKENS, taskType, retry|krok19-provider-test.php"
  "RAG K19: top-K, kolejnosc, prompt, diagnostyka|krok19-rag-test.php"
  "Ustawienia K19: progi, budzet myslenia, kontakt|krok19-settings-test.php"
  "Migracja K19: podpis, notice, budzet reindeksu|krok19-migracja-test.php"
  "K20 menu: MenuGuard, cykl zycia, komunikaty|krok20-menu-test.php"
  "K20 ustawienia: 8 nowych kluczy, podlogi H2, widok|krok20-ustawienia-test.php"
  "K20 generator FAQ: delimiter danych + retencja|krok20-faq-test.php"
  "K20 uprawnienia: cap narzedzia, macierz rola x trasa, ip_hash|krok20-capy-test.php"
  "K20 limity: parser quotaId, cooldown, limiter, sufit dobowy|krok20-limity-test.php"
  "K20 crawl: zagladzenie workerow, lista failed, ponowienia|krok20-crawl-test.php"
  "SEO podstrony: naglowek widgetu, wezel JSON-LD, tresc strony|seo-jsonld-test.php"
  "Odinstalowanie: kompletnosc kluczy w uninstall.php|uninstall-guard-test.php"
  "Audyt bezpieczenstwa: dziennik, autoload klucza, token crawla, prompt, eksport, IDOR|audyt-bezpieczenstwa-test.php"
  "Dlugi przed K22: F1 async, miernik sufitu, nonce, D10, R1-R3|dlugi-przed-k22-test.php"
  "K21 CSP inline guard: zero onclick/style w plikach standalone|krok21-csp-inline-guard-test.php"
  "K21 podstrona security: dwa profile naglowkow, nonce, filtr|krok21-podstrona-security-test.php"
  "K23 etap 1: indeksowanie + crawl (aktywacja, is_indexable wyjatki)|krok23-etap1-index-crawl-test.php"
  "K23 etap 1: metabox JS kontrakt|krok23-etap1-metabox-js-test.php"
  "K23 etap 1: parser granice (FaqGenerator fallbacki JSON)|krok23-etap1-parser-granice-test.php"
  "K23 etap 1: REST sanityzacja (PairsInput::from_request_for_publish)|krok23-etap1-rest-sanityzacja-test.php"
  "K23 etap 3 segment S1: PairsInput::from_request + PublishService::export + Chunker CJK|krok23-etap3-s1-core-test.php"
  "K23 etap 3 segment S2: GeminiProvider malformed JSON + verify() + HTTP 401/403 + klucz pusty|krok23-etap3-s2-provider-test.php"
  "K23 etap 3 segment S3: RagService sufit dobowy vs wlasciciel (place ale nie odbity)|krok23-etap3-s3-rag-budget-test.php"
  "K23 etap 3 segment S4: Plugin::on_knowledge_post_removed - dowod behawioralny naprawy K23 A2|krok23-etap3-s4-plugin-cleanup-test.php"
  "K23 etap 3 segment S5: Migrator (wp_aifaq_history -> qa_log) - zero pokrycia przed tym testem|krok23-etap3-s5-migrator-test.php"
  "K23 etap 3 segment S6: FIX PRODUKCYJNY - GeneratorService::generate() nie przycinal par|krok23-etap3-s6-generator-clip-test.php"
  "K23 etap 3 segment S7: PublicFaq snapshot OPTION_PREV - zero pokrycia przed tym testem|krok23-etap3-s7-publicfaq-snapshot-test.php"
  "K23 etap 3 segment S8: AskService::map_result - zero pokrycia przed tym testem|krok23-etap3-s8-askservice-test.php"
  "K23 etap 3 segment S10: app.js <-> REST kontrakt (settings/verify/generations-delete)|krok23-etap3-s10-app-js-contract-test.php"
  "K23 etap 5 (testy while): skutki uboczne zapisu ustawien poza is_admin + crony uninstall|krok23-etap5-cykl-test.php"
  "K23 etap 5 (wsad napraw): zamek publikacji D1, S1-S4, progi D3-A/D6-B, martwy kod D4-B|krok23-etap5-naprawy-test.php"
  "Harness obciazeniowy: galezie obronne tests/load (F11, statycznie)|load-harness-guard-test.php"
)

total_suites=0; total_fail=0; seg_fail=0
echo "================ TESTY WG SEGMENTÓW ================"
for entry in "${segments[@]}"; do
  name="${entry%%|*}"; files="${entry#*|}"
  echo ""
  echo "### $name"
  for f in $files; do
    total_suites=$((total_suites+1))
    out="$(run_php "$TESTS/$f" 2>&1)"; code=$?
    last="$(echo "$out" | grep -E 'WSZYSTKIE|OK$|BŁĘD|FAIL' | tail -1)"
    if [ $code -eq 0 ]; then
      printf "  ✓ %-38s %s\n" "$f" "$last"
    else
      printf "  ✗ %-38s %s\n" "$f" "$last"
      total_fail=$((total_fail+1)); seg_fail=$((seg_fail+1))
    fi
  done
done

echo ""
echo "==================================================="
echo "Zestawów: $total_suites | Niezaliczonych: $total_fail"
if [ $total_fail -eq 0 ]; then echo "WYNIK: WSZYSTKIE SEGMENTY OK"; exit 0; else echo "WYNIK: SĄ BŁĘDY"; exit 1; fi
