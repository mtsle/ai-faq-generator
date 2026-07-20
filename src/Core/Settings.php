<?php
/**
 * Ustawienia wtyczki — konfiguracja API i trasy publicznej.
 *
 * Przechowuje opcje w wp_options pod kluczem `aifaq_settings`,
 * rejestruje je przez Settings API (z sanityzacją) oraz obsługuje
 * akcję AJAX „Test połączenia" (realny ping klucza do Gemini).
 *
 * @package AI_FAQ_Generator
 */

namespace AIFAQ\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Warstwa ustawień i konfiguracji API.
 */
class Settings {

	/**
	 * Nazwa opcji w wp_options.
	 */
	const OPTION = 'aifaq_settings';

	/**
	 * Grupa ustawień (Settings API).
	 */
	const GROUP = 'aifaq_settings_group';

	/**
	 * Akcja i nonce dla testu połączenia.
	 */
	const AJAX_TEST  = 'aifaq_test_connection';
	const NONCE_TEST = 'aifaq_test_connection';

	/**
	 * Flaga „przebuduj reguły rewrite" (ustawiana przy zmianie sluga trasy).
	 */
	const FLUSH_FLAG = 'aifaq_flush_needed';

	/**
	 * Flaga „ustawienia crawla zmienione — treść wymaga ponownego przeliczenia".
	 * Podnoszona w {@see on_settings_updated()}, gaszona po pokazaniu komunikatu
	 * na Dashboardzie (tam, gdzie stoi przycisk indeksowania).
	 */
	const CRAWL_NOTICE = 'aifaq_crawl_notice';

	/**
	 * Uprawnienie wymagane do zmian ustawień.
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Wartości domyślne.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'provider'      => 'gemini',
			'api_key'       => '',
			'model'         => 'gemini-2.5-flash',
			'embed_model'   => 'gemini-embedding-001',
			'temperature'   => 0.4,
			'max_questions' => 20,
			'language'      => 'pl',
			'page_slug'     => 'faqgenerator',

			// --- RAG (Krok 6) — pokrętła rdzenia generatora zawężonego do tematu strony. ---
			'rag_threshold'          => 0.7,   // Próg cosinusa bramki tematu (0.0–1.0).
			'rag_threshold_hard'     => 0.65,  // Próg twardy: fragmenty poniżej nie wchodzą do kontekstu (0.05–1.0). ZMIERZONA w K19 (M-cal2): max( 0.55 ; max_off 0.6184 + 0.03 ) = 0.6484, zaokrąglone w górę. Źródło: zasoby/bench/bench-058-mcal2.tsv, 2026-07-20.
			'rag_top_k'              => 5,     // Ile najlepszych fragmentów bierze Answerer (1–10).
			'rag_rate_limit'         => 30,    // Pytań/godz. na gościa (0 = wyłączony; 0–200).
			'rag_temperature'        => 0.2,   // Temperatura odpowiedzi RAG (osobna od `temperature`).
			'rag_max_tokens'         => 500,   // Limit długości odpowiedzi (64–2048).
			'rag_thinking_budget'    => 0,     // Budżet myślenia modelu: 0 = wyłączone, -1 = dynamiczne, 128–24576 = jawny budżet tokenów rozumowania.
			'rag_refusal_message_pl' => 'Przepraszam, potrafię odpowiadać wyłącznie na pytania dotyczące tej strony.',
			'rag_refusal_message_en' => 'Sorry, I can only answer questions related to this website.',
			'rag_refusal_message_de' => 'Entschuldigung, ich kann nur Fragen zu dieser Website beantworten.',
			'rag_contact_hint'       => '',    // Dane kontaktowe wstrzykiwane do odpowiedzi przy częściowym pokryciu. Puste = bot odsyła ogólnie do zakładki Kontakt.

			// --- Kaskada źródeł treści (Krok 17) — czym karmimy bazę wiedzy. ---
			// Klucze MUSZĄ być tutaj, nie tylko w sanitize(): bez wpisu w defaults()
			// `get_field()` zwraca `null` na świeżej instalacji, przez co crawl byłby
			// domyślnie wyłączony (a dokładnie odwrotnie brzmi kontrakt).
			'crawl_enabled'   => '1',        // '1'/'0' — pobieranie własnych podstron jako gość.
			'crawl_exclude'   => '',         // Slugi po przecinku — wykluczenie z WSZYSTKICH źródeł.
			'meta_keys'       => '',         // Dodatkowe klucze postmeta (dokładane do listy domyślnej).
			'meta_post_types' => 'post,page', // Typy wpisów przeszukiwane przez źródło postmeta.
		);
	}

	/**
	 * Dostępne modele (whitelista + etykiety).
	 *
	 * @return array<string,string>
	 */
	public static function models(): array {
		return array(
			'gemini-2.5-flash'    => __( 'Gemini 2.5 Flash (szybki, zalecany)', 'ai-faq-generator' ),
			'gemini-2.5-pro'      => __( 'Gemini 2.5 Pro (jakość)', 'ai-faq-generator' ),
			'gemini-2.0-flash'    => __( 'Gemini 2.0 Flash', 'ai-faq-generator' ),
			'gemini-flash-latest' => __( 'Gemini Flash (najnowszy)', 'ai-faq-generator' ),
		);
	}

