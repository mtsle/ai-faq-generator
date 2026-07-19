<?php
/**
 * Kolejka crawla wyrenderowanych stron witryny (cron, paczkami).
 *
 * Pobiera własne podstrony JAKO GOŚĆ i zapisuje ich tekst do postmeta
 * {@see RenderedContentSource::META_KEY}. Robi to w tle, bo pobranie 35–400
 * stron nie zmieści się w jednym żądaniu do kokpitu (limit `max_execution_time`),
 * a właściciel ma widzieć postęp, nie białą stronę.
 *
 * Treść trafia do POSTMETA, nie do opcji ani transientów. Powody zmierzone:
 * 500 stron w jednej opcji to ~3 MB w jednym wierszu (odczytywanym przy każdym
 * żądaniu, gdyby autoload był włączony), a transienty na Redisie znikają przy
 * flushu — stan mówiłby „gotowe", a treści by nie było.
 *
 * @package AI_FAQ_Generator
 */

namespace AIFAQ\Index;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Kolejka pobierania wyrenderowanych stron.
 */
class CrawlQueue {

	/**
	 * Hook crona wywołujący {@see tick()} (rejestruje go Etap 4 w `Plugin`).
	 */
	public const CRON_HOOK = 'aifaq_crawl_tick';

	/**
	 * Nazwa harmonogramu (rejestruje go Etap 4 przez `cron_schedules` = 60 s).
	 */
	public const CRON_SCHEDULE = 'aifaq_minute';

	/**
	 * Opcja ze stanem kolejki (zawsze `autoload = 'no'`).
	 */
	public const OPTION = 'aifaq_crawl_state';

	/**
	 * Opcja-zamek chroniąca `tick()` przed równoległym uruchomieniem.
	 */
	public const LOCK = 'aifaq_crawl_lock';

	/**
	 * Maksymalna liczba adresów w jednej paczce.
	 */
	public const BATCH_MAX = 10;

	/**
	 * Budżet czasu jednej paczki w sekundach.
	 */
	public const BATCH_SECONDS = 20;

	/**
	 * Ważność zamka w sekundach.
	 *
	 * `WP_CRON_LOCK_TIMEOUT` to 60 s, a paczka może trwać dłużej (10 × timeout 15 s),
	 * więc zamek musi żyć własnym życiem — inaczej drugi tick wystartuje w połowie
	 * pierwszego i obaj nadpiszą sobie stan.
	 */
	public const LOCK_TTL = 120;

	/**
	 * Górny limit przechowywanych ostrzeżeń (ochrona przed puchnięciem opcji).
	 */
	protected const MAX_WARNINGS = 50;

	/**
	 * Próg alarmu dla listy wykluczeń (ile kandydatów może zjeść).
	 */
	protected const EXCLUDE_ALERT_RATIO = 0.8;

	/**
	 * Typy wpisów objęte crawlem.
	 *
	 * Domyślnie wąsko (KONTRAKT §1 reguła 5) — CPT zgłoszeń/rezerwacji z danymi
	 * osobowymi nie mogą trafić do publicznej bazy wiedzy.
	 *
	 * @var array<int,string>
	 */
	protected array $post_types;

	/**
	 * Konstruktor.
	 *
	 * @param array<int,string> $post_types Typy wpisów do crawlowania (domyślnie post + page).
	 */
	public function __construct( array $post_types = array( 'post', 'page' ) ) {
		$this->post_types = array() !== $post_types ? $post_types : array( 'post', 'page' );
	}

