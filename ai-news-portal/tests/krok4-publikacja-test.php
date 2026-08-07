<?php
/**
 * Krok 4, etap 4.5 — publikacja artykulu (`Publisher` + `Runner::process_item`).
 *
 * Tresc kosztowala jedno z 20 wywolan na dobe, wiec kazda asercja tutaj pilnuje
 * jednej z dwoch rzeczy: zeby nic kupionego nie przepadlo i zeby na portal nie
 * trafil wpis polowiczny.
 *
 * PIEC PUTAPEK, KTORE TEN ZESTAW ZAMYKA:
 *
 *   1. `wp_insert_post()` BEZ drugiego argumentu zwraca `0`, nie `WP_Error` —
 *      porazka wyglada wtedy jak sukces (test 9).
 *   2. Sprawdzenie duplikatu pytajace o status domyslny widzi same `publish`,
 *      wiec szkic po nieudanym przypisaniu kategorii dostalby DRUGI wpis
 *      (test 10).
 *   3. `wp_set_object_terms()` tez zwraca `WP_Error`. Wpisu wtedy NIE kasujemy
 *      — przestawiamy na szkic, bo tresc juz kosztowala (test 19).
 *   4. Artykul demo kasujemy tylko nieedytowany (test 17).
 *   5. Przy `failed` tresc pozycji ZOSTAJE w tabeli — jest jedynym materialem
 *      do ponowienia. Zerowanie nalezy do `done` i `skipped`.
 *
 * Prawdziwe klasy: `Publisher`, `Runner`, `Gemini`, `Validator`, `Settings`.
 * Atrapy: WordPress (wpisy, meta, terminy) w pamieci, `$wpdb`, transport HTTP.
 *
 * URUCHOMIENIE:  php tests/krok4-publikacja-test.php
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
	function k4x_check( $cond, $label ) {
		global $fail, $ran;
		$ran++;
		echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n";
		if ( ! $cond ) {
			$fail++;
		}
	}

	/** Atrapa `WP_Error`. */
	class WP_Error {

		public $code;
		private $msg;

		public function __construct( $code = '', $msg = '' ) {
			$this->code = $code;
			$this->msg  = $msg;
		}

		public function get_error_message() {
			return $this->msg;
		}
	}

	$GLOBALS['__opt']      = array();
	$GLOBALS['__posts']    = array();
	$GLOBALS['__meta']     = array();
	$GLOBALS['__terms']    = array();
	$GLOBALS['__objterms'] = array();
	$GLOBALS['__next_id']  = 100;
	$GLOBALS['__plan']     = array();
	$GLOBALS['__zadania']  = array();

	/** Wymuszone porazki: 'insert', 'terms', 'term_insert'. */
	$GLOBALS['__psuj'] = array();

	function is_wp_error( $t ) {
		return ( $t instanceof WP_Error );
	}

	function current_time( $type, $gmt = 0 ) {
		return ( 'Y-m-d' === $type ) ? '2026-08-07' : '2026-08-07 12:00:00';
	}

	function is_serialized( $d ) {
		return is_string( $d ) && (bool) preg_match( '/^[aOs]:\d+:/', $d );
	}

	function maybe_serialize( $d ) {
		return ( is_array( $d ) || is_object( $d ) ) ? serialize( $d ) : $d;
	}

	function maybe_unserialize( $d ) {
		return is_serialized( $d ) ? unserialize( $d ) : $d;
	}

	function get_option( $k, $default = false ) {
		return array_key_exists( $k, $GLOBALS['__opt'] ) ? maybe_unserialize( $GLOBALS['__opt'][ $k ] ) : $default;
	}

	function add_option( $k, $v = '', $d = '', $autoload = true ) {
		if ( array_key_exists( $k, $GLOBALS['__opt'] ) ) {
			return false;
		}
		$GLOBALS['__opt'][ $k ] = maybe_serialize( $v );
		return true;
	}

	function update_option( $k, $v, $autoload = null ) {
		$GLOBALS['__opt'][ $k ] = maybe_serialize( $v );
		return true;
	}

	function wp_cache_delete( $k, $g = '' ) {
		return true;
	}

	function wp_json_encode( $d, $o = 0 ) {
		return json_encode( $d, $o | JSON_UNESCAPED_UNICODE );
	}

	function wp_remote_retrieve_response_code( $r ) {
		return is_array( $r ) ? ( $r['response']['code'] ?? 0 ) : 0;
	}

	function wp_remote_retrieve_body( $r ) {
		return is_array( $r ) ? (string) ( $r['body'] ?? '' ) : '';
	}

	function wp_remote_post( $url, $args = array() ) {
		$GLOBALS['__zadania'][] = $args['body'] ?? '';
		if ( array() === $GLOBALS['__plan'] ) {
			return array( 'response' => array( 'code' => 200 ), 'body' => '{}' );
		}
		return array_shift( $GLOBALS['__plan'] );
	}

	function remove_accents( $t ) {
		return strtr(
			(string) $t,
			array(
				'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n',
				'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
				'Ą' => 'A', 'Ć' => 'C', 'Ę' => 'E', 'Ł' => 'L', 'Ń' => 'N',
				'Ó' => 'O', 'Ś' => 'S', 'Ź' => 'Z', 'Ż' => 'Z',
			)
		);
	}

	function wp_strip_all_tags( $t, $b = false ) {
		$t = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $t );
		return trim( strip_tags( (string) $t ) );
	}

	function wp_kses_post( $t ) {
		$t = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $t );
		return strip_tags( (string) $t, '<p><h2><h3><ul><ol><li><strong><em><a><br>' );
	}

	function wp_parse_url( $u, $c = -1 ) {
		return parse_url( $u, $c );
	}

	function get_current_user_id() {
		return 0;
	}

	function get_users( $args = array() ) {
		return array( 7 );
	}

	// --- Atrapa wpisow -----------------------------------------------------

	/**
	 * Atrapa `wp_insert_post()`.
	 *
	 * @param array<string,mixed> $dane     Dane wpisu.
	 * @param bool                $wp_error Czy zwracac `WP_Error`.
	 *
	 * @return int|WP_Error
	 */
	function wp_insert_post( $dane, $wp_error = false ) {
		if ( in_array( 'insert', $GLOBALS['__psuj'], true ) ) {
			// Tak jak w WordPressie: BEZ drugiego argumentu porazka to `0`.
			return $wp_error ? new WP_Error( 'db_insert_error', 'Nie udało się zapisać wpisu' ) : 0;
		}

		$id                      = $GLOBALS['__next_id']++;
		$data                    = $dane['post_date'] ?? '2026-08-07 12:00:00';
		$GLOBALS['__posts'][ $id ] = (object) array(
			'ID'            => $id,
			'post_type'     => $dane['post_type'] ?? 'post',
			'post_status'   => $dane['post_status'] ?? 'draft',
			'post_title'    => $dane['post_title'] ?? '',
			'post_excerpt'  => $dane['post_excerpt'] ?? '',
			'post_content'  => $dane['post_content'] ?? '',
			'post_author'   => $dane['post_author'] ?? 0,
			'post_date'     => $data,
			'post_modified' => $dane['post_modified'] ?? $data,
		);

		foreach ( (array) ( $dane['meta_input'] ?? array() ) as $k => $v ) {
			$GLOBALS['__meta'][ $id ][ $k ] = $v;
		}

		return $id;
	}

	function wp_update_post( $dane, $wp_error = false ) {
		$id = (int) ( $dane['ID'] ?? 0 );
		if ( ! isset( $GLOBALS['__posts'][ $id ] ) ) {
			return $wp_error ? new WP_Error( 'invalid_post', 'Brak wpisu' ) : 0;
		}
		foreach ( $dane as $k => $v ) {
			if ( 'ID' !== $k ) {
				$GLOBALS['__posts'][ $id ]->$k = $v;
			}
		}
		return $id;
	}

	function wp_delete_post( $id, $force = false ) {
		$id = (int) $id;
		if ( ! isset( $GLOBALS['__posts'][ $id ] ) ) {
			return false;
		}
		if ( ! $force ) {
			// Bez `true` wpis laduje w KOSZU i nadal odpowiada na `any`.
			$GLOBALS['__posts'][ $id ]->post_status = 'trash';
			return $GLOBALS['__posts'][ $id ];
		}
		$wpis = $GLOBALS['__posts'][ $id ];
		unset( $GLOBALS['__posts'][ $id ], $GLOBALS['__meta'][ $id ], $GLOBALS['__objterms'][ $id ] );
		return $wpis;
	}

	function get_post( $id ) {
		return $GLOBALS['__posts'][ (int) $id ] ?? null;
	}

	function get_post_status( $id ) {
		return isset( $GLOBALS['__posts'][ (int) $id ] ) ? $GLOBALS['__posts'][ (int) $id ]->post_status : false;
	}

	/**
	 * Atrapa `get_posts()` — obsluguje `post_status`, `meta_key`, `meta_value`.
	 *
	 * Statusu `trash` NIE oddaje nawet przy `any`, dokladnie jak WordPress:
	 * na tym stoi asercja o kasowaniu demo z drugim argumentem `true`.
	 *
	 * @param array<string,mixed> $args Argumenty.
	 *
	 * @return array<int,int>
	 */
	function get_posts( $args = array() ) {
		$typ    = $args['post_type'] ?? 'post';
		$status = $args['post_status'] ?? 'publish';
		$mk     = $args['meta_key'] ?? '';
		$mv     = $args['meta_value'] ?? null;
		$limit  = (int) ( $args['posts_per_page'] ?? 5 );

		$out = array();
		foreach ( $GLOBALS['__posts'] as $id => $wpis ) {
			if ( $wpis->post_type !== $typ ) {
				continue;
			}
			if ( 'any' === $status ) {
				if ( in_array( $wpis->post_status, array( 'trash', 'auto-draft' ), true ) ) {
					continue;
				}
			} elseif ( $wpis->post_status !== $status ) {
				continue;
			}
			if ( '' !== $mk ) {
				if ( ! isset( $GLOBALS['__meta'][ $id ][ $mk ] ) ) {
					continue;
				}
				if ( null !== $mv && (string) $GLOBALS['__meta'][ $id ][ $mk ] !== (string) $mv ) {
					continue;
				}
			}
			$out[] = (int) $id;
			if ( count( $out ) >= $limit ) {
				break;
			}
		}

		return $out;
	}

	function term_exists( $nazwa, $tax = '' ) {
		foreach ( $GLOBALS['__terms'] as $id => $t ) {
			if ( $t['name'] === $nazwa && $t['tax'] === $tax ) {
				return array( 'term_id' => $id );
			}
		}
		return null;
	}

	function wp_insert_term( $nazwa, $tax, $args = array() ) {
		if ( in_array( 'term_insert', $GLOBALS['__psuj'], true ) ) {
			return new WP_Error( 'term_error', 'Nie udało się utworzyć terminu' );
		}
		$id                        = $GLOBALS['__next_id']++;
		$GLOBALS['__terms'][ $id ] = array(
			'name' => $nazwa,
			'tax'  => $tax,
		);
		return array( 'term_id' => $id );
	}

	function wp_set_object_terms( $post_id, $terms, $tax, $append = false ) {
		if ( in_array( 'terms', $GLOBALS['__psuj'], true ) ) {
			return new WP_Error( 'invalid_taxonomy', 'Nieprawidłowa taksonomia' );
		}
		$GLOBALS['__objterms'][ (int) $post_id ][ $tax ] = (array) $terms;
		return (array) $terms;
	}

	/**
	 * Atrapa tabeli pozycji — trzyma wiersze i stosuje UPDATE-y.
	 */
	class AINP_Fake_WPDB_K4X {

		public $options    = 'wp_options';
		public $prefix     = 'wp_';
		public $last_error = '';
		public $wiersze    = array();
		public $selects    = array();
		public $updates    = array();

		public function prepare( $sql, ...$a ) {
			if ( 1 === count( $a ) && is_array( $a[0] ) ) {
				$a = $a[0];
			}
			$sql = str_replace( '%s', "'%s'", $sql );
			foreach ( $a as $arg ) {
				$z   = is_int( $arg ) ? (string) $arg : addslashes( (string) $arg );
				$sql = preg_replace( '/%[sd]/', str_replace( '$', '\\$', $z ), $sql, 1 );
			}
			return $sql;
		}

		private function literaly( $sql ) {
			preg_match_all( "/'((?:[^'\\\\]|\\\\.)*)'/", $sql, $m );
			return array_map( 'stripslashes', $m[1] );
		}

		public function get_var( $sql ) {
			// Licznik wywolan AI mieszka w opcjach.
			$lit = $this->literaly( $sql );
			$key = $lit[0] ?? '';
			return array_key_exists( $key, $GLOBALS['__opt'] ) ? (string) $GLOBALS['__opt'][ $key ] : null;
		}

		public function get_results( $sql ) {
			$this->selects[] = $sql;

			$limit = 1;
			if ( preg_match( '/LIMIT (\d+)/', $sql, $m ) ) {
				$limit = (int) $m[1];
			}

			$out = array();
			foreach ( $this->wiersze as $w ) {
				if ( 'new' !== $w->status || '' === (string) $w->content_hash || '' === (string) $w->content ) {
					continue;
				}
				$out[] = $w;
				if ( count( $out ) >= $limit ) {
					break;
				}
			}
			return $out;
		}

		public function query( $sql ) {
			$this->updates[] = $sql;

			// Licznik AI: `UPDATE wp_options SET option_value = ... WHERE option_name = ... AND option_value = ...`
			if ( false !== strpos( $sql, 'wp_options' ) ) {
				$lit = $this->literaly( $sql );
				if ( count( $lit ) < 3 ) {
					return 0;
				}
				list( $nowa, $klucz, $stara ) = $lit;
				$zastana                      = array_key_exists( $klucz, $GLOBALS['__opt'] ) ? (string) $GLOBALS['__opt'][ $klucz ] : null;
				if ( (string) $zastana !== (string) $stara ) {
					return 0;
				}
				$GLOBALS['__opt'][ $klucz ] = $nowa;
				return 1;
			}

			if ( ! preg_match( '/SET (.*) WHERE id = (\d+)/s', $sql, $m ) ) {
				return 0;
			}

			$id = (int) $m[2];
			if ( ! isset( $this->wiersze[ $id ] ) ) {
				return 0;
			}

			preg_match_all( "/(\w+) = '((?:[^'\\\\]|\\\\.)*)'/", $m[1], $pary, PREG_SET_ORDER );
			foreach ( $pary as $para ) {
				$this->wiersze[ $id ]->{$para[1]} = stripslashes( $para[2] );
			}

			return 1;
		}
	}

	$GLOBALS['wpdb'] = new AINP_Fake_WPDB_K4X();
}

