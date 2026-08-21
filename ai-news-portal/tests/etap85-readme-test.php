<?php
/**
 * Krok 8, etap 8.5 — STRAZNIK ZGODNOSCI README Z KODEM.
 *
 * README wtyczki 1 rozjechal sie z kodem, bo jego zgodnosc byla jednorazowa:
 * ktos raz przeszedl 107 twierdzen i odhaczyl. Ten zestaw robi z czesci tej
 * zgodnosci WARUNEK PRZEBIEGU TESTOW — liczba, nazwa opcji albo plik klasy,
 * ktore rozjada sie z kodem, wywalaja test, a nie czekaja na kolejnego czytelnika.
 *
 * Pilnowane sa trzy rodziny twierdzen:
 *   1. WERSJA — README nie powtarza numeru wydania. Jesli kiedys go dostanie
 *      (przy domknieciu Kroku 8), musi byc DOKLADNIE rowny `AINP_VERSION`.
 *      Dodatkowo stala `AINP_VERSION` musi zgadzac sie z naglowkiem wtyczki.
 *   2. TABELA STALYCH — kazdy wiersz tabeli „Stale konfiguracyjne" jest
 *      porownywany z prawdziwa wartoscia stalej w `src/`.
 *   3. NAZWY Z KODU — statusy, opcje, typ tresci, taksonomia, adresy, naglowki
 *      bezpieczenstwa, odrzucone naglowki i pliki `src/` musza wystepowac
 *      w README. Dopisanie klasy bez dopisania jej do README wywala test.
 *
 * Stale czytane sa z PRAWDZIWYCH plikow wtyczki (`require_once` z podstawiona
 * `ABSPATH`), nie ze skanowania tekstu — GOTCHA 28: test czytajacy plik jako
 * tekst klamie.
 *
 * Zero WordPressa, zero sieci.
 *
 * URUCHOMIENIE:  php tests/etap85-readme-test.php
 * Kod wyjscia: 0 = OK, 1 = bledy.
 *
 * @package AI_News_Portal
 */

namespace {
	define( 'ABSPATH', __DIR__ . '/atrapy/wp/' );

	$root = dirname( __DIR__ );
	$fail = 0;
	$ran  = 0;

	/**
	 * Asercja.
	 *
	 * @param bool   $cond  Warunek.
	 * @param string $label Opis.
	 *
	 * @return void
	 */
	function k85_check( $cond, $label ) {
		global $fail, $ran;
		$ran++;
		echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n";
		if ( ! $cond ) {
			$fail++;
		}
	}

	// Klasy wtyczki. Trait PRZED klasa, ktora go uzywa — inaczej blad krytyczny.
	require_once $root . '/src/Settings.php';
	require_once $root . '/src/Http.php';
	require_once $root . '/src/Feed.php';
	require_once $root . '/src/Dedup.php';
	require_once $root . '/src/Filter.php';
	require_once $root . '/src/Article.php';
	require_once $root . '/src/Gemini.php';
	require_once $root . '/src/Validator.php';
	require_once $root . '/src/Publisher.php';
	require_once $root . '/src/Runner.php';
	require_once $root . '/src/Portal.php';
	require_once $root . '/src/Security.php';
	require_once $root . '/src/Plugin.php';
	require_once $root . '/src/Admin_Screen.php';
	require_once $root . '/src/Admin.php';

	$readme_path = $root . '/README.md';
	$glowny_path = $root . '/ai-news-portal.php';

	echo "=== ETAP 8.5 — ZGODNOSC README Z KODEM ===\n\n";

	echo "--- 1. Plik i naglowek ---\n";

	k85_check( file_exists( $readme_path ), 'README.md istnieje w katalogu wtyczki' );

	$readme = file_exists( $readme_path ) ? (string) file_get_contents( $readme_path ) : '';
	$glowny = (string) file_get_contents( $glowny_path );

	k85_check( mb_strlen( $readme ) > 3000, 'README.md nie jest zaslepka (ponad 3000 znakow)' );
	k85_check( 1 === preg_match( '/^# AI News Portal$/m', $readme ), 'README zaczyna sie naglowkiem „AI News Portal"' );

	// Wersja w naglowku wtyczki i w stalej musza byc tym samym — to na tej
	// rownosci opiera sie caly straznik wersji nizej.
	preg_match( "/Version:\s*([0-9.]+)/", $glowny, $m_hdr );
	preg_match( "/define\(\s*'AINP_VERSION',\s*'([0-9.]+)'\s*\)/", $glowny, $m_const );

	$wersja_hdr   = $m_hdr[1] ?? '';
	$wersja_const = $m_const[1] ?? '';

	k85_check( '' !== $wersja_hdr, 'naglowek wtyczki ma pole `Version`' );
	k85_check( '' !== $wersja_const, 'plik glowny definiuje `AINP_VERSION`' );
	k85_check( $wersja_hdr === $wersja_const, "`AINP_VERSION` ($wersja_const) === `Version` z naglowka ($wersja_hdr)" );

	echo "\n--- 2. Straznik wersji w README ---\n";

