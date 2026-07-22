<?php
/**
 * Stan pozycji „Generator FAQ" w menu nawigacji — diagnoza, auto-naprawa, sprzątanie.
 *
 * Do Kroku 20 wtyczka NIE dotykała menu w ogóle (grep `nav_menu|wp_update_nav_menu_item|
 * get_nav_menu_locations` w całym `src/` → 0 trafień), więc gość nie miał JAK trafić
 * do `/generator-faq/`: trasa jest wirtualna, podstrona nie ląduje w żadnym menu sama z siebie.
 *
 * Klasa jest zbudowana 1:1 wg wzorca {@see PageGuard} z Kroku 18 i rozdziela te same trzy rzeczy:
 * 1. DIAGNOZĘ ({@see menu_state()}) — czysta funkcja, ZERO zapisów, dziesięć stanów z przyczyną;
 * 2. AUTO-NAPRAWĘ ({@see ensure()}) — licznik prób, backoff, zamek;
 * 3. NAPRAWĘ RĘCZNĄ ({@see repair()}) — świadome kliknięcie właściciela w komunikacie.
 *
 * Cztery zasady, które trzymają całość:
 * - **NIGDY nie tworzymy menu i nigdy go nie przypinamy do lokalizacji motywu.** Dopisujemy
 *   wyłącznie do menu, które motyw ma już przypięte. Motyw klienta (Dworek) renderuje nawigację przez
 *   `wp_nav_menu( …, fallback_cb )`; przypięcie czegokolwiek do `primary` gasi fallback i kasuje
 *   9 pozycji nawigacji klienta wraz z CTA. Brak przypiętego menu = stan `no_menu` i TYLKO
 *   komunikat (rysuje go `MenuNotice`).
 * - **Flaga PO próbie, nigdy PRZED** — dosłownie wada `Shortcode.php:165` naprawiona w K18.
 * - **Automat nie walczy z użytkownikiem**: pozycja usunięta ręką klienta dostaje stan
 *   {@see STATE_REMOVED_BY_USER} i nie wraca ani po odświeżeniu, ani po reaktywacji wtyczki
 *   (trwały znacznik {@see OPTOUT}).
 * - **Cudzej treści nie kasujemy**: pozycję dodaną ręką klienta ADOPTUJEMY (`owned = '0'`),
 *   ale przy deaktywacji zostawiamy w spokoju. Kasujemy wyłącznie własną (`owned = '1'`).
 *
 * Klasa działa w czystym PHP CLI (bez WordPressa) — każde wyjście poza nią jest osłonięte
 * `function_exists()`/`class_exists()`.
 *
 * @package AI_FAQ_Generator
 */

namespace AIFAQ\PublicUi;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Strażnik pozycji generatora w menu nawigacji.
 */
class MenuGuard {

	/**
	 * Opcja ze stanem pozycji (tablica 9 kluczy, autoload `no`).
	 *
	 * PRZEŻYWA deaktywację — to na niej opiera się obietnica „klient usunął link ręcznie,
	 * więc nie wraca ani po reaktywacji".
	 */
	public const OPTION = 'aifaq_menu_state';

	/**
	 * Tania bramka kokpitu (autoload `yes`) — patrz {@see save_state()}.
	 */
	public const OK_FLAG = 'aifaq_menu_ok';

	/**
	 * Opcja z ID naszej pozycji w menu.
	 */
	public const ITEM_OPTION = 'aifaq_menu_item_id';

	/**
	 * Opcja-zamek chroniąca przed równoległym tworzeniem pozycji.
	 */
	public const LOCK = 'aifaq_menu_lock';

	/**
	 * Trwały znacznik „klient świadomie usunął pozycję, nie odtwarzaj".
	 */
	public const OPTOUT = 'aifaq_menu_optout';

	/**
	 * Czas życia zamka (sekundy).
	 */
	public const LOCK_TTL = 30;

	/**
	 * Ile nieudanych prób automatycznych, zanim automat odpuści.
	 */
	public const MAX_TRIES = 3;

	/**
	 * Odstęp między automatycznymi ponowieniami (sekundy) — 6 h.
	 *
	 * Dłuższy niż w {@see PageGuard} (5 min), bo brak pozycji w menu nie psuje niczego
	 * po stronie serwera: to brak wygody, nie awaria. Ponawianie co pięć minut przez trzy
	 * próby zamknęłoby sprawę w kwadrans, zanim właściciel zdąży przeczytać komunikat.
	 */
	public const RETRY_DELAY = 21600;

	/**
	 * Lokalizacje menu, do których wolno wstrzyknąć pozycję — W TEJ KOLEJNOŚCI.
	 *
	 * Dopasowanie po fragmencie klucza (`stripos`), nigdy „pierwsza z brzegu": u realnego
	 * klienta pierwszą lokalizacją z przypiętym menu bywa `social` albo `footer`, a tekstowy
	 * link w stopce/pasku ikon psuje wygląd strony oddanej klientowi — czyli efekt gorszy
	 * niż dzisiejszy brak linku.
	 */
	public const LOCATION_PREFERENCE = array( 'primary', 'main', 'header', 'menu-1', 'top' );

	/**
	 * Pozycja istnieje w menu przypiętym do wybranej lokalizacji i wskazuje podstronę.
	 */
	public const STATE_OK = 'ok';

	/**
	 * Pozycji nie ma i nigdy nie było (zero nieudanych prób).
	 */
	public const STATE_MISSING = 'missing';