namespace AINP {

	/** Atrapa warstwy sieciowej. */
	final class Http {

		public const ENCODING = 'identity';

		public static function user_agent(): string {
			return 'AI News Portal/0.3.0';
		}
	}
}

namespace {

	require_once $root . '/src/Settings.php';
	require_once $root . '/src/Dedup.php';
	require_once $root . '/src/Filter.php';
	require_once $root . '/src/Gemini.php';
	require_once $root . '/src/Validator.php';
	require_once $root . '/src/Plugin.php';
	require_once $root . '/src/Publisher.php';
	require_once $root . '/src/Runner.php';

	use AINP\Plugin;
	use AINP\Publisher;
	use AINP\Runner;
	use AINP\Settings;

	/**
	 * Stan poczatkowy.
	 *
	 * @param array<string,mixed> $ustawienia Nadpisania ustawien.
	 *
	 * @return AINP_Fake_WPDB_K4X
	 */
	function k4x_reset( array $ustawienia = array() ) {
		$GLOBALS['__opt']      = array(
			Settings::OPTION     => serialize(
				array_merge(
					array(
						'daily_cap'  => 20,
						'categories' => array( 'Żywienie', 'Zdrowie', 'Rasy' ),
					),
					$ustawienia
				)
			),
			Settings::OPTION_KEY => 'AIzaTESTOWY',
		);
		$GLOBALS['__posts']    = array();
		$GLOBALS['__meta']     = array();
		$GLOBALS['__terms']    = array();
		$GLOBALS['__objterms'] = array();
		$GLOBALS['__next_id']  = 100;
		$GLOBALS['__psuj']     = array();
		$GLOBALS['__plan']     = array();
		$GLOBALS['__zadania']  = array();
		$GLOBALS['wpdb']       = new AINP_Fake_WPDB_K4X();

		return $GLOBALS['wpdb'];
	}

