<?php
/**
 * ETAP 8.4 (Instrukcje) — ciasteczka sesji administratora dworek.local jako JSON.
 *
 * Odpowiednik `pw-k18-cookie.php`, ale dla dworek.local (port bazy 10005) i dla witryny
 * chodzacej po HTTP — para ciasteczek to wtedy AUTH + LOGGED_IN, nie SECURE_AUTH.
 * Uzywany przez `zasoby/skrypty/instrukcje/klient-screeny.mjs`.
 *
 * URUCHOMIENIE (Git Bash):
 *   "C:/Users/matot/AppData/Roaming/Local/lightning-services/php-8.2.29+0/bin/win64/php.exe" \
 *     -d extension_dir="C:/Users/matot/AppData/Roaming/Local/lightning-services/php-8.2.29+0/bin/win64/ext" \
 *     -d extension=mysqli -d extension=mbstring -d extension=openssl \
 *     faq-generator/ai-faq-generator/ai-news-portal/instrukcje/narzedzia/dworek-cookie.php
 *
 * Wyjscie: linia `---AINP-COOKIES-BEGIN---`, JSON, linia `---AINP-COOKIES-END---`.
 * Kody wyjscia: 0 = OK, 2 = zly kontekst / brak administratora.
 * Tekst bez polskich znakow (konsola Git Bash).
 *
 * @package AI_News_Portal
 */

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'DB_HOST' ) ) {
		define( 'DB_HOST', '127.0.0.1:10005' );
	}
	$ainp_wp_load = 'C:\Users\matot\Local Sites\dworek\app\public\wp-load.php';
	if ( ! is_readable( $ainp_wp_load ) ) {
		fwrite( STDERR, "BLAD: nie znaleziono wp-load.php: {$ainp_wp_load}\n" );
		exit( 2 );
	}
	require $ainp_wp_load;
}

if ( ! function_exists( 'wp_generate_auth_cookie' ) ) {
	fwrite( STDERR, "BLAD: brak kontekstu WordPressa.\n" );
	exit( 2 );
}

$ainp_admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
		'order'  => 'ASC',
	)
);
if ( empty( $ainp_admins ) ) {
	fwrite( STDERR, "BLAD: brak uzytkownika z rola administrator.\n" );
	exit( 2 );
}
$ainp_uid = (int) $ainp_admins[0];
$ainp_exp = time() + ( 2 * DAY_IN_SECONDS );

$ainp_token = '';
if ( class_exists( 'WP_Session_Tokens' ) ) {
	$ainp_token = WP_Session_Tokens::get_instance( $ainp_uid )->create( $ainp_exp );
}

$ainp_home   = home_url( '/' );
$ainp_host   = (string) wp_parse_url( $ainp_home, PHP_URL_HOST );
$ainp_https  = 'https' === wp_parse_url( $ainp_home, PHP_URL_SCHEME );
$ainp_scheme = $ainp_https ? 'secure_auth' : 'auth';
$ainp_name   = $ainp_https ? SECURE_AUTH_COOKIE : AUTH_COOKIE;

$ainp_auth      = wp_generate_auth_cookie( $ainp_uid, $ainp_exp, $ainp_scheme, $ainp_token );
$ainp_logged_in = wp_generate_auth_cookie( $ainp_uid, $ainp_exp, 'logged_in', $ainp_token );

/**
 * Buduje wpis ciasteczka w ksztalcie oczekiwanym przez Playwright.
 *
 * @param string $name  Nazwa ciasteczka.
 * @param string $value Wartosc.
 * @param string $host  Domena.
 * @param int    $exp   Wygasniecie (unix).
 * @param bool   $sec   Czy tylko po https.
 * @return array<string,mixed>
 */
function ainp_k23_cookie( $name, $value, $host, $exp, $sec ) {
	// Sciezka '/' celowo dla OBU ciasteczek — front czyta LOGGED_IN, a AUTH bywa
	// przypiete do `/wp-admin`; pod Playwrightem chcemy jedna widoczna sesje.
	return array(
		'name'     => $name,
		'value'    => $value,
		'domain'   => $host,
		'path'     => '/',
		'expires'  => $exp,
		'httpOnly' => true,
		'secure'   => (bool) $sec,
		'sameSite' => 'Lax',
	);
}

$ainp_out = array(
	'user_id' => $ainp_uid,
	'host'    => $ainp_host,
	'home'    => $ainp_home,
	'cookies' => array(
		ainp_k23_cookie( LOGGED_IN_COOKIE, $ainp_logged_in, $ainp_host, $ainp_exp, $ainp_https ),
		ainp_k23_cookie( $ainp_name, $ainp_auth, $ainp_host, $ainp_exp, $ainp_https ),
	),
);

echo "---AINP-COOKIES-BEGIN---\n";
echo wp_json_encode( $ainp_out ) . "\n";
echo "---AINP-COOKIES-END---\n";
