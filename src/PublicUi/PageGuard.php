<?php
/**
 * Stan podstrony „Generator FAQ" — diagnoza, auto-naprawa i naprawa ręczna.
 *
 * Do Kroku 18 tworzenie podstrony żyło w {@see Shortcode::ensure_page()} i miało
 * trzy wady, które razem dawały awarię NIE DO ODZYSKANIA: flaga „próba już była"
 * stawała PRZED próbą, błąd `wp_insert_post()` był połykany (zero logu, zero
 * komunikatu), a bramka fallbacku zamykała się na zawsze. Jedna nieudana próba
 * (np. chwilowy błąd bazy przy aktywacji) = strona nie powstanie NIGDY, a
 * właściciel nie ma jak się o tym dowiedzieć.
 *
 * Ta klasa rozdziela trzy rzeczy, które tam były zlepione:
 * 1. DIAGNOZĘ ({@see page_state()}) — czysta funkcja, zero zapisów, osiem stanów
 *    z przyczyną; tylko na niej opiera się komunikat w kokpicie;
 * 2. AUTO-NAPRAWĘ ({@see ensure()}) — z licznikiem prób, backoffem i zamkiem;
 * 3. NAPRAWĘ RĘCZNĄ ({@see repair()}) — świadome kliknięcie właściciela.
 *
 * Dwie zasady, które trzymają całość:
 * - **automat nigdy nie walczy z użytkownikiem**: podstrona usunięta trwale
 *   (`deleted_post`) dostaje stan {@see STATE_DELETED} i NIE jest odtwarzana;
 *   bez tego klient kasuje stronę, a ta wraca przy pierwszym żądaniu bota;
 * - **cicha porażka jest zakazana**: każda ścieżka, która nie utworzyła strony,
 *   zapisuje stan z przyczyną, a właściciel dostaje komunikat w kokpicie.
 *
 * Klasa działa w czystym PHP CLI (bez WordPressa) — każde wyjście poza nią jest
 * osłonięte `function_exists()`/`class_exists()`.
 *
 * @package AI_FAQ_Generator
 */

namespace AIFAQ\PublicUi;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Strażnik podstrony generatora.
 */
class PageGuard {

	/**
	 * Opcja ze stanem podstrony (tablica 6 kluczy, autoload `no`).
	 */
	public const OPTION = 'aifaq_page_state';

	/**
	 * Tania bramka frontu (autoload `yes`) — patrz {@see save_state()}.
	 *
	 * Nazwa mówi „ok", ale znaczenie jest szersze: `page_settled`, czyli „front
	 * nie ma tu nic do zrobienia". Nazwa stałej i opcji ZOSTAJE bez zmian, bo
	 * kasuje ją `uninstall.php` literałem (działa bez autoloadera).
	 */
	public const OK_FLAG = 'aifaq_page_ok';

	/**
	 * Opcja-zamek chroniąca przed równoległym tworzeniem strony.
	 */
	public const LOCK = 'aifaq_page_lock';

	/**
	 * Czas życia zamka (sekundy).
	 */
	public const LOCK_TTL = 30;

	/**
	 * Ile nieudanych prób automatycznych, zanim automat odpuści.
	 */
	public const MAX_TRIES = 3;

	/**
	 * Odstęp między automatycznymi ponowieniami (sekundy).
	 */
	public const RETRY_DELAY = 300;

	/**
	 * Strona istnieje, jest stroną, jest opublikowana i zawiera shortcode.
	 */
	public const STATE_OK = 'ok';

	/**
	 * Strona nigdy nie powstała, nie było ani jednej nieudanej próby.
	 */
	public const STATE_MISSING = 'missing';

	/**
	 * `wp_insert_post()` zawiodło co najmniej raz (`tries > 0`).
	 */
	public const STATE_FAILED = 'failed';

	/**
	 * Strona jest w koszu — klient przeniósł ją tam świadomie.
	 */
	public const STATE_TRASHED = 'trashed';

	/**
	 * Strona istnieje, ale nie jest opublikowana (draft/private/pending).
	 */
	public const STATE_NOT_PUBLIC = 'not_public';

	/**
	 * Nasza (znane ID), opublikowana, ale w treści nie ma już shortcode'u.
	 */
	public const STATE_NO_SHORTCODE = 'no_shortcode';

	/**
	 * Slug trasy zrównał się ze slugiem podstrony — reguła rewrite (priorytet
	 * `top`) przesłania nawet idealną stronę.
	 */
	public const STATE_SLUG_TAKEN = 'slug_taken';

	/**
	 * Użytkownik usunął podstronę TRWALE. Automat jej nie odtwarza.
	 */
	public const STATE_DELETED = 'deleted';

