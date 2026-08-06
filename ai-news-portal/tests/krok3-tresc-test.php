<?php
/**
 * Krok 3, etap 3.6 — przygotowanie tresci: odcisk tresci i konflikt OBU kluczy.
 *
 * Ten zestaw pracuje na atrapie bazy, ktora NAPRAWDE pilnuje obu kluczy
 * `UNIQUE` (`url_hash`, `content_hash`) i naprawde zachowuje sie jak
 * `UPDATE IGNORE`: przy kolizji nie zmienia wiersza i NIE zglasza bledu.
 * Bez tego test sprawdzalby wlasne wyobrazenie o bazie, a nie kod.
 *
 * Cztery rzeczy, ktore musza tu wyjsc:
 *
 *   1. Ten sam tekst pod dwoma adresami daje JEDNA pozycje. Drugi wiersz
 *      konczy jako `skipped`, nie jako blad bazy.
 *   2. `canonical` potrafi odslonic duplikat, ktorego nie bylo widac po
 *      adresie z kanalu — i wtedy tez konczy sie na `skipped`.
 *   3. Pozycja z wykluczonym slowem konczy sie ZANIM padnie zadanie HTTP.
 *   4. Blad przejsciowy wraca do kolejki, trwaly konczy od razu.
 *
 * URUCHOMIENIE:  php tests/krok3-tresc-test.php
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
	function k3t_check( $cond, $label ) {
		global $fail, $ran;
		$ran++;
		echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n";
		if ( ! $cond ) {
			$fail++;
		}
	}

	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}

	function current_time( $type, $gmt = 0 ) {
		return '2026-08-06 12:00:00';
	}

	function wp_encode_emoji( $tekst ) {
		return $tekst;
	}

	function wp_kses_post( $tresc ) {
		$tresc = preg_replace( '#<(script|style|iframe|form)\b[^>]*>.*?</\1>#is', '', (string) $tresc );
		return (string) preg_replace( '#</?(script|style|iframe|form)\b[^>]*>#i', '', (string) $tresc );
	}

	function wp_convert_hr_to_bytes( $wartosc ) {
		$wartosc = strtolower( trim( (string) $wartosc ) );
		$bajty   = (int) $wartosc;

		if ( false !== strpos( $wartosc, 'g' ) ) {
			return $bajty * 1024 * 1024 * 1024;
		}
		if ( false !== strpos( $wartosc, 'm' ) ) {
			return $bajty * 1024 * 1024;
		}

		return $bajty;
	}

	function remove_accents( $tekst ) {
		return strtr(
			(string) $tekst,
			array(
				'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n',
				'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
				'Ą' => 'A', 'Ć' => 'C', 'Ę' => 'E', 'Ł' => 'L', 'Ń' => 'N',
				'Ó' => 'O', 'Ś' => 'S', 'Ź' => 'Z', 'Ż' => 'Z',
			)
		);
	}

	$GLOBALS['__opt']      = array();
	$GLOBALS['__odczyty']  = array();

	function get_option( $key, $default = false ) {
		$GLOBALS['__odczyty'][] = $key;

		return array_key_exists( $key, $GLOBALS['__opt'] ) ? $GLOBALS['__opt'][ $key ] : $default;
	}

	/**
	 * Atrapa `$wpdb` z DZIALAJACYMI kluczami UNIQUE.
	 *
	 * `UPDATE IGNORE` przy kolizji nie zmienia wiersza i zwraca 0; zwykly
	 * `UPDATE` zwraca `false` i zapisuje `last_error`. Dokladnie tak zachowuje
	 * sie MySQL i dokladnie na tym stoi caly odsiew duplikatow.
	 */
	class AINP_Fake_DB {

		public $prefix     = 'wp_';
		public $last_error = '';
		public $queries    = array();

		/** @var array<int,object> Wiersze tabeli, klucz = id. */
		public $rows = array();

		/** Kolumny z kluczem UNIQUE. */
		public $unikalne = array( 'url_hash', 'content_hash' );

		public function prepare( $sql, ...$args ) {
			$i = 0;

			return preg_replace_callback(
				'/%[sd]/',
				function ( $m ) use ( &$i, $args ) {
					$v = array_key_exists( $i, $args ) ? $args[ $i ] : '';
					$i++;
					if ( '%d' === $m[0] ) {
						return (string) (int) $v;
					}
					return "'" . str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), (string) $v ) . "'";
				},
				$sql
			);
		}

		public function get_results( $sql ) {
			$this->queries[] = $sql;

			$limit = 999;
			if ( preg_match( '/LIMIT\s+(\d+)/i', $sql, $m ) ) {
				$limit = (int) $m[1];
			}

			$wynik = array();

			foreach ( $this->rows as $wiersz ) {
				if ( 'new' !== $wiersz->status ) {
					continue;
				}
				if ( '' !== (string) $wiersz->content_hash ) {
					continue;
				}
				if ( count( $wynik ) >= $limit ) {
					break;
				}

				$wynik[] = clone $wiersz;
			}

			return $wynik;
		}

		public function get_var( $sql ) {
			$this->queries[] = $sql;

			if ( ! preg_match( '/SELECT\s+(\w+)\s+FROM\s+\S+\s+WHERE\s+id\s*=\s*(\d+)/i', $sql, $m ) ) {
				return null;
			}

			$kolumna = $m[1];
			$id      = (int) $m[2];

			if ( ! isset( $this->rows[ $id ] ) ) {
				return null;
			}

			return $this->rows[ $id ]->$kolumna;
		}

		public function query( $sql ) {
			$this->queries[] = $sql;

			$ignoruj = ( 0 === stripos( ltrim( $sql ), 'UPDATE IGNORE' ) );

			if ( ! preg_match( '/SET\s+(.*?)\s+WHERE\s+id\s*=\s*(\d+)/is', $sql, $m ) ) {
				return 0;
			}

			$id = (int) $m[2];

			if ( ! isset( $this->rows[ $id ] ) ) {
				return 0;
			}

			/*
			 * Grupa ATOMOWA `(?>` zamiast zwyklej `(?:` — bez niej wyrazenie
			 * nawraca wykladniczo na dlugiej wartosci i przy tresci rzedu
			 * 20 KB przekracza `pcre.backtrack_limit`. `preg_match_all` oddaje
			 * wtedy `false`, atrapa nie zapisuje NICZEGO, a test pokazuje
			 * „duplikat tresci" tam, gdzie produkt dziala poprawnie.
			 * Zmierzone przy sufinie tresci z audytu (D-3).
			 */
			$pary = array();
			preg_match_all( "/(\w+)\s*=\s*('(?>[^'\\\\]|\\\\.)*'|\d+)/", $m[1], $znalezione, PREG_SET_ORDER );

			foreach ( $znalezione as $para ) {
				$wartosc = $para[2];

				if ( "'" === substr( $wartosc, 0, 1 ) ) {
					$wartosc = str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), substr( $wartosc, 1, -1 ) );
				}

				$pary[ $para[1] ] = $wartosc;
			}

			// Klucze UNIQUE — dokladnie tak, jak pyta o nie baza.
			foreach ( $this->unikalne as $kolumna ) {
				if ( ! isset( $pary[ $kolumna ] ) || '' === (string) $pary[ $kolumna ] ) {
					continue;
				}

				foreach ( $this->rows as $inny ) {
					if ( (int) $inny->id === $id ) {
						continue;
					}

					if ( (string) $inny->$kolumna === (string) $pary[ $kolumna ] ) {
						if ( $ignoruj ) {
							return 0;
						}

						$this->last_error = 'Duplicate entry for key ' . $kolumna;
						return false;
					}
				}
			}

			foreach ( $pary as $kolumna => $wartosc ) {
				$this->rows[ $id ]->$kolumna = $wartosc;
			}

			return 1;
		}
	}

	$GLOBALS['__zadania'] = array();
	$GLOBALS['__strony']  = array();
}

