<?php
/**
 * Pojedynczy artykul Centrum Wiedzy — etap 6.5.
 *
 * Naglowek i stopke rysuje MOTYW. Wtyczka odpowiada za kolumne tekstu,
 * metryczke (kategoria, data), ramke zrodla i powrot do archiwum.
 *
 * @package AI_News_Portal
 */

namespace AINP;

// Blokada bezposredniego wywolania pliku.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>

<main class="ainp-portal ainp-portal--single" id="ainp-portal">

	<?php
	while ( have_posts() ) :
		the_post();

		$ainp_id     = get_the_ID();
		$ainp_termin = Portal::primary_term( $ainp_id );
		$ainp_link   = ( null !== $ainp_termin ) ? Portal::term_link( $ainp_termin ) : '';
		$ainp_zrodlo = Portal::source_url( $ainp_id );
		?>

		<article <?php post_class( 'ainp-article' ); ?>>

			<a class="ainp-article__back" href="<?php echo esc_url( Portal::archive_link() ); ?>">
				<?php esc_html_e( '← Centrum Wiedzy', 'ai-news-portal' ); ?>
			</a>

			<h1 class="ainp-article__title"><?php the_title(); ?></h1>

			<div class="ainp-article__meta">
				<?php if ( null !== $ainp_termin ) : ?>
					<?php if ( '' !== $ainp_link ) : ?>
						<a class="ainp-article__cat" href="<?php echo esc_url( $ainp_link ); ?>">
							<?php echo esc_html( $ainp_termin->name ); ?>
						</a>
					<?php else : ?>
						<span class="ainp-article__cat"><?php echo esc_html( $ainp_termin->name ); ?></span>
					<?php endif; ?>
				<?php endif; ?>
				<time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>">
					<?php echo esc_html( get_the_date() ); ?>
				</time>
			</div>

			<div class="ainp-article__body">
				<?php the_content(); ?>
			</div>

			<?php if ( '' !== $ainp_zrodlo ) : ?>
				<aside class="ainp-article__source">
					<?php esc_html_e( 'Źródło:', 'ai-news-portal' ); ?>
					<?php
					/*
					 * `nofollow` — nie oddajemy sily linku za tekst, ktory
					 * przepisal model. `noopener` — otwarcie w tej samej
					 * karcie tego nie wymaga, ale link zostaje bezpieczny,
					 * gdy motyw albo wtyczka klienta dorobi mu `target`.
					 */
					?>
					<a href="<?php echo esc_url( $ainp_zrodlo ); ?>" rel="nofollow noopener">
						<?php echo esc_html( Portal::source_host( $ainp_zrodlo ) ); ?>
					</a>
				</aside>
			<?php endif; ?>

			<a class="ainp-article__back" href="<?php echo esc_url( Portal::archive_link() ); ?>">
				<?php esc_html_e( '← Wróć do Centrum Wiedzy', 'ai-news-portal' ); ?>
			</a>

		</article>

		<?php
	endwhile;
	?>

</main>

<?php
get_footer();
