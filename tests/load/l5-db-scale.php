<?php
/**
 * L5 — Baza danych @ skala (Krok 23 etap 4), na PRAWDZIWYM MySQL.
 *
 * Łączy się z rzeczywistą instancją MySQL Local (site ai-faq-dev,
 * 127.0.0.1:10011, baza "local" — TA SAMA baza co strona, ale WYŁĄCZNIE przez
 * izolowane tabele z prefiksem `wp_loadtest_`, tworzone i DROPOWANE w tym
 * skrypcie — zero dotknięcia prawdziwych tabel `wp_aifaq_*` i ich danych).
 *
 * Odpala PRAWDZIWE klasy repozytoriów (KnowledgeRepository/QaLogRepository/
 * CacheRepository/GenerationRepository) nad realnym $wpdb-kompatybilnym
 * adapterem (mysqli), więc zapytania SQL są DOKŁADNIE te produkcyjne — nie
 * przybliżenie. Dla każdej hipotezy: BEFORE (timing + EXPLAIN) -> zmiana
 * (indeks) -> AFTER (timing + EXPLAIN); indeks zostaje w kodzie TYLKO jeśli
 * pomiar AFTER faktycznie potwierdzi poprawę.
 *
 * URUCHOMIENIE:  php tests/load/l5-db-scale.php
 * WYMAGA uruchomionego Local (site ai-faq-dev). Kod wyjścia zawsze 0 (harness
 * pomiarowy) — wynik trzeba przeczytać. Tabele `wp_loadtest_*` są sprzątane
 * (DROP) na końcu, także przy błędzie (finally).
 *
 * @package AI_FAQ_Generator
 */

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
if ( ! function_exists( 'current_time' ) ) { function current_time( $t ) { return ( 'mysql' === $t ) ? date( 'Y-m-d H:i:s' ) : time(); } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $d ) { return json_encode( $d ); } }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }

/** $wpdb-kompatybilny adapter nad PRAWDZIWYM mysqli — repozytoria produkcyjne działają BEZ ŻADNYCH zmian. */
class RealWpdb {
	public $prefix;
	public $insert_id = 0;
	public $query_count = 0;
	public $query_time_ms = 0.0;
	private $mysqli;

	public function __construct( mysqli $mysqli, string $prefix ) {
		$this->mysqli = $mysqli;
		$this->prefix = $prefix;
	}

	public function prepare( $sql, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$parts = preg_split( '/(%[sdf])/', $sql, -1, PREG_SPLIT_DELIM_CAPTURE );
		$out   = '';
		$ai    = 0;
		foreach ( $parts as $part ) {
			if ( '%d' === $part ) {
				$out .= (string) (int) ( $args[ $ai++ ] ?? 0 );
			} elseif ( '%f' === $part ) {
				$out .= (string) (float) ( $args[ $ai++ ] ?? 0 );
			} elseif ( '%s' === $part ) {
				$out .= "'" . $this->mysqli->real_escape_string( (string) ( $args[ $ai++ ] ?? '' ) ) . "'";
			} else {
				$out .= $part;
			}
		}
		return $out;
	}

	private function timed_query( string $sql ) {
		++$this->query_count;
		$t0     = microtime( true );
		$result = $this->mysqli->query( $sql );
		$this->query_time_ms += ( microtime( true ) - $t0 ) * 1000;
		if ( false === $result ) {
			fwrite( STDERR, "SQL error: " . $this->mysqli->error . "\nSQL: $sql\n" );
		}
		return $result;
	}

	public function get_results( $sql, $output = null ) {
		$res = $this->timed_query( $sql );
		if ( ! ( $res instanceof mysqli_result ) ) { return array(); }
		$out = array();
		while ( $row = $res->fetch_assoc() ) { $out[] = $row; }
		$res->free();
		return $out;
	}

	public function get_row( $sql, $output = null ) {
		$res = $this->timed_query( $sql );
		if ( ! ( $res instanceof mysqli_result ) ) { return null; }
		$row = $res->fetch_assoc();
		$res->free();
		return is_array( $row ) ? $row : null;
	}