	/**
	 * Dostępne modele embeddingów (whitelista + etykiety).
	 *
	 * @return array<string,string>
	 */
	public static function embed_models(): array {
		// Tylko modele zweryfikowane na żywym API. NIE dodawać niesprawdzonych
		// (klient mógłby wybrać nieistniejący model i dostać błąd przy generacji).
		return array(
			'gemini-embedding-001' => __( 'Google gemini-embedding-001 (768 wymiarów)', 'ai-faq-generator' ),
		);
	}

	/**
	 * Dostępne języki (whitelista + etykiety).
	 *
	 * @return array<string,string>
	 */
	public static function languages(): array {
		return array(
			'pl' => __( 'polski', 'ai-faq-generator' ),
			'en' => __( 'angielski', 'ai-faq-generator' ),
			'de' => __( 'niemiecki', 'ai-faq-generator' ),
		);
	}

	/**
	 * Zwraca wszystkie ustawienia (scalone z domyślnymi).
	 *
	 * @return array<string,mixed>
	 */
	public static function get(): array {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return array_merge( self::defaults(), $stored );
	}

	/**
	 * Zwraca pojedyncze ustawienie.
	 *
	 * @param string $key     Klucz ustawienia.
	 * @param mixed  $default Wartość domyślna, gdy brak.
	 * @return mixed
	 */
	public static function get_field( string $key, $default = null ) {
		$all = self::get();
		return $all[ $key ] ?? $default;
	}

