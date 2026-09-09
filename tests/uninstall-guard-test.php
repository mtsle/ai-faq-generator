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

// ---------------------------------------------------------------------------
// 5b. CRON: każdy zaplanowany hook musi być zdejmowany przez uninstall.php.
//
// PROBLEM, KTÓRY ROZWIĄZUJE: 2026-07-31 (Krok 23 etap 5) zrzut żywej bazy
// pokazał, że `uninstall.php` zdejmował TYLKO `aifaq_crawl_tick`, choć wtyczka
// planuje dwa hooki — `aifaq_reindex_continue` zostawał w tablicy `cron`.
// `Deactivator` zdejmował oba, więc w pojedynczej witrynie ratowała kolejność
// (deaktywacja przed usunięciem), ale przy wtyczce aktywowanej dla SIECI hook
// deaktywacji odpala się raz, a crony siedzą osobno w każdym blogu.
// Sekcje 4-7 tego pliku pilnowały opcji, meta i transientów — crona nikt.
// ---------------------------------------------------------------------------

/**
 * Zwraca argumenty wywołania funkcji jako listę list tokenów.
 *
 * @param array $tk  Tokeny pliku (bez białych znaków i komentarzy).
 * @param int   $pos Indeks tokenu z nazwą funkcji (nawias musi być następny).
 *
 * @return array<int,array>
 */