namespace AINP {

	/** Atrapa warstwy sieciowej — liczy KAZDE zadanie. */
	class Http {

		public static function get_article( string $url ): array {
			$GLOBALS['__zadania'][] = $url;

			// Plan `zwloka:N` udaje wolny serwer — bez tego budzetu czasu
			// nie da sie sprawdzic inaczej niz zegarkiem w reku.
			if ( isset( $GLOBALS['__strony'][ $url ] ) && is_string( $GLOBALS['__strony'][ $url ] )
				&& 0 === strpos( $GLOBALS['__strony'][ $url ], 'zwloka:' ) ) {
				usleep( (int) ( 1000000 * (float) substr( $GLOBALS['__strony'][ $url ], 7 ) ) );

				return array( 'ok' => false, 'code' => 0, 'body' => '', 'error' => 'Operation timed out', 'reason' => 'transport', 'truncated' => false );
			}

			if ( ! isset( $GLOBALS['__strony'][ $url ] ) ) {
				return array( 'ok' => false, 'code' => 404, 'body' => '', 'error' => 'Serwer odpowiedzial kodem 404', 'reason' => 'status', 'truncated' => false );
			}

			// Plan `throw` udaje awarie, ktorej nikt nie przewidzial —
			// wyjatek z wnetrza warstwy sieciowej.
			if ( 'throw' === $GLOBALS['__strony'][ $url ] ) {
				throw new \RuntimeException( 'siec padla w polowie' );
			}

			return $GLOBALS['__strony'][ $url ];
		}

