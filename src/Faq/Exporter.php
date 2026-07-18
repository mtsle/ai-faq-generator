<?php
/**
 * Exporter — zamienia listę par Q&A na gotowe formaty do wklejenia.
 *
 * Druga część narzędzia generatora (Krok 14): właściciel wygenerował pary na
 * ekranie „Narzędzie FAQ", a tu dostaje je w pięciu formatach do wklejenia w
 * dowolne miejsce strony:
 *  - `html`      — semantyczny blok `<details>/<summary>` (rozwijane FAQ),
 *  - `gutenberg` — komentarze bloków WP (heading + paragraph) do wklejenia w edytorze,
 *  - `elementor` — szablon (section → column → widget accordion) jako JSON do importu,
 *  - `json`      — czytelna lista par `[{question,answer},…]`,
 *  - `jsonld`    — Schema.org `FAQPage` (kanoniczny SEO, do `<script type="application/ld+json">`).
 *
 * Klasa jest CZYSTA: bez sieci, bez stanu, bez zapisu — dostaje pary, zwraca pięć
 * stringów. Treść wstawiana do HTML/Gutenberg jest escapowana (`esc_html`), a do
 * JSON/JSON-LD/Elementor kodowana przez `wp_json_encode` (samo kodowanie JSON jest
 * ucieczką). Pary są najpierw normalizowane: pomijamy nieskalarne/puste,
 * przycinamy białe znaki. Brak użytecznych par → wyjątek (REST i tak waliduje wejście).
 *
 * @package AI_FAQ_Generator
 */

namespace AIFAQ\Faq;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Formatuje pary Q&A do formatów eksportu.
 */
class Exporter {

	/**
	 * Twardy limit liczby par na eksport (rail bezpieczeństwa; regułę 5..20
	 * egzekwuje warstwa wyżej — REST/UI).
	 */
	const MAX_PAIRS = 50;

	/**
	 * Zamienia pary na pięć formatów eksportu.
	 *
	 * @param array<int,array{question?:mixed,answer?:mixed}> $pairs Pary Q&A (bieżący stan z UI).
	 * @return array{html:string,gutenberg:string,elementor:string,json:string,jsonld:string}
	 *
	 * @throws InvalidArgumentException Gdy po normalizacji nie ma żadnej użytecznej pary.
	 */
	public function export( array $pairs ): array {
		$clean = $this->normalize( $pairs );

		if ( empty( $clean ) ) {
			throw new InvalidArgumentException( 'Brak par do eksportu.' );
		}

		return array(
			'html'      => $this->to_html( $clean ),
			'gutenberg' => $this->to_gutenberg( $clean ),
			'elementor' => $this->to_elementor( $clean ),
			'json'      => $this->to_json( $clean ),
			'jsonld'    => $this->to_jsonld( $clean ),
		);
	}

