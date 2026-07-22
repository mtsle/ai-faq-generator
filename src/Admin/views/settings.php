<?php
/**
 * Widok: Ustawienia (konfiguracja API).
 *
 * Formularz zapisywany przez Settings API (options.php).
 * Sanityzacja odbywa się w AIFAQ\Core\Settings::sanitize().
 *
 * @package AI_FAQ_Generator
 */

use AIFAQ\Core\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$aifaq         = Settings::get();
$aifaq_models  = Settings::models();
$aifaq_langs   = Settings::languages();
$aifaq_test    = wp_create_nonce( Settings::NONCE_TEST );
$aifaq_has_key = '' !== (string) ( $aifaq['api_key'] ?? '' );

// Lokalizacje menu zarejestrowane przez motyw (Krok 20). Pusta lista = motyw blokowy
// albo motyw bez klasycznych menu — wtedy zamiast pustego <select> pokazujemy jawny
// komunikat, bo cicho pusta lista wygląda jak awaria wtyczki.
$aifaq_nav_locations = function_exists( 'get_registered_nav_menus' ) ? (array) get_registered_nav_menus() : array();

// Okna limitu gościa — whitelista spójna z Settings::sanitize().
$aifaq_rate_windows = array(
	'godzina' => __( 'godzina', 'ai-faq-generator' ),
	'doba'    => __( 'doba', 'ai-faq-generator' ),
);