		public static function is_retryable( array $result ): bool {
			if ( ! empty( $result['ok'] ) ) {
				return false;
			}

			$reason = isset( $result['reason'] ) ? (string) $result['reason'] : '';
			$code   = isset( $result['code'] ) ? (int) $result['code'] : 0;

			return ( 'transport' === $reason || 429 === $code || ( $code >= 500 && $code <= 599 ) );
		}

		public static function is_http_url( string $url ): bool {
			$parts = parse_url( $url );

			return is_array( $parts ) && ! empty( $parts['host'] )
				&& in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), array( 'http', 'https' ), true );
		}
	}

	/** Atrapa bootstrapu — potrzebna jest tylko nazwa tabeli. */
	class Plugin {

		public static function table(): string {
			return 'wp_ainp_items';
		}
	}
}

namespace {

	require_once $root . '/src/Dedup.php';
	require_once $root . '/src/Settings.php';
	require_once $root . '/src/Filter.php';
	require_once $root . '/src/Article.php';
	require_once $root . '/src/Runner.php';

	use AINP\Runner;
	use AINP\Dedup;

	/**
	 * Ustawia atrapy w stan poczatkowy.
	 *
	 * @return AINP_Fake_DB
	 */
	function k3t_reset() {
		$GLOBALS['__opt']     = array();
		$GLOBALS['__odczyty'] = array();
		$GLOBALS['__zadania'] = array();
		$GLOBALS['__strony']  = array();
		$GLOBALS['wpdb']      = new AINP_Fake_DB();

		return $GLOBALS['wpdb'];
	}

	/**
	 * Wstawia wiersz do atrapy tabeli.
	 *
	 * @param int    $id      Identyfikator.
	 * @param string $url     Adres.
	 * @param string $content Tresc z kanalu.
	 * @param string $title   Tytul. Domyslny MUSI zawierac slowo o psie: od
	 *                        wariantu C (2026-08-07) bramka slow wymaganych
	 *                        odsiewa pozycje, ktorych tytul i zajawka nie mowia
	 *                        o psach, a ten zestaw sprawdza tresc i odciski,
	 *                        nie filtr — material ma przez filtr przechodzic.
	 *
	 * @return object
	 */
	function k3t_wiersz( $id, $url, $content = '', $title = 'Tytuł artykułu o psach' ) {
		$wiersz = (object) array(
			'id'           => $id,
			'url'          => $url,
			'url_hash'     => Dedup::url_hash( $url ),
			'title'        => $title,
			'excerpt'      => 'Zajawka',
			'content'      => $content,
			'content_hash' => '',
			'status'       => 'new',
			'note'         => '',
			'attempts'     => 0,
			'post_id'      => 0,
			'updated_at'   => '2026-08-06 10:00:00',
		);

		$GLOBALS['wpdb']->rows[ $id ] = $wiersz;

		return $wiersz;
	}

	/**
	 * Strona HTML z trescia o zadanej dlugosci.
	 *
	 * @param string $tekst Zdanie powtarzane w akapicie.
	 * @param int    $ile   Ile powtorzen.
	 * @param string $extra Dodatkowy HTML w <head>.
	 *
	 * @return string
	 */
	function k3t_strona( $tekst, $ile = 30, $extra = '' ) {
		return '<html><head>' . $extra . '</head><body><nav><p>Menu serwisu</p></nav>'
			. '<article><p>' . str_repeat( $tekst . ' ', $ile ) . '</p></article>'
			. '<footer><p>Stopka</p></footer></body></html>';
	}

	/**
	 * Odpowiedz sieciowa z trescia.
	 *
	 * @param string $body Tresc.
	 *
	 * @return array
	 */
	function k3t_ok( $body ) {
		return array( 'ok' => true, 'code' => 200, 'body' => $body, 'error' => '', 'reason' => '', 'truncated' => false );
	}

	echo "=== KROK 3 / ETAP 3.6 — odcisk tresci i konflikt obu kluczy UNIQUE ===\n\n";

	// -----------------------------------------------------------------------
	echo "-- Pelna tresc z kanalu: bez sieci --\n";
	// -----------------------------------------------------------------------
	$wpdb   = k3t_reset();
	$dlugi  = str_repeat( 'Karma bytowa to podstawa diety dorosłego psa. ', 40 );
	$wiersz = k3t_wiersz( 1, 'https://psy.pl/karma/', '<p>' . $dlugi . '</p>' );

	$los = Runner::prepare_item( $wiersz, array( 'kot' ) );

