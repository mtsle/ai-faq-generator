<?php
/**
 * Testy: SEO podstrony generatora — nagłówek widgetu + węzeł JSON-LD.
 *
 * Broni trzech rzeczy, które łatwo cofnąć jedną „porządkującą" zmianą:
 *  A. widget na ścieżce shortcode'owej NIE drukuje `<h1>` (motyw ma już swój);
 *     na trasie standalone `<h1>` ZOSTAJE — inaczej strona traci jedyny nagłówek.
 *  B. `PageSchema` wypisuje DOKŁADNIE jeden węzeł `WebApplication` i NIGDY
 *     `WebPage`/`WebSite`/`Organization`/`BreadcrumbList` (duplikaty encji)
 *     ani `FAQPage` (brak pokrycia w widocznej treści = spam danymi struktur.).
 *  C. nowa podstrona powstaje z akapitem wstępu i wyciągiem — bez nich wtyczki
 *     SEO budują opis z pustki i spadają na ogólny opis witryny.
 *
 * URUCHOMIENIE:  php tests/seo-jsonld-test.php
 * Kod wyjścia: 0 = OK, 1 = błędy.
 *
 * @package AI_FAQ_Generator
 */

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
if ( ! defined( 'AIFAQ_PLUGIN_URL' ) ) { define( 'AIFAQ_PLUGIN_URL', 'http://test.local/wp-content/plugins/ai-faq-generator/' ); }
if ( ! defined( 'AIFAQ_VERSION' ) ) { define( 'AIFAQ_VERSION', '0.0.0-test' ); }

// --- shimy funkcji WP ---
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_url_raw' ) ) { function esc_url_raw( $s ) { return (string) $s; } }
if ( ! function_exists( 'home_url' ) ) { function home_url( $p = '/' ) { return 'http://test.local' . $p; } }
$GLOBALS['__tagline'] = '';
if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( $k = '' ) {
		if ( 'language' === $k ) { return 'pl-PL'; }
		if ( 'description' === $k ) { return (string) $GLOBALS['__tagline']; }
		return 'Test Site';
	}
}
if ( ! function_exists( 'delete_option' ) ) { function delete_option( $k ) { unset( $GLOBALS['__opt'][ $k ] ); return true; } }
if ( ! function_exists( 'wp_strip_all_tags' ) ) { function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); } }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $tag, $value ) { return $value; } }
if ( ! function_exists( 'is_user_logged_in' ) ) { function is_user_logged_in() { return false; } }
if ( ! function_exists( 'wp_create_nonce' ) ) { function wp_create_nonce( $a = '' ) { return 'nonce'; } }
if ( ! function_exists( 'rest_url' ) ) { function rest_url( $p = '' ) { return 'http://test.local/wp-json/' . ltrim( (string) $p, '/' ); } }

$GLOBALS['__opt'] = array();
if ( ! function_exists( 'get_option' ) ) { function get_option( $k, $d = false ) { return $GLOBALS['__opt'][ $k ] ?? $d; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $k, $v ) { $GLOBALS['__opt'][ $k ] = $v; return true; } }

// Kontekst zapytania — sterowany z testów.
$GLOBALS['__singular'] = true;
$GLOBALS['__feed']     = false;
$GLOBALS['__post']     = null;
if ( ! function_exists( 'is_singular' ) ) { function is_singular( $t = '' ) { return (bool) $GLOBALS['__singular']; } }
if ( ! function_exists( 'is_feed' ) ) { function is_feed() { return (bool) $GLOBALS['__feed']; } }
if ( ! function_exists( 'get_post' ) ) { function get_post( $id = null ) { return $GLOBALS['__post']; } }
if ( ! function_exists( 'get_permalink' ) ) { function get_permalink( $p = null ) { return 'http://test.local/generator-faq/'; } }
if ( ! function_exists( 'get_the_title' ) ) { function get_the_title( $p = null ) { return 'Generator FAQ'; } }
if ( ! function_exists( 'has_shortcode' ) ) { function has_shortcode( $c, $tag ) { return false !== strpos( (string) $c, '[' . $tag . ']' ); } }
if ( ! function_exists( 'add_action' ) ) { function add_action( ...$a ) { return true; } }

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public $ID = 1;
		public $post_status = 'publish';
		public $post_content = '';
		public $post_excerpt = '';
	}
}

