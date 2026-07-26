<?php
/**
 * Test-strażnik kompletności `uninstall.php`.
 *
 * PROBLEM, KTÓRY ROZWIĄZUJE: 2026-07-25 wraz z SEO podstrony doszły dwie nowe
 * opcje (`aifaq_site_profile`, `aifaq_public_faq`) i nikt nie dopisał ich do
 * `uninstall.php` — odinstalowanie wtyczki zostawiało śmieci w bazie klienta.
 * Żaden test tego nie złapał, bo cała reszta zestawów sprawdza ZACHOWANIE kodu,
 * a nie to, czy sprzątanie nadąża za nowymi kluczami.
 *
 * JAK DZIAŁA: czysto STATYCZNIE (token_get_all, ZERO ładowania WordPressa i ZERO
 * wywołań sieciowych). Skanuje `src/**` + `ai-faq-generator.php`, wyciąga klucze
 * przekazywane do funkcji trwałego zapisu (opcje, transienty, user meta, post meta),
 * rozwiązuje stałe klas (`self::OPTION` → `'aifaq_site_profile'`) i wymaga, żeby
 * KAŻDY klucz zaczynający się od `aifaq` (albo `_aifaq`) miał pokrycie w `uninstall.php`.
 *
 * DWIE ŚCIEŻKI POKRYCIA:
 *   1. dosłowny literał w `uninstall.php` (opcje, meta, transienty o stałej nazwie);
 *   2. wzorzec SQL zadeklarowany w `uninstall.php` znacznikiem `GUARD-PATTERN: <prefiks>` —
 *      WYŁĄCZNIE dla transientów, bo tylko one są kasowane hurtem po `LIKE`.
 *      Opcji wzorzec NIE pokrywa: opcje kasuje `delete_option()` po nazwie.
 *
 * URUCHOMIENIE:  php tests/uninstall-guard-test.php
 * Kod wyjścia: 0 = OK, 1 = błędy.
 *
 * @package AI_FAQ_Generator
 */

$root      = dirname( __DIR__ );
$uninstall = $root . '/uninstall.php';

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
function check( $cond, $label ) {
	global $fail, $ran;
	$ran++;
	echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n";
	if ( ! $cond ) {
		$fail++;
	}
}

// ---------------------------------------------------------------------------
// 1. Lista plików do skanowania.
// ---------------------------------------------------------------------------
$files = array( $root . '/ai-faq-generator.php' );
$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/src', FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $f ) {
	if ( 'php' === strtolower( $f->getExtension() ) ) {
		$files[] = $f->getPathname();
	}
}
sort( $files );

check( count( $files ) > 50, 'skaner widzi pliki zrodlowe (znaleziono: ' . count( $files ) . ')' );
check( is_file( $uninstall ), 'uninstall.php istnieje' );

// ---------------------------------------------------------------------------
// 2. Pomocnicze: tokeny bez śmieci.
// ---------------------------------------------------------------------------

/**
 * Zwraca tokeny pliku z pominięciem białych znaków i komentarzy.
 *
 * @param string $src Kod źródłowy.
 *
 * @return array
 */
