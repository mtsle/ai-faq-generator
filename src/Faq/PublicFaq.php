<?php
/**
 * Pary Q&A opublikowane na podstronie generatora.
 *
 * Domyka ostatnią lukę SEO tej podstrony: sam generator jest interaktywny, więc
 * jego odpowiedzi powstają PO kliknięciu i wyszukiwarka nie zobaczy ich nigdy.
 * Opublikowane pary są renderowane SERWEROWO — to jedyna treść tej strony, która
 * realnie trafia do indeksu, i jedyna, która daje prawo do `FAQPage`.
 *
 * DLACZEGO W OPCJACH, A NIE W TREŚCI STRONY. Trzy powody, każdy sparzył w praktyce:
 *  1. `kses` wycina `<script>` przy zapisie strony przez rolę bez `unfiltered_html`
 *     — JSON-LD wklejony w treść znika po cichu, zostawiając goły JSON jako tekst;
 *  2. treść strony klient edytuje — markup i widoczne pary natychmiast się rozjeżdżają,
 *     a rozjazd `FAQPage` z treścią to naruszenie wytycznych Google;
 *  3. wtyczka jest dostarczana jako ZIP i musi działać na dowolnej witrynie —
 *     cokolwiek żyje w bazie konkretnej strony, nie pojedzie razem z nią.
 *
 * Widoczne pary i `FAQPage` powstają z JEDNEJ tablicy ({@see pairs()}), więc nie
 * mogą się rozjechać z definicji.
 *
 * @package AI_FAQ_Generator
 */

namespace AIFAQ\Faq;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Magazyn i render publicznych par Q&A.
 */
class PublicFaq {

	/**
	 * Opcja z opublikowanymi parami (autoload `no` — czytana tylko na podstronie).
	 */
	public const OPTION = 'aifaq_public_faq';

	/**
	 * Ostatnia wersja SPRZED nadpisania/zdjęcia (K23 etap 1, znalezisko B6) —
	 * `publish()`/`unpublish()` dotąd nadpisywały/kasowały jedyną kopię par bez
	 * żadnego śladu. To NIE jest pełna historia ani UI przywracania (poza
	 * zakresem tej poprawki) — jedna sieć bezpieczeństwa na poziomie bazy.
	 */
	public const OPTION_PREV = 'aifaq_public_faq_prev';

	/**
	 * Sufit liczby par — ten sam co w {@see Exporter::MAX_PAIRS}.
	 */
	public const MAX_PAIRS = 50;

	/**
	 * Publikuje pary na podstronie.
	 *
	 * @param array<int,array<string,mixed>> $pairs         Pary `question`/`answer`.
	 * @param int                            $generation_id Źródłowa generacja (0 = nieznana).
	 * @return int Liczba opublikowanych par (0 = nic nie zapisano).
	 */
	public static function publish( array $pairs, int $generation_id = 0 ): int {
		$clean = self::normalize( $pairs );

		if ( array() === $clean ) {
			return 0;
		}

		if ( ! function_exists( 'update_option' ) ) {
			return 0;
		}

		self::snapshot_previous();

		update_option(
			self::OPTION,
			array(
				'pairs'         => $clean,
				'generation_id' => max( 0, $generation_id ),
				'updated'       => function_exists( 'current_time' ) ? current_time( 'mysql' ) : '',
			),
			false
		);

		return count( $clean );
	}

	/**
	 * Zdejmuje pary z podstrony (treść i `FAQPage` znikają razem).
	 */
	public static function unpublish(): void {
		if ( function_exists( 'delete_option' ) ) {
			self::snapshot_previous();
			delete_option( self::OPTION );
		}
	}

	/**
	 * Zapamiętuje bieżący stan {@see OPTION} PRZED nadpisaniem/skasowaniem —
	 * jedna, ostatnia wersja (nie stos), wystarczająca żeby publikacja/zdjęcie
	 * nie było nieodwracalne w jednym kliknięciu (K23 etap 1, znalezisko B6).
	 */
	private static function snapshot_previous(): void {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
			return;
		}

		$current = get_option( self::OPTION, null );
		if ( ! is_array( $current ) || array() === ( $current['pairs'] ?? array() ) ) {
			return; // nic realnego do zachowania.
		}