	k3t_check( 'ready' === $los, 'pozycja z pelna trescia: `ready`' );
	k3t_check( 0 === count( $GLOBALS['__zadania'] ), 'ZERO zadan HTTP, gdy kanal podal pelna tresc' );
	k3t_check( 64 === strlen( (string) $wpdb->rows[1]->content_hash ), 'odcisk tresci zapisany (64 znaki)' );
	k3t_check( 'new' === $wpdb->rows[1]->status, 'status zostaje `new` — model dostanie ja w Kroku 4' );
	k3t_check( '' === (string) $wpdb->rows[1]->note, 'notatka wyczyszczona' );
	k3t_check( false !== strpos( (string) $wpdb->rows[1]->content, 'Karma bytowa' ), 'tresc zapisana' );

	// -----------------------------------------------------------------------
	echo "\n-- Slowo wykluczajace w wierszu zebranym przed filtrem --\n";
	// -----------------------------------------------------------------------
	$wpdb   = k3t_reset();
	$wiersz = k3t_wiersz( 1, 'https://psy.pl/kot/', '<p>Krótko o kocie.</p>', 'Kot rasy brytyjskiej' );

	$los = Runner::prepare_item( $wiersz, array( 'kot' ) );

	k3t_check( 'skipped' === $los, 'pozycja odsiana: `skipped`' );
	k3t_check( 0 === count( $GLOBALS['__zadania'] ), 'ZERO zadan HTTP przy odsianiu (test 4 z planu)' );
	k3t_check( 'skipped' === $wpdb->rows[1]->status, 'status w bazie: `skipped`' );
	k3t_check( false !== strpos( (string) $wpdb->rows[1]->note, 'Słowo wykluczające: kot' ), 'powod w `note`' );
	k3t_check( '' === (string) $wpdb->rows[1]->content, 'tresc wyzerowana' );
	k3t_check( '' === (string) $wpdb->rows[1]->content_hash, 'odsiana pozycja NIE zajmuje odcisku tresci' );

	// -----------------------------------------------------------------------
	echo "\n-- Zajawka z kanalu: scraping --\n";
	// -----------------------------------------------------------------------
	$wpdb   = k3t_reset();
	$wiersz = k3t_wiersz( 1, 'https://psy.pl/szkolenie/', '<p>Zajawka. Czytaj dalej…</p>' );

	$GLOBALS['__strony']['https://psy.pl/szkolenie/'] = k3t_ok( k3t_strona( 'Szkolenie szczeniaka zaczyna się od komendy siad.' ) );

	$los = Runner::prepare_item( $wiersz, array( 'kot' ) );

	k3t_check( 'ready' === $los, 'zajawka + scraping: `ready`' );
	k3t_check( 1 === count( $GLOBALS['__zadania'] ), 'dokladnie JEDNO zadanie HTTP' );
	k3t_check( false !== strpos( (string) $wpdb->rows[1]->content, 'Szkolenie szczeniaka' ), 'tresc ze strony zapisana' );
	k3t_check( false === strpos( (string) $wpdb->rows[1]->content, 'Menu serwisu' ), 'menu ze strony NIE weszlo do tresci' );
	k3t_check( false === strpos( (string) $wpdb->rows[1]->content, 'Stopka' ), 'stopka NIE weszla do tresci' );

	// -----------------------------------------------------------------------
	echo "\n-- Ten sam tekst pod dwoma adresami --\n";
	// -----------------------------------------------------------------------
	$wpdb  = k3t_reset();
	$tekst = '<p>' . str_repeat( 'Identyczny tekst przedrukowany na innej domenie. ', 30 ) . '</p>';

	$a = k3t_wiersz( 1, 'https://psy.pl/przedruk/', $tekst );
	$b = k3t_wiersz( 2, 'https://inny-serwis.pl/przedruk/', $tekst );

	$los_a = Runner::prepare_item( $a, array() );
	$los_b = Runner::prepare_item( $b, array() );

	k3t_check( 'ready' === $los_a, 'pierwsza kopia: `ready`' );
	k3t_check( 'skipped' === $los_b, 'druga kopia: `skipped`, nie blad bazy' );
	k3t_check( '' === $wpdb->last_error, 'baza NIE zglosila bledu — konflikt zdjal `UPDATE IGNORE`' );
	k3t_check( 64 === strlen( (string) $wpdb->rows[1]->content_hash ), 'pierwsza pozycja trzyma odcisk' );
	k3t_check( '' === (string) $wpdb->rows[2]->content_hash, 'druga pozycja odcisku NIE dostala' );
	k3t_check( false !== strpos( (string) $wpdb->rows[2]->note, 'Duplikat treści' ), 'powod: duplikat tresci' );
	k3t_check( '' === (string) $wpdb->rows[2]->content, 'tresc duplikatu wyzerowana' );

	$z_odciskiem = 0;
	foreach ( $wpdb->rows as $w ) {
		if ( '' !== (string) $w->content_hash ) {
			$z_odciskiem++;
		}
	}
	k3t_check( 1 === $z_odciskiem, 'w tabeli zostaje DOKLADNIE jedna pozycja z tym tekstem (jest ' . $z_odciskiem . ')' );