	/**
	 * Motyw rejestruje lokalizacje, ale ŻADNE menu nie jest do nich przypięte.
	 * NIETERMINALNY: klient może menu utworzyć w każdej chwili.
	 */
	public const STATE_NO_MENU = 'no_menu';

	/**
	 * Motyw nie rejestruje żadnej lokalizacji ALBO jest motywem blokowym.
	 * NIETERMINALNY: klient może zmienić motyw.
	 */
	public const STATE_NO_LOCATION = 'no_location';

	/**
	 * Pozycja istnieje, ale w menu INNYM niż aktualnie przypięte do wybranej lokalizacji.
	 */
	public const STATE_MENU_CHANGED = 'menu_changed';

	/**
	 * Próba utworzenia pozycji zawiodła.
	 */
	public const STATE_FAILED = 'failed';

	/**
	 * Właściciel wyłączył link w ustawieniach (`menu_link_enabled = '0'`).
	 */
	public const STATE_DISABLED = 'disabled';

	/**
	 * Brak podstrony generatora albo podstrona nie jest opublikowana.
	 *
	 * TERMINALNY (bramka `'0'`): podstrona w koszu leży domyślnie 30 dni
	 * (`EMPTY_TRASH_DAYS`), a przez ten czas pełna diagnoza przy każdym `admin_init`
	 * byłaby czystą stratą. Odblokowuje ją {@see maybe_ensure()} po `aifaq_page_ok = '1'`.
	 */
	public const STATE_PAGE_MISSING = 'page_missing';

	/**
	 * Klient usunął pozycję ręcznie. Terminalny i jednokierunkowy — wyjście wyłącznie
	 * przez {@see repair()} z akcją `create`.
	 */
	public const STATE_REMOVED_BY_USER = 'removed_by_user';

	/**
	 * Są menu, ale żadna lokalizacja z przypiętym menu nie pasuje do listy preferencji.
	 * Nie zgadujemy — decyzję oddajemy właścicielowi (pole „Lokalizacja menu", E3).
	 */
	public const STATE_LOCATION_AMBIGUOUS = 'location_ambiguous';

	/**
	 * Diagnozuje stan pozycji w menu. CZYSTA FUNKCJA — nie zapisuje NICZEGO.
	 *
	 * Kolejność warunków jest WIĄŻĄCA, wygrywa pierwszy spełniony:
	 * 1. `disabled` — właściciel wyłączył link (opcja autoloadowana, zero zapytań);
	 * 2. `no_location` — motyw blokowy albo brak zarejestrowanych lokalizacji;
	 * 3. `no_menu` / `location_ambiguous` — wybór lokalizacji (`theme_mods`, autoload);
	 * 4. `page_missing` — brak celu dla linku;
	 * 5. stan samej pozycji (`ok` / `menu_changed` / `removed_by_user` / `failed` / `missing`).
	 *
	 * Kroki 1–3 czytają WYŁĄCZNIE opcje autoloadowane, więc wyjście na `no_menu`
	 * i `no_location` kosztuje ZERO zapytań SQL. To jedyny powód, dla którego te dwa stany
	 * mogą mieć otwartą bramkę i być sprawdzane przy każdym wejściu do kokpitu.
	 *
	 * `state`, `item_id`, `owned`, `location` i `menu_id` liczone są NA ŻYWO;
	 * `tries`, `last` i `error` pochodzą z zapisanej opcji.
	 *
	 * @return array{state:string,item_id:int,owned:string,location:string,menu_id:int,label:string,tries:int,last:int,error:string}
	 */
	public static function menu_state(): array {
		$s     = static::state();
		$saved = $s['state']; // Zapamiętany stan — rozstrzyga wyłącznie tam, gdzie żywych faktów brak.

		$s['state'] = self::STATE_MISSING;
		$s['label'] = self::label();

		// Bez WordPressa nie ma czego diagnozować — czyste PHP CLI.
		if ( ! function_exists( 'get_option' ) ) {
			return $s;
		}

		// 1. Przełącznik właściciela. Bije wszystko: pozycja ZOSTAJE w menu (kasuje ją
		//    wyłącznie deaktywacja), ale automat przestaje się nią interesować.
		if ( '1' !== self::setting( 'menu_link_enabled', '1' ) ) {
			$s['state'] = self::STATE_DISABLED;
			return $s;
		}

		// 2. Reguła zerowa — motyw blokowy. Motywy HYBRYDOWE rejestrują lokalizacje menu,
		//    ale nawigację renderują blokiem `core/navigation`, który klasycznych menu nie
		//    czyta. Bez tej reguły klient dostałby fałszywy sukces („link dodany") zamiast
		//    komunikatu, a w nawigacji nie zobaczyłby niczego. Obsługa `wp_navigation`
		//    jest jawnie poza zakresem Kroku 20.
		if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
			$s['state'] = self::STATE_NO_LOCATION;
			return $s;
		}

		if ( ! function_exists( 'get_registered_nav_menus' ) || ! function_exists( 'get_nav_menu_locations' ) ) {
			$s['state'] = self::STATE_NO_LOCATION;
			return $s;
		}

		$registered = get_registered_nav_menus();

		if ( ! is_array( $registered ) || array() === $registered ) {
			$s['state'] = self::STATE_NO_LOCATION;
			return $s;
		}

		// 3. Wybór lokalizacji i menu do niej przypiętego.
		$assigned = get_nav_menu_locations();
		$assigned = is_array( $assigned ) ? $assigned : array();
		$wanted   = self::setting( 'menu_location', '' );

