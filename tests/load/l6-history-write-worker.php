<?php
/**
 * L6 worker — jeden realny INSERT do wp_loadtest_aifaq_generations przez
 * mysqli bezpośrednio (osobny proces OS, prawdziwe równoległe zapisy).
 *
 * RAU-R15-006: worker NIE MIAŁ gałęzi dla braku odpowiedzi bazy — ani wyniku
 * `mysqli_connect()`, ani `prepare()` nie badał. Proces nadrzędny czyta wyłącznie
 * standardowe wyjście (potok 1; potok 2 zamyka bez odczytu), więc nieudany zapis
 * wchodził do pomiaru jako PUSTA POZYCJA, a nie jako błąd: przy limicie połączeń
 * albo restarcie MySQL w trakcie części B liczba udanych INSERT-ów była cicho
 * zaniżana i nikt się o tym nie dowiadywał.
 *
 * Plik nadrzędny (`l6-generator-concurrency.php`:50) ma tę gałąź dla TEGO SAMEGO
 * wywołania z tymi samymi pięcioma argumentami. Tutaj jej brakowało.
 *
 * KONTRAKT WYJŚCIA (czytany przez proces nadrzędny):
 *   sukces  — na stdout `insert_id`, kod wyjścia 0;
 *   awaria  — na stdout `BLAD:<powod>`, na stderr szczegół, kod wyjścia 1.
 * Znacznik `BLAD:` idzie na STDOUT świadomie: nadrzędny czyta tylko ten potok,
 * więc cicha pustka byłaby jedyną alternatywą.
 *
 * @package AI_FAQ_Generator
 */
$id = (int) ( $argv[1] ?? 0 );

/**
 * Kończy worker awarią widoczną dla procesu nadrzędnego.
 *
 * @param string $powod    Krótki znacznik dla stdout.
 * @param string $szczegol Pełny komunikat dla stderr.
 *
 * @return void
 */
function l6w_padnij( string $powod, string $szczegol ): void {
	echo 'BLAD:' . $powod . "\n";
	fwrite( STDERR, 'L6 worker: ' . $powod . ' — ' . $szczegol . "\n" );
	exit( 1 );
}

$mysqli = @mysqli_connect( '127.0.0.1', 'root', 'root', 'local', 10011 );

if ( ! $mysqli ) {
	l6w_padnij( 'brak-polaczenia', (string) mysqli_connect_error() );
}

$stmt = $mysqli->prepare( 'INSERT INTO wp_loadtest_aifaq_generations (created_at,topic,extra_desc,num_questions,language,user_id,pairs_json) VALUES (NOW(),?,?,10,?,?,?)' );

if ( false === $stmt ) {
	l6w_padnij( 'prepare-nieudany', (string) $mysqli->error );
}

$topic = 'temat-worker-' . $id;
$desc  = 'opis';
$lang  = 'pl';
$uid   = $id;
$pairs = json_encode( array( array( 'q' => 'Q' . $id, 'a' => 'A' . $id ) ) );
$stmt->bind_param( 'sssis', $topic, $desc, $lang, $uid, $pairs );

if ( ! $stmt->execute() ) {
	l6w_padnij( 'insert-nieudany', (string) $stmt->error );
}

echo $mysqli->insert_id . "\n";