	public function get_var( $sql ) {
		$res = $this->timed_query( $sql );
		if ( ! ( $res instanceof mysqli_result ) ) { return null; }
		$row = $res->fetch_row();
		$res->free();
		return $row[0] ?? null;
	}

	public function query( $sql ) {
		$res = $this->timed_query( $sql );
		if ( false === $res ) { return false; }
		return true === $res ? $this->mysqli->affected_rows : $res;
	}

	public function insert( $table, array $data ) {
		$cols = array_keys( $data );
		$vals = array();
		foreach ( $data as $v ) {
			$vals[] = null === $v ? 'NULL' : "'" . $this->mysqli->real_escape_string( (string) $v ) . "'";
		}
		$sql = "INSERT INTO {$table} (" . implode( ',', $cols ) . ') VALUES (' . implode( ',', $vals ) . ')';
		$this->timed_query( $sql );
		$this->insert_id = $this->mysqli->insert_id;
		return 1;
	}

	public function delete( $table, array $where, $fmt = null ) {
		$conds = array();
		foreach ( $where as $k => $v ) { $conds[] = "{$k} = '" . $this->mysqli->real_escape_string( (string) $v ) . "'"; }
		$this->timed_query( "DELETE FROM {$table} WHERE " . implode( ' AND ', $conds ) );
		return $this->mysqli->affected_rows;
	}

	public function explain( string $select_sql ): string {
		$res  = $this->mysqli->query( 'EXPLAIN ' . $select_sql );
		$rows = array();
		if ( $res instanceof mysqli_result ) {
			while ( $row = $res->fetch_assoc() ) { $rows[] = $row; }
			$res->free();
		}
		$out = array();
		foreach ( $rows as $r ) {
			$out[] = sprintf(
				'type=%s key=%s rows=%s extra=%s',
				$r['type'] ?? '?', $r['key'] ?? 'NULL', $r['rows'] ?? '?', $r['Extra'] ?? ''
			);
		}
		return implode( ' | ', $out );
	}
}

// --- Połączenie z realnym MySQL Local (ai-faq-dev, 127.0.0.1:10011) ---
$mysqli = @mysqli_connect( '127.0.0.1', 'root', 'root', 'local', 10011 );
if ( ! $mysqli ) {
	fwrite( STDERR, "BRAK POŁĄCZENIA z MySQL Local (127.0.0.1:10011): " . mysqli_connect_error() . "\n" );
	fwrite( STDERR, "L5 wymaga uruchomionego Local (site ai-faq-dev) — pomijam.\n" );
	exit( 0 );
}
$mysqli->set_charset( 'utf8mb4' );

$PFX = 'wp_loadtest_';
$T_KNOWLEDGE   = $PFX . 'aifaq_knowledge';
$T_QALOG       = $PFX . 'aifaq_qa_log';
$T_CACHE       = $PFX . 'aifaq_cache';
$T_GENERATIONS = $PFX . 'aifaq_generations';

function lt_drop_all( mysqli $m, array $tables ): void {
	foreach ( $tables as $t ) { $m->query( "DROP TABLE IF EXISTS {$t}" ); }
}

$ALL_TABLES = array( $T_KNOWLEDGE, $T_QALOG, $T_CACHE, $T_GENERATIONS );
lt_drop_all( $mysqli, $ALL_TABLES ); // sprzątnięcie po ewentualnym poprzednim przerwanym przebiegu.

