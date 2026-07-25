<?php
/**
 * Dane strukturalne (JSON-LD) strony niosącej shortcode generatora.
 *
 * Emitujemy DOKŁADNIE JEDEN węzeł — `WebApplication` opisujący samo narzędzie.
 * Węzły tożsamości witryny (`WebSite`, `Organization`, `BreadcrumbList`,
 * `WebPage`) należą do motywu albo do wtyczki SEO; powtórzenie ich tutaj
 * dałoby duplikaty encji w grafie.
 *
 * `FAQPage` jest tu ZAKAZANY. Pary pytanie-odpowiedź generatora powstają
 * dopiero po akcji użytkownika i nie istnieją w HTML, więc markup nie miałby
 * pokrycia w widocznej treści — a tego Google wymaga wprost. `FAQPage`
 * produkuje {@see \AIFAQ\Faq\Exporter}, do wklejenia tam, gdzie pary są
 * realnie widoczne dla czytelnika.
 *
 * Osobny blok `<script>`, a nie wstrzyknięcie do cudzego `@graph`: Google
 * scala wszystkie bloki JSON-LD ze strony i rozwiązuje referencje `@id`
 * MIĘDZY blokami, więc powiązanie działa bez modyfikowania cudzej struktury.
 *
 * @package AI_FAQ_Generator
 */

namespace AIFAQ\PublicUi;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Węzeł `WebApplication` podstrony generatora.
 */
class PageSchema {

	/**
	 * Sufiks `@id` naszego węzła (stabilny w obrębie URL strony).
	 */
	public const NODE_ID = '#aifaq-generator';

	/**
	 * Rejestruje hook (wołane z {@see \AIFAQ\Core\Plugin::init_hooks()}).
	 */
	public function register(): void {
		// Priorytet 30 — po wtyczkach SEO i po module SEO motywu. Kolejność nie
		// ma znaczenia semantycznego (Google scala bloki), ułatwia tylko czytanie
		// źródła strony przy diagnozie.
		add_action( 'wp_head', array( $this, 'maybe_render' ), 30 );
	}

	/**
	 * Wypisuje węzeł, gdy oglądana jest strona z naszym shortcode'em.
	 */
	public function maybe_render(): void {
		$post = $this->target_post();

		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		/**
		 * Pozwala witrynie dopiąć własne referencje albo wyłączyć węzeł.
		 *
		 * Zwrócenie pustej tablicy = nie wypisujemy nic.
		 *
		 * @param array<string,mixed> $node Węzeł JSON-LD.
		 * @param \WP_Post            $post Strona z shortcode'em.
		 */
		$node = apply_filters( 'aifaq_jsonld_node', $this->build_node( $post ), $post );

		if ( is_array( $node ) && array() !== $node ) {
			$this->emit( $node );
		}

		// `FAQPage` WYŁĄCZNIE z par opublikowanych na tej stronie — te są renderowane
		// serwerowo, więc markup ma pokrycie w widocznej treści. Bez opublikowanych par
		// nie ma węzła: pary generatora powstają po kliknięciu i oznaczenie ich byłoby
		// markupem bez pokrycia, czyli spamem danymi strukturalnymi.
		if ( class_exists( '\AIFAQ\Faq\PublicFaq' ) ) {
			$faq = \AIFAQ\Faq\PublicFaq::jsonld( (string) get_permalink( $post ) );

			if ( is_array( $faq ) && array() !== $faq ) {
				$this->emit( $faq );
			}
		}
	}

	/**
	 * Wypisuje pojedynczy węzeł jako blok `application/ld+json`.
	 *
	 * Osobne bloki, a nie wspólny `@graph`: Google scala wszystkie bloki ze strony
	 * i rozwiązuje `@id` między nimi, a rozdzielenie trzyma odpowiedzialności osobno
	 * (`WebApplication` opisuje narzędzie, `FAQPage` — opublikowaną treść).
	 *
	 * @param array<string,mixed> $node Węzeł JSON-LD.
	 */
	private function emit( array $node ): void {

		// JSON_HEX_TAG zamienia `<` i `>` na sekwencje `<`/`>`. Bez tego dowolna
		// wartość niosąca `</script>` (np. tytuł strony ustawiony przez klienta)
		// zamknęłaby element i wstrzyknęła HTML. Świadomie NIE podajemy
		// JSON_UNESCAPED_SLASHES — `\/` w URL-ach jest poprawnym JSON-em i stanowi
		// drugą zaporę przed przedwczesnym zamknięciem skryptu.
		$json = wp_json_encode( $node, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP );

		if ( ! is_string( $json ) || '' === $json ) {
			return;
		}

		echo '<script type="application/ld+json">' . $json . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — wp_json_encode + JSON_HEX_TAG.
	}

