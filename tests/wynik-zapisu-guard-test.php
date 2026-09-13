<?php
/**
 * Strażnik „zgubionego wyniku zapisu $wpdb" wtyczki 1 — czysto STATYCZNY.
 *
 * PO CO ISTNIEJE: `$wpdb` nie rzuca wyjątków. Błąd SQL to `false`, a „nic nie
 * zmieniono" to `0`. Kod, który wynik zapisu wyrzuca, rzutuje `(int)` albo
 * obcina `max( 0, … )`, nie odróżnia awarii od braku zmian — i ogłasza sukces,
 * choć baza mówi co innego. Tak przeżyły audyt „Wyczyść bazę" odpowiadające 200
 * przy nieskasowanej bazie, martwa ochrona flagi cache i retencja dziennika gości
 * bez śladu porażki (projektAUDYT/audyt/NOTATKA-BLEDY-WYNIKU-ZAPISU.txt).
 *
 * REGUŁY (na tokenach PHP — token_get_all, bez eval, bez wyrażeń regularnych
 * po tekście). Dla każdego `$wpdb->query|update|delete|insert|replace(`:
 *   R-a wynik nie może być WYRZUCONY (samodzielna instrukcja);
 *   R-b nie może być bezpośrednio rzutowany `(int)` / `(bool)` / zanegowany `!`;
 *   R-c przypisany do zmiennej — ta zmienna musi zostać porównana z `false`
 *       (`false ===`, `=== false`, `false !==`, `!== false`) w tej samej
 *       funkcji, zanim zostanie nadpisana. Wyjątek: `1 === (int) $zmienna`
 *       (przejęcie/CAS) — `false` nigdy nie daje 1, więc awaria jest tam
 *       zawsze „nieudanym przejęciem". `max( 0, (int) $zmienna )` bez
 *       porównania z false jest naruszeniem;
 *   R-d wyjątek wyłącznie z markerem `// WYNIK-ZAPISU-POMINIETY: <powód>`
 *       w linii wywołania albo nad nią ORAZ z wpisem w tablicy
 *       WZ1_SWIADOME. Marker bez wpisu, wpis bez markera i przekroczenie
 *       sufitu to naruszenia.
 *
 * Wywołanie `return $wpdb->…` przekazuje `int|false` wywołującemu i jest
 * dozwolone — rozstrzygnięcie należy wtedy do niego (pilnują tego strażniki
 * behawioralne w zestawach konsumentów).
 *
 * OGRANICZENIE MÓWIONE WPROST: strażnik nie widzi jawnego zamiecenia
 * `false === $x ? 0 : (int) $x` (M4) — formalnie to porównanie z false.
 * Te ścieżki pilnują asercje behawioralne z atrapą zwracającą false.
 *
 * Skanuje `src/` i `uninstall.php` wtyczki 1. Nic spoza repozytorium.
 *
 * URUCHOMIENIE:  php tests/wynik-zapisu-guard-test.php
 * Kod wyjścia: 0 = OK, 1 = błędy.
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
function wz1_check( $cond, $label ) {
	global $fail, $ran;
	++$ran;
	echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n";
	if ( ! $cond ) {
		++$fail;
	}
}

/**
 * ŚWIADOME POMINIĘCIA — wynik nie ma dokąd trafić albo porażka sama się leczy.
 * Klucz: `plik|funkcja`, wartość: [liczba wywołań z markerem, powód].
 * Każdy wpis MUSI mieć dokładnie tyle markerów w kodzie.
 */
const WZ1_SWIADOME = array(
	'src/Data/KnowledgeRepository.php|replace_for_post'   => array( 4, 'START TRANSACTION (degradacja MyISAM w docblocku) i ROLLBACK w gałęziach błędu — nie ma dokąd eskalować' ),
	'src/Data/Migrator.php|migrate_history_to_qa_log'     => array( 3, 'START TRANSACTION i ROLLBACK w gałęziach błędu — nie ma dokąd eskalować' ),
	'uninstall.php|aifaq_uninstall_cleanup_site'          => array( 2, 'odinstalowanie nie ma kanału raportowania' ),
);

/** Sufit liczby świadomych pominięć. Podniesienie = jawna decyzja z uzasadnieniem. */
const WZ1_SUFIT = 9;