	/**
	 * Pola po walidacji.
	 *
	 * @param array<string,string> $zmiany Nadpisania.
	 *
	 * @return array<string,string>
	 */
	function k4x_dane( array $zmiany = array() ) {
		return array_merge(
			array(
				'title'   => 'Jak karmić psa latem',
				'lead'    => 'Krótki poradnik o żywieniu psa w upały.',
				'content' => '<p>' . str_repeat( 'Pies potrzebuje wody i cienia. ', 30 ) . '</p>',
				'topic'   => 'Żywienie',
			),
			$zmiany
		);
	}

	/**
	 * Wiersz pozycji.
	 *
	 * @param int    $id    Identyfikator.
	 * @param string $url   Adres zrodla.
	 * @param string $title Tytul.
	 *
	 * @return object
	 */
	function k4x_wiersz( $id = 5, $url = 'https://psy.pl/karma/', $title = 'Karma dla psa latem' ) {
		return (object) array(
			'id'           => $id,
			'url'          => $url,
			'title'        => $title,
			'excerpt'      => 'Zajawka o psie',
			'content'      => 'Materiał źródłowy o karmieniu psa.',
			'content_hash' => str_repeat( 'a', 64 ),
			'status'       => 'new',
			'note'         => '',
		);
	}

	/**
	 * Wstawia artykul demo.
	 *
	 * @param bool $edytowany Czy ktos go zmienil.
	 *
	 * @return int
	 */
	function k4x_demo( $edytowany = false ) {
		$id                        = $GLOBALS['__next_id']++;
		$GLOBALS['__posts'][ $id ] = (object) array(
			'ID'            => $id,
			'post_type'     => Plugin::CPT,
			'post_status'   => 'publish',
			'post_title'    => 'Jak czytać skład karmy dla psa',
			'post_excerpt'  => '',
			'post_content'  => 'Demo',
			'post_author'   => 1,
			'post_date'     => '2026-08-01 10:00:00',
			'post_modified' => $edytowany ? '2026-08-05 09:00:00' : '2026-08-01 10:00:00',
		);
		$GLOBALS['__meta'][ $id ][ Plugin::META_DEMO ] = 1;

		return $id;
	}

