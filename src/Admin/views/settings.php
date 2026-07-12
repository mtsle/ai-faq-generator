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
							min="0" max="1" step="0.05"
							value="<?php echo esc_attr( $aifaq['rag_threshold'] ); ?>"
							class="small-text"
						>
						<p class="description"><?php esc_html_e( 'Minimalne podobieństwo pytania do treści strony (0–1). Poniżej progu → grzeczna odmowa. Wyżej = ostrzej.', 'ai-faq-generator' ); ?></p>
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
						<label for="aifaq-rag-rate-limit"><?php esc_html_e( 'Limit pytań na godzinę', 'ai-faq-generator' ); ?></label>
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
						<p class="description"><?php esc_html_e( 'Maks. pytań od jednego gościa w ciągu godziny. 0 = bez limitu.', 'ai-faq-generator' ); ?></p>
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

			</tbody>
		</table>

		<?php submit_button( __( 'Zapisz zmiany', 'ai-faq-generator' ) ); ?>
	</form>
</div>
