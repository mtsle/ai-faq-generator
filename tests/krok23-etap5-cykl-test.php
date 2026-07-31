<?php
/**
 * Krok 23 etap 5 („Testy while") — straznik znalezisk z przejsc W1/W2.
 *
 * Test STATYCZNY (token_get_all, zero WordPressa, zero sieci). Pilnuje
 * niezmiennikow, ktore w tym etapie zostaly ZLAMANE i naprawione — kazdy
 * udowodniony wczesniej na zywej witrynie `ai-faq-dev.local`.
 *
 * ZNALEZISKO W2 (to, o ktore powstal ten plik):
 *   `add_action( 'update_option_aifaq_settings', … )` byl zarejestrowany WEWNATRZ
 *   `if ( is_admin() )` w `Plugin::init_hooks()`. Skutki uboczne zapisu ustawien
 *   (flaga flush rewrite, unieważnienie bramki MenuGuarda, czyszczenie kolejki
 *   crawla) nie odpalaly sie wiec dla zadnej sciezki spoza kokpitu: WP-CLI
 *   `wp option update`, przywracanie kopii bazy, wywolania programowe.
 *   Zmierzone na zywo: po zmianie sluga w kontekscie nie-admin flaga NIE
 *   powstawala, a `Router::maybe_flush_rewrite()` reaguje WYLACZNIE na nia
 *   (zero samonaprawy) — nowy adres zwracal 404 bezterminowo.
 *
 * URUCHOMIENIE:  php tests/krok23-etap5-cykl-test.php
 * Kod wyjscia: 0 = OK, 1 = bledy.
 *
 * @package AI_FAQ_Generator
 */

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
function e5_check( $cond, $label ) {
	global $fail, $ran;
	$ran++;
	echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n";
	if ( ! $cond ) {
		$fail++;
	}
}

/**
 * Tokeny bez bialych znakow i komentarzy.
 *
 * @param string $src Kod zrodlowy.
 *
 * @return array
 */