	/**
	 * Diagnozuje stan podstrony. CZYSTA FUNKCJA — nie zapisuje NICZEGO.
	 *
	 * Kolejność warunków jest wiążąca, wygrywa pierwszy spełniony. `tries`,
	 * `last` i `deleted` pochodzą z zapisanej opcji; `status` i `id` liczone są
	 * na świeżo. `error` bierze się z zapisanej opcji tylko wtedy, gdy bieżący
	 * przebieg nie ustawił własnego komunikatu.
	 *
	 * @return array{status:string,id:int,tries:int,last:int,error:string,deleted:int}
	 */
	public static function page_state(): array {
		$s = static::state();

		// Komunikat z opcji zostaje, dopóki ten przebieg nie ustawi własnego.
		$s['status'] = self::STATE_MISSING;
		$s['id']     = 0;

		if ( ! function_exists( 'get_option' ) ) {
			return $s; // Czyste PHP CLI — nie ma czego diagnozować.
		}

		$slug = self::page_slug();

		// 1. Kolizja slugu. Pierwszeństwo, bo regułę rewrite trasy rejestrujemy
		//    z priorytetem `top` — przesłania nawet idealnie zbudowaną stronę,
		//    więc jej stan przestaje mieć znaczenie.
		if ( self::route_slug() === $slug ) {
			$s['status'] = self::STATE_SLUG_TAKEN;
			$s['id']     = (int) get_option( 'aifaq_page_id', 0 );
			return $s;
		}

		$stored_id = (int) get_option( 'aifaq_page_id', 0 );
		$own       = null;

		if ( $stored_id > 0 && function_exists( 'get_post' ) ) {
			$post = get_post( $stored_id );

			// Nie nasz typ treści = traktujemy jak brak ID (idziemy do przejęcia).
			if ( $post instanceof \WP_Post && 'page' === $post->post_type ) {
				$own = $post;
			}
		}

		// Strona spod slugu jest potrzebna wyłącznie wtedy, gdy nie mamy własnej
		// — jedno zapytanie, i tylko w ścieżce, która naprawdę go potrzebuje.
		$found = ( null === $own ) ? self::find_by_slug( $slug ) : null;

		// 2. Świadome usunięcie. Trzy warunki naraz, bo użytkownik mógł podstronę
		//    odtworzyć własnoręcznie — wtedy ją ROZPOZNAJEMY (krok 4). Zasada
		//    „automat nie walczy z użytkownikiem" zabrania TWORZYĆ, nie zabrania
		//    rozpoznać decyzji użytkownika. Bez tego klient zostawał w `deleted`
		//    na zawsze, `aifaq_page_id` zostawało 0, a indekser wciągał interfejs
		//    generatora do bazy wiedzy RAG.
		if ( $s['deleted'] > 0 && null === $own && ! self::is_our_page( $found ) ) {
			$s['status'] = self::STATE_DELETED;
			$s['id']     = 0;
			return $s;
		}

		// 3. Znamy ID i wpis nadal jest stroną.
		if ( null !== $own ) {
			$s['id'] = $stored_id;

			if ( 'trash' === $own->post_status ) {
				$s['status'] = self::STATE_TRASHED;
				return $s;
			}

			if ( 'publish' !== $own->post_status ) {
				$s['status'] = self::STATE_NOT_PUBLIC;
				return $s;
			}

			// Brak shortcode'u znaczy „klient go skasował" WYŁĄCZNIE tutaj, przy
			// znanym, własnym ID. Ze ścieżki przejęcia (krok 4) ten stan jest
			// nieosiągalny — inaczej cudzy landing pod naszym slugiem dawał
			// właścicielowi nieusuwalny komunikat o CUDZEJ stronie.
			$s['status'] = self::has_our_shortcode( $own ) ? self::STATE_OK : self::STATE_NO_SHORTCODE;

			return $s;
		}

		// 4. Przejęcie po slugu — jedyny przypadek, w którym adoptujemy cudzy wpis
		//    jako swój: musi być opublikowaną STRONĄ i zawierać nasz shortcode.
		if ( $found instanceof \WP_Post ) {
			if ( 'page' === $found->post_type && 'publish' === $found->post_status && self::has_our_shortcode( $found ) ) {
				$s['status'] = self::STATE_OK;
				$s['id']     = (int) $found->ID;
				return $s;
			}

			// Slug zajmuje CUDZA treść. Nie przejmujemy jej (wycięlibyśmy realną
			// stronę klienta z bazy wiedzy RAG), ale mówimy wprost, co się stanie
			// z adresem — inaczej właściciel nie dowie się, skąd `-2` w adresie.
			if ( 'publish' === $found->post_status ) {
				$s['error'] = sprintf(
					/* translators: %d: ID istniejącej strony zajmującej slug. */
					__( 'Slug generator-faq zajmuje obca strona o ID %d — podstrona powstanie pod adresem generator-faq-2.', 'ai-faq-generator' ),
					(int) $found->ID
				);
			} else {
				$s['error'] = sprintf(
					/* translators: %d: ID nieopublikowanej strony zajmującej slug. */
					__( 'Slug generator-faq zajmuje nieopublikowana strona o ID %d.', 'ai-faq-generator' ),
					(int) $found->ID
				);
			}
		}

		// 5. Strony nie ma. Licznik prób rozstrzyga, czy to „jeszcze nie" czy „nie udało się".
		$s['status'] = ( $s['tries'] > 0 ) ? self::STATE_FAILED : self::STATE_MISSING;
		$s['id']     = 0;

		return $s;
	}

