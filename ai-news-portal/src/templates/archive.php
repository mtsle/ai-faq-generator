<?php
/**
 * Archiwum Centrum Wiedzy — etap 6.2.
 *
 * JEDEN plik obsluguje archiwum CPT i archiwum kategorii. Rozni je wylacznie
 * naglowek; lista kart, a od 6.3 i 6.4 takze wyszukiwarka i paginacja, sa
 * identyczne. Decyzja z etapu 6.0 — drugi, prawie taki sam plik znaczylby
 * dwa miejsca do poprawiania przy kazdej zmianie karty.
 *
 * Naglowek i stopke rysuje MOTYW (`get_header()` / `get_footer()`). Wtyczka
 * odpowiada wylacznie za to, co miedzy nimi.
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

<main class="ainp-portal" id="ainp-portal">

	<header class="ainp-portal__head">
		<h1 class="ainp-portal__title"><?php echo esc_html( Portal::archive_title() ); ?></h1>
	</header>

	<?php if ( have_posts() ) : ?>

		<div class="ainp-portal__list">
			<?php
			while ( have_posts() ) :
				the_post();
				Portal::part( 'card.php' );
			endwhile;
			?>
		</div>

	<?php else : ?>

		<p class="ainp-portal__empty">
			<?php esc_html_e( 'Nie ma tu jeszcze żadnych artykułów.', 'ai-news-portal' ); ?>
		</p>

	<?php endif; ?>

</main>

<?php
get_footer();
