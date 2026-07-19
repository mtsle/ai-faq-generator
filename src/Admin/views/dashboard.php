<?php
/**
 * Widok: Dashboard (przegląd).
 *
 * Dwie liczby, które właściciel chce znać na wejściu: stan bazy wiedzy (czy jest
 * co odpowiadać) i ruch gości z dziennika `qa_log` (czy ktoś pyta i czy dostaje
 * odpowiedzi). Szczegóły dziennika — na podstronie „Historia".
 *
 * @package AI_FAQ_Generator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$aifaq_stats = \AIFAQ\Admin\IndexController::stats();
$aifaq_qa    = ( new \AIFAQ\Data\QaLogRepository() )->stats();

// --- Krok 17: stan kaskady źródeł treści. ---

// Czy właściciel zmienił ustawienia crawla? Komunikat pokazujemy TUTAJ, bo tu stoi
// przycisk indeksowania — ostrzeżenie na ekranie Ustawień nikt by nie skojarzył
// z kosztem. Gasimy flagę od razu po odczycie (jednorazowy komunikat).
$aifaq_crawl_notice = ( '1' === (string) get_option( \AIFAQ\Core\Settings::CRAWL_NOTICE, '' ) );
if ( $aifaq_crawl_notice ) {
	delete_option( \AIFAQ\Core\Settings::CRAWL_NOTICE );
}

$aifaq_crawl_on = ( '1' === (string) \AIFAQ\Core\Settings::get_field( 'crawl_enabled', '1' ) );

// Postęp pobierania — czytany z kolejki, ale jej brak nie może wywalić Dashboardu.
$aifaq_crawl = array(
	'total'         => 0,
	'done'          => 0,
	'running'       => false,
	'needs_reindex' => false,
	'warnings'      => array(),
);
if ( class_exists( '\AIFAQ\Index\CrawlQueue' ) ) {
	try {
		$aifaq_progress = ( new \AIFAQ\Index\CrawlQueue() )->progress();
		if ( is_array( $aifaq_progress ) ) {
			$aifaq_crawl = array_merge( $aifaq_crawl, $aifaq_progress );
		}
	} catch ( \Throwable $aifaq_e ) {
		unset( $aifaq_e );
	}
}

// Wynik testu pętli zwrotnej. Czytamy WYŁĄCZNIE zapisaną opcję — wołanie
// `loopback_ok()` strzelałoby żądaniem HTTP przy każdym otwarciu Dashboardu.
// Brak opcji = jeszcze nie sprawdzono → nie strasz użytkownika.
$aifaq_loopback = get_option( 'aifaq_loopback', array() );
$aifaq_loopback_bad = is_array( $aifaq_loopback ) && isset( $aifaq_loopback['ok'] ) && ! $aifaq_loopback['ok'];

// --- Krok 18: podstrona generatora. ---

// Szybka trasa `/{slug}` (wirtualna, obsługiwana przez Router) i podstrona WP
// `/generator-faq/` (realna strona z shortcode'em) to DWA RÓŻNE adresy. Mylenie ich
// było źródłem cichej awarii, przez którą właściciel nie wiedział, że podstrona
// w ogóle nie powstała — dlatego linkujemy oba, osobno i wprost.
$aifaq_slug = ltrim( (string) \AIFAQ\Core\Settings::get_field( 'page_slug', 'faqgenerator' ), '/' );

// Adres podstrony pokazujemy WYŁĄCZNIE wtedy, gdy naprawdę działa (stan `ok`).
// Czytamy zapisany stan, nie świeżą diagnozę — ta kosztuje zapytania, a `admin_init`
// i tak utrwala ją przed wyrysowaniem ekranu. Brak klasy strażnika nie ma prawa
// wywalić Dashboardu.
$aifaq_page_url = '';
if ( class_exists( '\AIFAQ\PublicUi\PageGuard' ) ) {
	try {
		$aifaq_page_state = \AIFAQ\PublicUi\PageGuard::state();
		if ( is_array( $aifaq_page_state )
			&& isset( $aifaq_page_state['status'] )
			&& \AIFAQ\PublicUi\PageGuard::STATE_OK === $aifaq_page_state['status'] ) {
			$aifaq_page_url = (string) \AIFAQ\PublicUi\PageGuard::page_url();
		}
	} catch ( \Throwable $aifaq_e ) {
		unset( $aifaq_e );
	}
}
?>
<div class="wrap aifaq-wrap">
	<h1 class="aifaq-title">
		<span class="dashicons dashicons-format-chat" aria-hidden="true"></span>
		<?php esc_html_e( 'AI FAQ Generator', 'ai-faq-generator' ); ?>
		<span class="aifaq-sub"><?php esc_html_e( 'Dashboard', 'ai-faq-generator' ); ?></span>
	</h1>

	<div class="aifaq-card">
		<h2><?php esc_html_e( 'Baza wiedzy (RAG)', 'ai-faq-generator' ); ?></h2>
		<p><?php esc_html_e( 'Indeksowanie zamienia treść Twojej strony (wpisy i strony) na fragmenty z embeddingami. Na tej bazie generator odpowiada wyłącznie w temacie strony.', 'ai-faq-generator' ); ?></p>

		<p class="aifaq-index-stats">
			<?php
			printf(
				/* translators: 1: liczba fragmentów, 2: liczba wpisów, 3: liczba fragmentów z embeddingiem */
				esc_html__( 'W bazie: %1$s fragmentów z %2$s wpisów (%3$s z embeddingiem).', 'ai-faq-generator' ),
				'<strong id="aifaq-stat-chunks">' . esc_html( (string) $aifaq_stats['chunks'] ) . '</strong>',
				'<strong id="aifaq-stat-posts">' . esc_html( (string) $aifaq_stats['posts'] ) . '</strong>',
				'<strong id="aifaq-stat-embedded">' . esc_html( (string) $aifaq_stats['embedded'] ) . '</strong>'
			);
			?>
		</p>

		<?php if ( $aifaq_crawl_notice ) : ?>
			<div class="notice notice-warning inline">
				<p>
					<strong><?php esc_html_e( 'Zmieniłeś ustawienia źródeł treści.', 'ai-faq-generator' ); ?></strong>
					<?php esc_html_e( 'Pobrane wcześniej strony zostały skasowane. Zaindeksuj treść ponownie — pamiętaj, że to ponowne, płatne liczenie embeddingów u dostawcy AI.', 'ai-faq-generator' ); ?>
				</p>
			</div>
		<?php endif; ?>

		<?php if ( $aifaq_crawl_on && $aifaq_loopback_bad ) : ?>
			<div class="notice notice-warning inline">
				<p>
					<strong><?php esc_html_e( 'Serwer nie potrafi pobrać własnej strony.', 'ai-faq-generator' ); ?></strong>
					<?php esc_html_e( 'Treść z szablonu motywu (to, co gość widzi na podstronach) nie trafi do bazy wiedzy — zaindeksujemy tylko treść wpisów i pola własne. Zwykle winne są: wtyczka „wkrótce otwieramy", ochrona hasłem katalogu, firewall albo brak wychodzącego HTTP na hostingu.', 'ai-faq-generator' ); ?>
				</p>
				<?php if ( ! empty( $aifaq_loopback['message'] ) ) : ?>
					<p><code><?php echo esc_html( (string) $aifaq_loopback['message'] ); ?></code></p>
				<?php endif; ?>
				<p><?php esc_html_e( 'Indeksowanie nadal działa — możesz spokojnie kliknąć „Zaindeksuj treść".', 'ai-faq-generator' ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( $aifaq_crawl_on && ! empty( $aifaq_crawl['needs_reindex'] ) ) : ?>
			<div class="notice notice-info inline">
				<p><?php esc_html_e( 'Pobieranie stron zakończone. Uruchom indeksowanie, żeby świeża treść trafiła do bazy wiedzy.', 'ai-faq-generator' ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( ! empty( $aifaq_crawl['warnings'] ) && is_array( $aifaq_crawl['warnings'] ) ) : ?>
			<div class="notice notice-warning inline">
				<p><strong><?php esc_html_e( 'Uwagi z pobierania stron:', 'ai-faq-generator' ); ?></strong></p>
				<ul style="list-style:disc;margin-left:1.5em;">
					<?php foreach ( array_slice( $aifaq_crawl['warnings'], 0, 10 ) as $aifaq_warning ) : ?>
						<li><?php echo esc_html( (string) $aifaq_warning ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>

		<p class="aifaq-index-actions">
			<button
				type="button"
				class="button button-primary"
				id="aifaq-reindex"
				<?php disabled( $aifaq_crawl_on && ! empty( $aifaq_crawl['running'] ), true ); ?>
			>
				<?php esc_html_e( 'Zaindeksuj treść', 'ai-faq-generator' ); ?>
			</button>
			<button type="button" class="button" id="aifaq-clear">
				<?php esc_html_e( 'Wyczyść bazę', 'ai-faq-generator' ); ?>
			</button>
			<span class="aifaq-index-status" id="aifaq-index-status" role="status" aria-live="polite"></span>
		</p>

		<?php
		// Miejsce na postęp pobierania. Wypełnia je indexer.js (textContent) odpytując
		// GET /admin/status; render serwerowy tylko na wejściu, żeby stan był widoczny
		// także przy wyłączonym JS.
		$aifaq_crawl_note = '';
		if ( $aifaq_crawl_on && ! empty( $aifaq_crawl['running'] ) ) {
			$aifaq_crawl_note = sprintf(
				/* translators: 1: liczba pobranych stron, 2: liczba wszystkich stron */
				__( 'Pobieram strony w tle: %1$d z %2$d. Indeksowanie odblokuje się po zakończeniu.', 'ai-faq-generator' ),
				(int) $aifaq_crawl['done'],
				(int) $aifaq_crawl['total']
			);
		}
		?>
		<p class="aifaq-crawl-note" id="aifaq-crawl-note" role="status" aria-live="polite"><?php echo esc_html( $aifaq_crawl_note ); ?></p>

		<div class="aifaq-index-report" id="aifaq-index-report" hidden></div>
	</div>

	<div class="aifaq-card">
		<h2><?php esc_html_e( 'Pytania gości', 'ai-faq-generator' ); ?></h2>
		<p><?php esc_html_e( 'Co się dzieje na publicznej stronie generatora. „Odmowy" to pytania spoza tematu Twojej strony — bramka tematu zadziałała. „Z cache" to powtórzone pytania, za które nie zapłaciłeś.', 'ai-faq-generator' ); ?></p>

		<?php
		$aifaq_tiles = array(
			array( 'n' => (string) $aifaq_qa['total'], 'l' => __( 'Wszystkich pytań', 'ai-faq-generator' ) ),
			array( 'n' => (string) $aifaq_qa['today'], 'l' => __( 'Dziś', 'ai-faq-generator' ) ),
			array( 'n' => (string) $aifaq_qa['week'], 'l' => __( 'Ostatnie 7 dni', 'ai-faq-generator' ) ),
			array( 'n' => (string) $aifaq_qa['refused'], 'l' => __( 'Odmów (poza tematem)', 'ai-faq-generator' ) ),
			array( 'n' => (string) $aifaq_qa['cached'], 'l' => __( 'Z cache (bez kosztu)', 'ai-faq-generator' ) ),
			array(
				'n' => $aifaq_qa['total'] ? number_format_i18n( $aifaq_qa['avg_score'], 2 ) : '–',
				'l' => __( 'Średnia trafność', 'ai-faq-generator' ),
			),
		);
		?>
		<div class="aifaq-tiles">
			<?php foreach ( $aifaq_tiles as $aifaq_tile ) : ?>
				<div class="aifaq-tile">
					<span class="aifaq-tile__n"><?php echo esc_html( $aifaq_tile['n'] ); ?></span>
					<span class="aifaq-tile__l"><?php echo esc_html( $aifaq_tile['l'] ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>

		<?php if ( $aifaq_qa['errors'] > 0 ) : ?>
			<p class="aifaq-tiles__note">
				<?php
				printf(
					/* translators: %s: liczba pytań zakończonych błędem */
					esc_html( _n(
						'Uwaga: %s pytanie zakończyło się błędem — sprawdź klucz API i szczegóły w Historii.',
						'Uwaga: %s pytań zakończyło się błędem — sprawdź klucz API i szczegóły w Historii.',
						$aifaq_qa['errors'],
						'ai-faq-generator'
					) ),
					'<strong>' . esc_html( number_format_i18n( $aifaq_qa['errors'] ) ) . '</strong>'
				);
				?>
			</p>
		<?php endif; ?>

		<p>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . \AIFAQ\Admin\Menu::SLUG_HISTORY ) ); ?>">
				<?php esc_html_e( 'Zobacz dziennik', 'ai-faq-generator' ); ?>
			</a>
		</p>
	</div>

	<div class="aifaq-card">
		<h2><?php esc_html_e( 'Generator FAQ', 'ai-faq-generator' ); ?></h2>
		<p>
			<?php esc_html_e( 'Przetestuj generator dokładnie tak, jak widzi go gość — na podstronie „Generator" w menu wtyczki (ten sam komponent co publiczna strona).', 'ai-faq-generator' ); ?>
		</p>
		<p>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . \AIFAQ\Admin\Menu::SLUG_GENERATOR ) ); ?>">
				<?php esc_html_e( 'Otwórz generator', 'ai-faq-generator' ); ?>
			</a>
			<a class="button" href="<?php echo esc_url( home_url( '/' . $aifaq_slug ) ); ?>" target="_blank" rel="noopener">
				<?php
				printf(
					/* translators: %s: slug szybkiej trasy generatora, np. „faqgenerator". */
					esc_html__( 'Otwórz szybki adres /%s ↗', 'ai-faq-generator' ),
					esc_html( $aifaq_slug )
				);
				?>
			</a>
			<?php if ( '' !== $aifaq_page_url ) : ?>
				<a class="button" href="<?php echo esc_url( $aifaq_page_url ); ?>" target="_blank" rel="noopener">
					<?php esc_html_e( 'Otwórz podstronę «Generator FAQ» ↗', 'ai-faq-generator' ); ?>
				</a>
			<?php endif; ?>
		</p>
	</div>
</div>
