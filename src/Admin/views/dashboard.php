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

		<p class="aifaq-index-actions">
			<button type="button" class="button button-primary" id="aifaq-reindex">
				<?php esc_html_e( 'Zaindeksuj treść', 'ai-faq-generator' ); ?>
			</button>
			<button type="button" class="button" id="aifaq-clear">
				<?php esc_html_e( 'Wyczyść bazę', 'ai-faq-generator' ); ?>
			</button>
			<span class="aifaq-index-status" id="aifaq-index-status" role="status" aria-live="polite"></span>
		</p>

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
			<a class="button" href="<?php echo esc_url( home_url( '/' . ltrim( (string) \AIFAQ\Core\Settings::get_field( 'page_slug', 'faqgenerator' ), '/' ) ) ); ?>" target="_blank" rel="noopener">
				<?php esc_html_e( 'Zobacz publiczną stronę ↗', 'ai-faq-generator' ); ?>
			</a>
		</p>
	</div>
</div>