	// -----------------------------------------------------------------------
	echo "\n-- Ten sam tekst, ale rozny HTML wokol niego --\n";
	// -----------------------------------------------------------------------
	$wpdb = k3t_reset();
	$zdanie = 'Ten sam artykuł w dwóch szablonach serwisu. ';

	$a = k3t_wiersz( 1, 'https://psy.pl/a/', '' );
	$b = k3t_wiersz( 2, 'https://psy.pl/b/', '' );

	$GLOBALS['__strony']['https://psy.pl/a/'] = k3t_ok(
		'<html><body><article><p>' . str_repeat( $zdanie, 30 ) . '</p></article></body></html>'
	);
	$GLOBALS['__strony']['https://psy.pl/b/'] = k3t_ok(
		'<html><body><div class="post"><div class="entry"><p>' . str_repeat( $zdanie, 30 ) . '</p></div></div></body></html>'
	);

	Runner::prepare_item( $a, array() );
	$los_b = Runner::prepare_item( $b, array() );

	k3t_check( 'skipped' === $los_b, 'inny szablon, ten sam tekst — nadal duplikat' );

	// -----------------------------------------------------------------------
	echo "\n-- Adres kanoniczny --\n";
	// -----------------------------------------------------------------------
	$wpdb  = k3t_reset();
	$tresc = str_repeat( 'Treść artykułu o pielęgnacji sierści psa. ', 30 );

	$a = k3t_wiersz( 1, 'https://psy.pl/wlasciwy-adres/', '' );
	$GLOBALS['__strony']['https://psy.pl/wlasciwy-adres/'] = k3t_ok( k3t_strona( $tresc, 5, '<link rel="canonical" href="https://psy.pl/kanoniczny/">' ) );

	$los = Runner::prepare_item( $a, array() );

	k3t_check( 'ready' === $los, 'canonical bez kolizji: `ready`' );
	k3t_check( 'https://psy.pl/kanoniczny/' === (string) $wpdb->rows[1]->url, 'adres podmieniony na kanoniczny' );
	k3t_check(
		Dedup::url_hash( 'https://psy.pl/kanoniczny/' ) === (string) $wpdb->rows[1]->url_hash,
		'odcisk adresu przeliczony razem z adresem'
	);

	// Ten sam artykul pod dwoma adresami, oba wskazuja ten sam canonical.
	$wpdb = k3t_reset();

	$a = k3t_wiersz( 1, 'https://psy.pl/kanoniczny/', '' );
	$b = k3t_wiersz( 2, 'https://psy.pl/kopia/', '' );

	$GLOBALS['__strony']['https://psy.pl/kanoniczny/'] = k3t_ok( k3t_strona( 'Pierwszy tekst o żywieniu psa.', 30 ) );
	$GLOBALS['__strony']['https://psy.pl/kopia/']      = k3t_ok( k3t_strona( 'Zupełnie inny tekst o szkoleniu psa.', 30, '<link rel="canonical" href="https://psy.pl/kanoniczny/">' ) );

	Runner::prepare_item( $a, array() );
	$los_b = Runner::prepare_item( $b, array() );

	k3t_check( 'skipped' === $los_b, 'kolizja adresu kanonicznego: `skipped`' );
	k3t_check( false !== strpos( (string) $wpdb->rows[2]->note, 'adresem kanonicznym' ), 'powod mowi o adresie kanonicznym' );
	k3t_check( 'https://psy.pl/kopia/' === (string) $wpdb->rows[2]->url, 'adres NIE zostal podmieniony przy kolizji' );
	k3t_check(
		Dedup::url_hash( 'https://psy.pl/kanoniczny/' ) === (string) $wpdb->rows[1]->url_hash,
		'pierwsza pozycja zachowala swoj odcisk adresu'
	);

	// -----------------------------------------------------------------------
	echo "\n-- Strona renderowana JavaScriptem --\n";
	// -----------------------------------------------------------------------
	$wpdb = k3t_reset();
	$a    = k3t_wiersz( 1, 'https://spa.example/artykul/', '' );

	$GLOBALS['__strony']['https://spa.example/artykul/'] = k3t_ok(
		'<html><body><div id="root"></div><script>window.APP=1;</script></body></html>'
	);

	$los = Runner::prepare_item( $a, array() );

	k3t_check( 'skipped' === $los, 'szkielet bez tresci: `skipped` (test 5 z planu)' );