	/**
	 * Ile wpisow CPT ma dany status.
	 *
	 * @param string $status Status.
	 *
	 * @return int
	 */
	function k4x_ile( $status ) {
		$n = 0;
		foreach ( $GLOBALS['__posts'] as $w ) {
			if ( Plugin::CPT === $w->post_type && $status === $w->post_status ) {
				$n++;
			}
		}
		return $n;
	}

	echo "=== KROK 4, ETAP 4.5: publikacja ===\n\n";

	// ------------------------------------------------------------------
	echo "-- Sciezka normalna: jedna pozycja, jeden artykul z kategoria --\n";

	k4x_reset();
	$row = k4x_wiersz();
	$r   = Publisher::publish( $row, k4x_dane() );

	k4x_check( true === $r['ok'], 'publikacja sie udaje' );
	k4x_check( true === $r['created'], 'wpis powstal w tym wywolaniu' );
	k4x_check( 1 === k4x_ile( 'publish' ), 'DOKLADNIE jeden opublikowany artykul' );
	k4x_check( 0 === k4x_ile( 'draft' ), 'zero szkicow' );

	$wpis = get_post( $r['post_id'] );
	k4x_check( Plugin::CPT === $wpis->post_type, 'wpis jest we wlasciwym typie tresci' );
	k4x_check( 'Jak karmić psa latem' === $wpis->post_title, 'tytul z odpowiedzi modelu' );
	k4x_check( 'Krótki poradnik o żywieniu psa w upały.' === $wpis->post_excerpt, 'zajawka trafia do post_excerpt' );
	k4x_check( 7 === (int) $wpis->post_author, 'autorem jest administrator, nie uzytkownik 0' );
	k4x_check( '5' === (string) $GLOBALS['__meta'][ $r['post_id'] ][ Plugin::META_ITEM ], 'meta z identyfikatorem pozycji zapisana' );
	k4x_check( 'https://psy.pl/karma/' === $GLOBALS['__meta'][ $r['post_id'] ][ Plugin::META_SOURCE ], 'link do zrodla zapisany' );