		if ( '' !== $wanted ) {
			// Wybór klienta jest wiążący — NIE podmieniamy go po cichu na inną lokalizację,
			// bo to jego decyzja o wyglądzie własnej witryny.
			$s['location'] = $wanted;
			$s['menu_id']  = isset( $assigned[ $wanted ] ) ? (int) $assigned[ $wanted ] : 0;

			if ( $s['menu_id'] < 1 ) {
				$s['state'] = self::STATE_NO_MENU;
				return $s;
			}
		} else {
			$match = self::pick_location( $assigned );

			if ( '' === $match ) {
				// Rozróżnienie kosztuje jedną pętlę, a dla właściciela znaczy dwie różne
				// rzeczy: „utwórz menu" kontra „wskaż, do której lokalizacji je wstawić".
				$s['state'] = self::has_any_menu( $assigned )
					? self::STATE_LOCATION_AMBIGUOUS
					: self::STATE_NO_MENU;

				return $s;
			}

			$s['location'] = $match;
			$s['menu_id']  = (int) $assigned[ $match ];
		}

		// 4. Cel linku. Podstrona w koszu NIE psuje nawigacji gościa: rdzeń nie kasuje
		//    pozycji, tylko oznacza ją `_invalid` i odfiltrowuje na froncie — gość nie
		//    widzi ani linku, ani 404. Pozycja zostaje widoczna wyłącznie w Wygląd → Menu.
		$page_id = self::page_id();

		if ( $page_id < 1 ) {
			$s['state'] = self::STATE_PAGE_MISSING;
			return $s;
		}

		// 5. Stan samej pozycji.
		$items  = self::menu_items( $s['menu_id'] );
		$stored = (int) ( function_exists( 'get_option' ) ? get_option( self::ITEM_OPTION, 0 ) : 0 );

		// 5a. Nasza zapisana pozycja nadal wisi w docelowym menu — najprostszy przypadek.
		if ( $stored > 0 && self::in_items( $items, $stored ) ) {
			$s['state']   = self::STATE_OK;
			$s['item_id'] = $stored;
			$s['owned']   = self::stored_owned();

			return $s;
		}

		// 5b. IDEMPOTENCJA I PROWENIENCJA. W docelowym menu jest już pozycja wskazująca
		//     naszą podstronę — przyjmujemy ją za swoją, ale znaczymy `owned = '0'`.
		//     Bez tego rozróżnienia wtyczka zaadoptowałaby link dodany RĘKĄ KLIENTA
		//     (dziś jedyny możliwy, bo v0.22.0 nie tworzy żadnego) i skasowała go przy
		//     pierwszej deaktywacji — utrata cudzej treści.
		//     Ta gałąź bije też zapisany `removed_by_user`: żywa pozycja jest faktem,
		//     zapisany stan tylko wspomnieniem.
		$adopted = self::find_item_for_page( $items, $page_id );

		if ( $adopted > 0 ) {
			$s['state']   = self::STATE_OK;
			$s['item_id'] = $adopted;
			$s['owned']   = '0';

			return $s;
		}

		// 5c. Znamy ID, ale pozycji nie ma w docelowym menu. DWA ROZŁĄCZNE WARUNKI.
		if ( $stored > 0 ) {
			$post = function_exists( 'get_post' ) ? get_post( $stored ) : null;

			if ( ! $post instanceof \WP_Post ) {
				// Wpisu nie ma w bazie — klient usunął pozycję. Terminalne i jednokierunkowe.
				$s['state']   = self::STATE_REMOVED_BY_USER;
				$s['item_id'] = 0;

				return $s;
			}

			if ( 'nav_menu_item' === $post->post_type ) {
				// Wpis żyje, ale w INNYM menu — klient przepiął nawigację na nowe menu.
				// To NIE jest usunięcie: `ensure()` dołoży pozycję do menu aktualnego.
				$s['state']   = self::STATE_MENU_CHANGED;
				$s['item_id'] = $stored;
				$s['owned']   = self::stored_owned();

				return $s;
			}

			// Zapisane ID wskazuje coś, co nie jest pozycją menu (ręczna edycja bazy,
			// kolizja po imporcie). Odczepiamy się od niego i traktujemy jak brak pozycji.
		}

		// 5d. Pozycji nie ma. Znacznik świadomego usunięcia i zapisany stan rozstrzygają,
		//     czy wolno próbować.
		if ( '1' === (string) get_option( self::OPTOUT, '' ) || self::STATE_REMOVED_BY_USER === $saved ) {
			$s['state']   = self::STATE_REMOVED_BY_USER;
			$s['item_id'] = 0;

			return $s;
		}

		$s['state']   = ( $s['tries'] > 0 ) ? self::STATE_FAILED : self::STATE_MISSING;
		$s['item_id'] = 0;
		$s['owned']   = '';