function e5_tokens( $src ) {
	$out = array();
	foreach ( token_get_all( $src ) as $t ) {
		if ( is_array( $t ) && in_array( $t[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}
		$out[] = $t;
	}
	return $out;
}

/**
 * Tekst tokenu.
 *
 * @param mixed $t Token.
 *
 * @return string
 */
function e5_tt( $t ) {
	return is_array( $t ) ? $t[1] : $t;
}

// ---------------------------------------------------------------------------
// 1. Skutki uboczne zapisu ustawien MUSZA byc rejestrowane poza `is_admin()`.
// ---------------------------------------------------------------------------
echo "\n=== 1. Rejestracja skutkow ubocznych zapisu ustawien ===\n";

$plugin_file = $root . '/src/Core/Plugin.php';
e5_check( is_file( $plugin_file ), 'Plugin.php istnieje' );

$tk = e5_tokens( (string) file_get_contents( $plugin_file ) );
$n  = count( $tk );

// Pozycja rejestracji hooka: literal 'update_option_' w wywolaniu add_action.
$hook_pos = -1;
for ( $i = 0; $i < $n; $i++ ) {
	if ( is_array( $tk[ $i ] ) && T_CONSTANT_ENCAPSED_STRING === $tk[ $i ][0]
		&& "'update_option_'" === $tk[ $i ][1] ) {
		$hook_pos = $i;
		break;
	}
}
e5_check( $hook_pos >= 0, 'znaleziono rejestracje add_action( "update_option_" . Settings::OPTION, … )' );

// Zakresy WSZYSTKICH blokow `if ( is_admin() ) { … }` w pliku.
$admin_blocks = array();
for ( $i = 0; $i < $n; $i++ ) {
	if ( ! is_array( $tk[ $i ] ) || T_STRING !== $tk[ $i ][0] || 'is_admin' !== $tk[ $i ][1] ) {
		continue;
	}
	// Znajdz `{` otwierajacy blok po warunku.
	$brace = -1;
	for ( $j = $i; $j < min( $n, $i + 12 ); $j++ ) {
		if ( '{' === e5_tt( $tk[ $j ] ) ) {
			$brace = $j;
			break;
		}
	}
	if ( $brace < 0 ) {
		continue;
	}
	// Domknij po glebokosci nawiasow klamrowych.
	$depth = 0;
	for ( $j = $brace; $j < $n; $j++ ) {
		$txt = e5_tt( $tk[ $j ] );
		if ( '{' === $txt ) {
			$depth++;
		} elseif ( '}' === $txt ) {
			$depth--;
			if ( 0 === $depth ) {
				$admin_blocks[] = array( $brace, $j );
				break;
			}
		}
	}
}

e5_check( count( $admin_blocks ) >= 1, 'znaleziono blok if ( is_admin() ) (blokow: ' . count( $admin_blocks ) . ')' );

$inside = false;
foreach ( $admin_blocks as $b ) {
	if ( $hook_pos > $b[0] && $hook_pos < $b[1] ) {
		$inside = true;
		break;
	}
}

// WLASCIWA BRAMKA.
e5_check(
	! $inside,
	'hook zapisu ustawien jest POZA blokiem is_admin() '
		. '(inaczej WP-CLI/przywracanie kopii/zapis programowy nie podnosi flagi flush → nowy slug = 404 na zawsze)'
);

// Kontrola, ze detektor blokow w ogole dziala: cos ADMINOWEGO musi wyjsc jako
// „w srodku". Bez tego asercja wyzej swiecilaby na zielono takze wtedy, gdyby
// skaner nie widzial zadnego bloku.
$menu_pos = -1;
for ( $i = 0; $i < $n; $i++ ) {
	if ( is_array( $tk[ $i ] ) && T_CONSTANT_ENCAPSED_STRING === $tk[ $i ][0]
		&& "'admin_menu'" === $tk[ $i ][1] ) {
		$menu_pos = $i;
		break;
	}
}
$menu_inside = false;
foreach ( $admin_blocks as $b ) {
	if ( $menu_pos > $b[0] && $menu_pos < $b[1] ) {
		$menu_inside = true;
		break;
	}
}
e5_check(
	$menu_pos >= 0 && $menu_inside,
	'kontrola detektora: hook `admin_menu` JEST wykrywany wewnatrz is_admin() (test rozroznia strony bloku)'
);

// ---------------------------------------------------------------------------
// 2. Flaga flush jest jedynym mechanizmem — Router nie ma samonaprawy.
//    Dlatego punkt 1 jest krytyczny, a nie kosmetyczny. Utrwalamy zaleznosc.
// ---------------------------------------------------------------------------
echo "\n=== 2. Router polega wylacznie na fladze ===\n";

$router_src = (string) file_get_contents( $root . '/src/Core/Router.php' );
e5_check(
	1 === preg_match( '/function\s+maybe_flush_rewrite.*?get_option\(\s*Settings::FLUSH_FLAG/s', $router_src ),
	'maybe_flush_rewrite() czyta flage Settings::FLUSH_FLAG'
);
e5_check(
	1 === preg_match( '/function\s+maybe_flush_rewrite.*?delete_option\(\s*Settings::FLUSH_FLAG/s', $router_src ),
	'maybe_flush_rewrite() konsumuje flage (kasuje po flushu — inaczej flush na kazde zadanie)'
);

// ---------------------------------------------------------------------------
// 3. Znalezisko W1: uninstall zdejmuje OBA crony.
//    Pelna bramka (skan planowanych hookow) siedzi w uninstall-guard-test.php;
//    tu zostaje tania kotwica, zeby regresja byla widoczna takze stad.
// ---------------------------------------------------------------------------
echo "\n=== 3. Kotwica: uninstall zdejmuje oba crony ===\n";

$un_src = (string) file_get_contents( $root . '/uninstall.php' );
foreach ( array( 'aifaq_crawl_tick', 'aifaq_reindex_continue' ) as $hook ) {
	e5_check( false !== strpos( $un_src, "'" . $hook . "'" ), "uninstall.php zna cron `{$hook}`" );
}

// Licznik podlogowy: bez niego plik z wyciete sekcja raportuje zielono na zero.
e5_check( $ran >= 9, "wykonano komplet asercji (asercji: {$ran})" );

echo "\n=== " . ( 0 === $fail ? 'WSZYSTKIE OK' : "BLEDOW: {$fail}" ) . " (asercji: {$ran}) ===\n";
exit( $fail > 0 ? 1 : 0 );