	/**
	 * Normalizuje pary: tylko skalarne, niepuste po przycięciu; cap MAX_PAIRS.
	 *
	 * @param array<int,mixed> $pairs Surowe pary.
	 * @return array<int,array{question:string,answer:string}>
	 */
	private function normalize( array $pairs ): array {
		$out = array();

		foreach ( $pairs as $pair ) {
			if ( ! is_array( $pair ) ) {
				continue;
			}

			$q = $pair['question'] ?? '';
			$a = $pair['answer'] ?? '';

			// Odrzucamy nie-skalarne (np. tablica → uniknięcie „Array to string conversion").
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
	 * Semantyczny blok FAQ z rozwijanymi parami (`<details>/<summary>`).
	 *
	 * @param array<int,array{question:string,answer:string}> $pairs Pary.
	 * @return string
	 */
	private function to_html( array $pairs ): string {
		$parts = array( '<div class="aifaq-faq">' );

		foreach ( $pairs as $pair ) {
			$parts[] = '  <details class="aifaq-faq__item">';
			$parts[] = '    <summary class="aifaq-faq__q">' . esc_html( $pair['question'] ) . '</summary>';
			$parts[] = '    <div class="aifaq-faq__a">' . esc_html( $pair['answer'] ) . '</div>';
			$parts[] = '  </details>';
		}

		$parts[] = '</div>';

		return implode( "\n", $parts );
	}

	/**
	 * Bloki edytora WordPress (Gutenberg): heading (poziom 3) + paragraph na parę.
	 *
	 * @param array<int,array{question:string,answer:string}> $pairs Pary.
	 * @return string
	 */
	private function to_gutenberg( array $pairs ): string {
		$parts = array();

		foreach ( $pairs as $pair ) {
			$q = esc_html( $pair['question'] );
			$a = esc_html( $pair['answer'] );

			$parts[] = '<!-- wp:heading {"level":3} -->';
			$parts[] = '<h3>' . $q . '</h3>';
			$parts[] = '<!-- /wp:heading -->';
			$parts[] = '';
			$parts[] = '<!-- wp:paragraph -->';
			$parts[] = '<p>' . $a . '</p>';
			$parts[] = '<!-- /wp:paragraph -->';
			$parts[] = '';
		}

		return trim( implode( "\n", $parts ) );
	}

	/**
	 * Szablon Elementor (section → column → widget accordion) jako JSON do importu.
	 *
	 * Kształt zgodny z eksportem szablonów Elementora: koperta
	 * `{version,title,type,content:[…]}`, a pary trafiają do zakładek widgetu
	 * `accordion`. Identyfikatory są deterministyczne (z indeksu), żeby eksport
	 * był powtarzalny i testowalny.
	 *
	 * @param array<int,array{question:string,answer:string}> $pairs Pary.
	 * @return string
	 */
	private function to_elementor( array $pairs ): string {
		$tabs = array();
		foreach ( $pairs as $i => $pair ) {
			$tabs[] = array(
				'_id'         => $this->el_id( 'tab', $i ),
				'tab_title'   => $pair['question'],
				'tab_content' => $pair['answer'],
			);
		}

		$widget = array(
			'id'         => $this->el_id( 'wgt', 0 ),
			'elType'     => 'widget',
			'widgetType' => 'accordion',
			'settings'   => array( 'tabs' => $tabs ),
			'elements'   => array(),
		);

		$column = array(
			'id'       => $this->el_id( 'col', 0 ),
			'elType'   => 'column',
			'settings' => array( '_column_size' => 100 ),
			'elements' => array( $widget ),
			'isInner'  => false,
		);

		$section = array(
			'id'       => $this->el_id( 'sec', 0 ),
			'elType'   => 'section',
			'settings' => array(),
			'elements' => array( $column ),
			'isInner'  => false,
		);

		$template = array(
			'version' => '0.4',
			'title'   => 'FAQ',
			'type'    => 'section',
			'content' => array( $section ),
		);

		return (string) wp_json_encode( $template, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Czytelna lista par jako JSON `[{question,answer},…]`.
	 *
	 * @param array<int,array{question:string,answer:string}> $pairs Pary.
	 * @return string
	 */
	private function to_json( array $pairs ): string {
		return (string) wp_json_encode( array_values( $pairs ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Schema.org `FAQPage` (kanoniczny format SEO dla rich results).
	 *
	 * @param array<int,array{question:string,answer:string}> $pairs Pary.
	 * @return string
	 */
	private function to_jsonld( array $pairs ): string {
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

		$doc = array(
			'@context'   => 'https://schema.org',
			'@type'      => 'FAQPage',
			'mainEntity' => $entities,
		);

		return (string) wp_json_encode( $doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Deterministyczny identyfikator elementu Elementora (7 znaków hex z indeksu).
	 *
	 * @param string $prefix Prefiks logiczny (sec/col/wgt/tab).
	 * @param int    $index  Indeks pary/elementu.
	 * @return string
	 */
	private function el_id( string $prefix, int $index ): string {
		return substr( md5( 'aifaq-' . $prefix . '-' . $index ), 0, 7 );
	}
}