	/*
	 * NAPRAWA D-1 (2026-08-07) zmienila TU powod, i o to w niej chodzilo.
	 * Przedtem `extract()` oddawalo `ok = true` z lancuchem pelnym znacznikow
	 * i zerem znakow tekstu, wiec pozycje odrzucal dopiero prog w `Runner`
	 * z notatka „Za mało treści" — a to wskazuje palcem na kanal. Szkielet SPA
	 * nie ma za malo tresci; on jej nie ma wcale i wina lezy po stronie strony.
	 */
	k3t_check(
		false !== strpos( (string) $wpdb->rows[1]->note, 'Nie znaleziono treści na stronie' ),
		'powod nazywa prawdziwa przyczyne: strona bez tekstu, nie krotki tekst (D-1)'
	);
	k3t_check( '' === (string) $wpdb->rows[1]->content_hash, 'pozycja bez tresci nie zajmuje odcisku' );

	// -----------------------------------------------------------------------
	echo "\n-- Bledy sieci: przejsciowy kontra trwaly --\n";
	// -----------------------------------------------------------------------
	$wpdb = k3t_reset();
	$a    = k3t_wiersz( 1, 'https://psy.pl/404/', '' );

	$los = Runner::prepare_item( $a, array() );

	k3t_check( 'failed' === $los, '404 konczy pozycje od razu' );
	k3t_check( 'failed' === $wpdb->rows[1]->status, 'status: `failed`' );
	k3t_check( 0 === (int) $wpdb->rows[1]->attempts, 'blad trwaly nie podbija licznika prob' );

	$wpdb = k3t_reset();
	$a    = k3t_wiersz( 1, 'https://psy.pl/timeout/', '' );

	$GLOBALS['__strony']['https://psy.pl/timeout/'] = array( 'ok' => false, 'code' => 0, 'body' => '', 'error' => 'Operation timed out', 'reason' => 'transport', 'truncated' => false );

	$los = Runner::prepare_item( $a, array() );

	k3t_check( 'retry' === $los, 'timeout wraca do kolejki' );
	k3t_check( 'new' === $wpdb->rows[1]->status, 'status zostaje `new`' );
	k3t_check( 1 === (int) $wpdb->rows[1]->attempts, 'licznik prob podbity do 1' );

	// Trzecia proba konczy pozycje.
	$wpdb->rows[1]->attempts = 2;
	$los                     = Runner::prepare_item( $wpdb->rows[1], array() );

	k3t_check( 'failed' === $los, 'trzecia nieudana proba konczy pozycje' );
	k3t_check( 'failed' === $wpdb->rows[1]->status, 'status: `failed`' );
	k3t_check( false !== strpos( (string) $wpdb->rows[1]->note, 'prób: 3' ), 'notatka podaje liczbe prob' );

	// -----------------------------------------------------------------------
	echo "\n-- Cala partia --\n";
	// -----------------------------------------------------------------------
	$wpdb = k3t_reset();

	k3t_wiersz( 1, 'https://psy.pl/1/', '<p>' . str_repeat( 'Pierwszy artykuł o karmie dla psa. ', 60 ) . '</p>' );
	k3t_wiersz( 2, 'https://psy.pl/2/', '<p>Krótko o kocie perskim.</p>', 'Kot perski' );
	k3t_wiersz( 3, 'https://psy.pl/3/', '<p>' . str_repeat( 'Trzeci artykuł o szkoleniu psa. ', 60 ) . '</p>' );
	k3t_wiersz( 4, 'https://psy.pl/4/', '' );

	$GLOBALS['__strony']['https://psy.pl/4/'] = array( 'ok' => false, 'code' => 500, 'body' => '', 'error' => 'Serwer odpowiedzial kodem 500', 'reason' => 'status', 'truncated' => false );

	// Piata pozycja ma juz odcisk — partia NIE ma prawa jej wziac.
	$gotowa               = k3t_wiersz( 5, 'https://psy.pl/5/', 'cokolwiek' );
	$gotowa->content_hash = str_repeat( 'a', 64 );

	$podsumowanie = Runner::prepare_batch( 10 );

	k3t_check( 4 === (int) $podsumowanie['taken'], 'partia wziela 4 pozycje, nie 5 (jest ' . (int) $podsumowanie['taken'] . ')' );
	k3t_check( 2 === (int) $podsumowanie['ready'], 'gotowych: 2 (jest ' . (int) $podsumowanie['ready'] . ')' );
	k3t_check( 1 === (int) $podsumowanie['skipped'], 'pominietych: 1 (jest ' . (int) $podsumowanie['skipped'] . ')' );
	k3t_check( 1 === (int) $podsumowanie['retry'], 'do ponowienia: 1 (jest ' . (int) $podsumowanie['retry'] . ')' );
	k3t_check( 0 === (int) $podsumowanie['failed'], 'nieudanych: 0' );
	k3t_check( str_repeat( 'a', 64 ) === (string) $wpdb->rows[5]->content_hash, 'pozycja z odciskiem nietknieta' );
	k3t_check( 1 === count( $GLOBALS['__zadania'] ), 'jedno zadanie HTTP na cala partie — tylko pozycja bez tresci' );