	/**
	 * Zwraca ZAPISANY stan — zawsze pełne 6 kluczy, zawsze typowane.
	 *
	 * Rekonstrukcja klucz po kluczu (a nie `array_merge`) jest celowa: opcja
	 * bywa niepełna po aktualizacji wtyczki i śmieciowa po ręcznej edycji bazy,
	 * a konsumenci (komunikat w kokpicie) mają prawo zakładać pełny kształt.
	 *
	 * @return array{status:string,id:int,tries:int,last:int,error:string,deleted:int}
	 */
	public static function state(): array {
		$stored = function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array();

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$status = (string) ( self::scalar( $stored, 'status' ) ?? self::STATE_MISSING );

		if ( ! in_array( $status, self::statuses(), true ) ) {
			$status = self::STATE_MISSING;
		}

		return array(
			'status'  => $status,
			'id'      => (int) ( self::scalar( $stored, 'id' ) ?? 0 ),
			'tries'   => (int) ( self::scalar( $stored, 'tries' ) ?? 0 ),
			'last'    => (int) ( self::scalar( $stored, 'last' ) ?? 0 ),
			'error'   => (string) ( self::scalar( $stored, 'error' ) ?? '' ),
			'deleted' => (int) ( self::scalar( $stored, 'deleted' ) ?? 0 ),
		);
	}

	/**
	 * Diagnoza + auto-naprawa. JEDYNE miejsce, w którym powstaje podstrona.
	 *
	 * Woła ją `init` (przez tanią bramkę {@see OK_FLAG}) i aktywacja wtyczki,
	 * więc każdy krok jest tak zbudowany, żeby nie kosztować nic w stanie
	 * ustalonym: tworzymy WYŁĄCZNIE przy `missing`/`failed`, najwyżej
	 * {@see MAX_TRIES} razy i nie częściej niż raz na {@see RETRY_DELAY}.
	 *
	 * @return array{status:string,id:int,tries:int,last:int,error:string,deleted:int}
	 */
	public static function ensure(): array {
		if ( ! function_exists( 'get_option' ) ) {
			return self::empty_state(); // Bez WordPressa nie tworzymy niczego.
		}

		$s = static::page_state();

		// 2. Świadome usunięcie — NIGDY nie odtwarzamy automatycznie. Jedyna droga
		//    powrotu prowadzi przez `repair( 'create' )`, czyli kliknięcie właściciela.
		if ( self::STATE_DELETED === $s['status'] ) {
			static::save_state( $s );
			return $s;
		}

		// 2a. Cokolwiek poza missing/failed znaczy, że strona ISTNIEJE (choćby w koszu
		//     albo bez shortcode'u) — to sprawa dla właściciela, nie dla automatu.
		if ( ! self::is_creatable( $s['status'] ) ) {
			$s = self::persist_ok( $s );
			static::save_state( $s );
			return $s;
		}

		// 3. Auto-stop: po MAX_TRIES przestajemy próbować, żeby nie dobijać witryny
		//    przy trwałej awarii (np. brak uprawnień do zapisu w bazie).
		if ( $s['tries'] >= self::MAX_TRIES ) {
			$s['status'] = self::STATE_FAILED;
			static::save_state( $s );
			return $s;
		}

		// 4. Backoff — bez niego nieudana próba powtarzałaby się na KAŻDYM żądaniu.
		if ( $s['last'] > 0 && ( time() - $s['last'] ) < self::RETRY_DELAY ) {
			return $s;
		}

		// 5. Zamek — inny proces już pracuje.
		if ( ! static::acquire_lock() ) {
			return $s;
		}

		try {
			// 6. DOUBLE-CHECK pod zamkiem: między diagnozą a zamkiem mógł minąć
			//    ułamek sekundy, w którym stronę utworzył ktoś inny.
			$s = static::page_state();

			if ( ! self::is_creatable( $s['status'] ) ) {
				$s = self::persist_ok( $s );
				static::save_state( $s );
				return $s;
			}

			// 7. Tworzenie.
			$id = wp_insert_post( self::insert_args(), true );

			// 8. Porażka. REGUŁA BEZWZGLĘDNA: żadne wyjście z kroków 7–8b, które nie
			//    kończy się sukcesem, nie ma prawa wyjść bez backoffu — inaczej
			//    `ensure()` zamienia się w pętlę na każdym żądaniu witryny.
			if ( is_wp_error( $id ) || ! $id ) {
				$s['status'] = self::STATE_FAILED;
				$s['tries']  = $s['tries'] + 1;
				$s['last']   = time();
				$s['error']  = is_wp_error( $id )
					? (string) $id->get_error_message()
					: __( 'WordPress nie zwrócił ID nowej podstrony generatora.', 'ai-faq-generator' );

				static::save_state( $s );
				return $s;
			}

			$new_id = (int) $id;
			$post   = function_exists( 'get_post' ) ? get_post( $new_id ) : null;
			$moved  = ( $post instanceof \WP_Post && self::page_slug() !== (string) $post->post_name );

			// 8b. Idempotencja. Inny slug niż nasz znaczy, że WordPress dokleił sufiks
			//     — ale to NIE dowód przegranego wyścigu: `wp_unique_post_slug()` robi
			//     to samo, gdy pod adresem siedzi cudza strona albo czyjś szkic.
			//     Kasujemy WYŁĄCZNIE wtedy, gdy pod slugiem stoi NASZA (shortcode!)
			//     opublikowana strona o innym ID. Warunek bez tych członów oznaczałby
			//     u każdego klienta z własną treścią pod `/generator-faq/`: tworzenie
			//     i twarde kasowanie strony na każdym żądaniu frontu, setki wpisów
			//     dziennie w `auto_increment` i stan wiszący wiecznie na `missing`.
			if ( $moved ) {
				$rival = self::find_by_slug( self::page_slug() );

				if ( $rival instanceof \WP_Post
					&& 'page' === $rival->post_type
					&& 'publish' === $rival->post_status
					&& (int) $rival->ID !== $new_id
					&& self::has_our_shortcode( $rival ) ) {
					wp_delete_post( $new_id, true );
					return static::refresh();
				}
			}

			// 9. Sukces. Przy doklejonym sufiksie zachowujemy komunikat z kroku 4
			//    diagnozy — to jedyne wyjaśnienie, skąd u klienta adres `-2`.
			$carry = $moved ? (string) $s['error'] : '';

			update_option( 'aifaq_page_id', $new_id );
			update_option( self::bootstrap_option(), '1' );

			$s            = static::page_state();
			$s['tries']   = 0;
			$s['last']    = time();
			$s['error']   = $carry;
			$s['deleted'] = 0;

			static::save_state( $s );

			return $s;
		} finally {
			// Zamek zwalniamy w KAŻDEJ ścieżce wyjścia — także przez wyjątek.
			static::release_lock();
		}
	}