// --- harness ---
$fail = 0;
function check( $cond, $label ) { global $fail; echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n"; if ( ! $cond ) { $fail++; } }

require __DIR__ . '/../src/Core/Settings.php';
require __DIR__ . '/../src/Rag/RagService.php';
require __DIR__ . '/../src/Rest/RestController.php';
// Krok 23 (czysty refaktor): RestController rozbity na warstwy — routing w
// RouteRegistrar, logika w klasach usługowych. Zestaw ładuje pliki RĘCZNIE
// (bez autoloadera wtyczki), więc doklejamy resztę warstwy REST.
require __DIR__ . '/../src/Rest/RouteRegistrar.php';
require __DIR__ . '/../src/Rest/GuestIdentity.php';
require __DIR__ . '/../src/Rest/PairsInput.php';
require __DIR__ . '/../src/Rest/AskService.php';
require __DIR__ . '/../src/Rest/AdminService.php';
require __DIR__ . '/../src/Rest/GeneratorService.php';
require __DIR__ . '/../src/Rest/PublishService.php';
require __DIR__ . '/../src/PublicUi/GeneratorPage.php';
require __DIR__ . '/../src/PublicUi/Shortcode.php';
require __DIR__ . '/../src/PublicUi/PageGuard.php';
require __DIR__ . '/../src/PublicUi/PageSchema.php';
require __DIR__ . '/../src/Seo/SiteProfile.php';
require __DIR__ . '/../src/Faq/PublicFaq.php';

use AIFAQ\PublicUi\GeneratorPage;
use AIFAQ\PublicUi\PageSchema;
use AIFAQ\Seo\SiteProfile;

// =====================================================================
echo "\n== A. Nagłówek widgetu ==\n";

$h1   = GeneratorPage::widget();
$h2   = GeneratorPage::widget( 'h2' );
$none = GeneratorPage::widget( 'none' );

check( false !== strpos( $h1, '<h1 class="aifaq__title"' ), 'domyślnie h1 (trasa standalone) — jedyny nagłówek strony' );
check( false !== strpos( $h2, '<h2 class="aifaq__title"' ), '„h2" → nagłówek schodzi o poziom' );
check( false === strpos( $h2, '<h1' ), '„h2" nie zostawia żadnego h1' );
check( false === strpos( $none, 'aifaq__title' ), '„none" → brak nagłówka (motyw ma własny h1)' );
check( false === strpos( $none, 'aifaq__eyebrow' ), '„none" → brak eyebrow (dublował nazwę witryny)' );
check( false !== strpos( $none, 'aifaq__subtitle' ), '„none" zostawia podtytuł jako lead widgetu' );
check( false !== strpos( $none, 'id="aifaq-form"' ), '„none" nie rusza formularza' );
check( GeneratorPage::widget( 'h7' ) === $h1, 'wartość spoza whitelisty → bezpiecznie h1' );

// =====================================================================
echo "\n== B. Węzeł JSON-LD ==\n";

$schema = new PageSchema();
$render = static function () use ( $schema ) {
	ob_start();
	$schema->maybe_render();
	return (string) ob_get_clean();
};

$post               = new WP_Post();
$post->post_content = 'Wstęp. [aifaq_generator]';
$GLOBALS['__post']  = $post;

$out = $render();
check( '' !== $out, 'strona z shortcode\'em → węzeł wypisany' );

preg_match( '#<script type="application/ld\+json">(.*?)</script>#s', $out, $m );
$node = json_decode( $m[1] ?? '', true );

check( is_array( $node ), 'wypisany JSON jest poprawny składniowo' );
check( 'WebApplication' === ( $node['@type'] ?? '' ), '@type = WebApplication' );
check( 'http://test.local/generator-faq/' . PageSchema::NODE_ID === ( $node['@id'] ?? '' ), '@id = URL + sufiks węzła' );
check( true === ( $node['isAccessibleForFree'] ?? null ), 'isAccessibleForFree = true' );
check( 'pl-PL' === ( $node['inLanguage'] ?? '' ), 'inLanguage z ustawień witryny' );

// Duplikaty encji i fabrykacje — twarde zakazy.
foreach ( array( 'WebPage', 'WebSite', 'Organization', 'BreadcrumbList', 'FAQPage', 'SearchAction' ) as $forbidden ) {
	check( false === strpos( $out, '"' . $forbidden . '"' ), "NIE wypisuje {$forbidden}" );
}
foreach ( array( 'aggregateRating', 'review', 'offers', 'price' ) as $forbidden ) {
	check( false === strpos( $out, $forbidden ), "NIE wypisuje {$forbidden} (dane nieistniejące)" );
}

// Referencje dopinane ZAWSZE — inaczej SEO wtyczki zależałoby od łatki w motywie.
check( 'http://test.local/#website' === ( $node['isPartOf']['@id'] ?? '' ), 'isPartOf dopięty bez żadnej wtyczki SEO' );
check( 'http://test.local/#organization' === ( $node['provider']['@id'] ?? '' ), 'provider dopięty bez żadnej wtyczki SEO' );

// Opis: wyciąg strony wygrywa z podtytułem widgetu.
$post->post_excerpt = 'Godziny 7:00-18:00, grupy do 14 dzieci.';
$node2              = json_decode( (string) preg_replace( '#.*<script type="application/ld\+json">(.*?)</script>.*#s', '$1', $render() ), true );
check( 'Godziny 7:00-18:00, grupy do 14 dzieci.' === ( $node2['description'] ?? '' ), 'description bierze wyciąg strony' );
$post->post_excerpt = '';

// Bramki kontekstu.
$post->post_content = 'Zwykła strona bez narzędzia.';
check( '' === $render(), 'strona bez shortcode\'u → nic nie wypisujemy' );

$post->post_content = '[aifaq_generator]';
$post->post_status  = 'draft';
check( '' === $render(), 'szkic → nic nie wypisujemy' );
$post->post_status = 'publish';

$GLOBALS['__feed'] = true;
check( '' === $render(), 'kanał RSS → nic nie wypisujemy' );
$GLOBALS['__feed'] = false;

$GLOBALS['__singular'] = false;
check( '' === $render(), 'archiwum/lista → nic nie wypisujemy' );
$GLOBALS['__singular'] = true;

$GLOBALS['__post'] = null;
check( '' === $render(), 'brak wpisu → nic nie wypisujemy' );
$GLOBALS['__post'] = $post;

// Obecność wtyczki SEO niczego nie zmienia — jeden węzeł, ten sam kształt.
define( 'WPSEO_VERSION', '99.9-test' );

$node3 = json_decode( (string) preg_replace( '#.*<script type="application/ld\+json">(.*?)</script>.*#s', '$1', $render() ), true );

check( 'WebApplication' === ( $node3['@type'] ?? '' ), 'wtyczka SEO nie zmienia typu węzła' );
check( ! isset( $node3['aggregateRating'] ), 'nawet z wtyczką SEO: zero zmyślonych ocen' );
check( ! isset( $node3['offers'] ), 'nawet z wtyczką SEO: zero zmyślonych cen' );

// =====================================================================
echo "\n== B2. Węzeł dostraja się do TEMATU witryny ==\n";

check( ! isset( $node3['about'] ), 'temat nieznany → BRAK pustego about' );

$GLOBALS['__opt'][ SiteProfile::OPTION ] = array( 'topic' => 'przedszkole językowo-muzyczne na Woli' );

$node4 = json_decode( (string) preg_replace( '#.*<script type="application/ld\+json">(.*?)</script>.*#s', '$1', $render() ), true );

check( 'przedszkole językowo-muzyczne na Woli' === ( $node4['about']['name'] ?? '' ), 'temat znany → about z tematem witryny' );
check( 'Thing' === ( $node4['about']['@type'] ?? '' ), 'about jest typu Thing' );
check(
	false !== mb_strpos( (string) ( $node4['description'] ?? '' ), 'przedszkole językowo-muzyczne na Woli' ),
	'description wspomina temat witryny'
);

$GLOBALS['__opt'][ SiteProfile::OPTION ] = array( 'topic' => 'hodowla kotów brytyjskich' );
$node5 = json_decode( (string) preg_replace( '#.*<script type="application/ld\+json">(.*?)</script>.*#s', '$1', $render() ), true );
check( 'hodowla kotów brytyjskich' === ( $node5['about']['name'] ?? '' ), 'inna witryna → inny about (to samo pole, inna treść)' );

unset( $GLOBALS['__opt'][ SiteProfile::OPTION ] );

// =====================================================================
echo "\n== C. Treść nowej podstrony ==\n";

$ref  = new ReflectionMethod( '\AIFAQ\PublicUi\PageGuard', 'insert_args' );
$ref->setAccessible( true );
$args = $ref->invoke( null );

check( isset( $args['post_excerpt'] ) && '' !== $args['post_excerpt'], 'nowa podstrona dostaje wyciąg (inaczej opis SEO z pustki)' );
check( false !== strpos( (string) $args['post_content'], 'wp:paragraph' ), 'treść zawiera akapit wstępu' );
check( false !== strpos( (string) $args['post_content'], '[aifaq_generator]' ), 'treść nadal zawiera shortcode' );
check(
	strpos( (string) $args['post_content'], 'wp:paragraph' ) < strpos( (string) $args['post_content'], '[aifaq_generator]' ),
	'akapit stoi PRZED shortcode\'em'
);

$intro_pl = GeneratorPage::strings( 'pl' )['pageIntro'] ?? '';
check( '' !== $intro_pl, 'i18n pl ma klucz pageIntro' );
foreach ( array( 'pl', 'en', 'de' ) as $lg ) {
	$s = GeneratorPage::strings( $lg );
	check(
		'' !== ( $s['pageIntro'] ?? '' ) && '' !== ( $s['pageTitle'] ?? '' )
			&& false !== strpos( (string) ( $s['pageTitleTopic'] ?? '' ), '%s' )
			&& false !== strpos( (string) ( $s['pageIntroTopic'] ?? '' ), '%s' ),
		"i18n {$lg}: komplet kluczy podstrony (w tym warianty z %s)"
	);
}
check( (string) $args['post_excerpt'] === $intro_pl, 'wyciąg = akapit wstępu (jedno źródło tekstu)' );

// Tytuł i wstęp dostrajają się do tematu witryny.
check( 'Pytania i odpowiedzi' === GeneratorPage::page_title(), 'temat nieznany → tytuł ogólny, NIE nazwa narzędzia' );
check( false === strpos( GeneratorPage::page_title(), 'Generator FAQ' ), 'tytuł strony to nie nazwa wtyczki' );

$GLOBALS['__opt'][ SiteProfile::OPTION ] = array( 'topic' => 'hodowla kotów brytyjskich' );
check( 'Pytania i odpowiedzi — hodowla kotów brytyjskich' === GeneratorPage::page_title(), 'temat krótki → wchodzi do tytułu' );
check( 0 === mb_strpos( GeneratorPage::page_intro(), 'hodowla kotów brytyjskich —' ), 'wstęp zaczyna się tematem' );

// Konstrukcja MUSI być myślnikowa. Model zwraca mianownik, więc „Pytania
// i odpowiedzi o hodowla kotów" byłoby błędem gramatycznym w każdym tytule.
check( false === mb_strpos( GeneratorPage::page_title(), ' o ' ), 'tytuł bez przyimka wymagającego odmiany' );
check( false === mb_strpos( GeneratorPage::page_intro(), 'pytanie o ' ), 'wstęp bez przyimka wymagającego odmiany' );

$args2 = $ref->invoke( null );
check( false !== mb_strpos( (string) $args2['post_excerpt'], 'hodowla kotów brytyjskich' ), 'nowa podstrona: wyciąg z tematem witryny' );
check( false !== mb_strpos( (string) $args2['post_title'], 'hodowla kotów' ), 'nowa podstrona: tytuł z tematem witryny' );

// Za długi temat NIE wchodzi do tytułu — ucięty w połowie w wynikach wygląda na awarię.
$GLOBALS['__opt'][ SiteProfile::OPTION ] = array( 'topic' => 'integracyjne przedszkole niepubliczne językowo-muzyczne na warszawskiej Woli przy Górczewskiej' );
check( 'Pytania i odpowiedzi' === GeneratorPage::page_title(), 'temat za długi → tytuł wraca do ogólnego' );
check( false !== mb_strpos( GeneratorPage::page_intro(), 'integracyjne przedszkole' ), 'ale wstęp długi temat przyjmuje' );

unset( $GLOBALS['__opt'][ SiteProfile::OPTION ] );

// =====================================================================
echo "\n== E. SiteProfile: drabinka i sanityzacja ==\n";

$GLOBALS['__tagline'] = '';
check( '' === SiteProfile::topic(), 'brak wszystkiego → brak tematu (nie zmyślamy)' );
check( 'none' === SiteProfile::source(), 'źródło: none' );

$GLOBALS['__tagline'] = 'Just another WordPress site';
check( '' === SiteProfile::topic(), 'domyślny tagline WordPressa → odrzucony' );

$GLOBALS['__tagline'] = 'Kolejna witryna oparta na WordPressie';
check( '' === SiteProfile::topic(), 'polski domyślny tagline → odrzucony' );

$GLOBALS['__tagline'] = 'przedszkole językowo-muzyczne na Woli';
check( 'przedszkole językowo-muzyczne na Woli' === SiteProfile::topic(), 'sensowny tagline → temat' );
check( 'tagline' === SiteProfile::source(), 'źródło: tagline' );

$GLOBALS['__opt'][ SiteProfile::OPTION ] = array( 'topic' => 'temat z treści' );
check( 'temat z treści' === SiteProfile::topic(), 'temat z treści wygrywa z taglinem' );
check( 'content' === SiteProfile::source(), 'źródło: content' );

SiteProfile::forget();
check( 'przedszkole językowo-muzyczne na Woli' === SiteProfile::topic(), 'forget() → spadek na tagline' );

$GLOBALS['__tagline'] = '';

// Sanityzacja odpowiedzi modelu — metoda prywatna, wołana przez refleksję.
$san = new ReflectionMethod( '\AIFAQ\Seo\SiteProfile', 'sanitize_topic' );
$san->setAccessible( true );

check( 'hodowla kotów' === $san->invoke( null, "  hodowla kotów.  " ), 'obcina spacje i kropkę końcową' );
check( 'hodowla kotów' === $san->invoke( null, "\"hodowla kotów\"" ), 'zdejmuje cudzysłowy' );
check( 'hodowla kotów' === $san->invoke( null, "hodowla kotów\nDodatkowy komentarz modelu" ), 'bierze pierwszą linię (model bywa gadatliwy)' );
check( '' === $san->invoke( null, 'NIEZNANY' ), 'jawna niewiedza → brak tematu' );
check( '' === $san->invoke( null, 'unknown' ), 'UNKNOWN bez względu na wielkość liter' );
check( '' === $san->invoke( null, str_repeat( 'a', SiteProfile::MAX_TOPIC_LEN + 1 ) ), 'za długa odpowiedź odrzucona, NIE ucinana' );
check( '' === $san->invoke( null, 'temat <script>alert(1)</script>' ), 'odpowiedź ze znacznikami odrzucona' );
check( '' === $san->invoke( null, '>>> wyciek promptu' ), 'ślad promptu w odpowiedzi → odrzucona' );
check( '' === $san->invoke( null, '   ' ), 'sama biel → brak tematu' );

// =====================================================================
echo "\n== D. Spoiwo: ścieżka shortcode'owa ==\n";

// Kontrakt na źródle, bo to jedyne miejsce, gdzie decyzja o poziomie nagłówka
// zapada. Sekcja A może być zielona w komplecie, a strona i tak mieć dwa `h1`,
// jeśli `Shortcode::render()` przestanie podawać `none`.
$src_shortcode = (string) file_get_contents( __DIR__ . '/../src/PublicUi/Shortcode.php' );
$src_appshell  = (string) file_get_contents( __DIR__ . '/../src/App/AppShell.php' );
$src_standalone = (string) file_get_contents( __DIR__ . '/../src/PublicUi/GeneratorPage.php' );

check(
	1 === preg_match( '/render_body\(\s*[\'"]none[\'"]\s*\)/', $src_shortcode ),
	'Shortcode::render() podaje „none" do render_body()'
);
check(
	1 === preg_match( '/function render_body\(\s*string \$heading = [\'"]h1[\'"]/', $src_appshell ),
	'AppShell::render_body() domyślnie h1 (trasa standalone bez zmian)'
);
check(
	1 === preg_match( '/GeneratorPage::widget\(\s*\$heading\s*\)/', $src_appshell ),
	'AppShell przekazuje poziom dalej do widgetu (gałąź właściciela)'
);
check(
	1 === preg_match( '/AppShell::render_body\(\)/', $src_standalone ),
	'render_standalone() zostaje na domyślnym h1'
);

// =====================================================================
echo "\n== F. Publikacja par: treść widoczna + FAQPage z JEDNEGO źródła ==\n";

use AIFAQ\Faq\PublicFaq;

$demo = array(
	array( 'question' => 'Ile kosztuje?', 'answer' => 'Kwotę podajemy telefonicznie.' ),
	array( 'question' => 'Gdzie jesteście?', 'answer' => 'Na Woli.' ),
);

PublicFaq::unpublish();

check( array() === PublicFaq::pairs(), 'brak publikacji → zero par' );
check( '' === PublicFaq::widget(), 'brak par → BRAK sekcji (nie pusty nagłówek)' );
check( null === PublicFaq::jsonld( 'http://test.local/x/' ), 'brak par → BRAK węzła FAQPage' );

check( 2 === PublicFaq::publish( $demo, 7 ), 'publish() zwraca liczbę par' );
check( 2 === count( PublicFaq::pairs() ), 'pary odczytane z opcji' );
check( 7 === PublicFaq::generation_id(), 'zapamiętana generacja źródłowa' );

$w = PublicFaq::widget();
check( false !== strpos( $w, 'Ile kosztuje?' ), 'widget renderuje pytanie SERWEROWO' );
check( false !== strpos( $w, 'Kwotę podajemy telefonicznie.' ), 'widget renderuje odpowiedź' );
check( 2 === substr_count( $w, 'aifaq-faq__q' ), 'dokładnie 2 pytania w markupie' );
check( false !== strpos( $w, '<h2' ), 'domyślnie nagłówek h2 (wewnątrz motywu)' );
check( false !== strpos( PublicFaq::widget( 'h3' ), '<h3' ), 'poziom nagłówka sterowalny' );

// Anti-XSS: treść od modelu NIGDY nie może wejść do HTML surowa.
PublicFaq::publish( array( array( 'question' => '<script>alert(1)</script>', 'answer' => 'a & b' ) ), 0 );
$x = PublicFaq::widget();
check( false === strpos( $x, '<script>' ), 'znaczniki w parze zostają zeskejpowane' );
check( false !== strpos( $x, '&amp;' ), 'ampersand zeskejpowany' );

// FAQPage musi odpowiadać DOKŁADNIE temu, co widać.
PublicFaq::publish( $demo, 0 );
$ld = PublicFaq::jsonld( 'http://test.local/generator-faq/' );
check( 'FAQPage' === ( $ld['@type'] ?? '' ), '@type = FAQPage' );
check( 2 === count( $ld['mainEntity'] ), 'liczba Question === liczba widocznych par' );
check( 'Ile kosztuje?' === ( $ld['mainEntity'][0]['name'] ?? '' ), 'pytanie w JSON-LD to TO SAMO pytanie' );
check(
	'Kwotę podajemy telefonicznie.' === ( $ld['mainEntity'][0]['acceptedAnswer']['text'] ?? '' ),
	'odpowiedź w JSON-LD to TA SAMA odpowiedź'
);
check( 'http://test.local/generator-faq/#faq' === ( $ld['@id'] ?? '' ), '@id zakotwiczony w adresie strony' );

// Normalizacja: do renderu i do FAQPage nie wolno wpuścić pary bez treści.
$dirty = array(
	array( 'question' => 'ok', 'answer' => 'ok' ),
	array( 'question' => '', 'answer' => 'sierota' ),
	array( 'question' => 'bez odpowiedzi', 'answer' => '   ' ),
	array( 'question' => array( 'tablica' ), 'answer' => 'x' ),
	'nie-tablica',
);
check( 1 === count( PublicFaq::normalize( $dirty ) ), 'normalize odrzuca puste, nie-skalarne i nie-tablice' );

$flood = array();
for ( $i = 0; $i < PublicFaq::MAX_PAIRS + 20; $i++ ) {
	$flood[] = array( 'question' => 'q' . $i, 'answer' => 'a' . $i );
}
check( PublicFaq::MAX_PAIRS === count( PublicFaq::normalize( $flood ) ), 'normalize przycina do MAX_PAIRS' );

// Odczyt też normalizuje — opcję da się podmienić z zewnątrz (WP-CLI, migracja).
$GLOBALS['__opt'][ PublicFaq::OPTION ] = array( 'pairs' => array( array( 'question' => 'q', 'answer' => '' ) ) );
check( array() === PublicFaq::pairs(), 'skażona opcja → pary odrzucone przy ODCZYCIE' );

// Spięcie ze stroną: dwa bloki JSON-LD, gdy pary opublikowane.
PublicFaq::publish( $demo, 0 );
$post->post_content = '[aifaq_generator]';
$page_out = $render();
check( 2 === substr_count( $page_out, 'application/ld+json' ), 'opublikowane pary → DWA bloki JSON-LD' );
check( false !== strpos( $page_out, 'FAQPage' ), 'drugi blok to FAQPage' );

PublicFaq::unpublish();
check( 1 === substr_count( $render(), 'application/ld+json' ), 'po zdjęciu par → znów JEDEN blok' );

// =====================================================================
echo "\n" . ( $fail ? "=== BLEDY: {$fail} ===\n" : "=== WSZYSTKIE OK ===\n" );
exit( $fail ? 1 : 0 );