	$linie  = preg_split( "/\r\n|\n/", $readme );
	$status = array_values( array_filter( $linie, static fn( $l ) => 0 === strpos( $l, '**Status:**' ) ) );

	k85_check( 1 === count( $status ), 'README ma DOKLADNIE jedna linie `**Status:**`' );

	$linia_status = $status[0] ?? '';
	$ma_wersje    = 1 === preg_match( '/\b(\d+\.\d+\.\d+)\b/', $linia_status, $m_st );

	// Dwa dopuszczalne stany: albo linia statusu nie ma numeru w ogole (tak jest
	// do domkniecia Kroku 8), albo ma DOKLADNIE numer z naglowka wtyczki.
	k85_check(
		! $ma_wersje || ( ( $m_st[1] ?? '' ) === $wersja_const ),
		'linia `**Status:**` albo nie ma numeru wersji, albo ma dokladnie ten z naglowka'
	);

	// Poza linia statusu numer wydania nie ma prawa wystapic w zadnej postaci,
	// ktora bedzie klamac po podbiciu wersji. Wzorce celuja w IDIOMY numeru
	// wtyczki, a nie w kazde „x.y.z" — README opisuje tez WordPress 7.0.2,
	// PHP 8.1 i model `gemini-2.5-flash`, ktore numerem wydania nie sa.
	$bez_statusu = str_replace( $linia_status, '', $readme );

	k85_check( 0 === preg_match( '/\bv\d+\.\d+\.\d+/', $bez_statusu ), 'poza statusem nie ma zapisu `vX.Y.Z`' );
	k85_check( 0 === preg_match( '/wersj\w*\s+v?\d+\.\d+\.\d+/iu', $bez_statusu ), 'poza statusem nie ma „wersja X.Y.Z"' );
	k85_check( 0 === preg_match( '/AI News Portal\s+v?\d+\.\d+\.\d+/u', $bez_statusu ), 'poza statusem nie ma „AI News Portal X.Y.Z"' );

	echo "\n--- 3. Tabela stalych konfiguracyjnych ---\n";

	// Wiersz tabeli: | `Klasa::STALA` | `wartosc` | opis |
	preg_match_all( '/^\|\s*`([A-Za-z_]+)::([A-Z0-9_]+)`\s*\|\s*`([^`]+)`\s*\|/mu', $readme, $wiersze, PREG_SET_ORDER );

	// Kontrakt ilosciowy: `=== N`, nie „co najmniej". Wyciecie wiersza z tabeli
	// bez zmiany tej liczby ma byc bledem, a nie cicha strata pokrycia.
	k85_check( 34 === count( $wiersze ), 'tabela stalych ma dokladnie 34 wiersze (jest: ' . count( $wiersze ) . ')' );

	foreach ( $wiersze as $w ) {
		list( , $klasa, $stala, $wartosc ) = $w;

		$fqn = 'AINP\\' . $klasa . '::' . $stala;

		if ( ! defined( $fqn ) ) {
			k85_check( false, "stala $klasa::$stala istnieje w kodzie" );
			continue;
		}

		$w_kodzie = constant( $fqn );

		k85_check(
			(string) $w_kodzie === $wartosc,
			"$klasa::$stala — README `$wartosc` === kod `" . (string) $w_kodzie . '`'
		);
	}

	echo "\n--- 4. Nazwy i adresy z kodu ---\n";

	$nazwy = array(
		'typ tresci'            => AINP\Plugin::CPT,
		'taksonomia'            => AINP\Plugin::TAX,
		'adres archiwum'        => AINP\Plugin::ARCHIVE_SLUG,
		'przedrostek kategorii' => AINP\Portal::TAX_BASE,
		'parametr szukania'     => AINP\Portal::SEARCH_VAR,
		'uchwyt crona'          => AINP\Plugin::CRON_HOOK,
		'tabela'                => AINP\Plugin::TABLE,
		'filtr naglowkow'       => AINP\Security::FILTER,
		'stala wylaczajaca'     => AINP\Security::CONST_OFF,
	);

	foreach ( $nazwy as $opis => $wartosc ) {
		k85_check( false !== mb_strpos( $readme, $wartosc ), "README wymienia $opis (`$wartosc`)" );
	}

	$opcje = array(
		AINP\Settings::OPTION,
		AINP\Settings::OPTION_SOURCES,
		AINP\Settings::OPTION_KEY,
		AINP\Settings::OPTION_USAGE,
		AINP\Admin::OPTION_LOCK,
		AINP\Plugin::OPTION_TOPICS_SEEDED,
		AINP\Plugin::OPTION_SLUG_COLLISION,
	);

	foreach ( $opcje as $opcja ) {
		k85_check( false !== mb_strpos( $readme, $opcja ), "README wymienia opcje `$opcja`" );
	}

	$statusy = array(
		AINP\Runner::STATUS_NEW,
		AINP\Runner::STATUS_PROCESSING,
		AINP\Runner::STATUS_SKIPPED,
		AINP\Runner::STATUS_FAILED,
		AINP\Runner::STATUS_DONE,
	);

	foreach ( $statusy as $status_kodu ) {
		k85_check( false !== mb_strpos( $readme, '`' . $status_kodu . '`' ), "README wymienia status `$status_kodu`" );
	}

