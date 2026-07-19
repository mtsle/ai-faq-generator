<?php
/**
 * Źródło treści z pól dodatkowych wpisu (postmeta / ACF).
 *
 * Na realnej witrynie znaczna część treści nie leży w `post_content`, tylko
 * w polach dodatkowych: biogramy kadry, opisy oferty, teksty sekcji szablonu.
 * Renderowany HTML też ich nie widzi, jeśli motyw pakuje je do JavaScriptu —
 * dlatego kaskada źródeł jest SUMUJĄCA, a to źródło jest w niej niezastąpione.
 *
 * Działa w czystym PHP CLI (każde wołanie WordPressa pod `function_exists()`)
 * i nie wymaga ACF — brak wtyczki to po prostu brak pasujących kluczy.
 *
 * @package AI_FAQ_Generator
 */

namespace AIFAQ\Index;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Źródło treści z postmeta.
 */
class PostMetaContentSource implements ContentSource {

	/**
	 * Domyślne wzorce nazw kluczy meta niosących treść.
	 *
	 * Dopasowanie po ZAWIERANIU (po odcięciu numerycznych segmentów ACF),
	 * bo na realnych witrynach klucze wyglądają jak `kadra_0_bio`.
	 *
	 * @var array<int,string>
	 */
	public const DEFAULT_KEYS = array( 'bio', 'opis', 'tresc', 'description', 'content', 'text' );

	/**
	 * Klucze odrzucane BEZWARUNKOWO, sprawdzane PRZED dopasowaniem.
	 *
	 * Rank Math zapisuje `rank_math_description` BEZ podkreślnika, więc złapałoby
	 * je dopasowanie na `description` — a to metaopis dla Google, nie treść strony.
	 * Odwrotnie: pola dodawane ręcznie przez redaktora z definicji nie mają
	 * podkreślnika (WordPress ukrywa `_`-owe w panelu), więc sam filtr `_`
	 * chroni mniej, niż się wydaje.
	 *
	 * @var array<int,string>
	 */
	public const DENY_KEYS = array(
		'rank_math_description',
		'rank_math_title',
		'rank_math_facebook_description',
		'rank_math_twitter_description',
		'rank_math_robots',
		'rank_math_focus_keyword',
		'_yoast_wpseo_metadesc',
		'_yoast_wpseo_title',
		'_seopress_titles_desc',
		'_aioseo_description',
		'et_pb_old_content',
		'panels_data',
	);

	/**
	 * Minimalna długość wartości pola (mb_strlen).
	 */
	private const MIN_LENGTH = 20;

	/**
	 * Typy wpisów do przejrzenia.
	 *
	 * Domyślnie WĄSKO (`post` + `page`): własne typy zawartości bywają
	 * zgłoszeniami/rezerwacjami z danymi osobowymi pod kluczem `opis`,
	 * a baza wiedzy jest recytowana anonimowemu gościowi.
	 *
	 * @var array<int,string>
	 */
	private array $post_types;

	/**
	 * Wzorce nazw kluczy: DEFAULT_KEYS + klucze właściciela (dodawane, nie zastępujące).
	 *
	 * @var array<int,string>
	 */
	private array $meta_keys;

	/**
	 * Konstruktor.
	 *
	 * @param array<int,string> $post_types Typy wpisów (domyślnie post + page).
	 * @param array<int,string> $meta_keys  Dodatkowe wzorce kluczy z ustawień.
	 */
	public function __construct( array $post_types = array( 'post', 'page' ), array $meta_keys = array() ) {
		$types = array();
		foreach ( $post_types as $type ) {
			if ( is_scalar( $type ) && '' !== trim( (string) $type ) ) {
				$types[] = trim( (string) $type );
			}
		}
		// Pusta lista z ustawień nie może oznaczać „wszystkie typy" — wracamy do domyślnych.
		$this->post_types = ( array() === $types ) ? array( 'post', 'page' ) : array_values( array_unique( $types ) );

		$keys = self::DEFAULT_KEYS;
		foreach ( $meta_keys as $key ) {
			if ( ! is_scalar( $key ) ) {
				continue;
			}
			$key = strtolower( trim( (string) $key ) );
			if ( '' !== $key ) {
				$keys[] = $key;
			}
		}
		$this->meta_keys = array_values( array_unique( $keys ) );
	}

	/**
	 * Zwraca dokumenty zbudowane z pasujących pól postmeta.
	 *
	 * Jeden dokument = jeden wpis; pola sklejone alfabetycznie po kluczu,
	 * separator dokładnie `"\n"`. Scalanie z pozostałymi źródłami po `post_id`
	 * robi `CompositeContentSource` — tu go nie ma.
	 *
	 * @return array<int,array{post_id:int,title:string,url:string,text:string}>
	 */
	public function documents(): array {
		if ( ! function_exists( 'get_posts' ) || ! function_exists( 'get_post_meta' ) ) {
			return array();
		}

		$posts = get_posts(
			array(
				'post_type'        => $this->post_types,
				'post_status'      => 'publish',
				'numberposts'      => -1,
				'has_password'     => false,
				'suppress_filters' => true,
				'orderby'          => 'ID',
				'order'            => 'ASC',
			)
		);

		$docs = array();

		foreach ( (array) $posts as $post ) {
			try {
				$post_id = self::post_id_of( $post );
				if ( $post_id <= 0 ) {
					continue;
				}
				if ( ! WpContentSource::is_indexable( $post_id ) ) {
					continue;
				}

				$text = $this->text_for_post( $post_id );
				if ( '' === $text ) {
					continue;
				}

				$docs[] = array(
					'post_id' => $post_id,
					'title'   => self::title_of( $post_id ),
					'url'     => self::url_of( $post_id ),
					'text'    => $text,
				);
			} catch ( \Throwable $e ) {
				continue; // pojedynczy wpis nie może wywrócić całego indeksowania.
			}
		}

		return $docs;
	}