// Etykiety języków dla komunikatów odmowy (RAG) — spójne z whitelistą języków.
$aifaq_refusal_langs = array(
	'pl' => __( 'polski', 'ai-faq-generator' ),
	'en' => __( 'angielski', 'ai-faq-generator' ),
	'de' => __( 'niemiecki', 'ai-faq-generator' ),
);
?>
<div class="wrap aifaq-wrap">
	<h1 class="aifaq-title">
		<span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>
		<?php esc_html_e( 'AI FAQ Generator', 'ai-faq-generator' ); ?>
		<span class="aifaq-sub"><?php esc_html_e( 'Ustawienia', 'ai-faq-generator' ); ?></span>
	</h1>

	<?php if ( isset( $_GET['settings-updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<div class="notice notice-success is-dismissible">
			<p><?php esc_html_e( 'Ustawienia zapisane.', 'ai-faq-generator' ); ?></p>
		</div>
	<?php endif; ?>

	<form method="post" action="options.php" class="aifaq-settings-form">
		<?php settings_fields( Settings::GROUP ); ?>

		<table class="form-table" role="presentation">
			<tbody>

				<tr>
					<th scope="row"><?php esc_html_e( 'Dostawca AI', 'ai-faq-generator' ); ?></th>
					<td>
						<label class="aifaq-provider">
							<input type="radio" name="aifaq_settings[provider]" value="gemini" checked>
							<strong>Google Gemini</strong>
							<span class="aifaq-pill-free"><?php esc_html_e( 'DARMOWE', 'ai-faq-generator' ); ?></span>
						</label>
						<p class="description"><?php esc_html_e( 'Domyślny, darmowy dostawca. Kolejni (Groq / OpenAI / Claude) mogą dojść w przyszłości.', 'ai-faq-generator' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="aifaq-api-key"><?php esc_html_e( 'Klucz API', 'ai-faq-generator' ); ?></label>
					</th>
					<td>
						<div class="aifaq-key-row">
							<input
								type="password"
								id="aifaq-api-key"
								name="aifaq_settings[api_key]"
								value=""
								placeholder="<?php echo $aifaq_has_key ? esc_attr__( 'Klucz zapisany — wklej nowy, aby zmienić', 'ai-faq-generator' ) : esc_attr__( 'Wklej klucz API Gemini', 'ai-faq-generator' ); ?>"
								class="regular-text"
								autocomplete="off"
								spellcheck="false"
							>
							<button type="button" class="button" id="aifaq-toggle-key"><?php esc_html_e( 'Pokaż', 'ai-faq-generator' ); ?></button>
							<button
								type="button"
								class="button button-secondary"
								id="aifaq-test-connection"
								data-nonce="<?php echo esc_attr( $aifaq_test ); ?>"
							><?php esc_html_e( 'Test połączenia', 'ai-faq-generator' ); ?></button>
							<span id="aifaq-test-status" class="aifaq-test-status" role="status" aria-live="polite"></span>
						</div>
						<p class="description">
							<?php esc_html_e( 'Wklej własny klucz. Darmowy klucz Gemini zdobędziesz bez karty płatniczej:', 'ai-faq-generator' ); ?>
							<a href="https://aistudio.google.com/app/apikey" target="_blank" rel="noopener noreferrer">
								<?php esc_html_e( 'Zdobądź darmowy klucz →', 'ai-faq-generator' ); ?>
							</a>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="aifaq-model"><?php esc_html_e( 'Model', 'ai-faq-generator' ); ?></label>
					</th>
					<td>
						<select id="aifaq-model" name="aifaq_settings[model]">
							<?php foreach ( $aifaq_models as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $aifaq['model'], $value ); ?>>
									<?php echo esc_html( $label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Modele oznaczone „WYMAGA KLUCZA PŁATNEGO" mają na kluczu darmowym przydział ZERO — pierwsze pytanie skończy się błędem. Uwaga: „Test połączenia" sprawdza sam klucz, a nie przydział wybranego modelu (realna próba kosztowałaby jedno z 20 dobowych żądań przy każdym kliknięciu).', 'ai-faq-generator' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="aifaq-temperature">
							<?php esc_html_e( 'Temperatura', 'ai-faq-generator' ); ?>
							· <span id="aifaq-temp-value"><?php echo esc_html( number_format( (float) $aifaq['temperature'], 1 ) ); ?></span>
						</label>
					</th>
					<td>
						<input
							type="range"
							id="aifaq-temperature"
							name="aifaq_settings[temperature]"
							min="0" max="1" step="0.1"
							value="<?php echo esc_attr( $aifaq['temperature'] ); ?>"
							class="aifaq-range"
						>
						<p class="description"><?php esc_html_e( '0 = zwięźle i przewidywalnie · 1 = swobodniej.', 'ai-faq-generator' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="aifaq-max-questions"><?php esc_html_e( 'Maksymalna liczba pytań', 'ai-faq-generator' ); ?></label>
					</th>
					<td>
						<input
							type="number"
							id="aifaq-max-questions"
							name="aifaq_settings[max_questions]"
							min="5" max="20"
							value="<?php echo esc_attr( $aifaq['max_questions'] ); ?>"
							class="small-text"
						>
						<p class="description"><?php esc_html_e( 'Górny limit pytań na jedną generację (5–20).', 'ai-faq-generator' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="aifaq-language"><?php esc_html_e( 'Język FAQ', 'ai-faq-generator' ); ?></label>
					</th>
					<td>
						<select id="aifaq-language" name="aifaq_settings[language]">
							<?php foreach ( $aifaq_langs as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $aifaq['language'], $value ); ?>>
									<?php echo esc_html( $label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="aifaq-page-slug"><?php esc_html_e( 'Adres publicznej strony', 'ai-faq-generator' ); ?></label>
					</th>
					<td>
						<code><?php echo esc_html( trailingslashit( home_url( '/' ) ) ); ?></code>
						<input
							type="text"
							id="aifaq-page-slug"
							name="aifaq_settings[page_slug]"
							value="<?php echo esc_attr( $aifaq['page_slug'] ); ?>"
							class="regular-text"
							style="width:14rem"
						>
						<p class="description"><?php esc_html_e( 'Slug publicznego generatora dla gości (domyślnie „faqgenerator"). Po zmianie zapisz ustawienia stałych bezpośrednich, jeśli trasa nie zadziała.', 'ai-faq-generator' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row" colspan="2" class="aifaq-section-head">
						<h2 style="margin:1.5em 0 .2em;"><?php esc_html_e( 'Generator zawężony do tematu (RAG)', 'ai-faq-generator' ); ?></h2>
						<p class="description" style="font-weight:normal;"><?php esc_html_e( 'Pokrętła publicznego generatora dla gości: dobór trafnych fragmentów, odcięcie pytań spoza tematu strony i ochrona kosztu klucza.', 'ai-faq-generator' ); ?></p>
					</th>
				</tr>

				<tr>
					<th scope="row">
						<label for="aifaq-rag-threshold"><?php esc_html_e( 'Próg dopasowania tematu', 'ai-faq-generator' ); ?></label>
					</th>
					<td>
						<input
							type="number"
							id="aifaq-rag-threshold"
							name="aifaq_settings[rag_threshold]"
							min="0.7" max="1" step="0.05"
							value="<?php echo esc_attr( $aifaq['rag_threshold'] ); ?>"
							class="small-text"
						>
						<p class="description"><?php esc_html_e( 'Minimalne podobieństwo pytania do treści strony. Poniżej progu → grzeczna odmowa. Wyżej = ostrzej. Wartość 0,70 jest SKALIBROWANA POMIAREM na realnych pytaniach i stanowi dolną granicę — niższą wpisaną wartość wtyczka podniesie z powrotem do 0,70, bo obniżenie wpuszcza pytania spoza tematu strony (i płaci za nie z Twojego limitu).', 'ai-faq-generator' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="aifaq-rag-threshold-hard"><?php esc_html_e( 'Próg twardy (odcięcie fragmentów)', 'ai-faq-generator' ); ?></label>
					</th>
					<td>
						<input
							type="number"
							id="aifaq-rag-threshold-hard"
							name="aifaq_settings[rag_threshold_hard]"
							min="0.65" max="1" step="0.05"
							value="<?php echo esc_attr( $aifaq['rag_threshold_hard'] ); ?>"
							class="small-text"
						>
						<p class="description"><?php esc_html_e( 'Fragmenty o dopasowaniu poniżej tej wartości w ogóle nie trafiają do odpowiedzi. Wartość 0,65 jest SKALIBROWANA POMIAREM (najwyższe dopasowanie pytania spoza tematu wyniosło 0,62) i stanowi dolną granicę — niższą wpisaną wartość wtyczka podniesie z powrotem do 0,65, bo obniżenie wpuszcza pytania spoza tematu strony. Musi być mniejszy lub równy progowi dopasowania tematu — wyższa wartość zostanie po cichu obniżona do niego.', 'ai-faq-generator' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="aifaq-rag-top-k"><?php esc_html_e( 'Liczba fragmentów (top-K)', 'ai-faq-generator' ); ?></label>
					</th>
					<td>
						<input
							type="number"
							id="aifaq-rag-top-k"
							name="aifaq_settings[rag_top_k]"
							min="1" max="10"
							value="<?php echo esc_attr( $aifaq['rag_top_k'] ); ?>"
							class="small-text"
						>
						<p class="description"><?php esc_html_e( 'Ile najlepiej pasujących fragmentów trafia do odpowiedzi (1–10).', 'ai-faq-generator' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="aifaq-rag-rate-limit"><?php esc_html_e( 'Limit pytań na gościa', 'ai-faq-generator' ); ?></label>
					</th>
					<td>
						<input
							type="number"
							id="aifaq-rag-rate-limit"
							name="aifaq_settings[rag_rate_limit]"
							min="0" max="200"
							value="<?php echo esc_attr( $aifaq['rag_rate_limit'] ); ?>"
							class="small-text"
						>
						<p class="description"><?php esc_html_e( 'Maks. pytań od jednego gościa w jednym oknie czasu. Długość okna ustawiasz niżej, w sekcji „Limity” (domyślnie godzina). 0 = bez limitu. Każde pytanie kosztuje dwa żądania do dostawcy AI, więc to pierwsza linia obrony Twojej dobowej puli.', 'ai-faq-generator' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="aifaq-rag-temperature">
							<?php esc_html_e( 'Temperatura odpowiedzi', 'ai-faq-generator' ); ?>
							· <span id="aifaq-rag-temp-value"><?php echo esc_html( number_format( (float) $aifaq['rag_temperature'], 1 ) ); ?></span>
						</label>
					</th>
					<td>
						<input
							type="range"
							id="aifaq-rag-temperature"
							name="aifaq_settings[rag_temperature]"
							min="0" max="1" step="0.1"
							value="<?php echo esc_attr( $aifaq['rag_temperature'] ); ?>"
							class="aifaq-range"
						>
						<p class="description"><?php esc_html_e( 'Niska (≈0.2) = odpowiedzi trzymają się treści strony. Osobna od temperatury generacji FAQ.', 'ai-faq-generator' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="aifaq-rag-max-tokens"><?php esc_html_e( 'Maks. długość odpowiedzi', 'ai-faq-generator' ); ?></label>
					</th>
					<td>
						<input
							type="number"
							id="aifaq-rag-max-tokens"
							name="aifaq_settings[rag_max_tokens]"
							min="64" max="2048"
							value="<?php echo esc_attr( $aifaq['rag_max_tokens'] ); ?>"
							class="small-text"
						>
						<p class="description"><?php esc_html_e( 'Górny limit długości odpowiedzi w tokenach (64–2048).', 'ai-faq-generator' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="aifaq-rag-thinking-budget"><?php esc_html_e( 'Budżet myślenia modelu', 'ai-faq-generator' ); ?></label>
					</th>
					<td>
						<input
							type="number"
							id="aifaq-rag-thinking-budget"
							name="aifaq_settings[rag_thinking_budget]"
							min="-1" max="24576" step="any"
							value="<?php echo esc_attr( $aifaq['rag_thinking_budget'] ); ?>"
							class="small-text"
						>
						<p class="description"><?php esc_html_e( '0 = myślenie wyłączone (zalecane — cały limit długości idzie na odpowiedź). -1 = model decyduje sam. Wartości dodatnie: od 128 do 24576 tokenów rozumowania; 1–127 API odrzuca.', 'ai-faq-generator' ); ?></p>
					</td>
				</tr>

				<?php foreach ( $aifaq_refusal_langs as $aifaq_lang => $aifaq_lang_label ) : ?>
					<tr>
						<th scope="row">
							<label for="aifaq-rag-refusal-<?php echo esc_attr( $aifaq_lang ); ?>">
								<?php
								/* translators: %s: nazwa języka */
								echo esc_html( sprintf( __( 'Komunikat odmowy (%s)', 'ai-faq-generator' ), $aifaq_lang_label ) );
								?>
							</label>
						</th>
						<td>
							<textarea
								id="aifaq-rag-refusal-<?php echo esc_attr( $aifaq_lang ); ?>"
								name="aifaq_settings[rag_refusal_message_<?php echo esc_attr( $aifaq_lang ); ?>]"
								rows="2"
								class="large-text"
							><?php echo esc_textarea( (string) ( $aifaq[ 'rag_refusal_message_' . $aifaq_lang ] ?? '' ) ); ?></textarea>
						</td>
					</tr>
				<?php endforeach; ?>

				<tr>
					<th scope="row">
						<label for="aifaq-rag-contact-hint"><?php esc_html_e( 'Dane kontaktowe podpowiadane w odpowiedziach', 'ai-faq-generator' ); ?></label>
					</th>
					<td>
						<input
							type="text"
							id="aifaq-rag-contact-hint"
							name="aifaq_settings[rag_contact_hint]"
							size="60"
							maxlength="120"
							value="<?php echo esc_attr( (string) ( $aifaq['rag_contact_hint'] ?? '' ) ); ?>"
						>
						<p class="description"><?php esc_html_e( 'np. »tel. 123 456 789, biuro@przyklad.pl«. Zostaw puste, żeby bot odsyłał ogólnie do zakładki Kontakt.', 'ai-faq-generator' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row" colspan="2" class="aifaq-section-head">
						<h2 style="margin:1.5em 0 .2em;"><?php esc_html_e( 'Źródła treści bazy wiedzy', 'ai-faq-generator' ); ?></h2>
						<p class="description" style="font-weight:normal;"><?php esc_html_e( 'Skąd wtyczka bierze treść do indeksowania. Sama treść wpisów i stron to zwykle ułamek tego, co widzi gość — reszta siedzi w polach własnych i w szablonie motywu.', 'ai-faq-generator' ); ?></p>
					</th>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Pobieranie własnych podstron', 'ai-faq-generator' ); ?></th>
					<td>
						<?php
						/*
						 * UKRYTY INPUT MUSI STAĆ PRZED CHECKBOXEM.
						 * Odznaczony checkbox nie wysyła NICZEGO, a Settings::sanitize()
						 * przetwarza to pole wyłącznie pod isset() (patrz komentarz przy
						 * sanitize — idiom `! empty()` gasił crawl przy zapisie z frontu).
						 * Bez tej pary „odznacz i zapisz" nie miałoby żadnego skutku.
						 */
						?>
						<input type="hidden" name="aifaq_settings[crawl_enabled]" value="0">
						<label for="aifaq-crawl-enabled">
							<input
								type="checkbox"
								id="aifaq-crawl-enabled"
								name="aifaq_settings[crawl_enabled]"
								value="1"
								<?php checked( '1', (string) ( $aifaq['crawl_enabled'] ?? '1' ) ); ?>
							>
							<?php esc_html_e( 'Pobieraj własne podstrony tak, jak widzi je gość (zalecane)', 'ai-faq-generator' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Serwer w tle odwiedza Twoje podstrony i czyta z nich gotowy tekst — także ten, który motyw dokłada z szablonu, a którego nie ma w treści wpisu. Pobieranie idzie paczkami w tle (cron), bez logowania. Wyłącz, jeśli hosting blokuje ruch „sam do siebie”.', 'ai-faq-generator' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="aifaq-crawl-exclude"><?php esc_html_e( 'Wyklucz strony (slugi po przecinku) — z WSZYSTKICH źródeł', 'ai-faq-generator' ); ?></label>
					</th>
					<td>
						<input
							type="text"
							id="aifaq-crawl-exclude"
							name="aifaq_settings[crawl_exclude]"
							value="<?php echo esc_attr( (string) ( $aifaq['crawl_exclude'] ?? '' ) ); ?>"
							class="large-text"
							placeholder="koszyk, moje-konto, regulamin"
						>
						<p class="description"><?php esc_html_e( 'Np. „koszyk, moje-konto”. Wykluczenie działa na CAŁĄ kaskadę: taka strona nie trafi do bazy wiedzy ani z treści wpisu, ani z pól własnych, ani z pobierania. Porównujemy pełny segment adresu, nie fragment.', 'ai-faq-generator' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="aifaq-meta-keys"><?php esc_html_e( 'Dodatkowe pola własne (klucze po przecinku)', 'ai-faq-generator' ); ?></label>
					</th>
					<td>
						<input
							type="text"
							id="aifaq-meta-keys"
							name="aifaq_settings[meta_keys]"
							value="<?php echo esc_attr( (string) ( $aifaq['meta_keys'] ?? '' ) ); ?>"
							class="large-text"
							placeholder="kadra_bio, program_opis"
						>
						<p class="description"><?php esc_html_e( 'Wtyczka i tak czyta pola o typowych nazwach (bio, opis, treść, description, content, text). Tutaj DOKŁADASZ własne — nie zastępujesz listy domyślnej. Pola techniczne (zaczynające się od podkreślnika) i pola SEO są pomijane.', 'ai-faq-generator' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="aifaq-meta-post-types"><?php esc_html_e( 'Typy wpisów dla pól własnych', 'ai-faq-generator' ); ?></label>
					</th>
					<td>
						<input
							type="text"
							id="aifaq-meta-post-types"
							name="aifaq_settings[meta_post_types]"
							value="<?php echo esc_attr( (string) ( $aifaq['meta_post_types'] ?? 'post,page' ) ); ?>"
							class="regular-text"
							placeholder="post,page"
						>
						<p class="description"><?php esc_html_e( 'Domyślnie wąsko: „post,page”. Dopisz własne typy treści tylko wtedy, gdy naprawdę mają być publicznie odpowiadalne — wszystko, co trafi do bazy wiedzy, generator może powtórzyć anonimowemu gościowi.', 'ai-faq-generator' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row"></th>
					<td>
						<p class="description">
							<strong><?php esc_html_e( 'Uwaga o koszcie:', 'ai-faq-generator' ); ?></strong>
							<?php esc_html_e( 'Zmiana dwóch pierwszych ustawień kasuje to, co już pobrano, i wymaga ponownego zaindeksowania treści — a to ponowne, płatne liczenie embeddingów u dostawcy AI.', 'ai-faq-generator' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row" colspan="2" class="aifaq-section-head">
						<h2 style="margin:1.5em 0 .2em;"><?php esc_html_e( 'Link w menu nawigacji', 'ai-faq-generator' ); ?></h2>
						<p class="description" style="font-weight:normal;"><?php esc_html_e( 'Bez linku gość nie ma jak trafić do publicznego generatora — podstrona istnieje, ale nie prowadzi do niej żadne łącze. Wtyczka dokłada pozycję wyłącznie do menu, które motyw JUŻ wyświetla; sama nigdy nie tworzy nowego menu ani nie przypina go do lokalizacji, bo mogłaby tym zastąpić całą nawigację Twojej strony.', 'ai-faq-generator' ); ?></p>
					</th>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Pozycja w menu', 'ai-faq-generator' ); ?></th>
					<td>
						<?php
						/*
						 * UKRYTY INPUT MUSI STAĆ PRZED CHECKBOXEM (jak przy `crawl_enabled`).
						 * Odznaczony checkbox nie wysyła NICZEGO, a Settings::sanitize()
						 * przetwarza to pole wyłącznie pod isset() — inaczej zapis czterech
						 * pól z panelu na froncie gasiłby link NA TRWAŁE (stan „wyłączone"
						 * jest dla bramki terminalny).
						 */
						?>
						<input type="hidden" name="aifaq_settings[menu_link_enabled]" value="0">
						<label for="aifaq-menu-link-enabled">
							<input
								type="checkbox"
								id="aifaq-menu-link-enabled"
								name="aifaq_settings[menu_link_enabled]"
								value="1"
								<?php checked( '1', (string) ( $aifaq['menu_link_enabled'] ?? '1' ) ); ?>
							>
							<?php esc_html_e( 'Dodaj link do generatora w menu nawigacji (zalecane)', 'ai-faq-generator' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Wyłączenie dotyczy przyszłych instalacji — istniejącej pozycji wtyczka sama nie usunie. Żeby zdjąć link już teraz, skasuj go ręcznie w Wygląd → Menu albo wyłącz wtyczkę (deaktywacja usuwa pozycję, którą wtyczka utworzyła).', 'ai-faq-generator' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="aifaq-menu-location"><?php esc_html_e( 'Lokalizacja menu', 'ai-faq-generator' ); ?></label>
					</th>
					<td>
						<?php if ( empty( $aifaq_nav_locations ) ) : ?>
							<p class="description">
								<strong><?php esc_html_e( 'Ten motyw nie udostępnia klasycznych menu.', 'ai-faq-generator' ); ?></strong>
								<?php esc_html_e( 'Nie zarejestrował żadnej lokalizacji menu (typowe dla motywów blokowych, gdzie nawigację składa się w Wygląd → Edytor). Wtyczka nie ma gdzie dołożyć pozycji — link do generatora dodaj ręcznie w edytorze nawigacji.', 'ai-faq-generator' ); ?>
							</p>
						<?php else : ?>
							<select id="aifaq-menu-location" name="aifaq_settings[menu_location]">
								<option value="" <?php selected( '', (string) ( $aifaq['menu_location'] ?? '' ) ); ?>>
									<?php esc_html_e( 'Automatycznie (pierwsze z: primary, main, header, menu-1, top)', 'ai-faq-generator' ); ?>
								</option>
								<?php foreach ( $aifaq_nav_locations as $aifaq_loc => $aifaq_loc_label ) : ?>
									<option value="<?php echo esc_attr( $aifaq_loc ); ?>" <?php selected( (string) $aifaq_loc, (string) ( $aifaq['menu_location'] ?? '' ) ); ?>>
										<?php echo esc_html( $aifaq_loc_label . ' (' . $aifaq_loc . ')' ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Gdzie ma trafić link. Przy wyborze automatycznym wtyczka bierze pierwszą lokalizację o typowej nazwie głównego menu — a gdy żadna nie pasuje, NIE zgaduje (link w menu ikon społecznościowych albo w stopce psułby wygląd strony) i prosi tutaj o wskazanie ręczne. Jeśli do wybranej lokalizacji nie jest przypięte żadne menu, dostaniesz komunikat w kokpicie.', 'ai-faq-generator' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="aifaq-menu-label"><?php esc_html_e( 'Etykieta pozycji', 'ai-faq-generator' ); ?></label>
					</th>
					<td>
						<input
							type="text"
							id="aifaq-menu-label"
							name="aifaq_settings[menu_label]"
							value="<?php echo esc_attr( (string) ( $aifaq['menu_label'] ?? 'Generator FAQ' ) ); ?>"
							class="regular-text"
							maxlength="60"
						>
						<p class="description"><?php esc_html_e( 'Tekst widoczny w menu (maks. 60 znaków). Puste pole wraca do „Generator FAQ". Zmiana etykiety nie przenosi pozycji — nazwę istniejącej możesz też poprawić w Wygląd → Menu.', 'ai-faq-generator' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row" colspan="2" class="aifaq-section-head">
						<h2 style="margin:1.5em 0 .2em;"><?php esc_html_e( 'Historia generowań', 'ai-faq-generator' ); ?></h2>
						<p class="description" style="font-weight:normal;"><?php esc_html_e( 'Ile zapisanych generacji FAQ trzymać w bazie. Domyślnie wtyczka NIE KASUJE NICZEGO — sprzątanie włączasz świadomie, bo to jedyna kopia tych danych.', 'ai-faq-generator' ); ?></p>
					</th>
				</tr>

				<tr>
					<th scope="row">
						<label for="aifaq-generations-keep-rows"><?php esc_html_e( 'Trzymaj ostatnich generacji', 'ai-faq-generator' ); ?></label>
					</th>
					<td>
						<input
							type="number"
							id="aifaq-generations-keep-rows"
							name="aifaq_settings[generations_keep_rows]"
							min="0" max="5000"
							value="<?php echo esc_attr( (string) ( $aifaq['generations_keep_rows'] ?? 0 ) ); ?>"
							class="small-text"
						>
						<p class="description"><?php esc_html_e( '0 = bez ograniczeń (nic nie jest kasowane). Wartość dodatnia zostawia tylko tyle najnowszych wpisów — starsze zostaną trwale usunięte przy najbliższym generowaniu. Rozsądna wartość na start: 200.', 'ai-faq-generator' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="aifaq-generations-keep-days"><?php esc_html_e( 'Trzymaj generacje przez (dni)', 'ai-faq-generator' ); ?></label>
					</th>
					<td>
						<input
							type="number"
							id="aifaq-generations-keep-days"
							name="aifaq_settings[generations_keep_days]"
							min="0" max="3650"
							value="<?php echo esc_attr( (string) ( $aifaq['generations_keep_days'] ?? 0 ) ); ?>"
							class="small-text"
						>
						<p class="description"><?php esc_html_e( '0 = bez ograniczeń. Wartość dodatnia oznacza, że wpisy starsze niż podana liczba dni zostaną trwale usunięte przy najbliższym generowaniu. Oba warunki działają niezależnie: wpis ginie, gdy jest za stary ALBO wypada poza ustawioną liczbę najnowszych. Rozsądna wartość na start: 90.', 'ai-faq-generator' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row" colspan="2" class="aifaq-section-head">
						<h2 style="margin:1.5em 0 .2em;"><?php esc_html_e( 'Limity', 'ai-faq-generator' ); ?></h2>
						<p class="description" style="font-weight:normal;"><?php esc_html_e( 'Ochrona Twojego klucza przed wyczerpaniem. Darmowy klucz Gemini ma 20 żądań na dobę na model, a każde pytanie gościa to dwa żądania (wyszukanie treści + odpowiedź).', 'ai-faq-generator' ); ?></p>
					</th>
				</tr>

				<tr>
					<th scope="row">
						<label for="aifaq-rag-rate-window"><?php esc_html_e( 'Okno limitu gościa', 'ai-faq-generator' ); ?></label>
					</th>
					<td>
						<select id="aifaq-rag-rate-window" name="aifaq_settings[rag_rate_window]">
							<?php foreach ( $aifaq_rate_windows as $aifaq_win => $aifaq_win_label ) : ?>
								<option value="<?php echo esc_attr( $aifaq_win ); ?>" <?php selected( (string) $aifaq_win, (string) ( $aifaq['rag_rate_window'] ?? 'godzina' ) ); ?>>
									<?php echo esc_html( $aifaq_win_label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Okres, w którym liczony jest „Limit pytań na gościa" ustawiony wyżej. Okno jest kotwiczone kalendarzowo (bieżąca godzina albo bieżąca doba), a nie liczone od pierwszego pytania.', 'ai-faq-generator' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="aifaq-rag-daily-budget"><?php esc_html_e( 'Dobowy sufit całej witryny', 'ai-faq-generator' ); ?></label>
					</th>
					<td>
						<input
							type="number"
							id="aifaq-rag-daily-budget"
							name="aifaq_settings[rag_daily_budget]"
							min="0" max="10000"
							value="<?php echo esc_attr( (string) ( $aifaq['rag_daily_budget'] ?? 12 ) ); ?>"
							class="small-text"
						>
						<p class="description"><?php esc_html_e( 'Łączna liczba pytań gości na dobę, licząc wszystkich razem. Po jej przekroczeniu generator odmawia do końca doby. Domyślne 12 (a nie 20) zostawia zapas na ponowne indeksowanie treści i na Twoje własne testy — jako administrator jesteś z tego sufitu wyłączony, choć Twoje pytania też zjadają pulę u dostawcy. 0 = sufit wyłączony; ustaw tak tylko przy kluczu PŁATNYM. Uwaga: pule „wyszukiwanie" i „odpowiedzi" są u dostawcy odrębne — wyczerpanie jednej nie blokuje drugiej.', 'ai-faq-generator' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Adres gościa zza proxy', 'ai-faq-generator' ); ?></th>
					<td>
						<?php
						/*
						 * UKRYTY INPUT MUSI STAĆ PRZED CHECKBOXEM — patrz komentarz przy
						 * `menu_link_enabled` i `crawl_enabled`. Bez niego zapis czterech pól
						 * z panelu na froncie wyłączałby zaufany proxy, a za Cloudflare
						 * wszyscy goście wracaliby do jednego wspólnego kubełka limitera.
						 */
						?>
						<input type="hidden" name="aifaq_settings[rag_trusted_proxy]" value="0">
						<label for="aifaq-rag-trusted-proxy">
							<input
								type="checkbox"
								id="aifaq-rag-trusted-proxy"
								name="aifaq_settings[rag_trusted_proxy]"
								value="1"
								<?php checked( '1', (string) ( $aifaq['rag_trusted_proxy'] ?? '0' ) ); ?>
							>
							<?php esc_html_e( 'Ufaj nagłówkom proxy (Cloudflare, load balancer) przy rozpoznawaniu gościa', 'ai-faq-generator' ); ?>
						</label>
						<p class="description">
							<strong><?php esc_html_e( 'Włącz TYLKO, jeśli witryna naprawdę stoi za proxy.', 'ai-faq-generator' ); ?></strong>
							<?php esc_html_e( 'Na witrynie bez proxy ta opcja pozwala KAŻDEMU gościowi ominąć limit pytań — wystarczy, że sam dopisze do żądania odpowiedni nagłówek. Gdy jest wyłączona, a witryna stoi za proxy, wszyscy goście trafiają do jednego wspólnego licznika (limit działa wtedy jak jeden na całą stronę). Włączenie zmienia sposób rozpoznawania gości, więc jednorazowo zeruje ich bieżące liczniki.', 'ai-faq-generator' ); ?>
						</p>
					</td>
				</tr>

			</tbody>
		</table>

		<?php submit_button( __( 'Zapisz zmiany', 'ai-faq-generator' ) ); ?>
	</form>
</div>