	$terminy = $GLOBALS['__objterms'][ $r['post_id'] ][ Plugin::TAX ] ?? array();
	k4x_check( 1 === count( $terminy ), 'przypisana dokladnie jedna kategoria' );
	k4x_check( is_int( $terminy[0] ), 'kategoria przypisana przez IDENTYFIKATOR, nie nazwe' );
	k4x_check( 'Żywienie' === $GLOBALS['__terms'][ $terminy[0] ]['name'], 'i jest to wlasciwa kategoria' );

	// Drugi raz ta sama kategoria nie zaklada drugiego terminu.
	$przed = count( $GLOBALS['__terms'] );
	Publisher::publish( k4x_wiersz( 6, 'https://psy.pl/inny/' ), k4x_dane() );
	k4x_check( $przed === count( $GLOBALS['__terms'] ), 'istniejaca kategoria NIE jest zakladana drugi raz' );

	// ------------------------------------------------------------------
	echo "\n-- Przelacznik „zapisuj jako szkice” --\n";

	k4x_reset( array( 'save_as_draft' => true ) );
	$r = Publisher::publish( k4x_wiersz(), k4x_dane() );
	k4x_check( true === $r['ok'] && 1 === k4x_ile( 'draft' ), 'przy wlaczonym przelaczniku powstaje szkic' );
	k4x_check( 0 === k4x_ile( 'publish' ), 'i nic nie jest publikowane' );