	/**
	 * Dokłada do kolejki adresy stron, których jeszcze nie ma w `done` ani w `queue`.
	 *
	 * Metoda jest IDEMPOTENTNA i PRZYROSTOWA: nigdy nie kasuje `done` ani
	 * zapisanej treści. Bez tego reindeks (który woła `seed()` przy każdym
	 * uruchomieniu) w nieskończoność napełniałby kolejkę i sam sobie odmawiał
	 * startu z powodu „crawl trwa".
	 *
	 * @return int Liczba adresów DOŁOŻONYCH w tym wywołaniu.
	 */
	public function seed(): int {
		if ( ! function_exists( 'get_posts' ) ) {
			return 0;
		}

		$state       = $this->state();
		$queue_empty = array() === $state['queue'];

		$known = array();
		foreach ( $state['done'] as $done_id ) {
			$known[ (int) $done_id ] = true;
		}
		foreach ( $state['queue'] as $item ) {
			if ( isset( $item['post_id'] ) ) {
				$known[ (int) $item['post_id'] ] = true;
			}
		}

		$posts = get_posts(
			array(
				'post_type'        => $this->post_types,
				'post_status'      => 'publish',
				'numberposts'      => -1,
				'has_password'     => false,
				'suppress_filters' => true,
				'orderby'          => 'ID',
				'order'            => 'ASC',
			)
		);

		$candidates = array();
		foreach ( (array) $posts as $post ) {
			$post_id = is_object( $post ) ? (int) ( $post->ID ?? 0 ) : (int) $post;
			if ( $post_id > 0 && ! isset( $candidates[ $post_id ] ) ) {
				$candidates[ $post_id ] = $post;
			}
		}

		$this->check_exclude_sanity( $state, $candidates );

		$added = 0;
		foreach ( $candidates as $post_id => $post ) {
			if ( isset( $known[ $post_id ] ) ) {
				continue;
			}

			// Jedyne miejsce wykluczeń: wspólna bramka z Etapu 1 (KONTRAKT §2.2).
			// Zawiera już `crawl_exclude`, status, hasło, noindex, strony systemowe
			// WooCommerce i własną podstronę wtyczki.
			if ( ! $this->indexable( $post_id ) ) {
				continue;
			}

			// URL WYŁĄCZNIE z get_permalink(). NIGDY url_to_postid() — przy WPML,
			// prefiksie `www` i stronie głównej zwraca 0, więc wszystkie strony
			// wylądowałyby w jednym worku `post_id = 0` i nadpisywały sobie treść.
			$url = function_exists( 'get_permalink' ) ? (string) get_permalink( is_object( $post ) ? $post : $post_id ) : '';
			if ( '' === $url ) {
				continue;
			}

			$state['queue'][]  = array(
				'post_id' => $post_id,
				'url'     => $url,
			);
			$known[ $post_id ] = true;
			++$added;
		}

		if ( $added > 0 && $queue_empty ) {
			$state['started'] = time();
		}

		// Reindeks właśnie trwa (to on woła seed()), więc wezwanie „uruchom
		// indeksowanie ponownie" jest już skonsumowane.
		$state['needs_reindex'] = false;
		$state['queue']         = array_values( $state['queue'] );
		$state['total']         = count( $state['done'] ) + count( $state['queue'] );

		$this->save_state( $state );

		return $added;
	}

	/**
	 * Planuje cykliczne wywołania `tick()` — tylko gdy jest co robić.
	 *
	 * Harmonogram `aifaq_minute` rejestruje Etap 4 (filtr `cron_schedules`);
	 * bez niego `wp_schedule_event()` zwraca `false` PO CICHU.
	 */
	public function schedule(): void {
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) {
			return;
		}