/**
 * DO NAPRAWY — znane naruszenia z dnia wdrożenia strażnika. Każda faza naprawy
 * usuwa swoje wpisy. Klucz: `plik|funkcja`, wartość: [liczba naruszeń, pozycja].
 * Wpis musi odpowiadać DOKŁADNIE liczbie naruszeń bez markera.
 */
const WZ1_DO_NAPRAWY = array();

/** Liczba wpisów DO NAPRAWY z dnia wdrożenia (historia trybu przejściowego, F1). */
const WZ1_DO_NAPRAWY_START = 13;

/** Podłoga liczby wywołań zapisu znalezionych w skanie (dzień wdrożenia: 26). */
const WZ1_PODLOGA_WYWOLAN = 26;

/* ------------------------------------------------------------------------- */
/* Klasyfikator na tokenach                                                  */
/* ------------------------------------------------------------------------- */

/**
 * Czy token jest „przezroczysty" (spacja, komentarz).
 *
 * @param mixed $tok Token.
 *
 * @return bool
 */
function wz1_pusty( $tok ) {
	return is_array( $tok ) && in_array( $tok[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true );
}

/**
 * Indeks następnego znaczącego tokenu albo -1.
 *
 * @param array $t Tokeny.
 * @param int   $i Indeks.
 *
 * @return int
 */
function wz1_nast( array $t, $i ) {
	$n = count( $t );
	for ( $j = $i + 1; $j < $n; $j++ ) {
		if ( ! wz1_pusty( $t[ $j ] ) ) {
			return $j;
		}
	}
	return -1;
}

/**
 * Indeks poprzedniego znaczącego tokenu albo -1.
 *
 * @param array $t Tokeny.
 * @param int   $i Indeks.
 *
 * @return int
 */
function wz1_pop( array $t, $i ) {
	for ( $j = $i - 1; $j >= 0; $j-- ) {
		if ( ! wz1_pusty( $t[ $j ] ) ) {
			return $j;
		}
	}
	return -1;
}

/**
 * Czy token o indeksie $i to podany znak albo [typ, (tekst)].
 *
 * @param array        $t  Tokeny.
 * @param int          $i  Indeks.
 * @param string|array $co Znak albo [typ, tekst?].
 *
 * @return bool
 */
function wz1_jest( array $t, $i, $co ) {
	if ( $i < 0 || ! isset( $t[ $i ] ) ) {
		return false;
	}
	if ( is_array( $co ) ) {
		return is_array( $t[ $i ] ) && $t[ $i ][0] === $co[0] && ( ! isset( $co[1] ) || 0 === strcasecmp( $t[ $i ][1], $co[1] ) );
	}
	return ! is_array( $t[ $i ] ) && $t[ $i ] === $co;
}

/**
 * Czy token to stała `false`.
 *
 * @param array $t Tokeny.
 * @param int   $i Indeks.
 *
 * @return bool
 */
function wz1_false( array $t, $i ) {
	return wz1_jest( $t, $i, array( T_STRING, 'false' ) );
}

/**
 * Czy token to `===` albo `!==`.
 *
 * @param array $t Tokeny.
 * @param int   $i Indeks.
 *
 * @return bool
 */
function wz1_tozsamosc( array $t, $i ) {
	return wz1_jest( $t, $i, array( T_IS_IDENTICAL ) ) || wz1_jest( $t, $i, array( T_IS_NOT_IDENTICAL ) );
}

/**
 * Indeks nawiasu domykającego ten, który otwiera token $i.
 *
 * @param array $t Tokeny.
 * @param int   $i Indeks nawiasu otwierającego.
 *
 * @return int
 */
function wz1_domknij( array $t, $i ) {
	$g = 0;
	$n = count( $t );
	for ( $j = $i; $j < $n; $j++ ) {
		$z = is_array( $t[ $j ] ) ? null : $t[ $j ];
		if ( is_array( $t[ $j ] ) && in_array( $t[ $j ][0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) {
			$z = '{';
		}
		if ( '(' === $z || '{' === $z || '[' === $z ) {
			++$g;
		} elseif ( ')' === $z || '}' === $z || ']' === $z ) {
			--$g;
			if ( 0 === $g ) {
				return $j;
			}
		}
	}
	return $n - 1;
}

/**
 * Ciała funkcji: lista [nazwa, indeks `{`, indeks `}`].
 *
 * @param array $t Tokeny.
 *
 * @return array
 */
function wz1_funkcje( array $t ) {
	$wynik = array();
	$n     = count( $t );
	for ( $i = 0; $i < $n; $i++ ) {
		if ( ! wz1_jest( $t, $i, array( T_FUNCTION ) ) ) {
			continue;
		}
		$j = wz1_nast( $t, $i );
		if ( wz1_jest( $t, $j, '&' ) ) {
			$j = wz1_nast( $t, $j );
		}
		$nazwa = wz1_jest( $t, $j, array( T_STRING ) ) ? $t[ $j ][1] : '{closure}';
		$k     = $j;
		while ( $k < $n && ! wz1_jest( $t, $k, '{' ) && ! wz1_jest( $t, $k, ';' ) ) {
			++$k;
		}
		if ( $k >= $n || wz1_jest( $t, $k, ';' ) ) {
			continue; // metoda abstrakcyjna / interfejs
		}
		$wynik[] = array( $nazwa, $k, wz1_domknij( $t, $k ) );
	}
	return $wynik;
}

/**
 * Klasyfikuje każde wywołanie zapisu w kodzie.
 *
 * Zwraca listę rekordów: linia, metoda, funkcja, klasa, naruszenie, marker.
 * Marker, który nie stoi przy żadnym NARUSZENIU, daje rekord `MARKER-BEZ-NARUSZENIA`
 * (naruszenie) — martwy marker udawałby świadomą decyzję.
 *
 * @param string $kod Kod PHP.
 *
 * @return array<int,array{linia:int,metoda:string,funkcja:string,klasa:string,narusza:bool,marker:bool}>
 */
function wz1_klasyfikuj( $kod ) {
	$t       = token_get_all( $kod );
	$n       = count( $t );
	$funkcje = wz1_funkcje( $t );
	$markery = array();
	foreach ( $t as $tok ) {
		if ( is_array( $tok ) && T_COMMENT === $tok[0] && false !== strpos( $tok[1], 'WYNIK-ZAPISU-POMINIETY:' ) ) {
			$markery[ $tok[2] ] = false;
		}
	}

	$wynik = array();
	for ( $i = 0; $i < $n; $i++ ) {
		if ( ! wz1_jest( $t, $i, array( T_VARIABLE, '$wpdb' ) ) ) {
			continue;
		}
		$m = $i + 2;
		$p = wz1_nast( $t, $m );
		if ( ! wz1_jest( $t, $i + 1, array( T_OBJECT_OPERATOR ) ) || ! wz1_jest( $t, $m, array( T_STRING ) )
			|| ! in_array( strtolower( $t[ $m ][1] ), array( 'query', 'update', 'delete', 'insert', 'replace' ), true )
			|| ! wz1_jest( $t, $p, '(' ) ) {
			continue;
		}

		$linia   = $t[ $i ][2];
		$funkcja = '(plik)';
		$f_do    = $n - 1;
		foreach ( $funkcje as $f ) {
			if ( $f[1] < $i && $f[2] > $i ) {
				$funkcja = $f[0];
				$f_do    = $f[2];
			}
		}

		$koniec = wz1_domknij( $t, $p );
		$za     = wz1_nast( $t, $koniec );
		$przed  = wz1_pop( $t, $i );
		$narusz = false;

		if ( ( wz1_tozsamosc( $t, $za ) && wz1_false( $t, wz1_nast( $t, $za ) ) )
			|| ( wz1_tozsamosc( $t, $przed ) && wz1_false( $t, wz1_pop( $t, $przed ) ) ) ) {
			$klasa = 'POROWNANY-Z-FALSE';
		} elseif ( wz1_jest( $t, $przed, ';' ) || wz1_jest( $t, $przed, '{' ) || wz1_jest( $t, $przed, '}' )
			|| wz1_jest( $t, $przed, ')' ) || wz1_jest( $t, $przed, array( T_ELSE ) ) || wz1_jest( $t, $przed, array( T_OPEN_TAG ) ) ) {
			$klasa  = 'WYRZUCONY';
			$narusz = true;
		} elseif ( wz1_jest( $t, $przed, array( T_INT_CAST ) ) || wz1_jest( $t, $przed, array( T_BOOL_CAST ) ) || wz1_jest( $t, $przed, '!' ) ) {
			$klasa  = 'RZUTOWANY';
			$narusz = true;
		} elseif ( wz1_jest( $t, $przed, array( T_RETURN ) ) ) {
			$klasa = 'ZWRACANY';
		} elseif ( wz1_jest( $t, $przed, '=' ) && wz1_jest( $t, wz1_pop( $t, $przed ), array( T_VARIABLE ) ) ) {
			$zmienna = $t[ wz1_pop( $t, $przed ) ][1];
			$ok      = false;
			for ( $j = $koniec + 1; $j < $f_do; $j++ ) {
				if ( ! wz1_jest( $t, $j, array( T_VARIABLE, $zmienna ) ) ) {
					continue;
				}
				$a = wz1_pop( $t, $j );
				$b = wz1_nast( $t, $j );
				if ( wz1_jest( $t, $b, '=' ) ) {
					break; // nadpisana przed rozstrzygnięciem
				}
				if ( ( wz1_tozsamosc( $t, $a ) && wz1_false( $t, wz1_pop( $t, $a ) ) )
					|| ( wz1_tozsamosc( $t, $b ) && wz1_false( $t, wz1_nast( $t, $b ) ) ) ) {
					$ok = true;
					break;
				}
				if ( wz1_jest( $t, $a, array( T_INT_CAST ) ) ) {
					$c = wz1_pop( $t, $a );
					if ( wz1_jest( $t, $c, array( T_IS_IDENTICAL ) ) && wz1_jest( $t, wz1_pop( $t, $c ), array( T_LNUMBER, '1' ) ) ) {
						$ok = true; // CAS: `1 === (int) $zmienna`
						break;
					}
				}
				break; // pierwsze użycie nie jest rozstrzygnięciem false
			}
			$klasa  = $ok ? 'PRZYPISANY-Z-FALSE' : 'PRZYPISANY-BEZ-FALSE';
			$narusz = ! $ok;
		} else {
			$klasa  = 'NIEROZSTRZYGNIETY';
			$narusz = true;
		}

		$marker = false;
		foreach ( array( $linia, $linia - 1 ) as $l ) {
			if ( isset( $markery[ $l ] ) ) {
				$marker = true;
				if ( $narusz ) {
					$markery[ $l ] = true;
				}
			}
		}

		$wynik[] = array(
			'linia'   => $linia,
			'metoda'  => $t[ $m ][1],
			'funkcja' => $funkcja,
			'klasa'   => $klasa,
			'narusza' => $narusz,
			'marker'  => $marker,
		);
	}

	foreach ( $markery as $l => $przy_naruszeniu ) {
		if ( ! $przy_naruszeniu ) {
			$wynik[] = array(
				'linia'   => $l,
				'metoda'  => '-',
				'funkcja' => '-',
				'klasa'   => 'MARKER-BEZ-NARUSZENIA',
				'narusza' => true,
				'marker'  => false,
			);
		}
	}

	return $wynik;
}

/**
 * Rozlicza rekordy z tablicami. Zwraca listę problemów (pusta = zgodność).
 *
 * @param array<string,array> $rekordy_wg_pliku Plik => rekordy z wz1_klasyfikuj().
 * @param array               $swiadome         Tablica świadomych pominięć.
 * @param array               $do_naprawy       Tablica DO NAPRAWY.
 * @param int                 $sufit            Sufit świadomych pominięć.
 *
 * @return array{nowe:string[],martwe:string[],sufit:string[]}
 */
function wz1_rozlicz( array $rekordy_wg_pliku, array $swiadome, array $do_naprawy, $sufit ) {
	$z_markerem = array();
	$bez        = array();
	$nowe       = array();
	foreach ( $rekordy_wg_pliku as $plik => $rekordy ) {
		foreach ( $rekordy as $r ) {
			if ( ! $r['narusza'] ) {
				continue;
			}
			$klucz = $plik . '|' . $r['funkcja'];
			if ( 'MARKER-BEZ-NARUSZENIA' === $r['klasa'] ) {
				$nowe[] = "{$plik}:{$r['linia']} marker bez naruszenia";
			} elseif ( $r['marker'] ) {
				$z_markerem[ $klucz ] = ( $z_markerem[ $klucz ] ?? 0 ) + 1;
			} else {
				$bez[ $klucz ][] = "{$plik}:{$r['linia']} {$r['funkcja']}() {$r['metoda']} {$r['klasa']}";
			}
		}
	}

	$martwe = array();
	foreach ( $z_markerem as $klucz => $ile ) {
		$wpis = $swiadome[ $klucz ][0] ?? 0;
		if ( $ile > $wpis ) {
			$nowe[] = "{$klucz}: markerów {$ile}, wpis w tablicy {$wpis} (marker bez wpisu)";
		}
	}
	foreach ( $swiadome as $klucz => $wpis ) {
		$ile = $z_markerem[ $klucz ] ?? 0;
		if ( $ile < $wpis[0] ) {
			$martwe[] = "{$klucz}: wpis {$wpis[0]}, markerów {$ile} (wpis bez markera)";
		}
	}
	foreach ( $bez as $klucz => $lista ) {
		$wpis = $do_naprawy[ $klucz ][0] ?? 0;
		if ( count( $lista ) > $wpis ) {
			foreach ( $lista as $opis ) {
				$nowe[] = $opis . ( $wpis ? " (DO NAPRAWY dopuszcza {$wpis})" : '' );
			}
		}
	}
	foreach ( $do_naprawy as $klucz => $wpis ) {
		$ile = isset( $bez[ $klucz ] ) ? count( $bez[ $klucz ] ) : 0;
		if ( $ile < $wpis[0] ) {
			$martwe[] = "{$klucz}: DO NAPRAWY {$wpis[1]} = {$wpis[0]}, naruszeń {$ile} (usuń naprawiony wpis)";
		}
	}

	$suma   = array_sum( array_column( $swiadome, 0 ) );
	$ponad  = $suma > $sufit ? array( "świadomych pominięć {$suma} > sufit {$sufit}" ) : array();

	return array( 'nowe' => $nowe, 'martwe' => $martwe, 'sufit' => $ponad );
}

/* ------------------------------------------------------------------------- */
/* A. Kontrole samego strażnika — cichy zwrot „nic nie znalazłem" udaje zieleń */
/* ------------------------------------------------------------------------- */
echo "=== A. Kontrole klasyfikatora ===\n";

/**
 * Czy fragment kodu daje co najmniej jedno naruszenie.
 *
 * @param string $cialo Ciało funkcji.
 *
 * @return bool
 */
function wz1_fragment_narusza( $cialo ) {
	foreach ( wz1_klasyfikuj( "<?php\nfunction probka() {\n\tglobal \$wpdb;\n{$cialo}\n}\n" ) as $r ) {
		if ( $r['narusza'] ) {
			return true;
		}
	}
	return false;
}

wz1_check( wz1_fragment_narusza( "\t\$wpdb->query( 'DELETE FROM t' );" ), 'WYRZUCONY zapis daje naruszenie' );
wz1_check( wz1_fragment_narusza( "\treturn (int) \$wpdb->query( 'DELETE FROM t' );" ), 'RZUTOWANY zapis daje naruszenie' );
wz1_check( wz1_fragment_narusza( "\t\$n = \$wpdb->update( 't', array(), array() );\n\treturn \$n;" ), 'PRZYPISANY bez porownania z false daje naruszenie' );
wz1_check( wz1_fragment_narusza( "\t\$n = \$wpdb->query( 'DELETE FROM t' );\n\treturn max( 0, (int) \$n );" ), 'max( 0, (int) ) bez false daje naruszenie' );
wz1_check( ! wz1_fragment_narusza( "\t\$n = \$wpdb->query( 'DELETE FROM t' );\n\tif ( false === \$n ) {\n\t\treturn false;\n\t}\n\treturn (int) \$n;" ), 'POPRAWNY zapis (false ===) NIE daje naruszenia' );
wz1_check( ! wz1_fragment_narusza( "\t\$z = \$wpdb->query( 'UPDATE t' );\n\treturn 1 === (int) \$z;" ), 'CAS `1 === (int)` NIE daje naruszenia' );
wz1_check( wz1_fragment_narusza( "\t// WYNIK-ZAPISU-POMINIETY: probka\n\t\$wpdb->query( 'x' ) === false;" ), 'marker przy zapisie bez naruszenia jest naruszeniem' );

$probka_marker = array( 'p.php' => wz1_klasyfikuj( "<?php\nfunction f() {\n\tglobal \$wpdb;\n\t// WYNIK-ZAPISU-POMINIETY: probka\n\t\$wpdb->query( 'x' );\n}\n" ) );
$r_bez_wpisu   = wz1_rozlicz( $probka_marker, array(), array(), 9 );
wz1_check( count( $r_bez_wpisu['nowe'] ) > 0, 'marker bez wpisu w tablicy jest naruszeniem' );
$r_bez_markera = wz1_rozlicz( array( 'p.php' => array() ), array( 'p.php|f' => array( 1, 'probka' ) ), array(), 9 );
wz1_check( count( $r_bez_markera['martwe'] ) > 0, 'wpis w tablicy bez markera jest naruszeniem' );
$r_sufit = wz1_rozlicz( $probka_marker, array( 'p.php|f' => array( 1, 'probka' ) ), array(), 0 );
wz1_check( count( $r_sufit['sufit'] ) > 0, 'przekroczenie sufitu jest naruszeniem' );
$r_zgodny = wz1_rozlicz( $probka_marker, array( 'p.php|f' => array( 1, 'probka' ) ), array(), 1 );
wz1_check( array() === array_merge( $r_zgodny['nowe'], $r_zgodny['martwe'], $r_zgodny['sufit'] ), 'marker z wpisem w suficie NIE jest naruszeniem' );

/* ------------------------------------------------------------------------- */
/* B. Skan wtyczki 1                                                         */
/* ------------------------------------------------------------------------- */
echo "\n=== B. Skan src/ i uninstall.php ===\n";

$pliki = array();
if ( is_dir( $root . '/src' ) ) {
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/src', FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $plik ) {
		if ( '.php' === substr( (string) $plik, -4 ) ) {
			$pliki[] = str_replace( '\\', '/', substr( (string) $plik, strlen( $root ) + 1 ) );
		}
	}
}
$pliki[] = 'uninstall.php';
sort( $pliki );

$rekordy  = array();
$wywolan  = 0;
$czytelne = true;
foreach ( $pliki as $plik ) {
	$kod = is_file( $root . '/' . $plik ) ? file_get_contents( $root . '/' . $plik ) : false;
	if ( false === $kod ) {
		$czytelne = false;
		continue;
	}
	$rekordy[ $plik ] = wz1_klasyfikuj( $kod );
	foreach ( $rekordy[ $plik ] as $r ) {
		if ( '-' !== $r['metoda'] ) {
			++$wywolan;
		}
	}
}
wz1_check( $czytelne && count( $pliki ) > 30, 'skan objal src/ i uninstall.php (plikow: ' . count( $pliki ) . ')' );
wz1_check( $wywolan >= WZ1_PODLOGA_WYWOLAN, "skan znalazl co najmniej " . WZ1_PODLOGA_WYWOLAN . " wywolan zapisu (znaleziono: {$wywolan})" );

$wynik = wz1_rozlicz( $rekordy, WZ1_SWIADOME, WZ1_DO_NAPRAWY, WZ1_SUFIT );
foreach ( $wynik['nowe'] as $opis ) {
	echo "       naruszenie: {$opis}\n";
}
wz1_check( array() === $wynik['nowe'], 'zero NOWYCH naruszen R-a/R-b/R-c/R-d (nierozliczonych: ' . count( $wynik['nowe'] ) . ')' );
foreach ( $wynik['martwe'] as $opis ) {
	echo "       martwy wpis: {$opis}\n";
}
wz1_check( array() === $wynik['martwe'], 'zero martwych wpisow w tablicach (martwych: ' . count( $wynik['martwe'] ) . ')' );
wz1_check( array() === $wynik['sufit'], 'swiadomych pominiec nie wiecej niz sufit ' . WZ1_SUFIT );

$do_naprawy = array_sum( array_column( WZ1_DO_NAPRAWY, 0 ) );
// ZMIENIONA JAWNIE w fazie F6: tryb przejsciowy zamkniety. Wszystkie pozycje DO NAPRAWY
// z dnia wdrozenia sa naprawione, wiec zamiast „nie przybylo" pilnujemy pustej tablicy —
// nowe naruszenie da sie odtad dopuscic wylacznie markerem i wpisem swiadomego pominiecia.
wz1_check( 0 === $do_naprawy, "zero wpisow DO NAPRAWY (tryb przejsciowy zamkniety; na starcie: " . WZ1_DO_NAPRAWY_START . ", jest: {$do_naprawy})" );

// ---------------------------------------------------------------------------
// Z. Podłoga pokrycia i wartownik końca pliku.
// ---------------------------------------------------------------------------
echo "\n=== Z. Podloga pokrycia ===\n";
wz1_check( $ran >= 17, 'wykonano komplet asercji (asercji: ' . $ran . ')' );
wz1_check( true, 'plik dobiegl konca' );

echo "\n";
if ( 0 === $fail ) {
	echo "=== WSZYSTKIE OK (asercji: {$ran}) ===\n";
	exit( 0 );
}
echo "=== BLEDOW: {$fail} (asercji: {$ran}) ===\n";
exit( 1 );
