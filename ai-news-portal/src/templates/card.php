<?php
/**
 * Karta artykulu na liscie Centrum Wiedzy — etap 6.2.
 *
 * Wolana z `archive.php` w petli, dla ustawionego wpisu. Nic nie zapytuje
 * i nic nie liczy: wszystkie decyzje (zdjecie kategorii albo kafelek, adres
 * kategorii) zapadaja w `Portal`, tutaj zostaje samo rysowanie.
 *
 * Motyw moze podmienic ten plik, kladac wlasny w `ai-news-portal/card.php`
 * — patrz `Portal::part()`.
 *
 * @package AI_News_Portal
 */

namespace AINP;

// Blokada bezposredniego wywolania pliku.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ainp_termin = Portal::primary_term( get_the_ID() );
$ainp_nazwa  = ( null !== $ainp_termin ) ? $ainp_termin->name : '';
$ainp_link   = ( null !== $ainp_termin ) ? Portal::term_link( $ainp_termin ) : '';
$ainp_foto   = ( null !== $ainp_termin ) ? Portal::category_image_url( $ainp_termin->slug ) : '';
?>
<article <?php post_class( 'ainp-card' ); ?>>

	<a class="ainp-card__shot" href="<?php the_permalink(); ?>" tabindex="-1" aria-hidden="true">
		<?php if ( '' !== $ainp_foto ) : ?>
			<?php
			/*
			 * `alt` jest PUSTY celowo. Zdjecie jest ilustracja kategorii, tej
			 * samej dla wszystkich artykulow z tej kategorii — nie niesie
			 * zadnej tresci, ktorej nie ma obok w tekscie. Opis w rodzaju
			 * „zdjecie kategorii Zywienie" czytnik ekranu powtorzylby przy
			 * kazdej karcie, zagluszajac tytuly. Puste `alt` na obrazku
			 * dekoracyjnym to zalecenie WCAG, nie przeoczenie.
			 */
			?>
			<img src="<?php echo esc_url( $ainp_foto ); ?>" alt="" loading="lazy" decoding="async"
				width="1200" height="675">
		<?php else : ?>
			<span class="ainp-card__tile" aria-hidden="true"><?php echo esc_html( Portal::initial( $ainp_nazwa ) ); ?></span>
		<?php endif; ?>
	</a>

	<div class="ainp-card__body">
		<?php if ( '' !== $ainp_nazwa ) : ?>
			<?php if ( '' !== $ainp_link ) : ?>
				<a class="ainp-card__kicker" href="<?php echo esc_url( $ainp_link ); ?>"><?php echo esc_html( $ainp_nazwa ); ?></a>
			<?php else : ?>
				<span class="ainp-card__kicker"><?php echo esc_html( $ainp_nazwa ); ?></span>
			<?php endif; ?>
		<?php endif; ?>

		<h2 class="ainp-card__title">
			<a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
		</h2>

		<?php $ainp_lead = Portal::lead( get_the_ID() ); ?>
		<?php if ( '' !== $ainp_lead ) : ?>
			<p class="ainp-card__lead"><?php echo esc_html( $ainp_lead ); ?></p>
		<?php endif; ?>

		<time class="ainp-card__date" datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>">
			<?php echo esc_html( get_the_date() ); ?>
		</time>
	</div>

</article>
