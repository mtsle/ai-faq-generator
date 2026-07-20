<?php
/**
 * Komunikat o stanie bazy wektorów (migracja przestrzeni embeddingów, Krok 19).
 *
 * @package AI_FAQ_Generator
 */

namespace AIFAQ\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Komunikat „baza wiedzy jest policzona starą metodą" (hook `admin notices`).
 *
 * Po Kroku 19 embeddingi są liczone z `taskType` (M5), więc wektory zapisane starą
 * metodą opisują inną przestrzeń. Bez ponownego indeksowania bot po aktualizacji jest
 * GORSZY niż przed naprawą — a klient nie ma skąd o tym wiedzieć. Ta klasa to jedyne
 * miejsce, które mu o tym mówi, i podaje POWÓD, gdy przebieg został przerwany.
 *
 * DLACZEGO OSOBNA KLASA, A NIE ROZBUDOWA {@see PageNotice} (KONTRAKT k19-v3 §3.9) —
 * cztery powody, żeby następna sesja nie próbowała ich scalać:
 *
 * 1. `PageNotice::render()` jest z definicji JEDNOWIERSZOWA: jeden stan, jeden wiersz,
 *    jeden wypis. Drugi komunikat wymagałby przerobienia jej na pętlę, czyli dotknięcia
 *    kodu pokrytego 16 asercjami sekcji J testu Kroku 18.
 * 2. Zamknięcie komunikatu K18 trzyma w JEDNEJ opcji literał statusu podstrony —
 *    wspólna przestrzeń wyciszałaby jeden komunikat drugim.
 * 3. Akcja naprawcza K18 ma zamkniętą whitelistę (create|restore|publish|dismiss) pod
 *    jednym noncem; mieszanie domen łamie założenie kontraktu K18.
 * 4. Test K18 (asercja #54-bis) sprawdza, że wypis `PageNotice::render()` NIE zawiera
 *    linku zamykającego o zadanej treści. Drugi komunikat wypisany z tego samego
 *    `render()` i mający własny link zaczerwieniłby tę asercję.
 *
 * Klasa NIE zapisuje niczego do bazy i NIE MA zamykania, nonce'a ani akcji naprawczej
 * (§2.8, §3.9). Zamknięcie komunikatu to zmiana stanu wywołana żądaniem: bez nonce'a jest
 * podatne na CSRF, z nonce'em łamie zakaz „bez akcji". Produktowo gorzej — wyciszenie stanu
 * `stale` znaczy „nigdy więcej nie przypominaj", więc jeden odruchowy klik kasuje JEDYNE
 * przypomnienie o migracji i M5 nigdy się nie włącza. Oba stany znikają same po udanym
 * reindeksie i to jest właściwy mechanizm wygaszania.
 */
class IndexNotice {

	/**
	 * Wypisuje komunikat o stanie bazy wektorów (kokpit).
	 *
	 * Bramki skopiowane z {@see PageNotice::render()} — ten sam komplet i ta sama
	 * kolejność, łącznie z guardem `is_object()` na `get_current_screen()`, które
	 * zwraca `null`, dopóki nie ustawiono `$GLOBALS['current_screen']` (odczyt `->id`
	 * z nulla to ostrzeżenie PHP 8.2 wypisane wprost na górze kokpitu klienta).
	 */
	public static function render(): void {
		// 1. Komunikat jest dla właściciela witryny, nie dla redakcji.
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// 2. Tylko ekrany, na których komunikat ma sens.
		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}
		$screen = get_current_screen();

		if ( ! is_object( $screen ) || ! isset( $screen->id ) ) {
			return;
		}
		$sid = (string) $screen->id;
		if ( 'plugins' !== $sid && 'dashboard' !== $sid && false === strpos( $sid, 'ai-faq-generator' ) ) {
			return;
		}

		// 3. Stan bazy wektorów. Zgodność podpisów = cisza.
		$state = self::state();
		if ( 'ok' === $state ) {
			return;
		}

		// 4. Wypis. Powód jest OBOWIĄZKOWY: przy włączonym pobieraniu stron pierwszy
		// przebieg po aktualizacji jest z definicji niekompletny, więc bez powodu
		// właściciel klika w kółko, a każde kliknięcie płaci z dobowej puli żądań.
		$reason = self::reason();
		$texts  = array(
			'crawl'  => __( 'Pobieranie stron jeszcze trwa. Poczekaj na jego zakończenie, potem uruchom indeksowanie ponownie.', 'ai-faq-generator' ),
			'errors' => __( 'Część fragmentów nie została przeliczona (błędy dostawcy). Uruchom indeksowanie ponownie — policzone fragmenty zostaną pominięte.', 'ai-faq-generator' ),
			'budget' => __( 'Indeksowanie przerwano po wyczerpaniu budżetu czasu. Uruchom je ponownie, żeby dokończyć.', 'ai-faq-generator' ),
			''       => __( 'Baza wiedzy jest policzona starą metodą. Uruchom indeksowanie, żeby bot odpowiadał trafniej.', 'ai-faq-generator' ),
		);

		$msg   = isset( $texts[ $reason ] ) ? $texts[ $reason ] : $texts[''];
		$level = ( 'partial' === $state ) ? 'notice-info' : 'notice-warning';

		// `admin_url()` NIE ma atrapy w powierzchni mockowania (§8.2), a wypis MUSI
		// zawierać `page=ai-faq-generator`. Fallback daje ten ciąg także bez WordPressa.
		$url = function_exists( 'admin_url' )
			? admin_url( 'admin.php?page=ai-faq-generator' )
			: 'admin.php?page=ai-faq-generator';

		printf(
			'<div class="notice %1$s"><p><strong>AI FAQ Generator:</strong> %2$s</p><p><a class="button" href="%3$s">%4$s</a></p></div>',
			esc_attr( $level ),
			esc_html( $msg ),
			esc_url( $url ),
			esc_html__( 'Przejdź do Kokpitu', 'ai-faq-generator' )
		);
	}

	/**
	 * Stan bazy wektorów wobec bieżącej przestrzeni embeddingów.
	 *
	 * TABELA DECYZYJNA (§3.9) — pięć reguł, sprawdzanych W TEJ KOLEJNOŚCI:
	 *
	 * | # | warunek                                          | wynik     |
	 * |---|--------------------------------------------------|-----------|
	 * | 1 | zapisany podpis === bieżący                       | 'ok'      |
	 * | 2 | baza pusta (0 fragmentów z wektorem)              | 'ok'      |
	 * | 3 | znacznik `partial` na 3 segmenty, trzeci === now  | 'partial' |
	 * | 4 | zapisany podpis pusty                             | 'stale'   |
	 * | 5 | pozostałe (niepusty, niezgodny, nie-`partial:`)    | 'stale'   |
	 *
	 * Reguły 4 i 5 dają tę samą wartość i to jest CELOWE: rozdzielenie ich odwzorowuje
	 * tabelę kontraktu 1:1 i czyni jawnym, że „pusty podpis" jest rozpatrywany OSOBNO
	 * (w k19-v1 reguły te nakładały się i kontrakt nie mówił, która wygrywa).
	 *
	 * @return string `ok`|`stale`|`partial`
	 */
	public static function state(): string {
		$saved = function_exists( 'get_option' ) ? (string) get_option( 'aifaq_index_signature', '' ) : '';
		$now   = self::current_signature();

		// 1. Zapisany podpis zgadza sie z biezacym — baza jest policzona aktualna metoda.
		if ( $saved === $now ) {
			return 'ok';
		}

		// 2. Pusta baza — nie ma czego migrowac, nie strasz wlasciciela.
		if ( 0 === self::embedded_count() ) {
			return 'ok';
		}

		// 3. Przebieg przerwany: znacznik 'partial:<powod>:<podpis>'.
		// $saved = 'partial:<powod>:<podpis>' — DOKLADNIE trzy segmenty.
		// Podpis moze zawierac '|', NIGDY ':' (§3.8: provider|embed_model|768|src2|q:TASK
		// — uwaga: czlon 'q:' zawiera dwukropek, dlatego limit 3 w explode() jest
		// OBOWIAZKOWY, nie ozdobny: bez niego trzeci segment to sam TASK, porownanie
		// z $now jest zawsze falszywe i stan 'partial' jest NIEOSIAGALNY).
		$parts = explode( ':', $saved, 3 );
		if ( 3 === count( $parts ) && 'partial' === $parts[0] && $parts[2] === $now ) {
			return 'partial';
		}

		// 4. Brak podpisu przy niepustej bazie — stara metoda.
		if ( '' === $saved ) {
			return 'stale';
		}

		// 5. Podpis niepusty i niezgodny (albo znacznik partial o innym podpisie).
		return 'stale';
	}

	/**
	 * Powód przerwania ostatniego przebiegu indeksowania.
	 *
	 * Powód jest ŚRODKOWYM segmentem znacznika `partial:<powód>:<podpis>`, nie sufiksem
	 * — sufiksem jest podpis. Whitelista zamknięta; wartość spoza niej daje `''`, czyli
	 * tekst domyślny, a nie wymyślony czwarty.
	 *
	 * @return string ``|`crawl`|`errors`|`budget`
	 */
	public static function reason(): string {
		// reason() — SRODKOWY segment, nie sufiks; whitelista, inaczej ''.
		$saved = function_exists( 'get_option' ) ? (string) get_option( 'aifaq_index_signature', '' ) : '';
		$parts = explode( ':', $saved, 3 );
		if ( 3 !== count( $parts ) || 'partial' !== $parts[0] ) {
			return '';
		}
		return in_array( $parts[1], array( 'crawl', 'errors', 'budget' ), true ) ? $parts[1] : '';
	}

	/**
	 * Bieżący podpis przestrzeni embeddingów.
	 *
	 * Klasa kontrolera indeksu należy do innego etapu — jej brak nie ma prawa wywalić
	 * kokpitu, więc fallback jest pusty (wtedy o stanie decyduje reguła 2 tabeli).
	 */
	private static function current_signature(): string {
		if ( ! class_exists( '\AIFAQ\Admin\IndexController' ) ) {
			return '';
		}

		try {
			return (string) \AIFAQ\Admin\IndexController::index_signature();
		} catch ( \Throwable $e ) {
			unset( $e );
			return '';
		}
	}

	/**
	 * Liczba fragmentów z policzonym wektorem.
	 *
	 * `count_embedded()` jest metodą INSTANCYJNĄ i sięga do `$wpdb` — bez bazy rzuci,
	 * dlatego cały odczyt jest pod osłoną, a fallback `0` znaczy „nie ma czego migrować".
	 */
	private static function embedded_count(): int {
		if ( ! class_exists( '\AIFAQ\Data\KnowledgeRepository' ) ) {
			return 0;
		}

		try {
			return (int) ( new \AIFAQ\Data\KnowledgeRepository() )->count_embedded();
		} catch ( \Throwable $e ) {
			unset( $e );
			return 0;
		}
	}
}