	/**
	 * Przelicza i zapisuje stan. NIGDY nie tworzy strony.
	 *
	 * Wersja dla kokpitu (`admin_init`) i dla zdarzeń kosza: właściciel ma
	 * widzieć prawdę o stanie, ale samo wejście do kokpitu nie jest zgodą na
	 * tworzenie treści na jego witrynie.
	 *
	 * @return array{status:string,id:int,tries:int,last:int,error:string,deleted:int}
	 */
	public static function refresh(): array {
		if ( ! function_exists( 'get_option' ) ) {
			return self::empty_state();
		}

		$s = self::persist_ok( static::page_state() );

		static::save_state( $s );

		return $s;
	}

	/**
	 * Naprawa RĘCZNA — świadome kliknięcie właściciela w komunikacie.
	 *
	 * Metoda broni się SAMA, niezależnie od wołającego: sprawdza uprawnienie i
	 * upewnia się, że wpis nadal jest naszą podstroną. Bez drugiej kontroli
	 * wtyczka oferowałaby przycisk publikujący na żywej witrynie nieskończony
	 * szkic klienta, który powstał z dawnej podstrony.
	 *
	 * @param string $action `create` | `restore` | `publish`.
	 * @return array{status:string,id:int,tries:int,last:int,error:string,deleted:int}
	 */
	public static function repair( string $action ): array {
		// Brak funkcji = czyste CLI/WP-CLI, nie ma czego chronić.
		if ( function_exists( 'current_user_can' ) && ! current_user_can( 'manage_options' ) ) {
			return static::state();
		}

		if ( ! function_exists( 'get_option' ) ) {
			return self::empty_state();
		}

		$s = static::page_state();

		if ( 'restore' === $action || 'publish' === $action ) {
			$id   = (int) $s['id'];
			$post = ( $id > 0 && function_exists( 'get_post' ) ) ? get_post( $id ) : null;

			// Wpis przestał być naszą podstroną (inny typ, skasowany, wyczyszczona
			// treść) — odczepiamy się od niego i NIE ruszamy go.
			if ( ! $post instanceof \WP_Post || 'page' !== $post->post_type || ! self::has_our_shortcode( $post ) ) {
				update_option( 'aifaq_page_id', 0 );
				return static::refresh();
			}
		}

		$u = null;

		if ( 'create' === $action ) {
			if ( self::is_creatable( $s['status'] ) || self::STATE_DELETED === $s['status'] ) {
				// Zerujemy licznik prób ORAZ znacznik świadomego usunięcia —
				// to kliknięcie jest zgodą właściciela na odtworzenie strony.
				$s['tries']   = 0;
				$s['last']    = 0;
				$s['deleted'] = 0;

				static::save_state( $s );

				return static::ensure();
			}

			return static::refresh();
		}

		if ( 'restore' === $action && self::STATE_TRASHED === $s['status'] && $s['id'] > 0 ) {
			$r = wp_untrash_post( (int) $s['id'] );

			if ( ! $r instanceof \WP_Post ) {
				$s['error'] = __( 'Nie udało się przywrócić strony z kosza.', 'ai-faq-generator' );
				static::save_state( $s );
				return $s;
			}

			// `wp_untrash_post()` przywraca do SZKICU, a przy przenoszeniu do kosza
			// WordPress dokleja do sluga `__trashed`. Bez jawnego przestawienia obu
			// pól stan raportowałby `ok`, a adres byłby inny niż ten w komunikacie.
			$u = wp_update_post(
				array(
					'ID'          => (int) $s['id'],
					'post_status' => 'publish',
					'post_name'   => self::page_slug(),
				),
				true
			);
		} elseif ( 'publish' === $action && self::STATE_NOT_PUBLIC === $s['status'] && $s['id'] > 0 ) {
			$u = wp_update_post(
				array(
					'ID'          => (int) $s['id'],
					'post_status' => 'publish',
				),
				true
			);
		}

		// Porzucenie wyniku `wp_untrash_post()`/`wp_update_post()` jest zakazane:
		// właściciel musi zobaczyć, dlaczego naprawa nie zadziałała.
		if ( is_wp_error( $u ) ) {
			$s          = static::refresh();
			$s['error'] = (string) $u->get_error_message();

			static::save_state( $s );

			return $s;
		}

		return static::refresh();
	}