		update_option( self::OPTION_PREV, $current, false );
	}

	/**
	 * Opublikowane pary — zawsze po normalizacji.
	 *
	 * Normalizacja także przy ODCZYCIE, nie tylko przy zapisie: opcję można podmienić
	 * z zewnątrz (WP-CLI, migracja, inna wtyczka), a do renderu i do `FAQPage` nie
	 * wolno wpuścić pary, której nie da się pokazać.
	 *
	 * @return array<int,array{question:string,answer:string}>
	 */
	public static function pairs(): array {
		if ( ! function_exists( 'get_option' ) ) {
			return array();
		}

		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) || ! isset( $stored['pairs'] ) || ! is_array( $stored['pairs'] ) ) {
			return array();
		}

		return self::normalize( $stored['pairs'] );
	}

	/**
	 * Identyfikator generacji, z której pochodzą opublikowane pary (0 = brak).
	 *
	 * @return int
	 */
	public static function generation_id(): int {
		if ( ! function_exists( 'get_option' ) ) {
			return 0;
		}

		$stored = get_option( self::OPTION, array() );

		return ( is_array( $stored ) && isset( $stored['generation_id'] ) ) ? (int) $stored['generation_id'] : 0;
	}

	/**
	 * Normalizuje pary: tylko skalarne, niepuste po przycięciu, cap {@see MAX_PAIRS}.
	 *
	 * Ta sama reguła co w {@see Exporter} i w warstwie REST — para bez pytania albo
	 * bez odpowiedzi wyrenderowałaby się jako pusty nagłówek, a w `FAQPage` byłaby
	 * `Question` bez treści.
	 *
	 * @param array<int,mixed> $pairs Pary wejściowe.
	 * @return array<int,array{question:string,answer:string}>
	 */
	public static function normalize( array $pairs ): array {
		$out = array();

		foreach ( $pairs as $pair ) {
			if ( ! is_array( $pair ) ) {
				continue;
			}

			$q = $pair['question'] ?? '';
			$a = $pair['answer'] ?? '';

			if ( ! is_scalar( $q ) || ! is_scalar( $a ) ) {
				continue;
			}

			$q = trim( (string) $q );
			$a = trim( (string) $a );

			if ( '' === $q || '' === $a ) {
				continue;
			}

			$out[] = array(
				'question' => $q,
				'answer'   => $a,
			);

			if ( count( $out ) >= self::MAX_PAIRS ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Teksty sekcji (pl/en/de).
	 *
	 * @param string $lang Kod języka.
	 * @return array<string,string>
	 */
	public static function strings( string $lang ): array {
		$all = array(
			'pl' => array( 'heading' => __( 'Najczęściej zadawane pytania', 'ai-faq-generator' ) ),
			'en' => array( 'heading' => __( 'Frequently asked questions', 'ai-faq-generator' ) ),
			'de' => array( 'heading' => __( 'Häufig gestellte Fragen', 'ai-faq-generator' ) ),
		);

		return $all[ $lang ] ?? $all['pl'];
	}

	/**
	 * Markup sekcji FAQ — renderowany SERWEROWO, więc widoczny dla wyszukiwarki.
	 *
	 * Pusty łańcuch, gdy nic nie opublikowano: pusty nagłówek „Najczęściej zadawane
	 * pytania" bez pytań byłby gorszy niż brak sekcji.
	 *
	 * @param string $heading_tag `h2` (wewnątrz motywu) albo `h3`.
	 * @return string
	 */
	public static function widget( string $heading_tag = 'h2' ): string {
		$pairs = self::pairs();

		if ( array() === $pairs ) {
			return '';
		}

		$tag = in_array( $heading_tag, array( 'h2', 'h3' ), true ) ? $heading_tag : 'h2';
		$sub = ( 'h2' === $tag ) ? 'h3' : 'h4';
		$t   = self::strings( self::lang() );

		$out  = '<section class="aifaq-faq">';
		$out .= '<' . $tag . ' class="aifaq-faq__h">' . esc_html( $t['heading'] ) . '</' . $tag . '>';

		foreach ( $pairs as $pair ) {
			$out .= '<' . $sub . ' class="aifaq-faq__q">' . esc_html( $pair['question'] ) . '</' . $sub . '>';
			$out .= '<p class="aifaq-faq__a">' . esc_html( $pair['answer'] ) . '</p>';
		}

		$out .= '</section>';

		return $out;
	}

	/**
	 * Węzeł `FAQPage` z opublikowanych par — albo `null`, gdy nie ma czego opisać.
	 *
	 * Zwraca TABLICĘ, nie łańcuch: kodowaniem (i jego bezpiecznymi flagami) zajmuje
	 * się jedno miejsce — {@see \AIFAQ\PublicUi\PageSchema}.
	 *
	 * @param string $page_url Adres podstrony (do `@id`).
	 * @return array<string,mixed>|null
	 */
	public static function jsonld( string $page_url ): ?array {
		$pairs = self::pairs();

		if ( array() === $pairs ) {
			return null;
		}

		$entities = array();

		foreach ( $pairs as $pair ) {
			$entities[] = array(
				'@type'          => 'Question',
				'name'           => $pair['question'],
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => $pair['answer'],
				),
			);
		}

		$node = array(
			'@context'   => 'https://schema.org',
			'@type'      => 'FAQPage',
			'mainEntity' => $entities,
		);

		if ( '' !== $page_url ) {
			$node['@id'] = $page_url . '#faq';
		}

		$lang = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'language' ) : '';

		if ( '' !== $lang ) {
			$node['inLanguage'] = $lang;
		}

		return $node;
	}

	/**
	 * Język UI z ustawień (fallback pl).
	 *
	 * @return string
	 */
	private static function lang(): string {
		if ( ! class_exists( '\AIFAQ\Core\Settings' ) ) {
			return 'pl';
		}

		$lang = (string) \AIFAQ\Core\Settings::get_field( 'language', 'pl' );

		return in_array( $lang, array( 'pl', 'en', 'de' ), true ) ? $lang : 'pl';
	}
}