		$state = $this->state();
		if ( array() === $state['queue'] ) {
			return;
		}

		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}

		wp_schedule_event( time(), self::CRON_SCHEDULE, self::CRON_HOOK );
	}

	/**
	 * Pobiera jedną paczkę adresów (do `BATCH_MAX` sztuk lub `BATCH_SECONDS`).
	 *
	 * Nigdy nie rzuca wyjątku — pojedynczy niedostępny adres nie może wywalić
	 * całego crona.
	 */
	public function tick(): void {
		if ( ! $this->acquire_lock() ) {
			return; // inny tick już pracuje.
		}

		try {
			$started = time();
			$count   = 0;

			while ( $count < self::BATCH_MAX ) {
				if ( $count > 0 && ( time() - $started ) >= self::BATCH_SECONDS ) {
					break; // budżet czasu wyczerpany; reszta w następnym ticku.
				}

				// Stan czytany od nowa przy każdym adresie: zapis po KAŻDYM URL-u
				// jest wymogiem (przy `max_execution_time = 30` zapis po pętli
				// oznacza postęp „0 z 35" aż do końca świata).
				$state = $this->state();
				if ( array() === $state['queue'] ) {
					break;
				}

				$item    = array_shift( $state['queue'] );
				$post_id = (int) ( $item['post_id'] ?? 0 );
				$url     = (string) ( $item['url'] ?? '' );

				// Adres trafia do `done` PRZED żądaniem: polityka kontraktu to
				// „nieudane = nie ponawiamy", a przy zapisie po żądaniu strona
				// wywracająca PHP byłaby pobierana w kółko co minutę.
				if ( $post_id > 0 ) {
					$state['done'][] = $post_id;
				}
				$state['queue'] = array_values( $state['queue'] );
				$state['total'] = count( $state['done'] ) + count( $state['queue'] );
				$this->save_state( $state );

				++$count;

				if ( $post_id <= 0 || '' === $url ) {
					continue;
				}

				$error = '';
				$text  = $this->fetch_text( $url, $error );

				if ( null === $text ) {
					$state = $this->state();
					$this->add_warning(
						$state,
						sprintf(
							/* translators: 1: adres strony, 2: powód niepowodzenia */
							$this->txt( 'Nie udało się pobrać %1$s — %2$s. Strona pominięta.' ),
							$url,
							$error
						)
					);
					$this->save_state( $state );
					continue;
				}

				$this->store_text( $post_id, $text );
			}

			$state = $this->state();
			if ( array() === $state['queue'] ) {
				// Komplet pobrany. Reindeksu NIE uruchamiamy automatycznie —
				// embeddingi kosztują, decyzja należy do właściciela.
				if ( ! $state['needs_reindex'] && array() !== $state['done'] ) {
					$state['needs_reindex'] = true;
					$this->save_state( $state );
				}
				$this->unschedule();
			}
		} catch ( \Throwable $e ) {
			$state = $this->state();
			$this->add_warning(
				$state,
				sprintf(
					/* translators: %s: komunikat błędu */
					$this->txt( 'Błąd pobierania stron: %s' ),
					$e->getMessage()
				)
			);
			$this->save_state( $state );
		} finally {
			$this->release_lock();
		}
	}

	/**
	 * Postęp crawla (dla Dashboardu i `/admin/status`).
	 *
	 * `running` to PUSTA/NIEPUSTA KOLEJKA, nigdy porównanie liczników: wpis
	 * usunięty po scrawlowaniu zostawia sierotę w `done`, przez co `done` potrafi
	 * przebić `total` i „kompletność" wypadałaby przy niepustej kolejce.
	 *
	 * @return array{total:int,done:int,running:bool,needs_reindex:bool,warnings:array<int,string>}
	 */
	public function progress(): array {
		$state = $this->state();

		return array(
			'total'         => (int) $state['total'],
			'done'          => count( $state['done'] ),
			'running'       => array() !== $state['queue'],
			'needs_reindex' => (bool) $state['needs_reindex'],
			'warnings'      => array_values( $state['warnings'] ),
		);
	}

	/**
	 * Kasuje stan kolejki, całą pobraną treść i harmonogram.
	 *
	 * Wołane przy zmianie `crawl_enabled`/`crawl_exclude` (Etap 4) — po takiej
	 * zmianie dotychczasowa treść renderowana jest niewiarygodna.
	 */
	public function clear(): void {
		if ( function_exists( 'delete_option' ) ) {
			delete_option( self::OPTION );
			delete_option( self::LOCK );
		}

		if ( function_exists( 'delete_post_meta_by_key' ) ) {
			delete_post_meta_by_key( $this->meta_key() );
		}

		$this->unschedule();
	}

	// -----------------------------------------------------------------------
	// Wnętrze (protected — test może podstawić atrapę przez dziedziczenie).
	// -----------------------------------------------------------------------

	/**
	 * Odczytuje stan kolejki, uzupełniając brakujące klucze wartościami domyślnymi.
	 *
	 * @return array{queue:array<int,array{post_id:int,url:string}>,done:array<int,int>,total:int,started:int,needs_reindex:bool,warnings:array<int,string>}
	 */
	protected function state(): array {
		$stored = function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array();
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$queue = array();
		foreach ( (array) ( $stored['queue'] ?? array() ) as $item ) {
			if ( is_array( $item ) && isset( $item['post_id'], $item['url'] ) ) {
				$queue[] = array(
					'post_id' => (int) $item['post_id'],
					'url'     => (string) $item['url'],
				);
			}
		}

		$done = array();
		foreach ( (array) ( $stored['done'] ?? array() ) as $done_id ) {
			$done[] = (int) $done_id;
		}

		$warnings = array();
		foreach ( (array) ( $stored['warnings'] ?? array() ) as $warning ) {
			if ( is_scalar( $warning ) ) {
				$warnings[] = (string) $warning;
			}
		}

		return array(
			'queue'         => $queue,
			'done'          => $done,
			'total'         => (int) ( $stored['total'] ?? ( count( $done ) + count( $queue ) ) ),
			'started'       => (int) ( $stored['started'] ?? 0 ),
			'needs_reindex' => (bool) ( $stored['needs_reindex'] ?? false ),
			'warnings'      => $warnings,
		);
	}

	/**
	 * Zapisuje stan kolejki.
	 *
	 * `add_option()` z `autoload = 'no'` idzie PRZED `update_option()`, bo opcja
	 * tworzona przez samo `update_option()` domyślnie się autoładuje — a to
	 * kilkaset kilobajtów dokładanych do KAŻDEGO żądania witryny.
	 *
	 * @param array<string,mixed> $state Stan do zapisania.
	 */
	protected function save_state( array $state ): void {
		if ( ! function_exists( 'update_option' ) ) {
			return;
		}

		if ( function_exists( 'add_option' ) ) {
			add_option( self::OPTION, array(), '', 'no' );
		}

		update_option( self::OPTION, $state, false );
	}

	/**
	 * Dopisuje ostrzeżenie (bez duplikatów, z limitem długości listy).
	 *
	 * @param array<string,mixed> $state   Stan (przez referencję).
	 * @param string              $message Treść ostrzeżenia.
	 */
	protected function add_warning( array &$state, string $message ): void {
		if ( '' === $message ) {
			return;
		}
		if ( ! isset( $state['warnings'] ) || ! is_array( $state['warnings'] ) ) {
			$state['warnings'] = array();
		}
		if ( in_array( $message, $state['warnings'], true ) ) {
			return;
		}
		while ( count( $state['warnings'] ) >= self::MAX_WARNINGS ) {
			array_shift( $state['warnings'] );
		}
		$state['warnings'][] = $message;
	}

	/**
	 * Zajmuje zamek w sposób ATOMOWY.
	 *
	 * Świadomie NIE `get_transient()` + `set_transient()`: to klasyczny TOCTOU —
	 * dwa ticki odczytują „brak zamka" w tej samej milisekundzie i oba ruszają.
	 * `add_option()` opiera się o UNIQUE na `option_name`, więc wygrywa dokładnie
	 * jeden proces.
	 *
	 * @return bool Czy zamek został zajęty.
	 */
	protected function acquire_lock(): bool {
		if ( ! function_exists( 'add_option' ) || ! function_exists( 'get_option' ) ) {
			return true; // czyste PHP CLI — brak współbieżności do pilnowania.
		}

		$now = time();

		// Szybka ścieżka dla trwałego cache obiektowego (Redis/Memcached).
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

		// Zamek przeterminowany (proces padł w połowie paczki) — przejmujemy go,
		// znowu atomowo.
		if ( function_exists( 'delete_option' ) ) {
			delete_option( self::LOCK );
		}

		return (bool) add_option( self::LOCK, (string) $now, '', 'no' );
	}

	/**
	 * Zwalnia zamek.
	 */
	protected function release_lock(): void {
		if ( function_exists( 'delete_option' ) ) {
			delete_option( self::LOCK );
		}
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( self::LOCK, 'aifaq' );
		}
	}

	/**
	 * Pobiera stronę JAKO GOŚĆ i zwraca jej czysty tekst.
	 *
	 * @param string $error Powód niepowodzenia (przez referencję).
	 * @param string $url   Adres strony.
	 * @return string|null Tekst strony albo `null` przy niepowodzeniu.
	 */
	protected function fetch_text( string $url, string &$error ) {
		if ( ! function_exists( 'wp_remote_get' ) ) {
			$error = $this->txt( 'brak API HTTP WordPressa' );
			return null;
		}

		$response = wp_remote_get( $url, $this->request_args( $url ) );

		if ( function_exists( 'is_wp_error' ) && is_wp_error( $response ) ) {
			$error = (string) $response->get_error_message();
			return null;
		}

		$code = function_exists( 'wp_remote_retrieve_response_code' ) ? (int) wp_remote_retrieve_response_code( $response ) : 0;
		if ( 200 !== $code ) {
			/* translators: %d: kod odpowiedzi HTTP */
			$error = sprintf( $this->txt( 'kod odpowiedzi %d' ), $code );
			return null;
		}

		$type = function_exists( 'wp_remote_retrieve_header' ) ? (string) wp_remote_retrieve_header( $response, 'content-type' ) : 'text/html';
		if ( 0 !== stripos( trim( $type ), 'text/html' ) ) {
			/* translators: %s: nagłówek Content-Type */
			$error = sprintf( $this->txt( 'typ treści „%s" zamiast HTML-a' ), '' !== $type ? $type : '?' );
			return null;
		}

		if ( ! $this->same_host( $url, $this->final_url( $response, $url ) ) ) {
			$error = $this->txt( 'przekierowanie poza witrynę' );
			return null;
		}

		$body = function_exists( 'wp_remote_retrieve_body' ) ? (string) wp_remote_retrieve_body( $response ) : '';
		if ( '' === trim( $body ) ) {
			$error = $this->txt( 'pusta odpowiedź' );
			return null;
		}

		return $this->to_text( $body );
	}

	/**
	 * Zamienia HTML strony na czysty tekst (czyszczenie → wspólny `to_plain`).
	 *
	 * @param string $html Surowy HTML odpowiedzi.
	 * @return string
	 */
	protected function to_text( string $html ): string {
		$rendered = __NAMESPACE__ . '\\RenderedContentSource';
		if ( class_exists( $rendered ) && method_exists( $rendered, 'clean_html' ) ) {
			$html = RenderedContentSource::clean_html( $html );
		}

		$wp_source = __NAMESPACE__ . '\\WpContentSource';
		if ( class_exists( $wp_source ) && method_exists( $wp_source, 'to_plain' ) ) {
			return WpContentSource::to_plain( $html );
		}

		return $this->plain_fallback( $html );
	}

	/**
	 * Awaryjna zamiana HTML → tekst (gdy `WpContentSource` niedostępny).
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	protected function plain_fallback( string $html ): string {
		$html = (string) preg_replace( '/<!--.*?-->/s', ' ', $html );
		$html = (string) preg_replace( '#<(br|/p|/div|/h[1-6]|/li|/tr|/td|/th|/blockquote|/a|/section|/article|/header|/footer|/nav|/ul|/ol|/dt|/dd|/figcaption|/label|/button)[^>]*>#i', "\n", $html );
		$text = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $html ) : strip_tags( $html );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = (string) preg_replace( '/[ \t]+/u', ' ', $text );
		$text = (string) preg_replace( '/\s*\n\s*/u', "\n", $text );
		$text = (string) preg_replace( '/\n{2,}/u', "\n", $text );

		return trim( $text );
	}

	/**
	 * Zapisuje pobrany tekst do postmeta.
	 *
	 * `wp_slash()` jest konieczne: funkcje metadanych robią `wp_unslash()` na
	 * wejściu, więc bez tego treść z ukośnikiem odwrotnym zapisałaby się okrojona.
	 *
	 * @param int    $post_id ID wpisu.
	 * @param string $text    Tekst strony.
	 */
	protected function store_text( int $post_id, string $text ): void {
		if ( ! function_exists( 'update_post_meta' ) ) {
			return;
		}

		$value = function_exists( 'wp_slash' ) ? wp_slash( $text ) : $text;
		update_post_meta( $post_id, $this->meta_key(), $value );
	}

	/**
	 * Klucz postmeta z treścią renderowaną (odporny na brak klasy źródła).
	 *
	 * @return string
	 */
	protected function meta_key(): string {
		$rendered = __NAMESPACE__ . '\\RenderedContentSource';
		if ( class_exists( $rendered ) && defined( $rendered . '::META_KEY' ) ) {
			return (string) RenderedContentSource::META_KEY;
		}
		return '_aifaq_rendered';
	}

	/**
	 * Argumenty żądania HTTP — zawsze JAKO GOŚĆ (pusta tablica ciasteczek).
	 *
	 * Crawl uruchamia zalogowany administrator. Bez wyzerowania ciasteczek
	 * żądanie poszłoby z JEGO sesją, więc do publicznej bazy wiedzy trafiłaby
	 * treść płatna i robocza (KONTRAKT §1).
	 *
	 * @param string $url Adres docelowy.
	 * @return array<string,mixed>
	 */
	protected function request_args( string $url ): array {
		return array(
			'timeout'             => 15,
			'redirection'         => 2,
			'cookies'             => array(),
			'sslverify'           => ! $this->is_local_url( $url ),
			'limit_response_size' => 2097152,
			'user-agent'          => 'AIFAQ-Indexer/' . ( defined( 'AIFAQ_VERSION' ) ? AIFAQ_VERSION : 'dev' ),
			'headers'             => array( 'X-AIFAQ-Crawl' => '1' ),
		);
	}

	/**
	 * Adres KOŃCOWY odpowiedzi (po ewentualnych przekierowaniach).
	 *
	 * @param mixed  $response Odpowiedź `wp_remote_get()`.
	 * @param string $fallback Adres żądany.
	 * @return string
	 */
	protected function final_url( $response, string $fallback ): string {
		if ( is_array( $response ) && isset( $response['http_response'] ) && is_object( $response['http_response'] )
			&& method_exists( $response['http_response'], 'get_response_object' ) ) {
			$raw = $response['http_response']->get_response_object();
			if ( is_object( $raw ) && isset( $raw->url ) && is_string( $raw->url ) && '' !== $raw->url ) {
				return $raw->url;
			}
		}

		return $fallback;
	}

	/**
	 * Czy adres końcowy nadal należy do tej witryny (pinning hosta).
	 *
	 * Porównanie po normalizacji `www.` i wielkości liter — witryna
	 * przekierowująca non-www → www odrzuciłaby inaczej 100% własnych adresów.
	 *
	 * @param string $requested Adres żądany.
	 * @param string $final     Adres końcowy.
	 * @return bool
	 */
	protected function same_host( string $requested, string $final ): bool {
		$home = function_exists( 'home_url' ) ? (string) home_url( '/' ) : $requested;

		$expected = $this->normalize_host( $home );
		$actual   = $this->normalize_host( $final );

		if ( '' === $expected || '' === $actual ) {
			return true; // nie da się porównać → nie blokujemy pobierania.
		}

		return $expected === $actual;
	}

	/**
	 * Normalizuje host adresu (małe litery, bez `www.`).
	 *
	 * @param string $url Adres.
	 * @return string
	 */
	protected function normalize_host( string $url ): string {
		$host = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );
		return ( 0 === strpos( $host, 'www.' ) ) ? substr( $host, 4 ) : $host;
	}

	/**
	 * Czy adres jest lokalny/prywatny (certyfikat samopodpisany → `sslverify=false`).
	 *
	 * @param string $url Adres.
	 * @return bool
	 */
	protected function is_local_url( string $url ): bool {
		$rendered = __NAMESPACE__ . '\\RenderedContentSource';
		if ( class_exists( $rendered ) && method_exists( $rendered, 'is_local_url' ) ) {
			return (bool) RenderedContentSource::is_local_url( $url );
		}

		$host = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $host ) {
			return false;
		}
		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1', '[::1]' ), true ) ) {
			return true;
		}
		foreach ( array( '.local', '.test', '.localhost' ) as $suffix ) {
			if ( substr( $host, -strlen( $suffix ) ) === $suffix ) {
				return true;
			}
		}

		return (bool) preg_match( '/^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/', $host );
	}

	/**
	 * Zdejmuje harmonogram crona.
	 */
	protected function unschedule(): void {
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_unschedule_event' ) ) {
			return;
		}

		$guard = 0;
		while ( $guard++ < 20 ) {
			$timestamp = wp_next_scheduled( self::CRON_HOOK );
			if ( ! $timestamp ) {
				break;
			}
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Czy wpis wolno zaindeksować (wspólna bramka z Etapu 1).
	 *
	 * Brak klasy/metody → warunek POMIJANY (nigdy fatal, nigdy `false`).
	 *
	 * @param int $post_id ID wpisu.
	 * @return bool
	 */
	protected function indexable( int $post_id ): bool {
		$class = __NAMESPACE__ . '\\WpContentSource';
		if ( class_exists( $class ) && method_exists( $class, 'is_indexable' ) ) {
			return (bool) WpContentSource::is_indexable( $post_id );
		}
		return true;
	}

	/**
	 * Ostrzega, gdy lista wykluczeń zjada niemal całą witrynę.
	 *
	 * Same wykluczenia stosuje bramka `is_indexable()` (Etap 1) — tu wyłącznie
	 * MIERZYMY ich zasięg, żeby literówka w polu ustawień („,", „/", „a") nie
	 * wygaszała bazy wiedzy w ciszy.
	 *
	 * @param array<string,mixed>  $state      Stan (przez referencję).
	 * @param array<int,mixed>     $candidates Kandydaci: post_id => wpis.
	 */
	protected function check_exclude_sanity( array &$state, array $candidates ): void {
		$tokens = $this->exclude_tokens();
		$total  = count( $candidates );

		if ( array() === $tokens || 0 === $total ) {
			return;
		}

		$hit = 0;
		foreach ( $candidates as $post_id => $post ) {
			if ( $this->matches_exclude( (int) $post_id, $post, $tokens ) ) {
				++$hit;
			}
		}

		if ( $hit < (int) ceil( self::EXCLUDE_ALERT_RATIO * $total ) ) {
			return;
		}

		$this->add_warning(
			$state,
			sprintf(
				/* translators: 1: liczba wykluczonych stron, 2: liczba wszystkich stron */
				$this->txt( 'UWAGA: lista „Wyklucz strony" pasuje do %1$d z %2$d stron witryny — baza wiedzy zostanie prawie pusta. Sprawdź, czy nie ma tam zbędnego przecinka albo ukośnika.' ),
				$hit,
				$total
			)
		);
	}

	/**
	 * Parsuje pole `crawl_exclude` z ustawień.
	 *
	 * PUSTE TOKENY ODRZUCAMY BEZWARUNKOWO: końcowy przecinek („kontakt,")
	 * dawałby pusty token, a dopasowanie po pustym stringu wyklucza CAŁĄ witrynę.
	 *
	 * @return array<int,string>
	 */
	protected function exclude_tokens(): array {
		$class = '\\AIFAQ\\Core\\Settings';
		if ( ! class_exists( $class ) || ! method_exists( $class, 'get_field' ) ) {
			return array();
		}

		$raw = (string) \AIFAQ\Core\Settings::get_field( 'crawl_exclude', '' );
		if ( '' === trim( $raw ) ) {
			return array();
		}

		$tokens = array_filter( array_map( 'trim', explode( ',', $raw ) ), 'strlen' );

		$out = array();
		foreach ( $tokens as $token ) {
			$token = strtolower( trim( $token, "/ \t\n\r\0\x0B" ) );
			if ( '' !== $token ) {
				$out[] = $token;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Czy adres wpisu pasuje do listy wykluczeń.
	 *
	 * Dopasowanie po PEŁNYM SEGMENCIE ścieżki, nigdy po fragmencie — token
	 * „kontakt" nie może wyciąć „/kontakty-do-kadry/".
	 *
	 * @param int                $post_id ID wpisu.
	 * @param mixed              $post    Obiekt wpisu (jeśli dostępny).
	 * @param array<int,string>  $tokens  Tokeny wykluczeń.
	 * @return bool
	 */
	protected function matches_exclude( int $post_id, $post, array $tokens ): bool {
		$segments = array();

		if ( is_object( $post ) && isset( $post->post_name ) && '' !== (string) $post->post_name ) {
			$segments[] = strtolower( (string) $post->post_name );
		}

		if ( function_exists( 'get_permalink' ) ) {
			$path = (string) parse_url( (string) get_permalink( $post_id ), PHP_URL_PATH );
			foreach ( explode( '/', strtolower( trim( $path, '/' ) ) ) as $segment ) {
				if ( '' !== $segment ) {
					$segments[] = $segment;
				}
			}
		}

		foreach ( $tokens as $token ) {
			if ( in_array( $token, $segments, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Tłumaczenie odporne na brak WordPressa (klasa działa w czystym PHP CLI).
	 *
	 * @param string $text Tekst źródłowy.
	 * @return string
	 */
	protected function txt( string $text ): string {
		// phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
		return function_exists( '__' ) ? __( $text, 'ai-faq-generator' ) : $text;
	}
}