	/**
	 * Adres podstrony albo pusty ciąg.
	 *
	 * Adres bierzemy WYŁĄCZNIE z `get_permalink()`. Sklejenie go ze sluga byłoby
	 * kłamstwem wszędzie tam, gdzie WordPress dokleił sufiks (`generator-faq-2`)
	 * — a to dokładnie te witryny, na których właściciel szuka strony najdłużej.
	 *
	 * @return string
	 */
	public static function page_url(): string {
		$id = (int) static::state()['id'];

		if ( $id < 1 || ! function_exists( 'get_permalink' ) ) {
			return '';
		}

		$url = get_permalink( $id );

		return is_string( $url ) ? $url : '';
	}

	/**
	 * Reakcja na przeniesienie podstrony do kosza i na jej przywrócenie.
	 *
	 * Nic nie tworzy — tylko przelicza stan, żeby właściciel od razu zobaczył
	 * komunikat z przyciskiem „Przywróć".
	 *
	 * @param int|mixed $post_id ID wpisu, którego dotyczy zdarzenie.
	 */
	public static function on_post_event( $post_id ): void {
		if ( ! function_exists( 'get_option' ) ) {
			return;
		}

		if ( (int) $post_id !== (int) get_option( 'aifaq_page_id', 0 ) ) {
			return;
		}

		$s = static::refresh();

		// Powrót z kosza znosi znacznik świadomego usunięcia. Jeden callback
		// obsługuje oba zdarzenia, więc rozstrzyga STAN wpisu, nie nazwa filtra.
		if ( $s['deleted'] > 0 && self::STATE_TRASHED !== $s['status'] ) {
			$s['deleted'] = 0;
			static::save_state( $s );
		}
	}

	/**
	 * Reakcja na TRWAŁE usunięcie podstrony (opróżnienie kosza, „Usuń trwale").
	 *
	 * Osobny callback jest JEDYNYM sposobem, żeby odróżnić „w koszu" od „usunięte
	 * trwale": samo przeliczenie stanu zapisałoby `missing`, a wtedy pierwsze
	 * żądanie kogokolwiek (także bota i `wp-cron.php`) odtworzyłoby stronę, którą
	 * klient przed chwilą skasował. Wariant cichszy: strona leży w koszu 30 dni,
	 * rdzeniowy `wp_scheduled_delete` kasuje ją trwale, a ona zmartwychwstaje
	 * miesiąc później, bez żadnego działania klienta.
	 *
	 * @param int|mixed $post_id ID usuniętego wpisu.
	 */
	public static function on_post_deleted( $post_id ): void {
		if ( ! function_exists( 'get_option' ) ) {
			return;
		}

		if ( (int) $post_id !== (int) get_option( 'aifaq_page_id', 0 ) ) {
			return;
		}

		// Martwe ID musi zniknąć: czyta je literałem `WpContentSource::is_own_page()`,
		// więc zostawione wskazywałoby na nieistniejący wpis, a po ręcznym odtworzeniu
		// podstrony indekser wciągnąłby interfejs generatora do bazy wiedzy RAG.
		update_option( 'aifaq_page_id', 0 );

		$s            = static::refresh();
		$s['status']  = self::STATE_DELETED;
		$s['id']      = 0;
		$s['deleted'] = time();

		static::save_state( $s );
	}

