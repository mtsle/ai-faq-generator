<?php
/**
 * Wejściowe pary Q&A warstwy REST — jedno miejsce na dwie RÓŻNE reguły przyjmowania.
 *
 * Wydzielone z {@see RestController} (Krok 23). Reguły celowo NIE są scalone:
 *  - {@see self::from_request()} bierze pary prosto z przeglądarki, więc sanityzuje
 *    (`sanitize_textarea_field` + `wp_unslash`), ale nie przycina długości —
 *    docelowy {@see Exporter::normalize()} robi to sam;
 *  - {@see self::from_snapshot()} czyta ZAPISANY `pairs_json` (dane już przeszły
 *    sanityzację przy zapisie), za to musi przyciąć długość, bo ścieżka publikacji
 *    nie przechodzi przez `Exporter::normalize()` — teraz WYWOŁUJE ją wprost,
 *    zamiast duplikować jej pętlę (K23 etap 1, znalezisko B9);
 *  - {@see self::from_request_for_publish()} to trzecia ścieżka (K23 etap 1,
 *    znalezisko A — było jej brak): pary z `pairs` w żądaniu `/admin/faq/publish`
 *    idą PROSTO na publiczną podstronę i do JSON-LD, więc muszą dostać SANITYZACJĘ
 *    jak `from_request()` ORAZ przycięcie/cap jak `from_snapshot()` — obie naraz.
 *
 * @package AI_FAQ_Generator
 */

namespace AIFAQ\Rest;

use AIFAQ\Faq\Exporter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalizacja list par {question, answer} przychodzących do tras REST.
 */
class PairsInput {

	/**
	 * Pary przysłane przez UI (eksport) — sanityzacja + klamp LICZBY par.
	 *
	 * Kontrola po stronie klienta ma temu tylko zapobiegać, więc walidujemy tutaj;
	 * samo formatowanie robi czysta klasa {@see Exporter}.
	 *
	 * @param mixed $raw Surowa wartość parametru `pairs`.
	 * @return array<int,array<string,string>>
	 */
	public static function from_request( $raw ): array {
		$pairs = array();

		if ( ! is_array( $raw ) ) {
			return $pairs;
		}

		foreach ( $raw as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$q = $item['question'] ?? '';
			$a = $item['answer'] ?? '';
			if ( ! is_scalar( $q ) || ! is_scalar( $a ) ) {
				continue;
			}

			$q = trim( sanitize_textarea_field( wp_unslash( (string) $q ) ) );
			$a = trim( sanitize_textarea_field( wp_unslash( (string) $a ) ) );
			if ( '' === $q || '' === $a ) {
				continue;
			}

			$pairs[] = array(
				'question' => $q,
				'answer'   => $a,
			);

			if ( count( $pairs ) >= Exporter::MAX_PAIRS ) {
				break;
			}
		}

		return $pairs;
	}

	/**
	 * Normalizuje snapshot par ze zdekodowanego `pairs_json` do listy {question, answer}.
	 *
	 * Snapshot to JSON sprzed wielu wersji — nie zakładamy nic o jego kształcie.
	 * Kontrakt (odrzucanie nie-tablic/nieskalarnych, trim, odrzucanie pustych,
	 * przycięcie długości, cap {@see Exporter::MAX_PAIRS}) jest IDENTYCZNY z
	 * {@see Exporter::normalize()} — dane ze snapshotu przeszły sanityzację przy
	 * ZAPISIE, więc tu nie sanityzujemy ponownie, tylko delegujemy do wspólnej pętli
	 * (K23 etap 1, znalezisko B9: był to dosłowny duplikat kod-w-kod).
	 *
	 * @param mixed $raw Zdekodowana zawartość `pairs_json`.
	 * @return array<int,array<string,string>>
	 */
	public static function from_snapshot( $raw ): array {
		return Exporter::normalize( is_array( $raw ) ? $raw : array() );
	}

	/**
	 * Pary przysłane z przeglądarki do PUBLIKACJI (`/admin/faq/publish`, parametr `pairs`).
	 *
	 * K23 etap 1, znalezisko A: ta ścieżka trafia PROSTO na publiczną podstronę i do
	 * JSON-LD FAQPage, więc — tak jak {@see self::from_request()} (eksport) — wymaga
	 * sanityzacji (`sanitize_textarea_field` + `wp_unslash`). W odróżnieniu od eksportu,
	 * dane tu NIE przechodzą później przez {@see Exporter::normalize()}, więc po
	 * sanityzacji dogrywamy TĘ SAMĄ metodę, żeby dostać przycięcie długości pola i cap
	 * liczby par — dokładnie to, co dotąd miał tylko `from_snapshot()`.
	 *
	 * @param mixed $raw Surowa wartość parametru `pairs`.
	 * @return array<int,array<string,string>>
	 */
	public static function from_request_for_publish( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$sanitized = array();

		foreach ( $raw as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$q = $item['question'] ?? '';
			$a = $item['answer'] ?? '';
			if ( ! is_scalar( $q ) || ! is_scalar( $a ) ) {
				continue;
			}

			$sanitized[] = array(
				'question' => sanitize_textarea_field( wp_unslash( (string) $q ) ),
				'answer'   => sanitize_textarea_field( wp_unslash( (string) $a ) ),
			);
		}

		// Trim/odrzucenie pustych/przycięcie długości/cap MAX_PAIRS — ta sama reguła
		// co snapshot, bo obie ścieżki kończą na tej samej publicznej podstronie.
		return Exporter::normalize( $sanitized );
	}
}