		return $s;
	}

	/**
	 * Zwraca ZAPISANY stan — zawsze pełne 9 kluczy, zawsze typowane.
	 *
	 * Rekonstrukcja klucz po kluczu (a nie `array_merge`) jest celowa: opcja bywa niepełna
	 * po aktualizacji wtyczki i śmieciowa po ręcznej edycji bazy, a konsumenci (komunikat
	 * w kokpicie) mają prawo zakładać pełny kształt.
	 *
	 * @return array{state:string,item_id:int,owned:string,location:string,menu_id:int,label:string,tries:int,last:int,error:string}
	 */
	public static function state(): array {
		$stored = function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array();

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$state = (string) ( self::scalar( $stored, 'state' ) ?? self::STATE_MISSING );

		if ( ! in_array( $state, self::states(), true ) ) {
			$state = self::STATE_MISSING;
		}

		return array(
			'state'    => $state,
			'item_id'  => (int) ( self::scalar( $stored, 'item_id' ) ?? 0 ),
			'owned'    => self::owned_value( self::scalar( $stored, 'owned' ) ),
			'location' => (string) ( self::scalar( $stored, 'location' ) ?? '' ),
			'menu_id'  => (int) ( self::scalar( $stored, 'menu_id' ) ?? 0 ),
			'label'    => (string) ( self::scalar( $stored, 'label' ) ?? '' ),
			'tries'    => (int) ( self::scalar( $stored, 'tries' ) ?? 0 ),
			'last'     => (int) ( self::scalar( $stored, 'last' ) ?? 0 ),
			'error'    => (string) ( self::scalar( $stored, 'error' ) ?? '' ),
		);
	}

	/**
	 * Diagnoza + auto-naprawa. JEDYNE miejsce, w którym powstaje pozycja w menu.
	 *
	 * Wołają ją aktywacja wtyczki, {@see maybe_ensure()} (przez tanią bramkę) i naprawa
	 * ręczna. Każdy krok jest tak zbudowany, żeby nie kosztować nic w stanie ustalonym:
	 * tworzymy WYŁĄCZNIE przy `missing`/`failed`/`menu_changed`, najwyżej {@see MAX_TRIES}
	 * razy i nie częściej niż raz na {@see RETRY_DELAY}.
	 *
	 * @return array{state:string,item_id:int,owned:string,location:string,menu_id:int,label:string,tries:int,last:int,error:string}
	 */
	public static function ensure(): array {
		if ( ! function_exists( 'get_option' ) ) {
			return self::empty_state(); // Bez WordPressa nie tworzymy niczego.
		}

		$s = static::menu_state();

		// Automat NIGDY nie walczy z użytkownikiem. Znacznik zdejmuje wyłącznie
		// `repair( 'create' )`, czyli świadome kliknięcie właściciela.
		if ( '1' === (string) get_option( self::OPTOUT, '' ) ) {
			if ( self::STATE_OK !== $s['state'] ) {
				$s['state'] = self::STATE_REMOVED_BY_USER;
			}

			static::save_state( $s );

			return $s;
		}

		// Cokolwiek poza listą „da się utworzyć" znaczy, że albo pozycja jest, albo
		// nie mamy dokąd jej wstawić. W obu razach to sprawa dla właściciela, nie dla automatu.
		if ( ! self::is_creatable( $s['state'] ) ) {
			static::save_state( $s );
			return $s;
		}

		// Auto-stop: po MAX_TRIES przestajemy próbować, żeby nie dobijać witryny przy
		// trwałej awarii (np. brak uprawnień do zapisu w bazie).
		if ( $s['tries'] >= self::MAX_TRIES ) {
			$s['state'] = self::STATE_FAILED;
			static::save_state( $s );

			return $s;
		}

		// Backoff — bez niego NIEUDANA próba powtarzałaby się przy każdym wejściu do kokpitu.
		// Warunek `tries > 0` jest istotny: backoff należy wyłącznie do stanu `failed`.
		// Bez niego `menu_changed` (klient przepiął nawigację na nowe menu tuż po udanym
		// utworzeniu pozycji) czekałby na odwieszenie sześć godzin, mimo że nic nie padło
		// — `last` po sukcesie też jest znacznikiem ostatniej próby.
		if ( $s['tries'] > 0 && $s['last'] > 0 && ( time() - $s['last'] ) < self::RETRY_DELAY ) {
			static::save_state( $s );
			return $s;
		}

		if ( ! function_exists( 'wp_update_nav_menu_item' ) ) {
			$s['state'] = self::STATE_FAILED;
			$s['tries'] = $s['tries'] + 1;
			$s['last']  = time();
			$s['error'] = self::tr( 'Brak funkcji WordPressa do zarządzania menu.' );

			static::save_state( $s );

			return $s;
		}

		// Zamek — inny proces już pracuje.
		if ( ! static::acquire_lock() ) {
			return $s;
		}

		try {
			// DOUBLE-CHECK pod zamkiem: między diagnozą a zamkiem mógł minąć ułamek
			// sekundy, w którym pozycję utworzył ktoś inny.
			$s = static::menu_state();

			if ( ! self::is_creatable( $s['state'] ) ) {
				static::save_state( $s );
				return $s;
			}

			// PRÓBA. Flaga i licznik idą PO niej, nigdy przed — odwrotna kolejność to
			// dosłownie wada `Shortcode.php:165`, przez którą jedna nieudana próba
			// zamykała sprawę na zawsze, a właściciel nie miał jak się o tym dowiedzieć.
			$item = wp_update_nav_menu_item(
				(int) $s['menu_id'],
				0,
				array(
					'menu-item-object-id' => self::page_id(),
					'menu-item-object'    => 'page',
					'menu-item-type'      => 'post_type',
					'menu-item-title'     => $s['label'],
					// OBOWIĄZKOWE. Rdzeń tworzy nową pozycję domyślnie jako `draft`,
					// a `wp_get_nav_menu_items()` filtruje po `post_status = 'publish'`.
					// Bez tego `ensure()` zwróciłby sukces, `menu_state()` raportowałby
					// `ok`, kontrola idempotencji NIE zobaczyłaby pozycji (tworzyłaby
					// kolejne przy każdym przebiegu), a gość nie widziałby żadnego linku.
					'menu-item-status'    => 'publish',
				)
			);

			$is_error = function_exists( 'is_wp_error' ) && is_wp_error( $item );

			if ( $is_error || (int) $item < 1 ) {
				$s['state'] = self::STATE_FAILED;
				$s['tries'] = $s['tries'] + 1;
				$s['last']  = time();
				$s['error'] = $is_error
					? (string) $item->get_error_message()
					: self::tr( 'WordPress nie zwrócił ID nowej pozycji menu.' );

				static::save_state( $s );

				return $s;
			}

			// Sukces. ID zapisujemy PRZED przeliczeniem stanu, żeby diagnoza zobaczyła
			// świeżą pozycję; `owned` nadpisujemy jawnie, bo w opcji stanu leży jeszcze
			// wartość sprzed próby.
			update_option( self::ITEM_OPTION, (int) $item );

			$s          = static::menu_state();
			$s['owned'] = '1';
			$s['tries'] = 0;
			$s['last']  = time();
			$s['error'] = '';

			static::save_state( $s );

			return $s;
		} finally {
			// Zamek zwalniamy w KAŻDEJ ścieżce wyjścia — także przez wyjątek.
			static::release_lock();
		}
	}

	/**
	 * Tania bramka wołana przy każdym wejściu do kokpitu (hook rejestruje `Plugin`).
	 *
	 * Bramki wejściowe są OBOWIĄZKOWE i muszą stać na samym początku: `admin_init` odpala
	 * się także w `admin-ajax.php` — dla KAŻDEGO żądania z parametrem `action`, również
	 * niezalogowanego — oraz w `admin-post.php`. Bez nich dowolny gość uderzający
	 * w `/wp-admin/admin-ajax.php?action=cokolwiek` (albo heartbeat każdego Subskrybenta
	 * co 15 s) uruchamiałby pełną diagnozę I ZAPIS do nawigacji witryny.
	 */
	public static function maybe_ensure(): void {
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return;
		}

		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return;
		}

		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! function_exists( 'get_option' ) ) {
			return;
		}

		$flag = (string) get_option( self::OK_FLAG, '' );

		if ( '1' === $flag ) {
			return;
		}

		if ( '0' === $flag ) {
			// Jedyne wyjście ze stanu terminalnego bez udziału właściciela: podstrona
			// wróciła (PageGuard zapalił własną bramkę), więc `page_missing` przestało
			// obowiązywać. Bez tego link nie powstałby nigdy po przywróceniu strony z kosza.
			if ( self::STATE_PAGE_MISSING !== static::state()['state'] || '1' !== (string) get_option( 'aifaq_page_ok', '' ) ) {
				return;
			}

			update_option( self::OK_FLAG, '', true );
		}

		static::ensure();
	}

	/**
	 * Naprawa RĘCZNA — świadome kliknięcie właściciela w komunikacie.
	 *
	 * Metoda broni się SAMA, niezależnie od wołającego (handler `admin_post_*` sprawdza
	 * nonce i uprawnienie po swojej stronie — to druga linia, nie jedyna).
	 *
	 * @param string $action `create` | `dismiss` — whitelista ZAMKNIĘTA.
	 * @return array<string,mixed>
	 */
	public static function repair( string $action ): array {
		// Nieznana akcja: ZERO zapisów, jawny błąd. Kształt jest celowo minimalny —
		// to nie jest diagnoza stanu menu, tylko odpowiedź „nie znam takiej akcji".
		if ( 'create' !== $action && 'dismiss' !== $action ) {
			return array(
				'state' => self::STATE_FAILED,
				'error' => 'unknown_action',
			);
		}

		// Brak funkcji = czyste CLI/WP-CLI, nie ma czego chronić.
		if ( function_exists( 'current_user_can' ) && ! current_user_can( 'manage_options' ) ) {
			return static::state();
		}

		if ( ! function_exists( 'get_option' ) ) {
			return self::empty_state();
		}

		// `dismiss` NIE ZAPISUJE NICZEGO. Wyciszenie komunikatu należy w całości do
		// `MenuNotice` — strażnik menu nie ma prawa gubić informacji o stanie nawigacji
		// tylko dlatego, że ktoś zamknął żółty pasek.
		if ( 'dismiss' === $action ) {
			return static::menu_state();
		}

		// `create` — świadoma zgoda właściciela. Zdejmuje znacznik świadomego usunięcia,
		// zeruje licznik prób i backoff, po czym idzie normalną ścieżką `ensure()`.
		if ( function_exists( 'delete_option' ) ) {
			delete_option( self::OPTOUT );
		}

		// WISZĄCY WSKAŹNIK. `aifaq_menu_item_id` trzymające ID wpisu, którego już nie ma
		// w bazie, jest JEDYNĄ przesłanką gałęzi 5c w `menu_state()` — a ta zwraca
		// `removed_by_user` PRZED gałęzią 5d, więc zdjęcie samego znacznika OPTOUT nie
		// wystarcza. Bez tego skasowania `repair( 'create' )` odbija się od stanu, z którego
		// §3.3 pkt 3 czyni go JEDYNYM wyjściem: klient, który raz usunie link ręcznie, nie
		// odzyskuje go ani komunikatem, ani reaktywacją (znalezisko O-108, testy w ciemno E9a).
		// Kasujemy WYŁĄCZNIE wskaźnik martwy — żywej pozycji (stan `menu_changed`) nie tykamy,
		// bo jej ID jest nadal prawdziwe.
		if ( function_exists( 'delete_option' ) ) {
			$stored = (int) get_option( self::ITEM_OPTION, 0 );

			if ( $stored > 0 ) {
				$post = function_exists( 'get_post' ) ? get_post( $stored ) : null;

				if ( ! $post instanceof \WP_Post ) {
					delete_option( self::ITEM_OPTION );
				}
			}
		}

		$s          = static::state();
		$s['tries'] = 0;
		$s['last']  = 0;
		$s['error'] = '';

		if ( self::STATE_REMOVED_BY_USER === $s['state'] ) {
			$s['state'] = self::STATE_MISSING;
		}

		static::save_state( $s );

		return static::ensure();
	}

	/**
	 * Usuwa NASZĄ pozycję z menu. Woła to WYŁĄCZNIE {@see \AIFAQ\Core\Deactivator}.
	 *
	 * Deaktywacja kasuje pozycję ZAWSZE, bez porównywania etykiety i rodzica — ale
	 * „zawsze" dotyczy pozycji, KTÓRĄ WTYCZKA SAMA UTWORZYŁA (`owned = '1'`). Pozycji
	 * zaadoptowanej (dodanej ręką klienta) nie ruszamy: to jego treść, a jej skasowanie
	 * byłoby nieodwracalne i niczym nieuzasadnione.
	 *
	 * @return bool Czy pozycja została faktycznie usunięta.
	 */
	public static function remove(): bool {
		if ( ! function_exists( 'get_option' ) ) {
			return false;
		}

		$s      = static::menu_state();
		$stored = (int) get_option( self::ITEM_OPTION, 0 );

		// Klient usunął pozycję sam. Zapisujemy trwały znacznik i NIE kasujemy
		// `aifaq_menu_state` — bez tego link wracałby po KAŻDYM cyklu
		// deaktywacja → aktywacja, czyli automat walczyłby z użytkownikiem.
		// Liczy się też stan ZAPISANY: klient mógł przy okazji zmienić motyw, a wtedy
		// diagnoza kończy się na `no_location` i o usunięciu pozycji nie wie nic.
		if ( self::STATE_REMOVED_BY_USER === $s['state'] || self::STATE_REMOVED_BY_USER === static::state()['state'] ) {
			if ( function_exists( 'update_option' ) ) {
				update_option( self::OPTOUT, '1', false );
			}

			if ( function_exists( 'delete_option' ) ) {
				delete_option( self::ITEM_OPTION );
			}

			return false;
		}

		$owned = ( self::STATE_OK === $s['state'] || self::STATE_MENU_CHANGED === $s['state'] )
			? (string) $s['owned']
			: self::stored_owned();

		$item = ( (int) $s['item_id'] > 0 ) ? (int) $s['item_id'] : $stored;

		if ( $item < 1 || '1' !== $owned ) {
			// Nic naszego do skasowania: brak opcji, cudza pozycja albo stan bez pozycji.
			// Czyścimy wyłącznie WŁASNE opcje — treść klienta zostaje nietknięta.
			self::forget_item();

			return false;
		}

		$post = function_exists( 'get_post' ) ? get_post( $item ) : null;

		if ( ! $post instanceof \WP_Post || 'nav_menu_item' !== $post->post_type ) {
			self::forget_item();

			return false;
		}

		if ( ! function_exists( 'wp_delete_post' ) ) {
			return false;
		}

		// Pozycja menu jest zwykłym wpisem typu `nav_menu_item` — rdzeń kasuje ją
		// dokładnie tak samo. TRWALE (`true`), bo pozycja w koszu i tak zniknęłaby
		// z nawigacji, a zostawiona zaśmiecałaby kosz klienta bez żadnego pożytku.
		wp_delete_post( $item, true );

		self::forget_item();

		return true;
	}

	/**
	 * Zajmuje zamek w sposób możliwie atomowy.
	 *
	 * Świadomie NIE `get_transient()` + `set_transient()`: to klasyczny TOCTOU. UWAGA:
	 * `add_option()` też NIE jest w pełni atomowe — rdzeń robi najpierw nieatomowy odczyt,
	 * a potem `INSERT … ON DUPLICATE KEY UPDATE`. Zamek jest więc PIERWSZĄ, a nie jedyną
	 * linią obrony: po nim idzie DOUBLE-CHECK w {@see ensure()}, a przegrany wyścig i tak
	 * kończy się na kontroli idempotencji ({@see menu_state()} krok 5b), która rozpozna
	 * cudzą pozycję zamiast tworzyć drugą.
	 *
	 * Wartością zamka jest ZNACZNIK CZASU, nie `1` — bez niego nie da się przejąć zamka
	 * po procesie, który padł w połowie.
	 *
	 * @return bool Czy zamek został zajęty.
	 */
	protected static function acquire_lock(): bool {
		if ( ! function_exists( 'add_option' ) || ! function_exists( 'get_option' ) ) {
			return true; // Czyste PHP CLI — brak współbieżności do pilnowania.
		}

		$now = time();

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
	 * Zapisuje stan i przestawia tanią bramkę kokpitu.
	 *
	 * Bramka {@see OK_FLAG} jest TRÓJSTANOWA i znaczy „kokpit nie ma tu nic do zrobienia":
	 * - `'1'` — pozycja jest, wszystko gra;
	 * - `'0'` — stan TERMINALNY (`disabled`, `page_missing`, `removed_by_user`,
	 *   `location_ambiguous`, `failed` po trzech próbach): automat i tak tego nie naprawi;
	 * - `''` — JEST co robić (`missing`, `no_menu`, `no_location`, `menu_changed`,
	 *   `failed` przed trzecią próbą).
	 *
	 * `no_menu` i `no_location` mają bramkę OTWARTĄ celowo: klient może utworzyć menu
	 * w dowolnej chwili (i robi to procedura odbioru), a przy tych stanach diagnoza czyta
	 * wyłącznie opcje autoloadowane — zero zapytań SQL. Zamknięcie ich uczyniłoby
	 * kryterium odbioru „link widoczny po utworzeniu menu" NIEOSIĄGALNYM.
	 *
	 * Opcja stanu jest zakładana przez `add_option( …, 'no' )`, bo samo `update_option()`
	 * na nieistniejącej opcji ustawia autoload `yes` — a tej tablicy nie wolno wozić
	 * przy każdym żądaniu.
	 *
	 * @param array<string,mixed> $state Pełny stan (9 kluczy).
	 */
	protected static function save_state( array $state ): void {
		if ( ! function_exists( 'update_option' ) ) {
			return;
		}

		if ( function_exists( 'add_option' ) ) {
			add_option( self::OPTION, array(), '', 'no' );
		}

		update_option( self::OPTION, $state, false );

		update_option( self::OK_FLAG, self::flag_for( $state ), true );
	}

	/**
	 * Wartość bramki dla danego stanu.
	 *
	 * @param array<string,mixed> $state Pełny stan.
	 * @return string `'1'` | `'0'` | `''`.
	 */
	protected static function flag_for( array $state ): string {
		$name = (string) ( $state['state'] ?? '' );

		if ( self::STATE_OK === $name ) {
			return '1';
		}

		$terminal = array(
			self::STATE_DISABLED,
			self::STATE_PAGE_MISSING,
			self::STATE_REMOVED_BY_USER,
			self::STATE_LOCATION_AMBIGUOUS,
		);

		if ( in_array( $name, $terminal, true ) ) {
			return '0';
		}

		if ( self::STATE_FAILED === $name && (int) ( $state['tries'] ?? 0 ) >= self::MAX_TRIES ) {
			return '0';
		}

		return '';
	}

	/**
	 * Czy z tego stanu wolno automatowi tworzyć pozycję.
	 *
	 * @param string $state Nazwa stanu.
	 * @return bool
	 */
	private static function is_creatable( string $state ): bool {
		return ( self::STATE_MISSING === $state
			|| self::STATE_FAILED === $state
			|| self::STATE_MENU_CHANGED === $state );
	}

	/**
	 * Wybiera lokalizację z listy preferencji. Pusty ciąg = żadna nie pasuje.
	 *
	 * Pętla idzie po PREFERENCJACH z zewnątrz, żeby o wyniku decydowała nasza kolejność,
	 * a nie przypadkowa kolejność kluczy zwrócona przez motyw.
	 *
	 * @param array<string,mixed> $assigned Mapa lokalizacja => ID menu.
	 * @return string
	 */
	private static function pick_location( array $assigned ): string {
		foreach ( self::LOCATION_PREFERENCE as $pref ) {
			foreach ( $assigned as $location => $menu_id ) {
				if ( (int) $menu_id < 1 ) {
					continue; // Lokalizacja bez przypiętego menu — nie ma dokąd wstawiać.
				}

				if ( false !== stripos( (string) $location, $pref ) ) {
					return (string) $location;
				}
			}
		}

		return '';
	}

	/**
	 * Czy jakakolwiek lokalizacja ma przypięte menu.
	 *
	 * @param array<string,mixed> $assigned Mapa lokalizacja => ID menu.
	 * @return bool
	 */
	private static function has_any_menu( array $assigned ): bool {
		foreach ( $assigned as $menu_id ) {
			if ( (int) $menu_id > 0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Pozycje menu o podanym ID (pusta tablica przy braku WordPressa).
	 *
	 * @param int $menu_id ID menu.
	 * @return array<int,mixed>
	 */
	private static function menu_items( int $menu_id ): array {
		if ( $menu_id < 1 || ! function_exists( 'wp_get_nav_menu_items' ) ) {
			return array();
		}

		$items = wp_get_nav_menu_items( $menu_id );

		return is_array( $items ) ? $items : array();
	}

	/**
	 * Czy pozycja o tym ID jest w podanym zbiorze.
	 *
	 * @param array<int,mixed> $items   Pozycje menu.
	 * @param int              $item_id Szukane ID.
	 * @return bool
	 */
	private static function in_items( array $items, int $item_id ): bool {
		foreach ( $items as $item ) {
			if ( is_object( $item ) && isset( $item->ID ) && (int) $item->ID === $item_id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * ID pierwszej pozycji wskazującej naszą podstronę (0, gdy nie ma).
	 *
	 * Warunek jest potrójny (`type` + `object` + `object_id`), bo w menu mogą siedzieć
	 * pozycje o tym samym `object_id` w zupełnie innym typie (kategoria, custom link).
	 *
	 * @param array<int,mixed> $items   Pozycje menu.
	 * @param int              $page_id ID podstrony generatora.
	 * @return int
	 */
	private static function find_item_for_page( array $items, int $page_id ): int {
		if ( $page_id < 1 ) {
			return 0;
		}

		foreach ( $items as $item ) {
			if ( ! is_object( $item ) || ! isset( $item->ID, $item->object_id ) ) {
				continue;
			}

			$type   = isset( $item->type ) ? (string) $item->type : '';
			$object = isset( $item->object ) ? (string) $item->object : '';

			if ( 'post_type' === $type && 'page' === $object && (int) $item->object_id === $page_id ) {
				return (int) $item->ID;
			}
		}

		return 0;
	}

	/**
	 * ID opublikowanej podstrony generatora (0, gdy jej nie ma).
	 *
	 * @return int
	 */
	private static function page_id(): int {
		if ( ! function_exists( 'get_option' ) ) {
			return 0;
		}

		$id = (int) get_option( 'aifaq_page_id', 0 );

		if ( $id < 1 || ! function_exists( 'get_post' ) ) {
			return 0;
		}

		$post = get_post( $id );

		if ( ! $post instanceof \WP_Post || 'page' !== $post->post_type || 'publish' !== $post->post_status ) {
			return 0;
		}

		return $id;
	}

	/**
	 * Etykieta pozycji — z ustawień, z jawnym fallbackiem.
	 *
	 * @return string
	 */
	private static function label(): string {
		$label = trim( self::setting( 'menu_label', 'Generator FAQ' ) );

		return ( '' === $label ) ? 'Generator FAQ' : $label;
	}

	/**
	 * Proweniencja zapisanej pozycji.
	 *
	 * Domyślnie `'0'` (cudza), NIE `'1'`. Opcja stanu zapisana przez starszą wersję
	 * wtyczki nie ma tego klucza, a pomyłka w tę stronę kosztuje tylko sierotę w menu;
	 * pomyłka w drugą stronę kasuje treść klienta.
	 *
	 * @return string
	 */
	private static function stored_owned(): string {
		return self::owned_value( static::state()['owned'] );
	}

	/**
	 * Normalizacja znacznika proweniencji do `'1'` | `'0'` | `''`.
	 *
	 * @param mixed $raw Wartość z opcji.
	 * @return string
	 */
	private static function owned_value( $raw ): string {
		$value = is_scalar( $raw ) ? (string) $raw : '';

		return in_array( $value, array( '1', '0' ), true ) ? $value : '';
	}

	/**
	 * Zapomina o pozycji: kasuje ID i otwiera bramkę. Treści NIE rusza.
	 */
	private static function forget_item(): void {
		if ( function_exists( 'delete_option' ) ) {
			delete_option( self::ITEM_OPTION );
		}

		if ( ! function_exists( 'update_option' ) ) {
			return;
		}

		update_option( self::OK_FLAG, '', true );

		$s            = static::state();
		$s['state']   = self::STATE_MISSING;
		$s['item_id'] = 0;
		$s['owned']   = '';
		$s['tries']   = 0;
		$s['last']    = 0;
		$s['error']   = '';

		update_option( self::OPTION, $s, false );
	}

	/**
	 * Ustawienie wtyczki z JAWNYM defaultem.
	 *
	 * Nigdy `Settings::get()['klucz']`: klucze menu tworzy inny etap, a klucz pod `isset()`
	 * nie materializuje się w `wp_options` przy zapisie ustawień z panelu na froncie
	 * (przysyła on tylko cztery pola).
	 *
	 * @param string $key     Klucz ustawienia.
	 * @param string $default Wartość domyślna.
	 * @return string
	 */
	private static function setting( string $key, string $default ): string {
		if ( ! function_exists( 'get_option' ) || ! class_exists( '\AIFAQ\Core\Settings' ) ) {
			return $default;
		}

		try {
			$value = \AIFAQ\Core\Settings::get_field( $key, $default );
		} catch ( \Throwable $e ) {
			unset( $e );

			return $default;
		}

		return is_scalar( $value ) ? (string) $value : $default;
	}

	/**
	 * Tłumaczenie odporne na brak WordPressa (klasa musi działać w czystym PHP CLI).
	 *
	 * @param string $text Tekst źródłowy.
	 * @return string
	 */
	private static function tr( string $text ): string {
		return function_exists( '__' ) ? (string) __( $text, 'ai-faq-generator' ) : $text; // phpcs:ignore WordPress.WP.I18n
	}

	/**
	 * Dziesięć dopuszczalnych stanów.
	 *
	 * @return array<int,string>
	 */
	private static function states(): array {
		return array(
			self::STATE_OK,
			self::STATE_MISSING,
			self::STATE_NO_MENU,
			self::STATE_NO_LOCATION,
			self::STATE_MENU_CHANGED,
			self::STATE_FAILED,
			self::STATE_DISABLED,
			self::STATE_PAGE_MISSING,
			self::STATE_REMOVED_BY_USER,
			self::STATE_LOCATION_AMBIGUOUS,
		);
	}

	/**
	 * Stan pusty — używany, gdy nie ma WordPressa.
	 *
	 * @return array{state:string,item_id:int,owned:string,location:string,menu_id:int,label:string,tries:int,last:int,error:string}
	 */
	private static function empty_state(): array {
		return array(
			'state'    => self::STATE_MISSING,
			'item_id'  => 0,
			'owned'    => '',
			'location' => '',
			'menu_id'  => 0,
			'label'    => 'Generator FAQ',
			'tries'    => 0,
			'last'     => 0,
			'error'    => '',
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