	/**
	 * Zajmuje zamek w sposób możliwie atomowy.
	 *
	 * Świadomie NIE `get_transient()` + `set_transient()`: to klasyczny TOCTOU —
	 * dwa procesy odczytują „brak zamka" w tej samej milisekundzie i oba ruszają.
	 * UWAGA: `add_option()` też NIE jest w pełni atomowe — rdzeń WP robi najpierw
	 * nieatomowy odczyt, a potem `INSERT … ON DUPLICATE KEY UPDATE`, więc przy
	 * równoległym zapisie obaj pisarze mogą dostać wartość prawdziwą; UNIQUE na
	 * `option_name` niczego nie odrzuca. Atomowa jest wyłącznie ścieżka
	 * `wp_cache_add()` przy trwałym cache obiektowym. Dlatego zamek jest PIERWSZĄ,
	 * a nie jedyną linią obrony: po nim idzie DOUBLE-CHECK ({@see ensure()} krok 6),
	 * a po insercie kontrola idempotencji ({@see ensure()} krok 8b), która kasuje
	 * stronę utworzoną jako `generator-faq-2` przez przegranego w wyścigu.
	 *
	 * Wartością zamka jest ZNACZNIK CZASU, nie `1` — bez niego nie da się przejąć
	 * zamka po procesie, który padł w połowie.
	 *
	 * @return bool Czy zamek został zajęty.
	 */
	protected static function acquire_lock(): bool {
		if ( ! function_exists( 'add_option' ) || ! function_exists( 'get_option' ) ) {
			return true; // Czyste PHP CLI — brak współbieżności do pilnowania.
		}

		$now = time();

		// Szybka (i jedyna naprawdę atomowa) ścieżka: trwały cache obiektowy.
		if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() && function_exists( 'wp_cache_add' ) ) {
			if ( ! wp_cache_add( self::LOCK, $now, 'aifaq', self::LOCK_TTL ) ) {
				return false;
			}
		}

		if ( add_option( self::LOCK, (string) $now, '', 'no' ) ) {
			return true;
		}

		$held = (int) get_option( self::LOCK, 0 );

		if ( $held > 0 && ( $now - $held ) < self::LOCK_TTL ) {
			return false;
		}

		// Zamek przeterminowany (proces padł w połowie) — przejmujemy go.
		if ( function_exists( 'delete_option' ) ) {
			delete_option( self::LOCK );
		}