	// ------------------------------------------------------------------
	echo "\n-- TEST 9: wp_insert_post() zwraca WP_Error --\n";

	$db                = k4x_reset();
	$db->wiersze[5]    = k4x_wiersz();
	$GLOBALS['__psuj'] = array( 'insert' );
	$r                 = Publisher::publish( $db->wiersze[5], k4x_dane() );

	k4x_check( false === $r['ok'], 'porazka rozpoznana' );
	k4x_check( 0 === count( $GLOBALS['__posts'] ), 'ZERO wpisow' );
	k4x_check( 'insert' === $r['reason'], 'powod wskazuje na zapis wpisu' );
	k4x_check( false !== strpos( $r['error'], 'Nie udało się zapisać wpisu' ), 'komunikat z WP_Error trafia do powodu' );

	// ------------------------------------------------------------------
	echo "\n-- TEST 19: wp_set_object_terms() zwraca WP_Error --\n";

	$db                = k4x_reset();
	$GLOBALS['__psuj'] = array( 'terms' );
	$r                 = Publisher::publish( k4x_wiersz(), k4x_dane() );

	k4x_check( false === $r['ok'], 'nieudane przypisanie kategorii to porazka' );
	k4x_check( 0 === k4x_ile( 'publish' ), 'ZERO wpisow w publish' );
	k4x_check( 1 === k4x_ile( 'draft' ), 'DOKLADNIE jeden wpis w draft' );
	k4x_check( 1 === count( $GLOBALS['__posts'] ), 'wpis NIE zostal skasowany — tresc kosztowala wywolanie' );
	k4x_check( 'terms' === $r['reason'], 'powod wskazuje na kategorie' );
	k4x_check( false !== strpos( $r['error'], 'Żywienie' ), 'powod cytuje kategorie, ktorej nie udalo sie przypisac' );

	// Ponowienie tej samej pozycji nie tworzy drugiego wpisu.
	$GLOBALS['__psuj'] = array();
	$r2                = Publisher::publish( k4x_wiersz(), k4x_dane() );
	k4x_check( 1 === count( $GLOBALS['__posts'] ), 'ponowienie po nieudanej kategorii NIE tworzy drugiego wpisu' );
	k4x_check( false === $r2['created'], 'i mowi wprost, ze wpis juz byl' );

	// Nieudane ZALOZENIE terminu tez konczy sie szkicem.
	k4x_reset();
	$GLOBALS['__psuj'] = array( 'term_insert' );
	$r                 = Publisher::publish( k4x_wiersz(), k4x_dane() );
	k4x_check( false === $r['ok'] && 1 === k4x_ile( 'draft' ), 'nieudane zalozenie kategorii tez daje szkic' );

	// ------------------------------------------------------------------
	echo "\n-- TEST 10: istniejacy wpis z tym samym _ainp_item_id --\n";

	foreach ( array( 'publish', 'draft' ) as $status ) {
		k4x_reset();
		$id                        = $GLOBALS['__next_id']++;
		$GLOBALS['__posts'][ $id ] = (object) array(
			'ID'            => $id,
			'post_type'     => Plugin::CPT,
			'post_status'   => $status,
			'post_title'    => 'Stary artykuł',
			'post_excerpt'  => '',
			'post_content'  => '',
			'post_author'   => 1,
			'post_date'     => '2026-08-01 10:00:00',
			'post_modified' => '2026-08-01 10:00:00',
		);
		$GLOBALS['__meta'][ $id ][ Plugin::META_ITEM ] = 5;

		$r = Publisher::publish( k4x_wiersz(), k4x_dane() );

		k4x_check( 1 === count( $GLOBALS['__posts'] ), 'status `' . $status . '`: ZERO nowych wpisow' );
		k4x_check( $id === $r['post_id'], 'status `' . $status . '`: oddany jest wpis istniejacy' );
		k4x_check( false === $r['created'], 'status `' . $status . '`: nic nie powstalo' );
		k4x_check( true === $r['ok'], 'status `' . $status . '`: to nie jest blad, tylko idempotencja' );
	}

