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

		<?php
		/*
		 * FORMULARZ GET, zero JavaScriptu. `action` celuje w archiwum, a nie
		 * w biezacy adres: szukanie z poziomu kategorii ma przeszukiwac CALE
		 * Centrum Wiedzy, nie zawezac wyniku do kategorii, w ktorej akurat
		 * stoimy. Inaczej pusty wynik wygladalby jak brak artykulu w portalu.
		 */
		?>
		<form class="ainp-portal__search" role="search" method="get"
			action="<?php echo esc_url( Portal::archive_link() ); ?>">
			<label class="screen-reader-text" for="ainp-search">
				<?php esc_html_e( 'Szukaj w Centrum Wiedzy', 'ai-news-portal' ); ?>
			</label>
			<input type="search" id="ainp-search" name="<?php echo esc_attr( Portal::SEARCH_VAR ); ?>"
				value="<?php echo esc_attr( Portal::search_term() ); ?>"
				placeholder="<?php esc_attr_e( 'np. karma dla szczeniaka', 'ai-news-portal' ); ?>">
			<button type="submit"><?php esc_html_e( 'Szukaj', 'ai-news-portal' ); ?></button>
		</form>

		<?php $ainp_kategorie = Portal::categories(); ?>
		<?php if ( array() !== $ainp_kategorie ) : ?>
			<nav class="ainp-portal__cats" aria-label="<?php esc_attr_e( 'Kategorie', 'ai-news-portal' ); ?>">
				<?php $ainp_biezaca = Portal::current_term_slug(); ?>
				<a class="ainp-chip<?php echo ( '' === $ainp_biezaca ) ? ' is-current' : ''; ?>"
					href="<?php echo esc_url( Portal::archive_link() ); ?>"
					<?php echo ( '' === $ainp_biezaca ) ? ' aria-current="page"' : ''; ?>>
					<?php esc_html_e( 'Wszystkie', 'ai-news-portal' ); ?>
				</a>
				<?php foreach ( $ainp_kategorie as $ainp_termin ) : ?>
					<?php
					$ainp_adres = Portal::term_link( $ainp_termin );
					if ( '' === $ainp_adres ) {
						continue;
					}
					$ainp_tu = ( $ainp_biezaca === (string) $ainp_termin->slug );
					?>
					<a class="ainp-chip<?php echo $ainp_tu ? ' is-current' : ''; ?>"
						href="<?php echo esc_url( $ainp_adres ); ?>"
						<?php echo $ainp_tu ? ' aria-current="page"' : ''; ?>>
						<?php echo esc_html( $ainp_termin->name ); ?>
					</a>
				<?php endforeach; ?>
			</nav>
		<?php endif; ?>
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

		<?php $ainp_strony = Portal::pagination(); ?>
		<?php if ( '' !== $ainp_strony ) : ?>
			<nav class="ainp-portal__pager" aria-label="<?php esc_attr_e( 'Strony artykułów', 'ai-news-portal' ); ?>">
				<?php
				/*
				 * `paginate_links()` oddaje gotowe znaczniki `<a>` i `<span>`
				 * zbudowane przez WordPressa z adresow, ktore sam przepuscil
				 * przez `esc_url()`. Powtorne escapowanie zamieniloby je
				 * w widoczny tekst — dlatego `wp_kses_post()`, a nie
				 * `esc_html()`.
				 */
				echo wp_kses_post( $ainp_strony );
				?>
			</nav>
		<?php endif; ?>

	<?php else : ?>

		<?php if ( '' !== Portal::search_term() ) : ?>
			<p class="ainp-portal__empty">
				<?php esc_html_e( 'Nic nie pasuje do tego zapytania. Spróbuj innego słowa albo zajrzyj do kategorii powyżej.', 'ai-news-portal' ); ?>
			</p>
		<?php else : ?>
			<p class="ainp-portal__empty">
				<?php esc_html_e( 'Nie ma tu jeszcze żadnych artykułów.', 'ai-news-portal' ); ?>
			</p>
		<?php endif; ?>

	<?php endif; ?>

</main>

<?php
get_footer();