	/**
	 * Strona, dla której wypisujemy węzeł — albo `null`.
	 *
	 * Wykrycie po OBECNOŚCI SHORTCODE'U, nie po ID ani slugu:
	 *  - ID bywa inne po odtworzeniu strony przez {@see PageGuard} i po migracji bazy;
	 *  - slug klient może zmienić, a stan `slug_taken` dopuszcza kolizję z trasą;
	 *  - shortcode jest PRZYCZYNĄ obecności narzędzia na stronie, więc działa też,
	 *    gdy klient wklei `[aifaq_generator]` gdzie indziej.
	 * To ta sama reguła, której wtyczka używa już w {@see Shortcode::maybe_enqueue()}
	 * i {@see Shortcode::maybe_nocache()} — jedno rozpoznanie w całej wtyczce.
	 *
	 * UWAGA: NIE wolno tu wołać `WpContentSource::is_indexable()` — ta bramka
	 * zwraca `false` dla własnej podstrony wtyczki („generator nie indeksuje sam
	 * siebie"), więc węzeł nie wypisałby się nigdy. To bramka bazy wiedzy, nie SEO.
	 *
	 * @return \WP_Post|null
	 */
	private function target_post(): ?\WP_Post {
		if ( ! is_singular() || is_feed() ) {
			return null;
		}

		// `is_embed()` osobno i przez `function_exists` — brak funkcji nie może
		// wywrócić żądania (ta sama zasada co w reszcie wtyczki).
		if ( function_exists( 'is_embed' ) && is_embed() ) {
			return null;
		}

		$post = get_post();

		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
			return null;
		}

		if ( ! has_shortcode( (string) $post->post_content, Shortcode::TAG ) ) {
			return null;
		}

		return $post;
	}

	/**
	 * Buduje węzeł `WebApplication`.
	 *
	 * Każde pole jest sprawdzalne wzrokiem na stronie. Zero ocen, cen,
	 * dostępności i recenzji — Google nie honoruje takich danych podanych
	 * przez samego wydawcę, a zmyślone byłyby spamem danymi strukturalnymi.
	 *
	 * @param \WP_Post $post Strona z shortcode'em.
	 * @return array<string,mixed>
	 */
	private function build_node( \WP_Post $post ): array {
		$url  = (string) get_permalink( $post );
		$lang = (string) get_bloginfo( 'language' ); // Np. `pl-PL`.

		$node = array(
			'@context'            => 'https://schema.org',
			'@type'               => 'WebApplication',
			'@id'                 => $url . self::NODE_ID,
			'name'                => wp_strip_all_tags( (string) get_the_title( $post ) ),
			'url'                 => $url,
			'applicationCategory' => 'ReferenceApplication',
			'browserRequirements' => 'Requires JavaScript',
			'operatingSystem'     => 'All',
			'isAccessibleForFree' => true,
			'mainEntityOfPage'    => array( '@id' => $url ),
		);

		if ( '' !== $lang ) {
			$node['inLanguage'] = $lang;
		}

		$desc = $this->description( $post );

		if ( '' !== $desc ) {
			$node['description'] = $desc;
		}

		// Temat witryny — to on sprawia, że dane strukturalne podstrony brzmią
		// inaczej u przedszkola, a inaczej na blogu o kotach. Gdy profil go nie zna,
		// pola po prostu nie ma: pusty `about` byłby gorszy niż jego brak.
		$topic = $this->topic();

		if ( '' !== $topic ) {
			$node['about'] = array(
				'@type' => 'Thing',
				'name'  => $topic,
			);
		}

		// Referencje dopinamy ZAWSZE. Wcześniej były bramkowane wykryciem wtyczki
		// SEO, przez co motyw wypisujący własne `#website`/`#organization` (a takich
		// jest sporo) musiał je dopinać własną łatką — czyli SEO wtyczki zależało
		// od cudzego kodu. Niedomknięta referencja `@id` NIE jest błędem: walidator
		// jej nie zgłasza, a Google po prostu ją pomija. Koszt zerowy, zysk realny.
		$node['isPartOf'] = array( '@id' => home_url( '/#website' ) );
		$node['provider'] = array( '@id' => home_url( '/#organization' ) );

		return $node;
	}

	/**
	 * Temat witryny z {@see \AIFAQ\Seo\SiteProfile} — '' , gdy nieznany.
	 *
	 * @return string
	 */
	private function topic(): string {
		if ( ! class_exists( '\AIFAQ\Seo\SiteProfile' ) ) {
			return '';
		}

		try {
			return trim( (string) \AIFAQ\Seo\SiteProfile::topic() );
		} catch ( \Throwable $e ) {
			unset( $e );
			return '';
		}
	}

	/**
	 * Opis węzła: wyciąg strony, a w jego braku podtytuł widgetu.
	 *
	 * Oba źródła są WIDOCZNE dla użytkownika — węzeł nie opisuje niczego,
	 * czego nie ma na stronie.
	 *
	 * @param \WP_Post $post Strona z shortcode'em.
	 * @return string
	 */
	private function description( \WP_Post $post ): string {
		$raw     = isset( $post->post_excerpt ) ? (string) $post->post_excerpt : '';
		$excerpt = trim( wp_strip_all_tags( $raw ) );

		if ( '' !== $excerpt ) {
			return $excerpt;
		}

		// Bez wyciągu bierzemy akapit wstępu — ten sam, który widnieje na stronie
		// i który jest już dostrojony do tematu witryny.
		return trim( (string) GeneratorPage::page_intro() );
	}
}
