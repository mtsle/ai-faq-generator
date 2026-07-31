<?php
/**
 * Odinstalowanie wtyczki — pełne sprzątanie.
 *
 * Uruchamiane przez WordPress WYŁĄCZNIE przy usuwaniu wtyczki
 * (nie przy deaktywacji). Usuwa tabele wtyczki, opcje, transienty i metadane.
 *
 * ZASADA BEZWZGLĘDNA: ten plik działa BEZ bootstrapu wtyczki i BEZ autoloadera.
 * Wolno tu używać WYŁĄCZNIE literałów stringów — `PageGuard::OPTION` byłoby
 * Fatal errorem, bo klasa nie jest załadowana. Każdą funkcję WP spoza
 * gwarantowanego rdzenia bramkujemy `function_exists()`.
 *
 * Kompletność listy kluczy pilnuje statycznie `tests/uninstall-guard-test.php`.
 *
 * @package AI_FAQ_Generator
 */

// Zabezpieczenie: plik może być wywołany tylko przez mechanizm usuwania WP.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! function_exists( 'aifaq_uninstall_cleanup_site' ) ) {
	/**
	 * Sprząta JEDNĄ witrynę (bieżący blog).
	 *
	 * Wydzielone do funkcji, bo w multisite trzeba to wykonać dla każdego bloga
	 * osobno — tabele i opcje są per-blog. Prefiks `aifaq_` w nazwie i osłona
	 * `function_exists()` chronią przed kolizją: plik ładuje się do globalnej
	 * przestrzeni nazw, obok uninstall.php innych wtyczek.
	 *
	 * @return void
	 */
	function aifaq_uninstall_cleanup_site() {
		global $wpdb;

		// Nazwy tabel MUSZĄ powstawać tutaj, a nie raz na starcie pliku:
		// `switch_to_blog()` podmienia `$wpdb->prefix` (wp_2_, wp_3_, …).
		$aifaq_tables = array(
			$wpdb->prefix . 'aifaq_knowledge',
			$wpdb->prefix . 'aifaq_qa_log',
			$wpdb->prefix . 'aifaq_cache',
			$wpdb->prefix . 'aifaq_faq',
			$wpdb->prefix . 'aifaq_generations',
			$wpdb->prefix . 'aifaq_history',
		);

		foreach ( $aifaq_tables as $aifaq_table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$aifaq_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL
		}

		// --- Opcje i flagi wtyczki ---------------------------------------
		delete_option( 'aifaq_db_version' );
		delete_option( 'aifaq_settings' );
		delete_option( 'aifaq_history_migrated' );
		delete_option( 'aifaq_page_id' );
		delete_option( 'aifaq_page_bootstrapped' );

		// Kaskada źródeł treści (Krok 17): stan kolejki pobierania, wynik testu
		// pętli zwrotnej i flaga komunikatu o koszcie.
		delete_option( 'aifaq_crawl_state' );
		delete_option( 'aifaq_loopback' );
		delete_option( 'aifaq_crawl_notice' );
		// Lock paczki crawla — przy braku trwałego object cache siedzi w wp_options
		// (CrawlQueue::LOCK), więc bez tego zostałby po odinstalowaniu wtyczki.
		delete_option( 'aifaq_crawl_lock' );

		// Niezawodność podstrony (Krok 18).
		delete_option( 'aifaq_page_state' );            // Tablica stanu podstrony (6 kluczy).
		delete_option( 'aifaq_page_ok' );               // Tania bramka trójstanowa '1'/'0'/''.
		delete_option( 'aifaq_page_lock' );             // Timestamp zamka `ensure()`.
		delete_option( 'aifaq_page_notice_dismissed' ); // Status, dla którego zamknięto komunikat.

		// Zaległość sprzed Kroku 18: jedyna opcja wtyczki, która przeżywała odinstalowanie.
		delete_option( 'aifaq_flush_needed' );

		// Migracja przestrzeni wektorów (Krok 19).
		delete_option( 'aifaq_index_signature' );   // Podpis bazy wektorów (pełny albo znacznik partial:).
		delete_option( 'aifaq_cache_flushed_for' ); // Wersja, dla której wyczyszczono cache odpowiedzi.

		// Utwardzenie opcji (audyt bezpieczeństwa): znacznik jednorazowego zdjęcia
		// autoloadu z `aifaq_settings` (Plugin::HARDEN_FLAG).
		delete_option( 'aifaq_autoload_hardened' );

		// SEO podstrony (Krok 21 / v0.24.0).
		delete_option( 'aifaq_site_profile' );      // Temat witryny wyprowadzony z bazy wiedzy (SiteProfile).
		delete_option( 'aifaq_public_faq' );        // Pary Q&A opublikowane na podstronie (PublicFaq).
		delete_option( 'aifaq_public_faq_prev' );   // K23 etap 1: ostatnia wersja sprzed nadpisania/zdjęcia (PublicFaq::OPTION_PREV).
		delete_option( 'aifaq_public_faq_lock' );   // K23 etap 5: zamek publikacji (PublicFaq::LOCK) — zwykle zwolniony, ale proces mógł paść w trakcie.

		// --- Menu nawigacji (Krok 20) ------------------------------------
		//
		// NAJPIERW pozycja, POTEM opcje: bez ID i bez znacznika proweniencji nie da się już
		// rozstrzygnąć, co wolno skasować. Usunięcie wtyczki BEZ wcześniejszej deaktywacji
		// (`wp plugin delete` robi dokładnie to) zostawiłoby w nawigacji klienta martwy link
		// do nieistniejącego generatora — jedyną sierotę widoczną dla wszystkich gości.
		// Kasujemy WYŁĄCZNIE pozycję, którą wtyczka sama utworzyła (`owned === '1'`);
		// pozycja dodana ręką klienta jest jego treścią i zostaje nietknięta.
		$aifaq_menu_item  = (int) get_option( 'aifaq_menu_item_id', 0 );
		$aifaq_menu_state = get_option( 'aifaq_menu_state', array() );
		$aifaq_menu_owned = ( is_array( $aifaq_menu_state ) && isset( $aifaq_menu_state['owned'] ) && is_scalar( $aifaq_menu_state['owned'] ) )
			? (string) $aifaq_menu_state['owned']
			: '';

		if ( $aifaq_menu_item > 0 && '1' === $aifaq_menu_owned && function_exists( 'wp_delete_post' ) ) {
			// Pozycja menu jest zwykłym wpisem typu `nav_menu_item` — rdzeń kasuje ją tak samo.
			wp_delete_post( $aifaq_menu_item, true );
		}

		delete_option( 'aifaq_menu_item_id' );          // ID naszej pozycji w menu.
		delete_option( 'aifaq_menu_state' );            // Tablica stanu menu (9 kluczy, w tym `owned`).
		delete_option( 'aifaq_menu_ok' );               // Tania bramka trójstanowa '1'/'0'/''.
		delete_option( 'aifaq_menu_lock' );             // Timestamp zamka `ensure()`.
		delete_option( 'aifaq_menu_notice_dismissed' ); // Stan, dla którego zamknięto komunikat.
		delete_option( 'aifaq_menu_optout' );           // Znacznik „klient usunął link świadomie".

		// Limity i tożsamość gościa (Krok 20). Należą do innych etapów, ale sprzątanie
		// mieszka wyłącznie tutaj — bez tych trzech wpisów zostałyby sierotami w wp_options.
		delete_option( 'aifaq_daily_usage' );  // Licznik dobowego sufitu witryny.
		delete_option( 'aifaq_budget_hit' );   // Znacznik przekroczenia sufitu (komunikat).
		delete_option( 'aifaq_proxy_seen' );   // Sygnał „witryna stoi za proxy" przy wyłączonym przełączniku.

		// --- Transienty o STAŁEJ nazwie ----------------------------------
		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( 'aifaq_indexing_lock' );    // Zamek reindeksu (IndexController::LOCK).
			delete_transient( 'aifaq_cache_flush_lock' ); // Zamek czyszczenia cache (Plugin).
		}

		// --- Transienty o ZMIENNYM sufiksie ------------------------------
		//
		// Nazwa zależy od danych wykonania, więc nie da się ich wymienić po jednym:
		//   • `aifaq_rl_<ip_hash>`               — RateLimiter::PREFIX (limit na gościa),
		//   • `aifaq_cooldown_generate_<model>`  — GeminiProvider, wyłącznik obwodu (pula generate),
		//   • `aifaq_cooldown_embed_<model>`     — GeminiProvider, wyłącznik obwodu (pula embed),
		//   • `aifaq_no_thinking_<model>`        — GeminiProvider, model odrzucił `thinkingConfig`.
		//
		// Zamiast trzech osobnych wzorców jeden, obejmujący WSZYSTKIE transienty wtyczki:
		// każdy klucz wtyczki zaczyna się od `aifaq_`, więc wzorzec pokrywa też każdy
		// przyszły transient — a jednocześnie nie dotyka niczego cudzego.
		//
		// GUARD-PATTERN: aifaq_
		// (znacznik czytany przez tests/uninstall-guard-test.php — nie usuwać)
		//
		// OGRANICZENIE: przy ZEWNĘTRZNYM trwałym object cache (Redis, Memcached)
		// transienty NIE trafiają do `wp_options` i ta ścieżka ich nie obejmie.
		// Wygasną same po TTL — najdłuższy to godzina (cooldown limitu dobowego).
		if ( isset( $wpdb ) && method_exists( $wpdb, 'esc_like' ) ) {
			$aifaq_like_value   = $wpdb->esc_like( '_transient_aifaq_' ) . '%';
			$aifaq_like_timeout = $wpdb->esc_like( '_transient_timeout_aifaq_' ) . '%';

			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL
					$aifaq_like_value,
					$aifaq_like_timeout
				)
			);
		}

		// --- Metadane ----------------------------------------------------

		// Wyciszenie komunikatu edytora siedzi w metadanych UŻYTKOWNIKA, nie w opcjach
		// (EditorNotice::USER_META). `delete_all = true` kasuje wpis wszystkim użytkownikom.
		if ( function_exists( 'delete_metadata' ) ) {
			delete_metadata( 'user', 0, 'aifaq_editor_hint_done', '', true );
		}

		// Wyrenderowany HTML podstron zapisany w postmeta (`RenderedContentSource::META_KEY`).
		// To kopia treści witryny — przy usuwaniu wtyczki nie ma powodu jej trzymać.
		if ( function_exists( 'delete_post_meta_by_key' ) ) {
			delete_post_meta_by_key( '_aifaq_rendered' );
		}

		// --- Cron --------------------------------------------------------
		// Zdejmij OBA crony wtyczki — bez tego zostałoby zadanie bez obsługi.
		// `aifaq_reindex_continue` (wznawianie reindeksu po wyczerpaniu budżetu)
		// jest zdejmowany także w `Deactivator`, ale na tym polegać nie można:
		// przy wtyczce aktywowanej dla całej SIECI hook deaktywacji odpala się
		// raz, a crony siedzą osobno w każdym blogu — dlatego to sprzątanie
		// biegnie w pętli `switch_to_blog()` niżej i musi znać komplet.
		if ( function_exists( 'wp_unschedule_hook' ) ) {
			wp_unschedule_hook( 'aifaq_crawl_tick' );
			wp_unschedule_hook( 'aifaq_reindex_continue' );
		}

		// UWAGA: samej podstrony „Generator FAQ" NIE kasujemy — to treść w witrynie
		// klienta (mógł ją przerobić, podlinkować w menu). Usunięcie zostawiamy jemu.
	}
}

// --- Wywołanie: pojedyncza witryna albo cała sieć ---------------------------
//
// W multisite wtyczka trzyma osobny komplet tabel i opcji w KAŻDYM blogu, więc
// sprzątanie tylko bieżącego zostawiałoby resztę sieci zaśmieconą. Limit `number`
// jest jawny, bo `get_sites()` bez niego zwraca domyślnie 100 witryn (cicha strata),
// a bez żadnego limitu duża sieć wywróciłaby pamięć.
if ( function_exists( 'is_multisite' ) && is_multisite()
	&& function_exists( 'get_sites' ) && function_exists( 'switch_to_blog' ) && function_exists( 'restore_current_blog' ) ) {

	$aifaq_site_ids = get_sites(
		array(
			'number' => 10000,
			'fields' => 'ids',
		)
	);

	foreach ( (array) $aifaq_site_ids as $aifaq_site_id ) {
		switch_to_blog( (int) $aifaq_site_id );
		aifaq_uninstall_cleanup_site();
		restore_current_blog();
	}
} else {
	aifaq_uninstall_cleanup_site();
}
