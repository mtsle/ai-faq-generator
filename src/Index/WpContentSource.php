<?php
/**
 * Źródło treści oparte na WordPressie.
 *
 * Pobiera opublikowane wpisy wskazanych typów (domyślnie `post` + `page`)
 * i zamienia ich treść na zwykły tekst (bez HTML, shortcode'ów i komentarzy
 * bloków Gutenberga), gotowy do chunkowania i embedowania.
 *
 * @package AI_FAQ_Generator
 */

namespace AIFAQ\Index;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Źródło treści z WordPressa.
 */
class WpContentSource implements ContentSource {

	/**
	 * Typy wpisów do indeksowania.
	 *
	 * @var array<int,string>
	 */
	private array $post_types;

	/**
	 * Konstruktor.
	 *
	 * @param array<int,string> $post_types Typy wpisów (domyślnie post + page).
	 */
	public function __construct( array $post_types = array( 'post', 'page' ) ) {
		$this->post_types = $post_types;
	}

	/**
	 * Zwraca opublikowane wpisy jako dokumenty (pomija te bez treści tekstowej).
	 *
	 * @return array<int,array{post_id:int,title:string,url:string,text:string}>
	 */
	public function documents(): array {
		$posts = get_posts(
			array(
				'post_type'        => $this->post_types,
				'post_status'      => 'publish',
				'numberposts'      => -1,
				'has_password'     => false, // nie indeksuj treści chronionej hasłem.
				// M14: indeks ma objąć CAŁĄ opublikowaną treść niezależnie od filtrów
				// wtyczek (WPML/Polylang zawężają do bieżącego języka). Bez tego
				// pruning kasowałby przy każdym reindeksie wpisy pozostałych języków.
				'suppress_filters' => true,
				'orderby'          => 'ID',
				'order'            => 'ASC',
			)
		);

		$docs = array();

		foreach ( (array) $posts as $post ) {
			$text = self::to_plain( (string) $post->post_content );
			if ( '' === $text ) {
				continue; // wpis bez treści tekstowej (np. sama galeria) — pomijamy.
			}

			$docs[] = array(
				'post_id' => (int) $post->ID,
				'title'   => (string) get_the_title( $post->ID ),
				'url'     => (string) get_permalink( $post->ID ),
				'text'    => $text,
			);
		}

		return $docs;
	}

	/**
	 * Zamienia HTML wpisu na zwykły tekst.
	 *
	 * Kolejność: komentarze bloków → shortcode'y → zamiana tagów blokowych na
	 * złamania linii (zachowanie granic) → usunięcie pozostałych tagów →
	 * dekodowanie encji → normalizacja białych znaków.
	 *
	 * @param string $html Surowa treść wpisu (`post_content`).
	 * @return string Zwykły tekst (może być pusty).
	 */
	public static function to_plain( string $html ): string {
		// Komentarze bloków Gutenberga: <!-- wp:paragraph --> itd.
		$html = (string) preg_replace( '/<!--.*?-->/s', ' ', $html );

		// Shortcode'y (np. [gallery], [contact-form-7 ...]).
		$html = function_exists( 'strip_shortcodes' )
			? strip_shortcodes( $html )
			: (string) preg_replace( '/\[[^\]]*\]/', '', $html );

		// Tagi blokowe i komórki tabel → złamanie linii, żeby słowa się nie zlepiły
		// (np. „<td>Poniedziałek</td><td>7:00</td>" nie może dać „Poniedziałek7:00").
		$html = (string) preg_replace( '#<(br|/p|/div|/h[1-6]|/li|/tr|/td|/th|/blockquote)[^>]*>#i', "\n", $html );

		// Pozostałe tagi precz.
		$text = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $html ) : strip_tags( $html );

		// Encje HTML → znaki (&amp; → &, &nbsp; → spacja itd.).
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// Normalizacja białych znaków.
		$text = (string) preg_replace( '/[ \t]+/u', ' ', $text );
		$text = (string) preg_replace( '/\s*\n\s*/u', "\n", $text );
		$text = (string) preg_replace( '/\n{2,}/u', "\n", $text );

		return trim( $text );
	}
}