		return (bool) add_option( self::LOCK, (string) $now, '', 'no' );
	}

	/**
	 * Zwalnia zamek — oba nośniki, każdy pod własnym `function_exists()`.
	 */
	protected static function release_lock(): void {
		if ( function_exists( 'delete_option' ) ) {
			delete_option( self::LOCK );
		}

		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( self::LOCK, 'aifaq' );
		}
	}

	/**
	 * Zapisuje stan i przestawia tanią bramkę frontu.
	 *
	 * Bramka {@see OK_FLAG} jest TRÓJSTANOWA i znaczy „front nie ma tu nic do
	 * zrobienia" (`page_settled`), a nie „jest ok":
	 * - `'1'` — strona jest, wszystko gra;
	 * - `'0'` — stan TERMINALNY (kosz, szkic, brak shortcode'u, kolizja slugu,
	 *   świadome usunięcie): front i tak nie ma prawa tego naprawić, więc nie
	 *   płaci za pełną diagnozę na każdym żądaniu gościa, bota i `wp-cron.php`;
	 * - `''` — `missing`/`failed`, czyli JEST co naprawiać: automat próbuje dalej.
	 *
	 * Opcja stanu jest zakładana przez `add_option( …, 'no' )`, bo samo
	 * `update_option()` na nieistniejącej opcji ustawia autoload `yes` — a tej
	 * tablicy nie wolno wozić przy każdym żądaniu.
	 *
	 * @param array<string,mixed> $state Pełny stan (6 kluczy).
	 */
	protected static function save_state( array $state ): void {
		if ( ! function_exists( 'update_option' ) ) {
			return;
		}

		if ( function_exists( 'add_option' ) ) {
			add_option( self::OPTION, array(), '', 'no' );
		}

		update_option( self::OPTION, $state, false );

		$status  = (string) ( $state['status'] ?? '' );
		$settled = array(
			self::STATE_DELETED,
			self::STATE_TRASHED,
			self::STATE_NOT_PUBLIC,
			self::STATE_NO_SHORTCODE,
			self::STATE_SLUG_TAKEN,
		);

		if ( self::STATE_OK === $status ) {
			$flag = '1';
		} elseif ( in_array( $status, $settled, true ) ) {
			$flag = '0';
		} else {
			$flag = '';
		}

		update_option( self::OK_FLAG, $flag, true );
	}

	/**
	 * Utrwala skutki stanu `ok`: ID podstrony i zdjęcie znacznika usunięcia.
	 *
	 * Dzisiejszy kod robi to jawnie przy przejęciu strony po slugu i musi to
	 * robić dalej: `uninstall.php` kasuje `aifaq_page_id`, a strona zostaje, więc
	 * po reinstalacji opcja byłaby `0` na zawsze — `WpContentSource::is_own_page()`
	 * przestałaby rozpoznawać własną podstronę i indekser wciągnąłby interfejs
	 * generatora do bazy wiedzy RAG jako wiedzę o witrynie. Warunek `ok` gwarantuje,
	 * że nigdy nie zapiszemy tam ID cudzej strony (co wycięłoby treść klienta).
	 *
	 * @param array<string,mixed> $s Stan z {@see page_state()}.
	 * @return array<string,mixed>
	 */
	private static function persist_ok( array $s ): array {
		if ( self::STATE_OK !== $s['status'] ) {
			return $s;
		}

		// Strona JEST — świadome usunięcie przestało obowiązywać (np. właściciel
		// odtworzył ją własnoręcznie albo z kopii zapasowej).
		$s['deleted'] = 0;

		if ( $s['id'] > 0 && function_exists( 'get_option' ) && (int) get_option( 'aifaq_page_id', 0 ) !== (int) $s['id'] ) {
			update_option( 'aifaq_page_id', (int) $s['id'] ); // INT — czyta ją literałem indekser.
			update_option( self::bootstrap_option(), '1' );
		}

		return $s;
	}

	/**
	 * Argumenty tworzonej podstrony (identyczne z dotychczasowymi).
	 *
	 * @return array<string,mixed>
	 */
	private static function insert_args(): array {
		$args = array(
			'post_title'     => self::page_title(),
			'post_name'      => self::page_slug(),
			'post_content'   => '<!-- wp:shortcode -->[' . self::shortcode_tag() . ']<!-- /wp:shortcode -->',
			'post_status'    => 'publish',
			'post_type'      => 'page',
			'comment_status' => 'closed',
			'ping_status'    => 'closed',
		);

		// Autor MUSI być deterministyczny. `ensure()` biega na `init`, więc
		// „bieżący użytkownik" to równie dobrze pierwszy lepszy zalogowany
		// Subskrybent, który wszedł na witrynę po utracie podstrony: jego login
		// wyciekłby przez `/author/`, a na witrynach z wtyczką do ról dostałby
		// realne prawo edycji i skasowania kluczowej strony produktu.
		$author = 0;

		if ( function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ) && function_exists( 'get_current_user_id' ) ) {
			$author = (int) get_current_user_id();
		}

		if ( $author < 1 && function_exists( 'get_users' ) ) {
			$admins = get_users(
				array(
					'role'    => 'administrator',
					'number'  => 1,
					'fields'  => 'ID',
					'orderby' => 'ID',
					'order'   => 'ASC',
				)
			);

			$author = ( is_array( $admins ) && isset( $admins[0] ) ) ? (int) $admins[0] : 0;
		}

		if ( $author > 0 ) {
			$args['post_author'] = $author;
		}

		return $args;
	}

	/**
	 * Czy w treści wpisu jest nasz generator.
	 *
	 * Jedno źródło prawdy dla wszystkich ścieżek. Świadomie prostsze od
	 * rdzeniowego wykrywacza shortcode'ów: zwykły `strpos` nie wymaga shimowania
	 * funkcji, której nie ma w żadnym dzisiejszym teście.
	 *
	 * Dwa fallbacki są obowiązkowe, bo bez nich klient z page builderem dostaje
	 * WIECZNY i nieusuwalny komunikat „z podstrony zniknął shortcode":
	 * 1. blok wielokrotnego użytku — jego treść mieszka w osobnym wpisie `wp_block`
	 *    i z `post_content` jej nie widać;
	 * 2. Elementor i spółka trzymają układ strony w postmeta, nie w treści.
	 *
	 * @param mixed $post Kandydat na naszą podstronę.
	 * @return bool
	 */
	private static function has_our_shortcode( $post ): bool {
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		$tag     = self::shortcode_tag();
		$content = (string) $post->post_content;

		if ( false !== strpos( $content, '[' . $tag ) ) {
			return true;
		}

		if ( false !== strpos( $content, '<!-- wp:block' ) ) {
			return true;
		}

		if ( function_exists( 'get_post_meta' ) ) {
			$data = get_post_meta( (int) $post->ID, '_elementor_data', true );

			if ( is_string( $data ) && '' !== $data && false !== strpos( $data, $tag ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Czy to opublikowana strona z naszym generatorem (kandydat do przejęcia).
	 *
	 * @param mixed $post Kandydat.
	 * @return bool
	 */
	private static function is_our_page( $post ): bool {
		return ( $post instanceof \WP_Post
			&& 'page' === $post->post_type
			&& 'publish' === $post->post_status
			&& self::has_our_shortcode( $post ) );
	}

	/**
	 * Strona spod naszego sluga albo `null`.
	 *
	 * @param string $slug Slug podstrony.
	 * @return \WP_Post|null
	 */
	private static function find_by_slug( string $slug ) {
		if ( ! function_exists( 'get_page_by_path' ) ) {
			return null;
		}

		$found = get_page_by_path( $slug );

		return ( $found instanceof \WP_Post ) ? $found : null;
	}

	/**
	 * Czy z tego stanu wolno automatowi tworzyć stronę.
	 *
	 * @param string $status Status stanu.
	 * @return bool
	 */
	private static function is_creatable( string $status ): bool {
		return ( self::STATE_MISSING === $status || self::STATE_FAILED === $status );
	}

	/**
	 * Slug publicznej trasy generatora (do wykrycia kolizji).
	 *
	 * @return string
	 */
	private static function route_slug(): string {
		if ( class_exists( '\AIFAQ\Core\Router' ) ) {
			try {
				return (string) \AIFAQ\Core\Router::slug();
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		$raw = 'faqgenerator';

		if ( class_exists( '\AIFAQ\Core\Settings' ) ) {
			try {
				$raw = (string) \AIFAQ\Core\Settings::get_field( 'page_slug', 'faqgenerator' );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		return function_exists( 'sanitize_title' ) ? (string) sanitize_title( $raw ) : $raw;
	}

	/**
	 * Slug podstrony generatora.
	 *
	 * @return string
	 */
	private static function page_slug(): string {
		return class_exists( '\AIFAQ\PublicUi\Shortcode' ) ? (string) Shortcode::PAGE_SLUG : 'generator-faq';
	}

	/**
	 * Znacznik shortcode'u generatora.
	 *
	 * @return string
	 */
	private static function shortcode_tag(): string {
		return class_exists( '\AIFAQ\PublicUi\Shortcode' ) ? (string) Shortcode::TAG : 'aifaq_generator';
	}

	/**
	 * Nazwa opcji „próba utworzenia strony już była" (zgodność wstecz + odinstalowanie).
	 *
	 * @return string
	 */
	private static function bootstrap_option(): string {
		return class_exists( '\AIFAQ\PublicUi\Shortcode' ) ? (string) Shortcode::BOOTSTRAP_OPTION : 'aifaq_page_bootstrapped';
	}

	/**
	 * Tytuł tworzonej podstrony (w języku interfejsu).
	 *
	 * @return string
	 */
	private static function page_title(): string {
		if ( class_exists( '\AIFAQ\PublicUi\GeneratorPage' ) && method_exists( '\AIFAQ\PublicUi\GeneratorPage', 'page_title' ) ) {
			try {
				$title = (string) GeneratorPage::page_title();

				if ( '' !== $title ) {
					return $title;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		return 'Generator FAQ';
	}

	/**
	 * Osiem dopuszczalnych statusów.
	 *
	 * @return array<int,string>
	 */
	private static function statuses(): array {
		return array(
			self::STATE_OK,
			self::STATE_MISSING,
			self::STATE_FAILED,
			self::STATE_TRASHED,
			self::STATE_NOT_PUBLIC,
			self::STATE_NO_SHORTCODE,
			self::STATE_SLUG_TAKEN,
			self::STATE_DELETED,
		);
	}

	/**
	 * Stan pusty — używany, gdy nie ma WordPressa.
	 *
	 * @return array{status:string,id:int,tries:int,last:int,error:string,deleted:int}
	 */
	private static function empty_state(): array {
		return array(
			'status'  => self::STATE_MISSING,
			'id'      => 0,
			'tries'   => 0,
			'last'    => 0,
			'error'   => '',
			'deleted' => 0,
		);
	}

	/**
	 * Skalar spod klucza albo `null` — chroni rzutowania przed tablicą w opcji.
	 *
	 * @param array<string,mixed> $stored Zapisana tablica.
	 * @param string              $key    Klucz.
	 * @return scalar|null
	 */
	private static function scalar( array $stored, string $key ) {
		return ( isset( $stored[ $key ] ) && is_scalar( $stored[ $key ] ) ) ? $stored[ $key ] : null;
	}
}