	// ------------------------------------------------------------------
	echo "\n-- TEST 17: artykul demo znika po pierwszym prawdziwym --\n";

	k4x_reset();
	$demo = k4x_demo();
	k4x_check( 1 === count( $GLOBALS['__posts'] ), 'na starcie jest samo demo' );

	$r = Publisher::publish( k4x_wiersz(), k4x_dane() );
	k4x_check( true === $r['ok'], 'prawdziwy artykul opublikowany' );
	k4x_check( 1 === $r['demo_removed'], 'demo skasowane' );
	k4x_check( ! isset( $GLOBALS['__posts'][ $demo ] ), 'ZERO artykulow demo' );
	k4x_check( isset( $GLOBALS['__posts'][ $r['post_id'] ] ), 'prawdziwy artykul NIETKNIETY' );
	k4x_check( ! isset( $GLOBALS['__meta'][ $demo ] ), 'meta demo tez znika — kasowanie jest twarde, nie do kosza' );

	// Demo EDYTOWANE zostaje.
	k4x_reset();
	$demo = k4x_demo( true );
	$r    = Publisher::publish( k4x_wiersz(), k4x_dane() );
	k4x_check( 0 === $r['demo_removed'], 'edytowane demo NIE jest kasowane' );
	k4x_check( isset( $GLOBALS['__posts'][ $demo ] ), 'i nadal jest w bazie (1 demo, nieusuniete)' );

	// Drugi prawdziwy artykul nie ma juz czego kasowac.
	k4x_reset();
	k4x_demo();
	Publisher::publish( k4x_wiersz( 5 ), k4x_dane() );
	$r = Publisher::publish( k4x_wiersz( 6, 'https://psy.pl/dwa/' ), k4x_dane() );
	k4x_check( 0 === $r['demo_removed'], 'drugi artykul nie kasuje niczego' );
	k4x_check( 2 === k4x_ile( 'publish' ), 'i oba prawdziwe artykuly zostaja' );

	// ------------------------------------------------------------------
	echo "\n-- Link do zrodla jest obowiazkowy --\n";

	k4x_reset();
	$r = Publisher::publish( k4x_wiersz( 5, '' ), k4x_dane() );
	k4x_check( false === $r['ok'] && 'no_source' === $r['reason'], 'pozycja bez adresu zrodla nie jest publikowana' );
	k4x_check( 0 === count( $GLOBALS['__posts'] ), 'i nie powstaje zaden wpis' );

	// ------------------------------------------------------------------
	echo "\n-- Runner::process_item(): status pozycji w tabeli --\n";

