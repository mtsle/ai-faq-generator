<?php
/**
 * L3 — atrapa prymitywów WP (transients/opcje) nad PRAWDZIWYM, współdzielonym
 * plikiem — realna trwałość między OSOBNYMI procesami OS, na tym samym
 * poziomie atomowości co produkcyjny WordPress BEZ trwałego cache obiektowego
 * (Redis/Memcached): KAŻDE pojedyncze wywołanie (jeden odczyt/zapis pliku pod
 * `flock`) jest atomowe — tak jak pojedyncza instrukcja SQL w MySQL — ale
 * SEKWENCJA "odczytaj -> zdecyduj -> zapisz" rozbita na DWA osobne wywołania
 * w kodzie WOŁAJĄCYM (get_transient() ... set_transient()) nie jest chroniona
 * między nimi. To jest DOKŁADNIE ta sama klasa błędu (TOCTOU), którą ma
 * IndexController::run_reindex() (get_transient→set_transient bez atomowości)
 * i RagService::site_budget_allows()+site_budget_hit() (odczyt i inkrementacja
 * jako dwa osobne wywołania).
 *
 * @package AI_FAQ_Generator
 */

if ( ! defined( 'AIFAQ_LT_STATE_FILE' ) ) {
	define( 'AIFAQ_LT_STATE_FILE', getenv( 'AIFAQ_LT_STATE_FILE' ) ?: ( sys_get_temp_dir() . '/aifaq_lt_state.json' ) );
}

function aifaq_lt_read(): array {
	$fh = @fopen( AIFAQ_LT_STATE_FILE, 'c+' );
	if ( ! $fh ) {
		return array();
	}
	flock( $fh, LOCK_SH );
	$raw = stream_get_contents( $fh );
	flock( $fh, LOCK_UN );
	fclose( $fh );
	$data = $raw ? json_decode( $raw, true ) : array();
	return is_array( $data ) ? $data : array();
}

function aifaq_lt_write( array $data ): void {
	$fh = fopen( AIFAQ_LT_STATE_FILE, 'c+' );
	flock( $fh, LOCK_EX );
	ftruncate( $fh, 0 );
	rewind( $fh );
	fwrite( $fh, (string) json_encode( $data ) );
	fflush( $fh );
	flock( $fh, LOCK_UN );
	fclose( $fh );
}

function get_transient( string $key ) {
	$d = aifaq_lt_read();
	return $d[ $key ] ?? false;
}

function set_transient( string $key, $value, int $ttl = 0 ): bool {
	$d          = aifaq_lt_read();
	$d[ $key ]  = $value;
	aifaq_lt_write( $d );
	return true;
}

function delete_transient( string $key ): bool {
	$d = aifaq_lt_read();
	unset( $d[ $key ] );
	aifaq_lt_write( $d );
	return true;
}

function get_option( string $key, $default = false ) {
	$d = aifaq_lt_read();
	return array_key_exists( $key, $d ) ? $d[ $key ] : $default;
}

function update_option( string $key, $value ): bool {
	$d          = aifaq_lt_read();
	$d[ $key ]  = $value;
	aifaq_lt_write( $d );
	return true;
}

function aifaq_lt_reset(): void {
	@unlink( AIFAQ_LT_STATE_FILE );
	aifaq_lt_write( array() );
}

/**
 * `add_option()` real (nie shim get_option/set_option powyżej): plik z
 * flagą `x` (create-exclusive) — atomowe na poziomie systemu plików, ta sama
 * gwarancja co UNIQUE KEY na `option_name` w MySQL, którą wykorzystuje
 * `IndexController::acquire_lock()` po poprawce L3.
 */
function aifaq_lt_option_path( string $name ): string {
	return AIFAQ_LT_STATE_FILE . '.opt_' . preg_replace( '/[^a-z0-9_]/i', '_', $name );
}

function add_option( string $name, $value, $deprecated = '', $autoload = 'yes' ): bool {
	$fh = @fopen( aifaq_lt_option_path( $name ), 'x' );
	if ( ! $fh ) {
		return false;
	}
	fwrite( $fh, (string) $value );
	fclose( $fh );
	return true;
}

function real_get_option( string $name, $default = false ) {
	$path = aifaq_lt_option_path( $name );
	return file_exists( $path ) ? file_get_contents( $path ) : $default;
}

function delete_option( string $name ): bool {
	return @unlink( aifaq_lt_option_path( $name ) );
}