	/**
	 * Rejestruje ustawienia (hook admin_init).
	 */
	public function register(): void {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Sanityzacja i walidacja wejścia z formularza.
	 *
	 * @param mixed $input Surowe dane z formularza.
	 * @return array<string,mixed>
	 */
	public static function sanitize( $input ): array {
		$current = self::get();
		$input   = is_array( $input ) ? $input : array();
		$out     = $current; // Start od bieżących — pola nieprzysłane/niepoprawne ZOSTAJĄ (nie reset do domyślnych).

		// Dostawca (na razie tylko Gemini).
		$out['provider'] = 'gemini';

		// Klucz API — aktualizowany TYLKO, gdy wpisano nową wartość. Pusty submit
		// zachowuje zapisany klucz (pole w formularzu jest zamaskowane — patrz widok).
		if ( isset( $input['api_key'] ) ) {
			$submitted = trim( sanitize_text_field( wp_unslash( $input['api_key'] ) ) );
			if ( '' !== $submitted ) {
				$out['api_key'] = $submitted;
			}
		}

		// Model — nadpisujemy tylko wartością z whitelisty; inaczej zostaje bieżąca.
		if ( isset( $input['model'] ) && in_array( $input['model'], array_keys( self::models() ), true ) ) {
			$out['model'] = $input['model'];
		}

		// Model embeddingów — jw.
		if ( isset( $input['embed_model'] ) && in_array( $input['embed_model'], array_keys( self::embed_models() ), true ) ) {
			$out['embed_model'] = $input['embed_model'];
		}

		// Temperatura — zakres 0.0–1.0, krok 0.1 (tylko gdy przysłano).
		if ( isset( $input['temperature'] ) ) {
			$out['temperature'] = max( 0.0, min( 1.0, round( (float) $input['temperature'], 1 ) ) );
		}

		// Maksymalna liczba pytań — zakres 5–20 (tylko gdy przysłano).
		if ( isset( $input['max_questions'] ) ) {
			$out['max_questions'] = max( 5, min( 20, (int) $input['max_questions'] ) );
		}

		// Język — tylko z whitelisty; inaczej zostaje bieżący.
		if ( isset( $input['language'] ) && in_array( $input['language'], array_keys( self::languages() ), true ) ) {
			$out['language'] = $input['language'];
		}

		// Slug publicznej trasy — bezpieczny slug; pusty/zarezerwowany/zajęty → zostaje bieżący.
		//
		// Bramka jest obowiązkowa, bo regułę rewrite trasy rejestrujemy z priorytetem
		// `top` (Router::add_rewrite_rules): wpisanie tu sluga automatycznej podstrony
		// PRZESŁANIAŁOBY ją wirtualną trasą i klient przestałby ją widzieć, choć
		// w Stronach dalej by wisiała.
		//
		// Odrzucenie MUSI być widoczne. Bez komunikatu powstaje pętla bez wyjścia:
		// komunikat o kolizji mówi „otwórz ustawienia", klient klika Zapisz,
		// WordPress pokazuje zielone „Ustawienia zapisane", wartość po cichu wraca
		// do starej, a czerwony komunikat wisi dalej.
		if ( isset( $input['page_slug'] ) ) {
			$slug     = sanitize_title( wp_unslash( $input['page_slug'] ) );
			$reserved = class_exists( '\AIFAQ\PublicUi\Shortcode' )
				? \AIFAQ\PublicUi\Shortcode::PAGE_SLUG
				: 'generator-faq';

			$taken = false;
			if ( '' !== $slug ) {
				if ( function_exists( 'get_page_by_path' ) ) {
					$page  = get_page_by_path( $slug );
					$taken = ( $page instanceof \WP_Post && 'publish' === $page->post_status );
				}
				// Reguła rewrite z priorytetem `top` przesłania KAŻDY adres najwyższego
				// poziomu, nie tylko strony (wpisy, taksonomie, archiwa) — więc o zajętość
				// pytamy sam WordPress, zamiast zgadywać po typach treści.
				if ( ! $taken && function_exists( 'url_to_postid' ) && function_exists( 'home_url' ) ) {
					$taken = ( (int) url_to_postid( home_url( '/' . $slug . '/' ) ) > 0 );
				}
			}

			if ( '' !== $slug && $slug !== $reserved && ! $taken ) {
				$out['page_slug'] = $slug;
			} elseif ( function_exists( 'add_settings_error' ) ) {
				// `add_settings_error()` pod `function_exists()`, bo sanitize() bywa
				// wołane z REST i z WP-CLI, gdzie Settings API nie jest załadowane.
				if ( '' === $slug ) {
					$msg = __( 'Adres podstrony generatora nie może być pusty. Zostawiono poprzedni adres.', 'ai-faq-generator' );
				} elseif ( $slug === $reserved ) {
					$msg = sprintf(
						/* translators: %s: slug wpisany przez użytkownika. */
						__( 'Adres «%s» jest zarezerwowany dla podstrony generatora. Zostawiono poprzedni adres.', 'ai-faq-generator' ),
						$slug
					);
				} else {
					$msg = sprintf(
						/* translators: %s: slug wpisany przez użytkownika. */
						__( 'Adres «%s» jest już zajęty przez inną treść witryny. Zostawiono poprzedni adres.', 'ai-faq-generator' ),
						$slug
					);
				}

				add_settings_error( self::OPTION, 'aifaq_page_slug', $msg, 'error' );
			}
		}

		// --- RAG (Krok 6) — każdy knob z twardym zakresem i bezpiecznym clampem (GC2). ---
		// Fail-safe: wejście spoza zakresu → granica przedziału, nigdy fail-open (np. próg 0).

		// Próg podobieństwa bramki tematu — 0.05–1.0. Dolna podłoga jest DODATNIA
		// świadomie: próg 0.0 przepuszczałby wszystko (TopicGuard: best≥0 zawsze),
		// czyli fail-open wyłączający bramkę tematu. Nie pozwalamy go ustawić.
		if ( isset( $input['rag_threshold'] ) ) {
			$out['rag_threshold'] = max( 0.05, min( 1.0, round( (float) $input['rag_threshold'], 2 ) ) );
		}

		// Próg twardy — 0.05–1.0, ale NIGDY powyżej progu miękkiego. To jedyna
		// walidacja krzyżowa w tym pliku: blok stoi PO bloku `rag_threshold`,
		// żeby czytać już zsanityzowaną wartość sąsiada, a nie surowe wejście.
		//
		// Brak `isset()` jest CELOWY — blok wykonuje się przy KAŻDYM zapisie, także
		// gdy pola nie przysłano (`RestController::handle_settings_save()` przysyła
		// cztery pola), inaczej klucz nigdy nie zmaterializowałby się w `wp_options`.
		//
		// Zejście do granicy jest CICHE — idiom „poza zakresem → GRANICA" obowiązuje
		// dla wszystkich pól liczbowych; głośny wariant z komunikatem jest
		// zarezerwowany dla `page_slug`, gdzie ciche cofnięcie tworzyło pętlę bez wyjścia.
		$hard = max( 0.05, min( 1.0, round( (float) ( $input['rag_threshold_hard'] ?? $out['rag_threshold_hard'] ), 2 ) ) );
		if ( $hard > $out['rag_threshold'] ) {
			$hard = $out['rag_threshold'];   // cicho do granicy, BEZ add_settings_error()
		}
		$out['rag_threshold_hard'] = $hard;

		// Liczba fragmentów top-K — 1–10.
		if ( isset( $input['rag_top_k'] ) ) {
			$out['rag_top_k'] = max( 1, min( 10, (int) $input['rag_top_k'] ) );
		}

		// Limit zapytań/godz. na gościa — 0–200 (0 = wyłączony).
		if ( isset( $input['rag_rate_limit'] ) ) {
			$out['rag_rate_limit'] = max( 0, min( 200, (int) $input['rag_rate_limit'] ) );
		}

		// Temperatura odpowiedzi RAG — 0.0–1.0 (osobna od `temperature` FAQ).
		if ( isset( $input['rag_temperature'] ) ) {
			$out['rag_temperature'] = max( 0.0, min( 1.0, round( (float) $input['rag_temperature'], 1 ) ) );
		}

		// Limit długości odpowiedzi — 64–2048 tokenów.
		if ( isset( $input['rag_max_tokens'] ) ) {
			$out['rag_max_tokens'] = max( 64, min( 2048, (int) $input['rag_max_tokens'] ) );
		}

		// Budżet myślenia modelu. Zbiór legalny: {-1, 0} ∪ [128, 24576].
		//
		// UWAGA: to JEDYNE pole w tym pliku z legalną wartością UJEMNĄ. Idiom
		// `max( 0, min( …, (int) … ) )` z sąsiedniego bloku `rag_max_tokens`
		// zabiłby semantykę `-1` (= „model decyduje sam") i zgasił jedyne pokrętło
		// mitygacji dla modeli, które nie pozwalają wyłączyć myślenia.
		//
		// Zakres nie jest wymyślony: API odrzuca `thinkingBudget` z przedziału
		// 1..127 błędem 400, a sufit modeli 2.5 to 24576 (flash) / 32768 (pro).
		// Zbiór jest NIECIĄGŁY — żadna kombinacja max()/min() go nie wyraża.
		$b = (int) ( $input['rag_thinking_budget'] ?? $out['rag_thinking_budget'] );
		if ( $b < -1 ) {
			$b = -1;
		} elseif ( 0 === $b ) {
			$b = 0;
		} elseif ( $b > 0 && $b < 128 ) {
			$b = 128;   // „granica", nie „bieżąca"
		} elseif ( $b > 24576 ) {
			$b = 24576;
		}
		$out['rag_thinking_budget'] = $b;

		// Komunikaty odmowy per język — sanitize_textarea_field; pusty submit ZOSTAWIA bieżący (M11/GC2).
		foreach ( array( 'pl', 'en', 'de' ) as $lang ) {
			$key = 'rag_refusal_message_' . $lang;
			if ( isset( $input[ $key ] ) ) {
				$msg = sanitize_textarea_field( wp_unslash( $input[ $key ] ) );
				if ( '' !== $msg ) {
					$out[ $key ] = $msg;
				}
			}
		}

		// Dane kontaktowe wstrzykiwane do odpowiedzi przy CZĘŚCIOWYM pokryciu tematu
		// (podstawiane jako {KONTAKT} w systemInstruction — Krok 19 §2.9). Puste jest
		// poprawne: wtedy bot odsyła ogólnie do zakładki Kontakt, bez zmyślania numeru.
		//
		// Tu `isset()` JEST — inaczej niż w blokach progu twardego i budżetu myślenia:
		// pole tekstowe wolno wyczyścić do pusta, więc wartość musi pochodzić z wejścia,
		// a pole nieprzysłane ma zostać nietknięte. Przycięcie idzie PO
		// `sanitize_text_field()`, bo ta funkcja sama potrafi skrócić string.
		if ( isset( $input['rag_contact_hint'] ) ) {
			$out['rag_contact_hint'] = mb_substr( sanitize_text_field( (string) $input['rag_contact_hint'] ), 0, 120 );
		}

		// --- Kaskada źródeł treści (Krok 17). ---

		// Crawl własnej witryny — wartość LOGICZNA trzymana jako '1'/'0' i przetwarzana
		// WYŁĄCZNIE pod `isset()`.
		//
		// PUŁAPKA (świadomie omijana): naturalny idiom `! empty( $input['crawl_enabled'] )`
		// wyłączyłby crawl przy każdym zapisie, który tego pola nie przysyła — a
		// `RestController::handle_settings_save()` przysyła tylko cztery pola. Skutek:
		// zapisanie klucza API w panelu na froncie gasi crawl, a najbliższy reindeks
		// patroszy bazę wiedzy z treści renderowanej. Dlatego pole nieprzysłane ZOSTAJE
		// bez zmian, a formularz dokłada ukryty input `value="0"` przed checkboxem.
		if ( isset( $input['crawl_enabled'] ) ) {
			$out['crawl_enabled'] = ( '1' === (string) $input['crawl_enabled'] ) ? '1' : '0';
		}

		// Wykluczone slugi — lista po przecinku. Puste tokeny i białe znaki rozbiera
		// dopiero konsument (parsowanie jest wspólne dla bramki i crawla), tutaj tylko
		// odsiewamy znaczniki. Pusty ciąg jest POPRAWNĄ wartością (nic nie wykluczamy).
		if ( isset( $input['crawl_exclude'] ) ) {
			$out['crawl_exclude'] = sanitize_text_field( wp_unslash( $input['crawl_exclude'] ) );
		}

		// Dodatkowe klucze postmeta — DOKŁADANE do listy domyślnej, nie zastępujące jej.
		// Pusty ciąg = „tylko klucze domyślne”, więc jest poprawną wartością.
		if ( isset( $input['meta_keys'] ) ) {
			$out['meta_keys'] = sanitize_text_field( wp_unslash( $input['meta_keys'] ) );
		}

		// Typy wpisów dla źródła postmeta. Pusty ciąg oznaczałby „nie czytaj niczego” —
		// cichą utratę połowy kaskady, więc wracamy do domyślnych `post,page`.
		if ( isset( $input['meta_post_types'] ) ) {
			$types = trim( sanitize_text_field( wp_unslash( $input['meta_post_types'] ) ) );
			$out['meta_post_types'] = ( '' !== $types ) ? $types : 'post,page';
		}

		return $out;
	}

	/**
	 * Reakcja na zapis ustawień: przy ZMIANIE sluga trasy publicznej ustawia flagę,
	 * by przy najbliższym `init` przebudować reguły rewrite. Bez tego zmiana sluga
	 * w panelu kończy się 404 na nowym adresie (reguły są cache'owane w opcji).
	 *
	 * Podpięte pod `update_option_{OPTION}` (patrz {@see \AIFAQ\Core\Plugin}).
	 *
	 * Krok 17: zmiana `crawl_enabled` albo `crawl_exclude` unieważnia to, co crawl
	 * już pobrał (inny zbiór stron = inna treść), więc kasujemy kolejkę razem
	 * z zapisanym HTML-em i zdejmujemy cron. Zostawiamy flagę na komunikat o koszcie —
	 * ponowne indeksowanie oznacza ponowne (płatne) liczenie embeddingów.
	 *
	 * @param mixed $old_value Poprzednia wartość opcji.
	 * @param mixed $new_value Nowa wartość opcji.
	 */
	public function on_settings_updated( $old_value, $new_value ): void {
		$old_slug = is_array( $old_value ) ? (string) ( $old_value['page_slug'] ?? '' ) : '';
		$new_slug = is_array( $new_value ) ? (string) ( $new_value['page_slug'] ?? '' ) : '';

		if ( $old_slug !== $new_slug ) {
			update_option( self::FLUSH_FLAG, '1' );
		}

		$old_crawl = is_array( $old_value ) ? (string) ( $old_value['crawl_enabled'] ?? '1' ) : '1';
		$new_crawl = is_array( $new_value ) ? (string) ( $new_value['crawl_enabled'] ?? '1' ) : '1';
		$old_excl  = is_array( $old_value ) ? (string) ( $old_value['crawl_exclude'] ?? '' ) : '';
		$new_excl  = is_array( $new_value ) ? (string) ( $new_value['crawl_exclude'] ?? '' ) : '';

		if ( $old_crawl === $new_crawl && $old_excl === $new_excl ) {
			return;
		}

		// Klasa należy do innego etapu — brak klasy pomija warunek, nigdy nie wywala.
		if ( class_exists( '\AIFAQ\Index\CrawlQueue' ) ) {
			try {
				( new \AIFAQ\Index\CrawlQueue() )->clear();
			} catch ( \Throwable $e ) {
				// Świadomie cicho: zmiana ustawień nie może się wywrócić przez sprzątanie.
				unset( $e );
			}
			if ( function_exists( 'wp_unschedule_hook' ) ) {
				wp_unschedule_hook( \AIFAQ\Index\CrawlQueue::CRON_HOOK );
			}
		} elseif ( function_exists( 'wp_unschedule_hook' ) ) {
			wp_unschedule_hook( 'aifaq_crawl_tick' );
		}

		update_option( self::CRAWL_NOTICE, '1', false );
	}

	/**
	 * Zapisuje ustawienia z jawnym przejściem przez {@see sanitize()}.
	 *
	 * Ścieżka dla zapisu POZA Settings API (np. REST z frontu) — Settings API
	 * uruchamia `sanitize` tylko przez `options.php`, więc przy bezpośrednim
	 * `update_option` musimy zawołać ją sami. Dzięki temu zapis z frontu i z
	 * kokpitu przechodzi DOKŁADNIE tę samą walidację/clamp (jeden kontrakt).
	 *
	 * @param array<string,mixed> $input Surowe pola (whitelistowane w sanitize).
	 * @return array<string,mixed> Zapisane (zsanityzowane) ustawienia.
	 */
	public static function save( array $input ): array {
		$clean = self::sanitize( $input );
		update_option( self::OPTION, $clean );
		return $clean;
	}

	/**
	 * Realny, lekki ping klucza do Gemini (wspólny rdzeń testu połączenia).
	 *
	 * Idzie przez providera (ta sama ścieżka autoryzacji co generacja) —
	 * Settings nie zna adresu Gemini ani nagłówków; klucz trafia do nagłówka w
	 * providerze, nigdy do URL (GA1). Pusty klucz → fallback do zapisanego
	 * (pole w formularzu bywa zamaskowane po zapisie).
	 *
	 * @param string $api_key Klucz do sprawdzenia (pusty = użyj zapisanego).
	 * @return true|\WP_Error `true` gdy klucz autoryzuje; WP_Error z kodem
	 *                        `aifaq_no_key` (brak klucza) lub błędem providera.
	 */
	public static function verify_key( string $api_key = '' ) {
		$key = trim( $api_key );
		if ( '' === $key ) {
			$key = (string) self::get_field( 'api_key', '' );
		}
		if ( '' === $key ) {
			return new \WP_Error( 'aifaq_no_key', __( 'Podaj klucz API.', 'ai-faq-generator' ) );
		}

		$result = \AIFAQ\Providers\ProviderFactory::make_with_key( $key )->verify();
		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * AJAX: „Test połączenia" — realny, lekki ping klucza do Gemini.
	 *
	 * Cienkie opakowanie {@see verify_key()} dla ekranu Ustawień w kokpicie.
	 */
	public function ajax_test_connection(): void {
		check_ajax_referer( self::NONCE_TEST, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Brak uprawnień.', 'ai-faq-generator' ) ), 403 );
		}

		$api_key = isset( $_POST['api_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) ) : '';
		$result  = self::verify_key( $api_key );

		if ( is_wp_error( $result ) ) {
			$message = ( 'aifaq_no_key' === $result->get_error_code() )
				? $result->get_error_message()
				/* translators: %s: komunikat błędu z providera */
				: sprintf( __( 'Błąd: %s', 'ai-faq-generator' ), $result->get_error_message() );
			wp_send_json_error( array( 'message' => $message ) );
		}

		wp_send_json_success( array( 'message' => __( 'Połączenie OK — klucz działa.', 'ai-faq-generator' ) ) );
	}
}