	/**
	 * Koperta Gemini.
	 *
	 * @param string $tekst Tresc.
	 *
	 * @return array<string,mixed>
	 */
	function k4x_odp( $tekst ) {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => json_encode(
				array(
					'candidates' => array(
						array( 'content' => array( 'parts' => array( array( 'text' => $tekst ) ) ) ),
					),
				)
			),
		);
	}

	/**
	 * Poprawna odpowiedz modelu.
	 *
	 * @return string
	 */
	function k4x_json() {
		return json_encode( k4x_dane() );
	}

	$db             = k4x_reset();
	$db->wiersze[5] = k4x_wiersz();
	$GLOBALS['__plan'] = array( k4x_odp( k4x_json() ) );

	$los = Runner::process_item( $db->wiersze[5], null );

	k4x_check( 'published' === $los['outcome'], 'pozycja konczy jako opublikowana' );
	k4x_check( 1 === $los['calls'], 'jedno wywolanie modelu' );
	k4x_check( 'done' === $db->wiersze[5]->status, 'status pozycji w tabeli to `done`' );
	k4x_check( '' === $db->wiersze[5]->content, 'tresc pozycji `done` jest ZEROWANA — zyje we wpisie' );
	k4x_check( 'Karma dla psa latem' === $db->wiersze[5]->title, 'tytul i zajawka ZOSTAJA — na nich stoi bramka wymaganych' );
	k4x_check( 1 === k4x_ile( 'publish' ), 'powstal dokladnie jeden artykul' );

	// Porazka terminalna: `failed` i tresc ZOSTAJE.
	$db                = k4x_reset();
	$db->wiersze[5]    = k4x_wiersz();
	$GLOBALS['__plan'] = array( k4x_odp( '{"urwany' ), k4x_odp( '{"znowu urwany' ) );

	$los = Runner::process_item( $db->wiersze[5], null );

	k4x_check( 'failed' === $los['outcome'], 'dwa razy urwany JSON konczy pozycje na failed' );
	k4x_check( 'failed' === $db->wiersze[5]->status, 'status w tabeli to `failed`' );
	k4x_check( '' !== $db->wiersze[5]->note, 'powod zapisany w kolumnie note' );
	k4x_check( 'Materiał źródłowy o karmieniu psa.' === $db->wiersze[5]->content, 'tresc pozycji `failed` ZOSTAJE — jest materialem do ponowienia' );
	k4x_check( 0 === count( $GLOBALS['__posts'] ), 'i nie powstaje zaden wpis' );

	// Wyczerpany sufit: pozycja CZEKA, status bez zmian.
	$db             = k4x_reset();
	$db->wiersze[5] = k4x_wiersz();
	$GLOBALS['__opt'][ Settings::OPTION_USAGE ] = serialize( array( 'date' => '2026-08-07', 'count' => 20 ) );

	$los = Runner::process_item( $db->wiersze[5], null );

	k4x_check( 'waiting' === $los['outcome'], 'przy wyczerpanym suficie pozycja czeka' );
	k4x_check( 'new' === $db->wiersze[5]->status, 'i zachowuje status `new`' );
	k4x_check( 0 === count( $GLOBALS['__zadania'] ), 'zero zadan do modelu' );

	// ------------------------------------------------------------------
	echo "\n-- Runner::publish_batch(): partia --\n";

	$db = k4x_reset();
	$db->wiersze[1] = k4x_wiersz( 1, 'https://psy.pl/1/', 'Najlepsza pizza w mieście' );
	$db->wiersze[1]->excerpt = 'Przepis na ciasto';
	$db->wiersze[2] = k4x_wiersz( 2, 'https://psy.pl/2/', 'Szczeniak w domu' );
	$db->wiersze[3] = k4x_wiersz( 3, 'https://psy.pl/3/', 'Jamnik na spacerze' );

	$GLOBALS['__plan'] = array( k4x_odp( k4x_json() ), k4x_odp( k4x_json() ) );

	$wynik = Runner::publish_batch( 5, 10.0 );

	k4x_check( 1 === $wynik['offtopic'], 'pozycja poza tematem odsiana po drodze' );
	k4x_check( 'skipped' === $db->wiersze[1]->status, 'i zapisana jako skipped' );
	k4x_check( 2 === $wynik['published'], 'opublikowane obie pozycje o psach' );
	k4x_check( 2 === $wynik['calls'], 'dwa wywolania modelu, po jednym na pozycje' );
	k4x_check( 2 === k4x_ile( 'publish' ), 'w bazie dwa artykuly' );
	k4x_check( false === $wynik['budget_hit'], 'budzet czasu nie zostal wyczerpany' );

	// Wyczerpany sufit przerywa partie zamiast krecic sie w kolko.
	$db             = k4x_reset();
	$db->wiersze[1] = k4x_wiersz( 1, 'https://psy.pl/1/', 'Szczeniak w domu' );
	$db->wiersze[2] = k4x_wiersz( 2, 'https://psy.pl/2/', 'Jamnik na spacerze' );
	$GLOBALS['__opt'][ Settings::OPTION_USAGE ] = serialize( array( 'date' => '2026-08-07', 'count' => 20 ) );

	$wynik = Runner::publish_batch( 5, 10.0 );

	k4x_check( 1 === $wynik['waiting'], 'partia konczy sie na pierwszej pozycji, ktora czeka' );
	k4x_check( 0 === $wynik['published'], 'nic nie zostalo opublikowane' );
	k4x_check( 'new' === $db->wiersze[1]->status && 'new' === $db->wiersze[2]->status, 'obie pozycje zachowuja status `new`' );
	k4x_check( '' !== $wynik['note'], 'podsumowanie niesie powod wstrzymania' );

	// Pusta kolejka.
	$db    = k4x_reset();
	$wynik = Runner::publish_batch( 5, 10.0 );
	k4x_check( 0 === $wynik['taken'], 'pusta tabela: zero wzietych pozycji' );
	k4x_check( 0 === $wynik['calls'], 'i zero wywolan modelu' );

	// ------------------------------------------------------------------
	echo "\n";
	echo '=== Asercji: ' . $ran . ' | bledow: ' . $fail . " ===\n";
	if ( 0 === $fail ) {
		echo "WSZYSTKIE OK\n";
		exit( 0 );
	}
	echo "BŁĘDY\n";
	exit( 1 );
}