function aifaq_tokens( $src ) {
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
function aifaq_tt( $t ) {
	return is_array( $t ) ? $t[1] : $t;
}

/**
 * Wartość literału stringowego (zdejmuje cudzysłowy).
 *
 * @param mixed $t Token.
 *
 * @return string|null
 */
function aifaq_str( $t ) {
	if ( ! is_array( $t ) || T_CONSTANT_ENCAPSED_STRING !== $t[0] ) {
		return null;
	}
	return substr( $t[1], 1, -1 );
}

// ---------------------------------------------------------------------------
// 3. Mapa stałych: "Klasa::NAZWA" => wartość  oraz  "plik|NAZWA" => wartość.
// ---------------------------------------------------------------------------
$const_by_class = array();
$const_by_file  = array();
$tokens_by_file = array();

foreach ( $files as $file ) {
	$tk                     = aifaq_tokens( (string) file_get_contents( $file ) );
	$tokens_by_file[ $file ] = $tk;
	$class                  = '';
	$n                      = count( $tk );

	for ( $i = 0; $i < $n; $i++ ) {
		if ( is_array( $tk[ $i ] ) && T_CLASS === $tk[ $i ][0] && isset( $tk[ $i + 1 ] ) && is_array( $tk[ $i + 1 ] ) && T_STRING === $tk[ $i + 1 ][0] ) {
			$class = $tk[ $i + 1 ][1];
			continue;
		}
		if ( is_array( $tk[ $i ] ) && T_CONST === $tk[ $i ][0]
			&& isset( $tk[ $i + 1 ], $tk[ $i + 2 ], $tk[ $i + 3 ] )
			&& is_array( $tk[ $i + 1 ] ) && T_STRING === $tk[ $i + 1 ][0]
			&& '=' === aifaq_tt( $tk[ $i + 2 ] ) ) {
			$val = aifaq_str( $tk[ $i + 3 ] );
			if ( null === $val ) {
				continue;
			}
			$name                              = $tk[ $i + 1 ][1];
			$const_by_file[ $file . '|' . $name ] = $val;
			if ( '' !== $class ) {
				$const_by_class[ $class . '::' . $name ] = $val;
			}
		}
	}
}

check( isset( $const_by_class['SiteProfile::OPTION'] ), 'mapa stalych rozwiazuje SiteProfile::OPTION' );

// ---------------------------------------------------------------------------
// 4. Skan wywołań zapisu trwałego.
//    Wartość = indeks argumentu (0-based), pod którym siedzi KLUCZ, oraz typ.
// ---------------------------------------------------------------------------
$targets = array(
	// Opcje — klucz w argumencie 1.
	'update_option'           => array( 0, 'option' ),
	'add_option'              => array( 0, 'option' ),
	'get_option'              => array( 0, 'option' ),
	'delete_option'           => array( 0, 'option' ),
	// Transienty — klucz w argumencie 1.
	'set_transient'           => array( 0, 'transient' ),
	'get_transient'           => array( 0, 'transient' ),
	'delete_transient'        => array( 0, 'transient' ),
	// Meta użytkownika/wpisu — klucz w argumencie 2 (arg 1 to ID obiektu).
	'update_user_meta'        => array( 1, 'user_meta' ),
	'get_user_meta'           => array( 1, 'user_meta' ),
	'delete_user_meta'        => array( 1, 'user_meta' ),
	'add_user_meta'           => array( 1, 'user_meta' ),
	'update_post_meta'        => array( 1, 'post_meta' ),
	'get_post_meta'           => array( 1, 'post_meta' ),
	'delete_post_meta'        => array( 1, 'post_meta' ),
	'add_post_meta'           => array( 1, 'post_meta' ),
	'delete_post_meta_by_key' => array( 0, 'post_meta' ),
	// Rodzina *_metadata — klucz w argumencie 3 (typ, ID, klucz).
	'update_metadata'         => array( 2, 'meta' ),
	'get_metadata'            => array( 2, 'meta' ),
	'delete_metadata'         => array( 2, 'meta' ),
	'add_metadata'            => array( 2, 'meta' ),
);

$found      = array(); // klucz => array( 'type' => …, 'prefix' => bool, 'where' => … ).
$unresolved = array(); // "plik|wyrazenie" => liczba wystąpień.

/**
 * Rejestruje znaleziony klucz.
 *
 * @param string $key    Klucz albo prefiks.
 * @param bool   $prefix Czy to prefiks (klucz sklejany dynamicznie).
 * @param string $type   Rodzaj magazynu.
 * @param string $where  Plik.
 *
 * @return void
 */
function aifaq_record( $key, $prefix, $type, $where ) {
	global $found;
	if ( '' === $key || 1 !== preg_match( '/^_?aifaq/', $key ) ) {
		return; // Nie nasz klucz (np. 'page_on_front') — nie nasza sprawa.
	}
	if ( ! isset( $found[ $key ] ) ) {
		$found[ $key ] = array(
			'type'   => $type,
			'prefix' => $prefix,
			'where'  => array(),
		);
	}
	if ( $prefix ) {
		$found[ $key ]['prefix'] = true;
	}
	$found[ $key ]['where'][ basename( $where ) ] = true;
}

foreach ( $files as $file ) {
	$tk = $tokens_by_file[ $file ];
	$n  = count( $tk );

	for ( $i = 0; $i < $n; $i++ ) {
		$t = $tk[ $i ];

		// --- 4a. Sklejanie dynamiczne: 'aifaq_xxx' . <cokolwiek> ---------
		// Łapie klucze budowane w metodach pomocniczych (cooldown_key(),
		// 'aifaq_no_thinking_' . $model), których nie widać w miejscu wywołania.
		$lit = aifaq_str( $t );
		if ( null !== $lit && 1 === preg_match( '/^_?aifaq_/', $lit ) && isset( $tk[ $i + 1 ] ) && '.' === aifaq_tt( $tk[ $i + 1 ] ) ) {
			aifaq_record( $lit, true, 'transient', $file );
		}

		// --- 4b. Wywołania funkcji zapisu --------------------------------
		if ( ! is_array( $t ) || T_STRING !== $t[0] || ! isset( $targets[ $t[1] ] ) ) {
			continue;
		}
		// Odrzuć definicje własnych shimów i wywołania metod ($obj->get_option()).
		if ( $i > 0 ) {
			$prev = aifaq_tt( $tk[ $i - 1 ] );
			if ( in_array( $prev, array( '->', '::', 'function' ), true ) ) {
				continue;
			}
		}
		if ( ! isset( $tk[ $i + 1 ] ) || '(' !== aifaq_tt( $tk[ $i + 1 ] ) ) {
			continue;
		}

		list( $arg_index, $type ) = $targets[ $t[1] ];

		// Zbierz argumenty aż do domykającego nawiasu.
		$depth = 0;
		$args  = array();
		$cur   = array();
		for ( $j = $i + 1; $j < $n; $j++ ) {
			$txt = aifaq_tt( $tk[ $j ] );
			if ( in_array( $txt, array( '(', '[' ), true ) ) {
				$depth++;
				if ( 1 === $depth ) {
					continue;
				}
			} elseif ( in_array( $txt, array( ')', ']' ), true ) ) {
				$depth--;
				if ( 0 === $depth ) {
					$args[] = $cur;
					break;
				}
			} elseif ( ',' === $txt && 1 === $depth ) {
				$args[] = $cur;
				$cur    = array();
				continue;
			}
			$cur[] = $tk[ $j ];
		}

		if ( ! isset( $args[ $arg_index ] ) ) {
			continue;
		}
		$arg = $args[ $arg_index ];
		if ( ! $arg ) {
			continue;
		}

		// Rozwiązanie argumentu.
		$first  = $arg[0];
		$prefix = count( $arg ) > 1; // Cokolwiek za pierwszym elementem = sklejanie.
		$value  = aifaq_str( $first );

		if ( null === $value ) {
			// self::CONST / static::CONST / Klasa::CONST / \Ns\Klasa::CONST.
			$names = array();
			foreach ( $arg as $tok ) {
				$tx = aifaq_tt( $tok );
				$name_tokens = array( T_STRING, T_NS_SEPARATOR );
				if ( defined( 'T_NAME_QUALIFIED' ) ) {
					$name_tokens[] = T_NAME_QUALIFIED;
					$name_tokens[] = T_NAME_FULLY_QUALIFIED;
				}
				if ( '::' === $tx || ( is_array( $tok ) && in_array( $tok[0], $name_tokens, true ) ) ) {
					$names[] = $tx;
				} else {
					break;
				}
			}
			$expr = implode( '', $names );
			if ( 1 === preg_match( '/(?:^|\\\\)(\w+)::(\w+)$/', $expr, $m ) ) {
				$cls   = $m[1];
				$cname = $m[2];
				if ( in_array( $cls, array( 'self', 'static' ), true ) ) {
					$value = $const_by_file[ $file . '|' . $cname ] ?? null;
				} else {
					$value = $const_by_class[ $cls . '::' . $cname ] ?? null;
				}
				$prefix = count( $arg ) > count( $names );
			}
		}

		if ( null === $value ) {
			$sig = basename( $file ) . '|' . trim( implode( ' ', array_map( 'aifaq_tt', $arg ) ) );
			$unresolved[ $sig ] = ( $unresolved[ $sig ] ?? 0 ) + 1;
			continue;
		}

		// Stała kończąca się podkreśleniem to z definicji prefiks (RateLimiter::PREFIX).
		if ( '_' === substr( $value, -1 ) ) {
			$prefix = true;
		}

		aifaq_record( $value, $prefix, $type, $file );
	}
}

check( count( $found ) >= 25, 'skaner wyciagnal klucze wtyczki (znaleziono: ' . count( $found ) . ')' );

// ---------------------------------------------------------------------------
// 5. Co pokrywa uninstall.php.
// ---------------------------------------------------------------------------
$un_src = (string) file_get_contents( $uninstall );

$un_literals = array();
foreach ( aifaq_tokens( $un_src ) as $t ) {
	$v = aifaq_str( $t );
	if ( null !== $v ) {
		$un_literals[ $v ] = true;
	}
}

// Wzorce SQL zadeklarowane znacznikiem `GUARD-PATTERN: <prefiks>`.
$un_patterns = array();
if ( preg_match_all( '/GUARD-PATTERN:\s*(\S+)/', $un_src, $m ) ) {
	$un_patterns = $m[1];
}

check( count( $un_patterns ) >= 1, 'uninstall.php deklaruje wzorzec GUARD-PATTERN (znaleziono: ' . count( $un_patterns ) . ')' );

// Wzorzec działa tylko wtedy, gdy w pliku jest realny DELETE po LIKE na wp_options
// dla OBU prefiksów transientu — inaczej znacznik byłby pustą obietnicą.
check(
	false !== strpos( $un_src, '_transient_aifaq_' )
	&& false !== strpos( $un_src, '_transient_timeout_aifaq_' )
	&& false !== stripos( $un_src, 'LIKE %s' )
	&& false !== strpos( $un_src, 'esc_like' ),
	'wzorzec ma pokrycie w kodzie: DELETE ... LIKE na obu prefiksach transientu + esc_like'
);

// Multisite: sprzątanie musi objąć każdy blog, a nazwy tabel powstawać po switch_to_blog().
//
// UWAGA METODYCZNA: pierwotnie stało tu `strpos( $un_src, 'switch_to_blog' )` i była to
// FAŁSZYWA ZIELEŃ — wykryta mutacją. Nazwa zostaje w pliku wewnątrz
// `function_exists( 'switch_to_blog' )`, więc po wycięciu samego przełączania blogów
// asercja dalej przechodziła. Stawka: w multisite pętla przeleciałaby N razy po
// bieżącym blogu, a pozostałe witryny sieci zostałyby zaśmiecone.
// Dlatego liczymy REALNE WYWOŁANIA na tokenach: literał w `function_exists()` to
// T_CONSTANT_ENCAPSED_STRING i tu się nie liczy, wywołanie to T_STRING + '('.
$un_calls  = array();
$un_tokens = aifaq_tokens( $un_src );
for ( $i = 0, $n = count( $un_tokens ); $i < $n; $i++ ) {
	$t = $un_tokens[ $i ];
	if ( ! is_array( $t ) || T_STRING !== $t[0] ) {
		continue;
	}
	if ( ! isset( $un_tokens[ $i + 1 ] ) || '(' !== aifaq_tt( $un_tokens[ $i + 1 ] ) ) {
		continue;
	}
	$prev = $i > 0 ? aifaq_tt( $un_tokens[ $i - 1 ] ) : '';
	if ( in_array( $prev, array( '->', '::', 'function' ), true ) ) {
		continue; // Definicja albo wywołanie metody, nie wywołanie funkcji.
	}
	$un_calls[ $t[1] ] = ( $un_calls[ $t[1] ] ?? 0 ) + 1;
}

check(
	isset( $un_calls['is_multisite'], $un_calls['get_sites'], $un_calls['switch_to_blog'], $un_calls['restore_current_blog'], $un_calls['aifaq_uninstall_cleanup_site'] ),
	'multisite: REALNE wywolania is_multisite/get_sites/switch/restore + sprzatanie (nie same nazwy w function_exists)'
);

// Symetria: każdy `switch_to_blog()` musi mieć swój `restore_current_blog()`, inaczej
// uninstall kończy się na przełączonym blogu i psuje kontekst kolejnym wtyczkom.
check(
	( $un_calls['switch_to_blog'] ?? 0 ) === ( $un_calls['restore_current_blog'] ?? 0 )
	&& ( $un_calls['switch_to_blog'] ?? 0 ) >= 1,
	'symetria switch_to_blog / restore_current_blog (switch: ' . ( $un_calls['switch_to_blog'] ?? 0 ) . ', restore: ' . ( $un_calls['restore_current_blog'] ?? 0 ) . ')'
);
check(
	1 === preg_match( '/function\s+aifaq_uninstall_cleanup_site/', $un_src )
	&& false !== strpos( $un_src, "function_exists( 'aifaq_uninstall_cleanup_site' )" ),
	'sprzatanie w jednej funkcji z prefiksem aifaq_, chronionej function_exists()'
);
check(
	1 === preg_match( '/function\s+aifaq_uninstall_cleanup_site.*?\$wpdb->prefix/s', $un_src ),
	'nazwy tabel wyliczane WEWNATRZ funkcji (przezywaja switch_to_blog)'
);

// ZASADA: w uninstall.php nie wolno używać stałych klas — nie ma autoloadera.
// Sprawdzane na KODZIE, nie na źródle: komentarze wolno (i trzeba) wskazywać,
// z której stałej pochodzi dany literał.
$un_code = '';
foreach ( aifaq_tokens( $un_src ) as $t ) {
	$un_code .= aifaq_tt( $t ) . ' ';
}
check(
	0 === preg_match( '/\b[A-Z][A-Za-z0-9_]*\s*::\s*[A-Z][A-Z0-9_]+\b/', $un_code ),
	'uninstall.php nie uzywa stalych klas (bez autoloadera = Fatal error)'
);

// ---------------------------------------------------------------------------
// 6. Jawne wyjątki. KAŻDY wymaga uzasadnienia — lista ma zostać krótka.
// ---------------------------------------------------------------------------
$exceptions = array(
	// Klucz czytany z ZEWNĄTRZ wtyczki: `aifaq_rl_` używa też wp_cache (grupa `aifaq`),
	// a sam transient jest pokryty wzorcem — wyjątków merytorycznych na dziś brak.
);

// Wywołania, w których klucz jest zmienną/wywołaniem metody. Statycznie nie do
// rozwiązania, więc lista jest ZAMKNIĘTA: nowa pozycja = świadoma decyzja autora,
// nie przeoczenie. Każda z nich ma pokrycie inną drogą (patrz komentarz).
$unresolved_allow = array(
	// Settings::get() — generyczny odczyt, klucz przychodzi z wywołującego.
	'Settings.php|$option',
	// PageGuard: nazwa opcji z metody z literałem fallbacku 'aifaq_page_bootstrapped'
	// (ten literał skaner i tak łapie osobno w tym samym pliku).
	'PageGuard.php|self :: bootstrap_option ( )',
	// GeminiProvider: klucz z cooldown_key(); oba literały sklejane są łapane regułą 4a.
	'GeminiProvider.php|$key',
	'GeminiProvider.php|$cooldown_key',
	// IndexController: kopia self::LOCK do zmiennej przed zamknięciem (shutdown).
	'IndexController.php|$lock',
	// CrawlQueue::meta_key() — odporny na brak klasy wariant `_aifaq_rendered`
	// (literał fallbacku leży w tym samym pliku, klucz kasowany w uninstall.php).
	'CrawlQueue.php|$this -> meta_key ( )',
	// WpContentSource: pętla po OPCJACH WOOCOMMERCE (cudze klucze, nie nasze).
	'WpContentSource.php|$option',
	// RagService: D7 (cache odmów off-topic, dług sprzed Kroku 22) — klucz sklejany
	// w refusal_cache_key() jako 'aifaq_refuse_' . hash(pytanie); prefiks 'aifaq_'
	// ma pokrycie wzorcem SQL LIKE '_transient_aifaq_%' już zadeklarowanym wyżej.
	'RagService.php|$this -> refusal_cache_key ( $q )',
);

// ---------------------------------------------------------------------------
// 7. Właściwa bramka: każdy klucz musi mieć pokrycie.
// ---------------------------------------------------------------------------
$missing = array();

foreach ( $found as $key => $info ) {
	if ( in_array( $key, $exceptions, true ) ) {
		continue;
	}

	$covered = isset( $un_literals[ $key ] );

	// Wzorzec SQL pokrywa WYŁĄCZNIE transienty (opcje kasuje delete_option po nazwie).
	if ( ! $covered && 'transient' === $info['type'] ) {
		foreach ( $un_patterns as $p ) {
			if ( 0 === strpos( $key, $p ) ) {
				$covered = true;
				break;
			}
		}
	}

	// Prefiks (klucz sklejany) w innym magazynie niż transient nie ma jak być
	// pokryty literałem — traktujemy jako brak i wymagamy świadomej decyzji.
	if ( ! $covered ) {
		$missing[] = $key . ' [' . $info['type'] . ( $info['prefix'] ? ', prefiks' : '' ) . '] ← ' . implode( ', ', array_keys( $info['where'] ) );
	}
}

check(
	0 === count( $missing ),
	'KAZDY klucz aifaq* ma pokrycie w uninstall.php' . ( $missing ? ( "\n         BRAK: " . implode( "\n         BRAK: ", $missing ) ) : ' (sprawdzono: ' . count( $found ) . ')' )
);

$new_unresolved = array_diff( array_keys( $unresolved ), $unresolved_allow );
check(
	0 === count( $new_unresolved ),
	'brak NOWYCH nierozwiazywalnych kluczy' . ( $new_unresolved ? ( ' → ' . implode( ' ; ', $new_unresolved ) ) : ' (znanych: ' . count( $unresolved ) . ')' )
);

// Kotwice regresji: klucze, o które ten test powstał (2026-07-25) i ofiary
// najczęstszego przeoczenia — transienty.
foreach ( array( 'aifaq_site_profile', 'aifaq_public_faq', 'aifaq_editor_hint_done', 'aifaq_indexing_lock', 'aifaq_cache_flush_lock', '_aifaq_rendered' ) as $anchor ) {
	check( isset( $un_literals[ $anchor ] ), "kotwica: uninstall.php kasuje `{$anchor}`" );
}
foreach ( array( 'aifaq_rl_', 'aifaq_cooldown_generate_', 'aifaq_cooldown_embed_', 'aifaq_no_thinking_' ) as $anchor ) {
	check( isset( $found[ $anchor ] ), "skaner widzi prefiks transientu `{$anchor}`" );
}

// Licznik podłogowy: bez niego plik z wywaloną sekcją raportuje zielono na zero asercji.
check( $ran >= 20, "wykonano komplet asercji (asercji: {$ran})" );

echo "\n=== " . ( 0 === $fail ? 'WSZYSTKIE OK' : "BŁĘDÓW: {$fail}" ) . " (asercji: {$ran}) ===\n";
exit( $fail > 0 ? 1 : 0 );