	$odczyty_ustawien = count(
		array_filter(
			$GLOBALS['__odczyty'],
			function ( $k ) {
				return 'ainp_settings' === $k;
			}
		)
	);
	k3t_check( 1 === $odczyty_ustawien, 'lista slow czytana RAZ na partie (jest ' . $odczyty_ustawien . ')' );

	// Sufit partii dziala.
	$wpdb = k3t_reset();
	for ( $i = 1; $i <= 12; $i++ ) {
		k3t_wiersz( $i, 'https://psy.pl/x' . $i . '/', '<p>' . str_repeat( 'Tekst artykułu numer ' . $i . '. ', 60 ) . '</p>' );
	}

	$podsumowanie = Runner::prepare_batch( 3 );
	k3t_check( 3 === (int) $podsumowanie['taken'], 'sufit partii przestrzegany (jest ' . (int) $podsumowanie['taken'] . ')' );

	// Pozycja polamana nie zatrzymuje partii.
	$wpdb = k3t_reset();
	k3t_wiersz( 1, 'https://psy.pl/ok/', '<p>' . str_repeat( 'Poprawna treść artykułu o psach. ', 60 ) . '</p>' );

	$podsumowanie = Runner::prepare_batch( 10 );
	k3t_check( 1 === (int) $podsumowanie['ready'], 'partia z jedna poprawna pozycja konczy sie wynikiem 1' );
	k3t_check( 0 === (int) $podsumowanie['error'], 'zero wyjatkow w partii' );

	/*
	 * Zabezpieczenie „brak zatrzyman": awaria, ktorej nikt nie przewidzial,
	 * ma zabrac JEDNA pozycje, a nie cala partie. Pozycja rzucajaca wyjatkiem
	 * stoi w SRODKU, wiec test wykryje tez przerwanie petli.
	 */
	$wpdb = k3t_reset();
	k3t_wiersz( 1, 'https://psy.pl/przed/', '<p>' . str_repeat( 'Artykuł przed awarią. ', 60 ) . '</p>' );
	k3t_wiersz( 2, 'https://psy.pl/awaria/', '' );
	k3t_wiersz( 3, 'https://psy.pl/po/', '<p>' . str_repeat( 'Artykuł po awarii, z pełną treścią. ', 60 ) . '</p>' );

	$GLOBALS['__strony']['https://psy.pl/awaria/'] = 'throw';

	$podsumowanie = Runner::prepare_batch( 10 );

	k3t_check( 3 === (int) $podsumowanie['taken'], 'partia wziela wszystkie 3 pozycje' );
	k3t_check( 1 === (int) $podsumowanie['error'], 'wyjatek policzony jako JEDNA pozycja (jest ' . (int) $podsumowanie['error'] . ')' );
	k3t_check( 2 === (int) $podsumowanie['ready'], 'pozycje PRZED i PO awarii przygotowane (jest ' . (int) $podsumowanie['ready'] . ')' );
	k3t_check( isset( $podsumowanie['errors'][2] ), 'numer polamanej pozycji jest w podsumowaniu' );
	k3t_check(
		false !== strpos( (string) $podsumowanie['errors'][2], 'siec padla' ),
		'tresc bledu zachowana — inaczej nie ma po czym szukac przyczyny'
	);

	// -----------------------------------------------------------------------
	echo "\n-- Audyt P3: budzet czasu partii --\n";
	// -----------------------------------------------------------------------
	/*
	 * Bez budzetu partia dziesieciu pozycji zamawia do 10 x (15 s artykul
	 * + 5 s robots.txt) w jednym zadaniu administracyjnym, a typowy
	 * max_execution_time to 30-60 s. Test skraca skale: budzet zostaje ten
	 * sam, a „wolny serwer" oddaje odpowiedz po ulamku sekundy.
	 */
	$wpdb = k3t_reset();

	// Budzet podany JAWNIE — bramka jest ta sama, a test nie czeka pietnastu
	// sekund, zeby ja sprawdzic. Tego samego argumentu uzyje tick z Kroku 5,
	// podajac swoj pozostaly czas.
	$budzet = 1.0;

	for ( $i = 1; $i <= 6; $i++ ) {
		k3t_wiersz( $i, 'https://wolny.pl/' . $i . '/', '' );
		$GLOBALS['__strony'][ 'https://wolny.pl/' . $i . '/' ] = 'zwloka:' . ( $budzet / 2 + 0.05 );
	}

	$start        = microtime( true );
	$podsumowanie = Runner::prepare_batch( 6, $budzet );
	$czas         = microtime( true ) - $start;