function aifaq_call_args( $tk, $pos ) {
	$n = count( $tk );
	if ( ! isset( $tk[ $pos + 1 ] ) || '(' !== aifaq_tt( $tk[ $pos + 1 ] ) ) {
		return array();
	}
	$depth = 0;
	$args  = array();
	$cur   = array();
	for ( $j = $pos + 1; $j < $n; $j++ ) {
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
	return $args;
}

/**
 * Rozwiązuje argument do literału: `'x'` albo `self::CONST` / `Klasa::CONST`.
 *
 * @param array  $arg  Tokeny argumentu.
 * @param string $file Plik (dla stałych `self::`).
 *
 * @return string|null
 */
function aifaq_resolve_arg( $arg, $file ) {
	global $const_by_class, $const_by_file;
	if ( ! $arg ) {
		return null;
	}
	$lit = aifaq_str( $arg[0] );
	if ( null !== $lit ) {
		return $lit;
	}
	$expr = '';
	foreach ( $arg as $tok ) {
		$expr .= aifaq_tt( $tok );
	}
	if ( 1 !== preg_match( '/(?:^|\\\\)(\w+)::(\w+)$/', $expr, $m ) ) {
		return null;
	}
	if ( in_array( $m[1], array( 'self', 'static' ), true ) ) {
		return $const_by_file[ $file . '|' . $m[2] ] ?? null;
	}
	return $const_by_class[ $m[1] . '::' . $m[2] ] ?? null;
}

// Indeks argumentu, pod którym siedzi NAZWA HOOKA.
$sched_targets = array(
	'wp_schedule_event'        => 2, // ( timestamp, recurrence, hook ).
	'wp_schedule_single_event' => 1, // ( timestamp, hook ).
);

$scheduled_hooks = array();
foreach ( $files as $file ) {
	$tk = $tokens_by_file[ $file ];
	for ( $i = 0, $n = count( $tk ); $i < $n; $i++ ) {
		$t = $tk[ $i ];
		if ( ! is_array( $t ) || T_STRING !== $t[0] || ! isset( $sched_targets[ $t[1] ] ) ) {
			continue;
		}
		$prev = $i > 0 ? aifaq_tt( $tk[ $i - 1 ] ) : '';
		if ( in_array( $prev, array( '->', '::', 'function' ), true ) ) {
			continue;
		}
		$args = aifaq_call_args( $tk, $i );
		$idx  = $sched_targets[ $t[1] ];
		if ( ! isset( $args[ $idx ] ) ) {
			continue;
		}
		$hook = aifaq_resolve_arg( $args[ $idx ], $file );
		if ( null !== $hook && 1 === preg_match( '/^_?aifaq/', $hook ) ) {
			$scheduled_hooks[ $hook ][ basename( $file ) ] = true;
		}
	}
}

// Podłoga: gdyby skaner przestał cokolwiek widzieć, bramka niżej byłaby pusta
// i świeciła na zielono. Dziś planowane są DWA hooki — asercja ilościowa `=== N`.
check(
	2 === count( $scheduled_hooks ),
	'skaner widzi komplet planowanych cronow (znaleziono: ' . count( $scheduled_hooks )
		. ' → ' . implode( ', ', array_keys( $scheduled_hooks ) ) . ')'
);

$cron_missing = array();
foreach ( $scheduled_hooks as $hook => $where ) {
	if ( ! isset( $un_literals[ $hook ] ) ) {
		$cron_missing[] = $hook . ' ← ' . implode( ', ', array_keys( $where ) );
	}
}
check(
	0 === count( $cron_missing ),
	'KAZDY planowany cron jest zdejmowany w uninstall.php'
		. ( $cron_missing ? ( "\n         BRAK: " . implode( "\n         BRAK: ", $cron_missing ) ) : ' (sprawdzono: ' . count( $scheduled_hooks ) . ')' )
);

// Sam literał nie wystarcza — musi być realne wywołanie zdejmujące, po jednym
// na hook. Bez tego dopisanie nazwy w komentarzu zaliczałoby bramkę wyżej.
check(
	( $un_calls['wp_unschedule_hook'] ?? 0 ) === count( $scheduled_hooks ),
	'uninstall.php wola wp_unschedule_hook raz na hook (wywolan: '
		. ( $un_calls['wp_unschedule_hook'] ?? 0 ) . ', hookow: ' . count( $scheduled_hooks ) . ')'
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



// ---------------------------------------------------------------------------
// F10. RAU-R09-005 — ODINSTALOWANIE W1 NIE MOŻE TKNĄĆ DANYCH WTYCZKI 2
// ---------------------------------------------------------------------------
//
// Teza ZACH-AUD-03 była pokryta tylko w JEDNĄ stronę: zestawy wtyczki 2
// sprawdzają, że jej odinstalowanie nie rusza danych wtyczki 1, a w całym
// katalogu tests/ wtyczki 1 nie było ani jednej asercji w kierunku odwrotnym
// (grep -rn ainp po tests/ dawał zero trafień).
//
// To najcięższa klasa regresji tego produktu: klient dostaje JEDNĄ paczkę
// z dwiema wtyczkami, więc zbyt szeroki wzorzec `LIKE` przy usuwaniu wtyczki 1
// kasowałby artykuły portalu — i cały komplet zestawów W1 świeciłby na zielono.
//
// Strażnik nie pyta „czy w pliku jest napis ainp". Bierze KAŻDY wzorzec `LIKE`
// i KAŻDY literał z uninstall.php W1 i sprawdza je wobec prawdziwych kluczy
// wtyczki 2. Sam napis nie musiałby się pojawić, żeby dane zniknęły — wystarczy
// wzorzec `%_settings` albo `_transient_%`.

/**
 * Czy wzorzec SQL `LIKE` pasuje do nazwy.
 *
 * `%` = dowolny ciąg, `_` = DOKŁADNIE JEDEN znak (o tym się zapomina),
 * a `\` wprowadzony przez `esc_like()` odbiera obu specjalne znaczenie.
 *
 * @param string $wzorzec Wzorzec z zapytania.
 * @param string $nazwa   Sprawdzana nazwa klucza.
 *
 * @return bool
 */
function aifaq_like_pasuje( $wzorzec, $nazwa ) {
	$re  = '';
	$n   = strlen( $wzorzec );
	for ( $i = 0; $i < $n; $i++ ) {
		$z = $wzorzec[ $i ];
		if ( chr( 92 ) === $z && $i + 1 < $n ) {
			++$i;
			$re .= preg_quote( $wzorzec[ $i ], '/' );
			continue;
		}
		if ( '%' === $z ) {
			$re .= '.*';
			continue;
		}
		if ( '_' === $z ) {
			$re .= '.';
			continue;
		}
		$re .= preg_quote( $z, '/' );
	}
	return 1 === preg_match( '/^' . $re . '$/s', $nazwa );
}

// Kontrola samego narzędzia — inaczej matcher, który nigdy nie trafia, dałby
// komplet zieleni z zupełnie innego powodu.
check( aifaq_like_pasuje( 'aifaq!_%', 'aifaq!_settings' ), 'LIKE: `%` lapie dowolny ciag' );
check( aifaq_like_pasuje( 'ain_', 'ainp' ), 'LIKE: `_` lapie DOKLADNIE jeden znak' );
check( ! aifaq_like_pasuje( 'ain_', 'ainpx' ), 'LIKE: `_` NIE lapie dwoch znakow' );
check( ! aifaq_like_pasuje( chr( 92 ) . '_transient%', 'xtransient_a' ), 'LIKE: escapowany `_` jest zwyklym podkresleniem' );

// Prawdziwe klucze wtyczki 2 — nazwy wzięte z jej uninstall.php, nie wymyślone.
$ainp_probki = array(
	'ainp_settings',
	'ainp_sources',
	'ainp_key',
	'ainp_usage',
	'ainp_slug_collision',
	'ainp_topics_seeded',
	'ainp_db_version',
	'_transient_ainp_cokolwiek',
	'_transient_timeout_ainp_cokolwiek',
);
$ainp_meta   = array( '_ainp_item_id', '_ainp_source_url', '_ainp_demo' );
$ainp_obiekty = array( 'ainp_article', 'ainp_topic', 'ainp_items' );

// 1. Żaden wzorzec LIKE z uninstall.php W1 nie może trafić w klucz wtyczki 2.
// Wzorzec powstaje w kodzie jako `esc_like( '<prefiks>' ) . '%'`, więc w tokenach
// stoi ROZBITY na dwa literały. Sklejamy go tak samo jak zapytanie: bierzemy każdy
// literał i sami escapujemy w nim `_` oraz `%` — dokładnie to robi `esc_like()` —
// a na końcu doklejamy niescapowane `%`.
//
// Sam token `'%'` wzorcem NIE JEST: wzięty osobno łapie wszystko i dawałby fałszywy
// alarm zamiast dowodu. Bierzemy więc WSZYSTKIE literały pliku, bo każdy z nich
// mógłby jutro stać się prefiksem zamiatania — pytanie brzmi „czy którykolwiek
// prefiks obecny w tym pliku sięga po klucz wtyczki 2", nie „czy ten jeden".
$un_like = array();
foreach ( aifaq_tokens( $un_src ) as $t ) {
	$v = aifaq_str( $t );
	if ( null === $v || '' === $v ) {
		continue;
	}
	$un_like[] = str_replace(
		array( '_', '%' ),
		array( chr( 92 ) . '_', chr( 92 ) . '%' ),
		$v
	) . '%';
}
check( count( $un_like ) >= 2, 'rozlacznosc: skaner widzi wzorce LIKE w uninstall.php W1 (znalezionych: ' . count( $un_like ) . ')' );

$kolizje = array();
foreach ( $un_like as $wzorzec ) {
	foreach ( $ainp_probki as $klucz ) {
		if ( aifaq_like_pasuje( $wzorzec, $klucz ) ) {
			$kolizje[] = $wzorzec . ' -> ' . $klucz;
		}
	}
}
check(
	0 === count( $kolizje ),
	'rozlacznosc: ZADEN wzorzec LIKE z uninstall.php W1 nie lapie klucza ainp_'
		. ( $kolizje ? ( ' → ' . implode( ' ; ', $kolizje ) ) : '' )
);

// 2. Znacznik GUARD-PATTERN, po którym idzie zamiatanie, musi być prefiksem W1.
$guard_prefix = '';
if ( 1 === preg_match( '/GUARD-PATTERN:\s*(\S+)/', $un_src, $m_gp ) ) {
	$guard_prefix = $m_gp[1];
}
check( 'aifaq_' === $guard_prefix, 'rozlacznosc: GUARD-PATTERN to `aifaq_` (jest: ' . var_export( $guard_prefix, true ) . ')' );
// Osobny licznik: dziedziczenie wyniku poprzedniej pętli dałoby asercję, która
// czerwienieje z CUDZEGO powodu i niczego własnego nie dowodzi.
$kolizje_gp = array();
foreach ( array_merge( $ainp_probki, $ainp_meta, $ainp_obiekty ) as $klucz ) {
	if ( '' !== $guard_prefix && false !== strpos( $klucz, $guard_prefix ) ) {
		$kolizje_gp[] = 'GUARD-PATTERN -> ' . $klucz;
	}
}
check(
	0 === count( $kolizje_gp ),
	'rozlacznosc: prefiks zamiatania nie obejmuje zadnego klucza wtyczki 2'
		. ( $kolizje_gp ? ( ' -> ' . implode( ' ; ', $kolizje_gp ) ) : '' )
);

// 3. Żaden literał w uninstall.php W1 nie może BYĆ kluczem, meta ani typem W2.
$literaly_ainp = array();
foreach ( array_keys( $un_literals ) as $lit ) {
	foreach ( array_merge( $ainp_probki, $ainp_meta, $ainp_obiekty ) as $cudzy ) {
		if ( $lit === $cudzy ) {
			$literaly_ainp[] = $lit;
		}
	}
}
check( 0 === count( $literaly_ainp ), 'rozlacznosc: uninstall.php W1 nie wymienia z nazwy zadnego klucza wtyczki 2' );

// 4. Prefiks drugiej wtyczki nie pada w tym pliku w ogóle — także w komentarzu,
//    bo komentarz jest pierwszym krokiem do dopisania go do listy.
check(
	false === strpos( $un_src, 'ainp' ),
	'rozlacznosc: napis `ainp` nie wystepuje w uninstall.php W1 nawet w komentarzu'
);

// 5. Kasowane tabele muszą należeć do wtyczki 1 — sprawdzamy wszystkie, nie jedną.
$tabele_un = array();
if ( preg_match_all( '/\$wpdb->prefix \. .([a-z0-9_]+)./', $un_src, $m_tab ) ) {
	$tabele_un = $m_tab[1];
}
check( count( $tabele_un ) >= 6, 'rozlacznosc: skaner widzi liste tabel (znalezionych: ' . count( $tabele_un ) . ')' );
$obce_tabele = array();
foreach ( $tabele_un as $tab ) {
	if ( 0 !== strpos( $tab, 'aifaq_' ) ) {
		$obce_tabele[] = $tab;
	}
}
check(
	0 === count( $obce_tabele ),
	'rozlacznosc: KAZDA kasowana tabela ma prefiks aifaq_' . ( $obce_tabele ? ( ' → ' . implode( ',', $obce_tabele ) ) : '' )
);

// 6. Kierunek odwrotny jest pokryty po stronie W2 — sprawdzamy, że dalej jest.
//    Bez tego para asercji rozjeżdża się przy pierwszym porządkowaniu tamtego pliku.
$w2_test = dirname( __DIR__ ) . '/ai-news-portal/tests/krok1-uninstall-test.php';
check(
	is_readable( $w2_test ) && false !== strpos( (string) file_get_contents( $w2_test ), 'aifaq' ),
	'rozlacznosc: zestaw W2 nadal pilnuje kierunku odwrotnego'
);

// ---------------------------------------------------------------------------
// F9. RAU-R15-002 — NAJDŁUŻSZY TTL TRANSIENTU LICZONY, NIE PRZEPISANY
// ---------------------------------------------------------------------------
//
// Przy zewnętrznym trwałym object cache (Redis, Memcached) transienty nie leżą
// w `wp_options`, więc zamiatanie po `LIKE` ich nie obejmuje — zostaje TTL.
// uninstall.php deklaruje, jak długo to trwa, i ta deklaracja MUSI być prawdziwa.
//
// Strażnik nie porównuje zdań. Wylicza największy TTL ze WSZYSTKICH wywołań
// `set_transient()` w źródle i zderza go z liczbą z pliku. Zgłoszenie audytu
// mówiło o 12 h (`aifaq_no_thinking_`); rachunek pokazał 24 h, bo transient
// limitera gościa żyje tyle, ile okno limitu, a właściciel może wybrać dobę.

/**
 * Stałe czasu rdzenia WordPressa — jedyne nazwy spoza wtyczki, które rozwiązujemy.
 *
 * @var array<string,int>
 */
$ttl_wp_const = array(
	'MINUTE_IN_SECONDS' => 60,
	'HOUR_IN_SECONDS'   => 3600,
	'DAY_IN_SECONDS'    => 86400,
	'WEEK_IN_SECONDS'   => 604800,
);

// Definicje stałych jako TOKENY (wspólna mapa `$const_by_class` bierze tylko
// wartości jednotokenowe, więc `15 * MINUTE_IN_SECONDS` by w niej nie wylądowało).
$ttl_const_tok = array();
foreach ( $files as $file ) {
	$tk    = $tokens_by_file[ $file ];
	$class = '';
	for ( $i = 0, $n = count( $tk ); $i < $n; $i++ ) {
		if ( is_array( $tk[ $i ] ) && T_CLASS === $tk[ $i ][0]
			&& isset( $tk[ $i + 1 ] ) && is_array( $tk[ $i + 1 ] ) && T_STRING === $tk[ $i + 1 ][0] ) {
			$class = $tk[ $i + 1 ][1];
			continue;
		}
		if ( ! is_array( $tk[ $i ] ) || T_CONST !== $tk[ $i ][0] ) {
			continue;
		}
		if ( ! isset( $tk[ $i + 1 ], $tk[ $i + 2 ] ) || ! is_array( $tk[ $i + 1 ] )
			|| T_STRING !== $tk[ $i + 1 ][0] || '=' !== aifaq_tt( $tk[ $i + 2 ] ) ) {
			continue;
		}
		$nazwa = $tk[ $i + 1 ][1];
		$wart  = array();
		for ( $j = $i + 3; $j < $n && ';' !== aifaq_tt( $tk[ $j ] ); $j++ ) {
			$wart[] = $tk[ $j ];
		}
		$ttl_const_tok[ $file . '|' . $nazwa ] = $wart;
		if ( '' !== $class ) {
			$ttl_const_tok[ $class . '::' . $nazwa ] = $wart;
		}
	}
}

/**
 * Liczy wyrażenie całkowite złożone z liczb, `*`, `+` i nawiasów.
 *
 * Świadomie BEZ `eval()`: do testu trafia treść pliku źródłowego, więc
 * wykonanie jej jako kodu byłoby wykonaniem czegokolwiek, co ktoś tam wpisze.
 *
 * @param string $wyr Wyrażenie.
 *
 * @return int|null Wartość albo null, gdy wyrażenie nie jest czystą arytmetyką.
 */
function aifaq_licz_arytmetyke( $wyr ) {
	$wyr = str_replace( ' ', '', $wyr );
	if ( '' === $wyr || 1 !== preg_match( '/^[0-9()*+]+$/', $wyr ) ) {
		return null;
	}
	// Nawiasy od środka.
	$strzal = 0;
	while ( false !== strpos( $wyr, '(' ) ) {
		if ( ++$strzal > 20 ) {
			return null;
		}
		$wyr = preg_replace_callback(
			'/\(([0-9*+]+)\)/',
			static function ( $m ) {
				$v = aifaq_licz_arytmetyke( $m[1] );
				return null === $v ? 'X' : (string) $v;
			},
			$wyr
		);
		if ( null === $wyr || false !== strpos( (string) $wyr, 'X' ) ) {
			return null;
		}
	}
	$suma = 0;
	foreach ( explode( '+', $wyr ) as $skladnik ) {
		if ( '' === $skladnik ) {
			return null;
		}
		$iloczyn = 1;
		foreach ( explode( '*', $skladnik ) as $czynnik ) {
			if ( 1 !== preg_match( '/^[0-9]+$/', $czynnik ) ) {
				return null;
			}
			$iloczyn *= (int) $czynnik;
		}
		$suma += $iloczyn;
	}
	return $suma;
}

/**
 * Rozwiązuje argument TTL do liczby sekund.
 *
 * Radzi sobie z: literałem, arytmetyką literałów, stałymi czasu rdzenia,
 * `self::STALA` i `Klasa::STALA` (rekurencyjnie) oraz operatorem `? :`
 * (bierze WIĘKSZĄ z gałęzi — deklaracja ma opisywać najgorszy przypadek).
 *
 * @param array  $arg       Tokeny argumentu.
 * @param string $file      Plik, w którym stoi wywołanie.
 * @param int    $glebokosc Zabezpieczenie przed stałą wskazującą samą siebie.
 *
 * @return int|null Sekundy albo null, gdy wartość nie wynika ze źródła statycznie.
 */
function aifaq_ttl_sekundy( $arg, $file, $glebokosc = 0 ) {
	global $ttl_wp_const, $ttl_const_tok;

	if ( ! $arg || $glebokosc > 8 ) {
		return null;
	}

	$wyr = '';
	foreach ( $arg as $tok ) {
		$wyr .= aifaq_tt( $tok );
	}
	// Wyrażenia budowane na potrzeby rekurencji przechodzą przez `aifaq_tokens()`,
	// więc niosą znacznik otwarcia i średnik — obcinamy je, nie są częścią wartości.
	$wyr = trim( preg_replace( '/^\s*<\?php\s*/', '', trim( $wyr ) ) );
	$wyr = trim( rtrim( $wyr, ';' ) );

	// `warunek ? A : B` → większa z gałęzi.
	$q = strpos( $wyr, '?' );
	if ( false !== $q ) {
		$d = strpos( $wyr, ':', $q );
		if ( false === $d ) {
			return null;
		}
		$a = aifaq_ttl_sekundy( aifaq_tokens( '<?php ' . substr( $wyr, $q + 1, $d - $q - 1 ) . ';' ), $file, $glebokosc + 1 );
		$b = aifaq_ttl_sekundy( aifaq_tokens( '<?php ' . substr( $wyr, $d + 1 ) . ';' ), $file, $glebokosc + 1 );
		if ( null === $a || null === $b ) {
			return null;
		}
		return max( $a, $b );
	}

	// Stałe czasu rdzenia.
	foreach ( $ttl_wp_const as $nazwa => $wartosc ) {
		$wyr = str_replace( $nazwa, (string) $wartosc, $wyr );
	}

	// `self::X` / `static::X` / `Klasa::X` — rekurencyjnie, aż do liczb.
	if ( 1 === preg_match( '/(?:^|\\\\)(\w+)::(\w+)/', $wyr, $m ) ) {
		$klucz = in_array( $m[1], array( 'self', 'static' ), true )
			? $file . '|' . $m[2]
			: $m[1] . '::' . $m[2];
		if ( ! isset( $ttl_const_tok[ $klucz ] ) ) {
			return null;
		}
		$wartosc = aifaq_ttl_sekundy( $ttl_const_tok[ $klucz ], $file, $glebokosc + 1 );
		if ( null === $wartosc ) {
			return null;
		}
		$wyr = str_replace( $m[0], (string) $wartosc, $wyr );
	}

	return aifaq_licz_arytmetyke( $wyr );
}

// Kontrola samego narzędzia — bez niej cichy zwrot null udawałby „nic nie znalazłem".
check( 43200 === aifaq_ttl_sekundy( aifaq_tokens( '<?php 12 * 3600;' ), '' ), 'TTL: licznik radzi sobie z `12 * 3600`' );
check( 900 === aifaq_ttl_sekundy( aifaq_tokens( '<?php 15 * MINUTE_IN_SECONDS;' ), '' ), 'TTL: licznik zna stale czasu rdzenia' );
check( null === aifaq_ttl_sekundy( aifaq_tokens( '<?php $this->window;' ), '' ), 'TTL: zmienna NIE jest rozwiazywana po cichu' );

// Zbiór wszystkich TTL-i z `set_transient()` w źródle.
$ttl_wartosci     = array();
$ttl_nierozwiazane = array();
foreach ( $files as $file ) {
	$tk = $tokens_by_file[ $file ];
	for ( $i = 0, $n = count( $tk ); $i < $n; $i++ ) {
		$t = $tk[ $i ];
		if ( ! is_array( $t ) || T_STRING !== $t[0] || 'set_transient' !== $t[1] ) {
			continue;
		}
		if ( ! isset( $tk[ $i + 1 ] ) || '(' !== aifaq_tt( $tk[ $i + 1 ] ) ) {
			continue; // Sama nazwa w `function_exists()` albo w komentarzu.
		}
		$prev = $i > 0 ? aifaq_tt( $tk[ $i - 1 ] ) : '';
		if ( in_array( $prev, array( '->', '::', 'function' ), true ) ) {
			continue;
		}
		$args = aifaq_call_args( $tk, $i );
		if ( count( $args ) < 3 ) {
			// `set_transient()` bez TTL to transient BEZ WYGASANIA — najgorszy
			// możliwy przypadek dla tej deklaracji, więc jest błędem, nie pominięciem.
			$ttl_nierozwiazane[] = basename( $file ) . ':<brak trzeciego argumentu>';
			continue;
		}
		$sek = aifaq_ttl_sekundy( $args[2], $file );
		if ( null === $sek ) {
			$wyr = '';
			foreach ( $args[2] as $tok ) {
				$wyr .= aifaq_tt( $tok );
			}
			$ttl_nierozwiazane[] = basename( $file ) . ':' . trim( $wyr );
			continue;
		}
		$ttl_wartosci[] = $sek;
	}
}

check( count( $ttl_wartosci ) >= 4, 'TTL: skaner widzi wywolania set_transient() (policzonych: ' . count( $ttl_wartosci ) . ')' );

// TTL-e, których nie da się ustalić statycznie, MUSZĄ być wymienione tutaj
// z sufitem i dowodem. Nowy taki przypadek zapala strażnika — ma go obejrzeć
// człowiek, a nie przemknąć jako „nie umiem policzyć, więc pomijam".
$ttl_znane_zmienne = array(
	// RateLimiter dostaje okno z ustawień: `rag_rate_window = doba` → 86400 s.
	'RateLimiter.php:$this->window' => 86400,
	// GeminiProvider: `$ttl` to karencja wyłącznika obwodu — dobowa albo minutowa,
	// więc sufitem jest ta dłuższa, COOLDOWN_DAY_SECONDS.
	'GeminiProvider.php:$ttl'       => 3600,
);
$ttl_nierozwiazane = array_values( array_unique( $ttl_nierozwiazane ) );
$ttl_nowe          = array_values( array_diff( $ttl_nierozwiazane, array_keys( $ttl_znane_zmienne ) ) );

check(
	0 === count( $ttl_nowe ),
	'TTL: brak NOWYCH nieustalonych wartosci' . ( $ttl_nowe ? ( ' → ' . implode( ' ; ', $ttl_nowe ) ) : ' (znanych: ' . count( $ttl_nierozwiazane ) . ')' )
);

// Sufit przypisany zmiennej musi mieć kotwicę w kodzie, inaczej jest zgadywaniem.
$rag_src = (string) file_get_contents( $root . '/src/Rag/RagService.php' );
check(
	false !== strpos( $rag_src, "? 86400 : 3600" ) && false !== strpos( $rag_src, "'rag_rate_window'" ),
	'TTL: sufit okna limitera (86400) ma kotwice w RagService — `rag_rate_window`'
);

$gem_src = (string) file_get_contents( $root . '/src/Providers/GeminiProvider.php' );
check(
	false !== strpos( $gem_src, 'self::COOLDOWN_DAY_SECONDS' )
		&& 3600 === aifaq_ttl_sekundy( $ttl_const_tok['GeminiProvider::COOLDOWN_DAY_SECONDS'] ?? array(), '' ),
	'TTL: sufit karencji wylacznika (3600) ma kotwice w GeminiProvider::COOLDOWN_DAY_SECONDS'
);

$ttl_max = max( array_merge( $ttl_wartosci, array_values( $ttl_znane_zmienne ) ) );

// Deklaracja z uninstall.php — znacznik maszynowy, nie zdanie do czytania.
$ttl_deklarowany = null;
if ( 1 === preg_match( '/GUARD-MAX-TTL:\s*(\d+)/', $un_src, $m_ttl ) ) {
	$ttl_deklarowany = (int) $m_ttl[1];
}
check( null !== $ttl_deklarowany, 'TTL: uninstall.php niesie znacznik GUARD-MAX-TTL' );
check(
	$ttl_max === $ttl_deklarowany,
	'TTL: deklaracja uninstall.php zgadza sie z najdluzszym TTL w kodzie (kod: ' . $ttl_max . ', deklaracja: ' . var_export( $ttl_deklarowany, true ) . ')'
);
check(
	false === strpos( $un_src, 'najdłuższy to godzina' ),
	'TTL: zniknelo nieprawdziwe zdanie o godzinie'
);

// ---------------------------------------------------------------------------
// F9. RAU-R15-003 — LISTA WITRYN SIECI POBIERANA STRONICOWANIEM
// ---------------------------------------------------------------------------
//
// Jedno wywołanie `get_sites()` z jakimkolwiek `number` zostawia w większej
// sieci resztę witryn nieposprzątaną — dokładnie ta cicha strata, przed którą
// broniło jawne ustawienie limitu. Bramka pilnuje trzech rzeczy naraz:
// przesunięcia, pętli i tego, że limit nie wrócił jako pojedynczy strzał.
$un_sites_args = array();
for ( $i = 0, $n = count( $un_tokens ); $i < $n; $i++ ) {
	$t = $un_tokens[ $i ];
	if ( is_array( $t ) && T_STRING === $t[0] && 'get_sites' === $t[1]
		&& isset( $un_tokens[ $i + 1 ] ) && '(' === aifaq_tt( $un_tokens[ $i + 1 ] ) ) {
		$args = aifaq_call_args( $un_tokens, $i );
		$wyr  = '';
		foreach ( ( $args[0] ?? array() ) as $tok ) {
			$wyr .= aifaq_tt( $tok );
		}
		$un_sites_args[] = $wyr;
	}
}
check( 1 === count( $un_sites_args ), 'siec: dokladnie jedno wywolanie get_sites() (jest: ' . count( $un_sites_args ) . ')' );
check(
	false !== strpos( $un_sites_args[0] ?? '', "'offset'" ),
	'siec: get_sites() dostaje offset — bez niego kazda strona jest ta sama'
);
check(
	1 === preg_match( '/do\s*\{.*?get_sites.*?\}\s*while\s*\(/s', $un_src ),
	'siec: get_sites() stoi w petli do/while, nie w jednym strzale'
);
check(
	false === strpos( $un_src, "'number' => 10000" ),
	'siec: znikl pojedynczy strzal z number => 10000'
);
check(
	1 === preg_match( '/while\s*\(\s*\$aifaq_pobrano\s*>=\s*\$aifaq_strona\s*\)/', $un_src ),
	'siec: warunek zejscia to „strona krotsza niz pelna" — pusta strona konczy petle'
);

// ---------------------------------------------------------------------------
// F9. TECH-22 — dokument przestaje twierdzić, że sieci nie ma
// ---------------------------------------------------------------------------
$sciezka_tech = $root . '/audyt/dokumentacja/DOKUMENTACJA-TECHNICZNA.txt';
/**
 * Zderza kopie dokumentu w repozytorium z kanonem w `projektAUDYT/dokumentacja/`.
 *
 * PO CO: straznicy dokumentacyjni czytali dotad WYLACZNIE kanon, ktory lezy POZA
 * repozytorium. Lokalnie bylo zielono, a pierwsze zderzenie z GitHub Actions
 * przewrocilo cztery zestawy naraz — CI tego katalogu nie widzi. Dokument jest
 * teraz kopiowany do repo i to kopie czytaja asercje, wiec straznik chodzi
 * takze w CI. Ta funkcja pilnuje, zeby kopia nie rozjechala sie z kanonem.
 *
 * Asercja wykonuje sie w OBU galeziach, takze gdy kanonu nie ma. Liczba asercji
 * musi byc identyczna w kazdym srodowisku, bo runner wtyczki 2 zalicza zestaw
 * dopiero przy DOKLADNEJ rownosci liczby wykonanych asercji.
 *
 * @param string $w_repo Sciezka kopii w repozytorium.
 * @param string $nazwa  Nazwa pliku dokumentu.
 *
 * @return void
 */
function aifaq_doc_zgodna( $w_repo, $nazwa ) {
	$kanon = dirname( dirname( dirname( __DIR__ ) ) ) . '/projektAUDYT/dokumentacja/' . $nazwa;
	if ( ! is_file( $kanon ) ) {
		check( is_file( $w_repo ), 'kopia ' . $nazwa . ' jest w repo (kanon spoza repo niedostepny w tym srodowisku)' );
		return;
	}
	// Porownanie idzie po tresci ZNORMALIZOWANEJ na konce wierszy, nie po bajtach.
	// Git zamienia CRLF na LF przy pobraniu, wiec swiezy klon mialby inne bajty niz
	// kanon na maszynie autora i kontrola zapalilaby sie na roznicy, ktora nie jest
	// rozjazdem tresci.
	$norm = static function ( $sciezka ) {
		return str_replace( "\r\n", "\n", (string) file_get_contents( $sciezka ) );
	};
	check(
		is_file( $w_repo ) && $norm( $kanon ) === $norm( $w_repo ),
		'kopia ' . $nazwa . ' w repo zgodna z kanonem w projektAUDYT (konce wierszy pominiete)'
	);
}
aifaq_doc_zgodna( $sciezka_tech, 'DOKUMENTACJA-TECHNICZNA.txt' );
$doc_tech     = is_readable( $sciezka_tech ) ? (string) file_get_contents( $sciezka_tech ) : '';
if ( '' !== $doc_tech ) {
	check(
		false === strpos( $doc_tech, 'uninstall.php nie obsługuje multisite' ),
		'TECH-22: zniklo twierdzenie, ze uninstall.php nie obsluguje multisite'
	);
	check(
		false !== strpos( $doc_tech, 'ainp_uninstall_cleanup_site()' )
			&& false !== strpos( $doc_tech, 'aifaq_uninstall_cleanup_site()' ),
		'TECH-22: kotwica wskazuje obie funkcje sprzatajace witryne'
	);
} else {
	check( false, 'TECH-22: dokumentacja techniczna nieczytelna z testu (' . $sciezka_tech . ')' );
}

// Licznik podłogowy: bez niego plik z wywaloną sekcją raportuje zielono na zero asercji.
// F9: podłoga podniesiona 20 -> 43, F10: 43 -> 56. Asercja ZMIENIONA, nie usunięta: zestaw
// wykonywał już 24 asercje, więc próg 20 przepuszczałby wycięcie całej sekcji
// multisite. Nowa liczba to stan po dopisaniu strażników TTL, sieci i TECH-22.
check( $ran >= 56, "wykonano komplet asercji (asercji: {$ran})" );

echo "\n=== " . ( 0 === $fail ? 'WSZYSTKIE OK' : "BŁĘDÓW: {$fail}" ) . " (asercji: {$ran}) ===\n";
exit( $fail > 0 ? 1 : 0 );