	/**
	 * Skleja treść pasujących pól jednego wpisu.
	 *
	 * @param int $post_id Identyfikator wpisu.
	 * @return string
	 */
	protected function text_for_post( int $post_id ): string {
		$all = get_post_meta( $post_id, '', false );
		if ( ! is_array( $all ) || array() === $all ) {
			return '';
		}

		$parts = array();

		foreach ( $all as $key => $values ) {
			$key = (string) $key;
			if ( ! $this->key_accepted( $key ) ) {
				continue;
			}

			$chunks = array();
			foreach ( (array) $values as $value ) {
				$plain = self::value_to_text( $value );
				if ( '' !== $plain ) {
					$chunks[] = $plain;
				}
			}

			if ( array() !== $chunks ) {
				$parts[ $key ] = implode( "\n", $chunks );
			}
		}

		if ( array() === $parts ) {
			return '';
		}

		ksort( $parts, SORT_STRING ); // deterministyczna kolejność = stabilne hashe.

		return trim( implode( "\n", $parts ) );
	}

	/**
	 * Czy nazwa klucza kwalifikuje pole do indeksowania?
	 *
	 * Kolejność jest istotna: DENY_KEYS PRZED dopasowaniem.
	 *
	 * @param string $key Nazwa klucza meta.
	 * @return bool
	 */
	protected function key_accepted( string $key ): bool {
		$lower = strtolower( trim( $key ) );
		if ( '' === $lower ) {
			return false;
		}

		foreach ( self::DENY_KEYS as $denied ) {
			if ( $lower === strtolower( $denied ) ) {
				return false;
			}
		}

		if ( 0 === strpos( $lower, '_' ) ) {
			return false;
		}

		$normalized = self::normalize_key( $lower );

		foreach ( $this->meta_keys as $needle ) {
			$needle = strtolower( trim( (string) $needle ) );
			if ( '' === $needle ) {
				continue;
			}
			if ( false !== strpos( $normalized, $needle ) || false !== strpos( $lower, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Odcina numeryczne segmenty ACF: `kadra_0_bio` → `kadra_bio`.
	 *
	 * @param string $key Klucz (już małymi literami).
	 * @return string
	 */
	protected static function normalize_key( string $key ): string {
		$segments = explode( '_', $key );
		$kept     = array();

		foreach ( $segments as $segment ) {
			if ( '' === $segment || preg_match( '/^\d+$/', $segment ) ) {
				continue;
			}
			$kept[] = $segment;
		}

		return implode( '_', $kept );
	}

	/**
	 * Zamienia wartość pola na czysty tekst albo odrzuca ją.
	 *
	 * @param mixed $value Wartość z postmeta.
	 * @return string Pusty string = odrzucone.
	 */
	protected static function value_to_text( $value ): string {
		if ( is_bool( $value ) || ! is_scalar( $value ) ) {
			return ''; // tablice/obiekty (dane powtarzalne ACF) pomijamy.
		}

		$raw = trim( (string) $value );
		if ( '' === $raw ) {
			return '';
		}

		if ( self::looks_serialized( $raw ) ) {
			return '';
		}

		// URL-e, gołe liczby (ID załączników) i numery telefonów to nie proza.
		if ( preg_match( '#^(https?://|\d+$|[\d\s+()-]{6,}$)#', $raw ) ) {
			return '';
		}

		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $raw, 'UTF-8' ) : strlen( $raw );
		if ( $length < self::MIN_LENGTH ) {
			return '';
		}

		return WpContentSource::to_plain( $raw );
	}

	/**
	 * Czy wartość jest serializowana? (`is_serialized()` z zapasem na CLI.)
	 *
	 * @param string $value Wartość.
	 * @return bool
	 */
	protected static function looks_serialized( string $value ): bool {
		if ( function_exists( 'is_serialized' ) ) {
			return (bool) is_serialized( $value );
		}
		return (bool) preg_match( '/^([adObis]:|N;)/', $value );
	}

	/**
	 * Wyciąga `post_id` z obiektu/tablicy/liczby zwróconej przez `get_posts()`.
	 *
	 * @param mixed $post Wpis.
	 * @return int
	 */
	private static function post_id_of( $post ): int {
		if ( is_object( $post ) && isset( $post->ID ) ) {
			return (int) $post->ID;
		}
		if ( is_array( $post ) && isset( $post['ID'] ) ) {
			return (int) $post['ID'];
		}
		if ( is_numeric( $post ) ) {
			return (int) $post;
		}
		return 0;
	}

	/**
	 * Tytuł wpisu (z zapasem, gdy WordPressa nie ma).
	 *
	 * @param int $post_id Identyfikator wpisu.
	 * @return string
	 */
	private static function title_of( int $post_id ): string {
		if ( function_exists( 'get_the_title' ) ) {
			$title = get_the_title( $post_id );
			return is_scalar( $title ) ? (string) $title : '';
		}
		return '';
	}

	/**
	 * Adres wpisu (z zapasem, gdy WordPressa nie ma).
	 *
	 * @param int $post_id Identyfikator wpisu.
	 * @return string
	 */
	private static function url_of( int $post_id ): string {
		if ( function_exists( 'get_permalink' ) ) {
			$url = get_permalink( $post_id );
			return is_scalar( $url ) ? (string) $url : '';
		}
		return '';
	}
}