	k3t_check( true === $podsumowanie['budget_hit'], 'partia zglasza, ze zatrzymal ja budzet czasu' );
	k3t_check( 2 === (int) $podsumowanie['taken'], 'wzieto 2 pozycje z 6, reszta czeka (jest ' . (int) $podsumowanie['taken'] . ')' );
	k3t_check(
		$czas < $budzet * 1.6,
		'przebieg skonczyl sie blisko budzetu, nie po pelnej partii (' . round( $czas, 2 ) . ' s)'
	);
	k3t_check(
		2 === count( $GLOBALS['__zadania'] ),
		'pozycje ponad budzetem nie kosztowaly ani jednego zadania HTTP (jest ' . count( $GLOBALS['__zadania'] ) . ')'
	);
	k3t_check( 'new' === $wpdb->rows[3]->status, 'pozycja nietknieta zostaje `new`' );
	k3t_check( 0 === (int) $wpdb->rows[3]->attempts, 'pozycji nietknietej nie podbito licznika prob' );

	// Pozycja zaczeta MUSI sie domknac — budzet sprawdzamy miedzy pozycjami.
	k3t_check( 1 === (int) $wpdb->rows[2]->attempts, 'ostatnia zaczeta pozycja zostala domknieta (attempts=1)' );

	$wpdb = k3t_reset();
	k3t_wiersz( 1, 'https://psy.pl/szybko/', '<p>' . str_repeat( 'Pełna treść z kanału, bez sieci. ', 60 ) . '</p>' );
	$podsumowanie = Runner::prepare_batch( 10 );

	k3t_check( false === $podsumowanie['budget_hit'], 'przy szybkiej partii budzet nie jest zglaszany' );
	k3t_check(
		Runner::PREPARE_BUDGET >= 10 && Runner::PREPARE_BUDGET <= 20,
		'domyslny budzet miesci sie pod typowym max_execution_time (' . Runner::PREPARE_BUDGET . ' s)'
	);

	// -----------------------------------------------------------------------
	echo "\n-- Audyt D-7: boksy w tresci Z KANALU --\n";
	// -----------------------------------------------------------------------
	$wpdb = k3t_reset();

	$z_boksem = '<p>' . str_repeat( 'Właściwa treść artykułu o karmie dla psa. ', 40 ) . '</p>'
		. '<div class="post-ratings"><p>Rate this post</p></div>'
		. '<div class="related-posts"><p>Zobacz też: kot brytyjski</p></div>';

	$w   = k3t_wiersz( 1, 'https://psy.pl/z-boksem/', $z_boksem );
	$los = Runner::prepare_item( $w, array( 'kot' ) );

	k3t_check( 'ready' === $los, 'pozycja z boksem w tresci przechodzi: `ready`' );
	k3t_check( 0 === count( $GLOBALS['__zadania'] ), 'zero zadan HTTP — tresc byla w kanale' );
	k3t_check( false !== strpos( (string) $wpdb->rows[1]->content, 'Właściwa treść' ), 'tresc artykulu zapisana' );
	k3t_check( false === strpos( (string) $wpdb->rows[1]->content, 'Rate this post' ), 'ramka oceny NIE trafila do bazy' );
	k3t_check( false === strpos( (string) $wpdb->rows[1]->content, 'kot brytyjski' ), 'boks powiazanych NIE trafil do bazy' );

	// -----------------------------------------------------------------------
	echo "\n-- Audyt D-3: sufit tresci przy zapisie --\n";
	// -----------------------------------------------------------------------
	$wpdb = k3t_reset();

	$ogromna = '';
	for ( $i = 0; $i < 500; $i++ ) {
		$ogromna .= '<p>Akapit numer ' . $i . ' o żywieniu psa, powtarzany dla objętości tekstu w kanale. </p>';
	}

	$w   = k3t_wiersz( 1, 'https://psy.pl/ogromna/', $ogromna );
	$los = Runner::prepare_item( $w, array() );

	k3t_check( 'ready' === $los, 'ogromna tresc z kanalu: `ready`' );
	k3t_check(
		AINP\Article::text_length( (string) $wpdb->rows[1]->content ) <= AINP\Article::MAX_CONTENT_CHARS,
		'w bazie ląduje tresc przycieta do sufitu (' . AINP\Article::text_length( (string) $wpdb->rows[1]->content ) . ')'
	);
	k3t_check( 64 === strlen( (string) $wpdb->rows[1]->content_hash ), 'odcisk liczony z tresci PRZYCIETEJ' );

	echo "\n";
	echo '=== WYNIK: ' . ( $ran - $fail ) . ' / ' . $ran . " asercji ===\n";

	if ( $fail > 0 ) {
		echo "BŁĘDY: $fail\n";
		exit( 1 );
	}

	echo "WSZYSTKIE OK\n";
	exit( 0 );
}