	k85_check(
		false !== mb_strpos( $readme, 'edit.php?post_type=' . AINP\Plugin::CPT ),
		'README podaje adres hurtowego zarzadzania artykulami'
	);

	echo "\n--- 5. Wymagania i ustawienia domyslne ---\n";

	// Wymagania brane z NAGLOWKA WTYCZKI, nie wpisane w tescie na sztywno.
	foreach ( array( 'Requires at least', 'Requires PHP', 'Tested up to' ) as $pole ) {
		preg_match( '/' . preg_quote( $pole, '/' ) . ':\s*([0-9.]+)/', $glowny, $m_p );
		$wart = $m_p[1] ?? '';
		k85_check( '' !== $wart && false !== mb_strpos( $readme, $wart ), "README podaje `$pole` = $wart" );
	}

	$domyslne  = AINP\Settings::defaults();
	$kanaly    = AINP\Settings::default_sources();
	$kategorie = $domyslne['categories'];
	$sufit     = $domyslne['daily_cap'];
	$model     = $domyslne['model'];

	$slownie = array( 3 => 'trzy', 4 => 'cztery', 5 => 'piec', 6 => 'szesc', 7 => 'siedem', 8 => 'osiem' );

	k85_check(
		isset( $slownie[ count( $kanaly ) ] ) && false !== mb_strpos( $readme, $slownie[ count( $kanaly ) ] . ' domyślne' ),
		'README podaje liczbe domyslnych kanalow slownie (' . count( $kanaly ) . ')'
	);
	k85_check(
		isset( $slownie[ count( $kategorie ) ] ) && false !== mb_strpos( $readme, $slownie[ count( $kategorie ) ] . ' kategori' ),
		'README podaje liczbe kategorii slownie (' . count( $kategorie ) . ')'
	);
	k85_check( false !== mb_strpos( $readme, '`' . $model . '`' ), "README podaje domyslny model `$model`" );
	k85_check( 1 === preg_match( '/sufit to ' . (int) $sufit . ' wywołań/u', $readme ), "README podaje domyslny sufit dobowy ($sufit)" );
	k85_check( 1 === preg_match( '/domyślnie ' . (int) $sufit . '/u', $readme ), 'README podaje sufit takze przy opisie pola Ustawien' );

	echo "\n--- 6. Naglowki bezpieczenstwa ---\n";

	foreach ( array_keys( AINP\Security::rejected() ) as $naglowek ) {
		k85_check( false !== mb_strpos( $readme, $naglowek ), "README wymienia odrzucony naglowek `$naglowek`" );
	}

	foreach ( array( 'X-Content-Type-Options', 'Referrer-Policy', 'Permissions-Policy', 'X-Frame-Options', 'Content-Security-Policy' ) as $naglowek ) {
		k85_check( false !== mb_strpos( $readme, $naglowek ), "README wymienia wysylany naglowek `$naglowek`" );
	}

	echo "\n--- 7. Struktura kodu ---\n";

	$pliki = glob( $root . '/src/*.php' );
	sort( $pliki );

	k85_check( count( $pliki ) > 0, 'katalog `src/` ma pliki PHP' );

	foreach ( $pliki as $plik ) {
		$nazwa = basename( $plik );
		k85_check( false !== mb_strpos( $readme, $nazwa ), "README wymienia plik `$nazwa` w strukturze kodu" );
	}

	foreach ( array( 'archive.php', 'single.php', 'card.php', 'uninstall.php', 'LICENSE' ) as $nazwa ) {
		k85_check( false !== mb_strpos( $readme, $nazwa ), "README wymienia `$nazwa`" );
	}

	echo "\n--- 8. Ostrzezenie o odinstalowaniu i higiena tekstu ---\n";

	// Wymog planu (Krok 8 pkt 4 i 6): ostrzezenie ma byc WYTLUSZCZONE.
	k85_check(
		1 === preg_match( '/\*\*UWAGA[^*]*BEZPOWROTNIE[^*]*\*\*/u', $readme ),
		'ostrzezenie o kasowaniu artykulow jest WYTLUSZCZONE'
	);
	k85_check(
		1 === preg_match( '/wyłączenie wtyczki niczego nie kasuje/iu', $readme ),
		'README rozroznia wylaczenie od usuniecia'
	);

	// GOTCHA 115: cyrylickie „e" w polskim tekscie jest nieodroznialne wzrokowo.
	k85_check( 0 === preg_match( '/[\x{0400}-\x{04FF}]/u', $readme ), 'README nie zawiera znakow cyrylicy' );

	foreach ( array( 'TODO', 'FIXME', '{{' ) as $smiec ) {
		k85_check( false === mb_strpos( $readme, $smiec ), "README nie zawiera niepodstawionego `$smiec`" );
	}

	echo "\n";
	echo '=== WYNIK: ' . ( $ran - $fail ) . ' / ' . $ran . " asercji ===\n";

	if ( $fail > 0 ) {
		echo "BŁĘDY: $fail\n";
		exit( 1 );
	}

	echo "WSZYSTKIE OK\n";
	exit( 0 );
}