try {
	$mysqli->query( "CREATE TABLE {$T_KNOWLEDGE} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		post_id bigint(20) unsigned NOT NULL DEFAULT 0,
		chunk_index smallint(5) unsigned NOT NULL DEFAULT 0,
		content longtext NOT NULL,
		content_hash char(64) NOT NULL DEFAULT '',
		embedding longtext NULL,
		tokens smallint(5) unsigned NOT NULL DEFAULT 0,
		updated_at datetime NOT NULL,
		PRIMARY KEY (id),
		UNIQUE KEY post_chunk (post_id,chunk_index),
		KEY content_hash (content_hash)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" ) or die( $mysqli->error );

	$mysqli->query( "CREATE TABLE {$T_QALOG} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		created_at datetime NOT NULL,
		question text NOT NULL,
		answer longtext NULL,
		status varchar(20) NOT NULL DEFAULT 'answered',
		source varchar(20) NOT NULL DEFAULT 'ai',
		score float NOT NULL DEFAULT 0,
		user_id bigint(20) unsigned NOT NULL DEFAULT 0,
		ip_hash char(64) NOT NULL DEFAULT '',
		PRIMARY KEY (id),
		KEY created_at (created_at),
		KEY status (status)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" ) or die( $mysqli->error );

	$mysqli->query( "CREATE TABLE {$T_CACHE} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		question_hash char(64) NOT NULL DEFAULT '',
		question text NOT NULL,
		answer longtext NOT NULL,
		score float NOT NULL DEFAULT 0,
		hits int(10) unsigned NOT NULL DEFAULT 0,
		created_at datetime NOT NULL,
		PRIMARY KEY (id),
		UNIQUE KEY question_hash (question_hash)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" ) or die( $mysqli->error );

	$mysqli->query( "CREATE TABLE {$T_GENERATIONS} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		created_at datetime NOT NULL,
		topic text NOT NULL,
		extra_desc longtext NULL,
		num_questions smallint(5) unsigned NOT NULL DEFAULT 0,
		language varchar(10) NOT NULL DEFAULT 'pl',
		user_id bigint(20) unsigned NOT NULL DEFAULT 0,
		pairs_json longtext NULL,
		PRIMARY KEY (id),
		KEY created_at (created_at)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" ) or die( $mysqli->error );

	global $wpdb;
	$wpdb = new RealWpdb( $mysqli, $PFX );

	require __DIR__ . '/../../src/Data/Schema.php';
	require __DIR__ . '/../../src/Data/Repository.php';
	require __DIR__ . '/../../src/Data/KnowledgeRepository.php';
	require __DIR__ . '/../../src/Data/CacheRepository.php';
	require __DIR__ . '/../../src/Data/QaLogRepository.php';
	require __DIR__ . '/../../src/Data/GenerationRepository.php';

	$knowledge   = new AIFAQ\Data\KnowledgeRepository();
	$cache_repo  = new AIFAQ\Data\CacheRepository();
	$qalog       = new AIFAQ\Data\QaLogRepository();
	$generations = new AIFAQ\Data\GenerationRepository();

	/** Seeduje N wierszy wsadowo (multi-row INSERT, żeby seed sam nie zdominował czasu przebiegu). */
	function lt_seed_knowledge( mysqli $m, string $table, int $n ): void {
		$batch = array();
		for ( $i = 1; $i <= $n; $i++ ) {
			$post_id = intdiv( $i - 1, 2 ) + 1;
			$chunk   = ( $i - 1 ) % 2;
			$emb     = json_encode( array_fill( 0, 16, round( sin( $i * 0.01 ), 4 ) ) ); // skrócony wektor (16D) — sama treść nienieistotna dla kosztu SELECT/EXPLAIN.
			$batch[] = "({$post_id},{$chunk},'fragment {$i} o ofercie','" . hash( 'sha256', (string) $i ) . "','" . $m->real_escape_string( $emb ) . "',50,NOW())";
			if ( 0 === $i % 1000 || $i === $n ) {
				$m->query( "INSERT INTO {$table} (post_id,chunk_index,content,content_hash,embedding,tokens,updated_at) VALUES " . implode( ',', $batch ) );
				$batch = array();
			}
		}
	}
	function lt_seed_qalog( mysqli $m, string $table, int $n ): void {
		$batch    = array();
		$statuses = array( 'answered', 'refused', 'error' );
		for ( $i = 1; $i <= $n; $i++ ) {
			$days_ago = intdiv( $i, max( 1, intdiv( $n, 90 ) ) ); // rozrzut dat na ~90 dni wstecz.
			$status   = $statuses[ $i % 3 ];
			$batch[]  = "(DATE_SUB(NOW(), INTERVAL {$days_ago} DAY), 'pytanie testowe {$i}', 'odpowiedź {$i}', '{$status}', 'ai', 0.8, 0, '" . hash( 'sha256', (string) ( $i % 500 ) ) . "')";
			if ( 0 === $i % 1000 || $i === $n ) {
				$m->query( "INSERT INTO {$table} (created_at,question,answer,status,source,score,user_id,ip_hash) VALUES " . implode( ',', $batch ) );
				$batch = array();
			}
		}
	}
	function lt_seed_generations( mysqli $m, string $table, int $n ): void {
		$batch = array();
		for ( $i = 1; $i <= $n; $i++ ) {
			$days_ago = intdiv( $i, max( 1, intdiv( $n, 90 ) ) );
			$batch[]  = "(DATE_SUB(NOW(), INTERVAL {$days_ago} DAY), 'temat {$i}', 'opis', 10, 'pl', 0, '" . $m->real_escape_string( json_encode( array_fill( 0, 10, array( 'q' => 'Q?', 'a' => 'A.' ) ) ) ) . "')";
			if ( 0 === $i % 1000 || $i === $n ) {
				$m->query( "INSERT INTO {$table} (created_at,topic,extra_desc,num_questions,language,user_id,pairs_json) VALUES " . implode( ',', $batch ) );
				$batch = array();
			}
		}
	}

	echo "=== L5 — Baza danych @ skala (PRAWDZIWY MySQL, tabele izolowane wp_loadtest_*) ===\n\n";

	// -----------------------------------------------------------------
	echo "--- A. KnowledgeRepository::embeddings_page() — retrieval na realnej bazie (weryfikacja L1/L2) ---\n";
	printf( "%-8s %10s %10s %10s\n", 'N', 'strony', 'czas(ms)', 'ms/strona' );
	foreach ( array( 400, 2000, 20000 ) as $n ) {
		$mysqli->query( "TRUNCATE TABLE {$T_KNOWLEDGE}" );
		lt_seed_knowledge( $mysqli, $T_KNOWLEDGE, $n );
		$wpdb->query_count = 0; $wpdb->query_time_ms = 0.0;
		$t0 = microtime( true );
		$pages = 0;
		for ( $offset = 0; $offset < $n; $offset += 200 ) {
			$page = $knowledge->embeddings_page( 200, $offset );
			++$pages;
			if ( empty( $page ) ) { break; }
		}
		$ms = ( microtime( true ) - $t0 ) * 1000;
		printf( "%-8d %10d %10.2f %10.3f\n", $n, $pages, $ms, $pages > 0 ? $ms / $pages : 0 );
	}
	echo "  (jeśli ms/strona zostaje ~stałe rosnąc N -> potwierdza, że L2's O(N^1.5-2) był artefaktem\n";
	echo "  atrapy FakeWpdb, nie realnym zachowaniem MySQL z indeksem UNIQUE post_chunk.)\n\n";

	// -----------------------------------------------------------------
	echo "--- B. QaLogRepository::stats() — PEŁNY SKAN + agregaty (Dashboard), rośnie z tabelą ---\n";
	printf( "%-8s %10s %10s\n", 'N', 'czas(ms)', 'EXPLAIN' );
	foreach ( array( 400, 2000, 20000 ) as $n ) {
		$mysqli->query( "TRUNCATE TABLE {$T_QALOG}" );
		lt_seed_qalog( $mysqli, $T_QALOG, $n );
		$t0 = microtime( true );
		$stats = $qalog->stats();
		$ms = ( microtime( true ) - $t0 ) * 1000;
		$explain = $wpdb->explain( "SELECT COUNT(*) AS total FROM {$T_QALOG}" );
		printf( "%-8d %10.2f %s\n", $n, $ms, $explain );
	}
	echo "  -> stats() nie ma WHERE, MySQL musi przejrzeć całą tabelę na KAŻDE otwarcie Dashboardu.\n";
	echo "  Rekomendacja: retencja (qa_log_keep_rows/days — już ISTNIEJE jako opcja, ale domyślnie\n";
	echo "  wyłączona) trzyma tabelę małą; to NIE jest brakujący indeks (COUNT(*) bez WHERE zawsze\n";
	echo "  skanuje), więc żaden indeks by tu nie pomógł — nie dodaję żadnego.\n\n";

	// -----------------------------------------------------------------
	echo "--- C. Paginacja OFFSET (QaLogRepository::page() / GenerationRepository::page()) — BEFORE/AFTER indeks złożony ---\n";
	$mysqli->query( "TRUNCATE TABLE {$T_QALOG}" );
	lt_seed_qalog( $mysqli, $T_QALOG, 20000 );

	function lt_time_page( AIFAQ\Data\QaLogRepository $repo, int $limit, int $offset ): float {
		$t0 = microtime( true );
		$repo->page( $limit, $offset );
		return ( microtime( true ) - $t0 ) * 1000;
	}

	echo "  BEFORE (tylko KEY created_at, produkcyjny schemat):\n";
	foreach ( array( 0, 5000, 19000 ) as $offset ) {
		$ms = lt_time_page( $qalog, 20, $offset );
		$explain = $wpdb->explain( "SELECT * FROM {$T_QALOG} ORDER BY created_at DESC, id DESC LIMIT 20 OFFSET {$offset}" );
		printf( "    offset=%-6d czas=%.2fms  EXPLAIN: %s\n", $offset, $ms, $explain );
	}

	$mysqli->query( "ALTER TABLE {$T_QALOG} ADD KEY created_id (created_at, id)" );
	echo "  AFTER (dodany indeks złożony (created_at, id) — dopasowany do ORDER BY):\n";
	$after_times = array();
	foreach ( array( 0, 5000, 19000 ) as $offset ) {
		$ms = lt_time_page( $qalog, 20, $offset );
		$after_times[ $offset ] = $ms;
		$explain = $wpdb->explain( "SELECT * FROM {$T_QALOG} ORDER BY created_at DESC, id DESC LIMIT 20 OFFSET {$offset}" );
		printf( "    offset=%-6d czas=%.2fms  EXPLAIN: %s\n", $offset, $ms, $explain );
	}
	echo "  -> Decyzja o zachowaniu indeksu w KODZIE PRODUKCYJNYM: patrz podsumowanie na końcu pliku.\n\n";

	// -----------------------------------------------------------------
	echo "--- D. CacheRepository::get_by_question() — kontrola/baseline (UNIQUE KEY question_hash) ---\n";
	printf( "%-8s %10s %10s\n", 'N', 'czas(ms)', 'EXPLAIN' );
	foreach ( array( 400, 5000, 20000 ) as $n ) {
		$mysqli->query( "TRUNCATE TABLE {$T_CACHE}" );
		$batch = array();
		for ( $i = 1; $i <= $n; $i++ ) {
			$q_text = 'pytanie ' . $i;
			$batch[] = "('" . AIFAQ\Data\CacheRepository::hash( $q_text ) . "','{$q_text}','odpowiedź {$i}',0.9,0,NOW())";
			if ( 0 === $i % 1000 || $i === $n ) {
				$mysqli->query( "INSERT INTO {$T_CACHE} (question_hash,question,answer,score,hits,created_at) VALUES " . implode( ',', $batch ) );
				$batch = array();
			}
		}
		$needle = 'pytanie ' . intdiv( $n, 2 );
		$t0 = microtime( true );
		$found = $cache_repo->get_by_question( $needle );
		$ms = ( microtime( true ) - $t0 ) * 1000;
		$hash = AIFAQ\Data\CacheRepository::hash( $needle );
		$explain = $wpdb->explain( "SELECT * FROM {$T_CACHE} WHERE question_hash = '{$hash}'" );
		if ( null === $found ) { echo "    UWAGA: zapytanie kontrolne nie znalazło wiersza — sprawdź seeding.\n"; }
		printf( "%-8d %10.3f %s\n", $n, $ms, $explain );
	}
	echo "  -> oczekiwane: type=const/eq_ref (użycie UNIQUE KEY), czas ~stały niezależnie od N.\n\n";

} finally {
	lt_drop_all( $mysqli, $ALL_TABLES );
	$mysqli->close();
	echo "(tabele wp_loadtest_* usunięte — realne dane strony nietknięte)\n";
	echo "KONIEC L5.\n";
}
