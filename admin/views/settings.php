<?php
/**
 * Widok: Ustawienia (konfiguracja API).
 *
 * Formularz zapisywany przez Settings API (options.php).
 * Sanityzacja odbywa się w AIFAQ_Settings::sanitize().
 *
 * @package AI_FAQ_Generator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$aifaq        = AIFAQ_Settings::get();
$aifaq_models = AIFAQ_Settings::models();
$aifaq_langs  = AIFAQ_Settings::languages();
$aifaq_test   = wp_create_nonce( AIFAQ_Settings::NONCE_TEST );
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
		<?php settings_fields( AIFAQ_Settings::GROUP ); ?>

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
								value="<?php echo esc_attr( $aifaq['api_key'] ); ?>"
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

			</tbody>
		</table>

		<?php submit_button( __( 'Zapisz zmiany', 'ai-faq-generator' ) ); ?>
	</form>
</div>
