<?php
/**
 * Definicja schematu bazy (5 tabel) — schema v4.
 *
 * Tworzy/aktualizuje tabele przez dbDelta():
 *  - wp_aifaq_knowledge   — fragmenty treści strony + ich embeddingi (RAG),
 *  - wp_aifaq_qa_log      — dziennik pytań gości (status/źródło/score),
 *  - wp_aifaq_cache       — cache odpowiedzi (dedup po hashu pytania),
 *  - wp_aifaq_faq         — gotowe pary FAQ pod SEO (JSON-LD/eksport; uśpione),
 *  - wp_aifaq_generations — historia generowań FAQ w kokpicie + snapshot par (Krok 11).
 *
 * dbDelta jest wybredne: typy małymi literami, każde pole w osobnej linii,
 * DWIE spacje po „PRIMARY KEY".
 *
 * @package AI_FAQ_Generator
 */

namespace AIFAQ\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Instalator/aktualizator struktury bazy.
 */
class Schema {

	/**
	 * Nazwy tabel bez prefiksu.
	 */
	const T_KNOWLEDGE   = 'aifaq_knowledge';
	const T_QA_LOG      = 'aifaq_qa_log';
	const T_CACHE       = 'aifaq_cache';

	/**
	 * NIEUŻYWANA (K23 etap 5, decyzja D4-B).
	 *
	 * Tabela powstała pod pierwotny pomysł trzymania gotowych par FAQ w bazie.
	 * Produkt poszedł inną drogą: opublikowane pary żyją w opcji `aifaq_public_faq`
	 * ({@see \AIFAQ\Faq\PublicFaq}), a snapshoty generacji w `aifaq_generations`.
	 * Obsługujące ją `src/Data/FaqRepository.php` NIE miało ani jednego wywołania
	 * w całym repo i zostało usunięte; przejście na żywo w etapie 5 potwierdziło,
	 * że przez pełny cykl życia produktu tabela pozostaje PUSTA.
	 *
	 * Sama tabela zostaje ŚWIADOMIE do v1.0.0: jej skasowanie to migracja
	 * z `DROP TABLE`, czyli nieodwracalna operacja na bazie klienta tuż przed
	 * wydaniem — ryzyko nieproporcjonalne do zysku z jednej pustej tabeli.
	 * `uninstall.php` i tak ją usuwa przy deinstalacji, więc śladu po sobie
	 * nie zostawia. Do rozważenia po v1.0.0.
	 */
	const T_FAQ         = 'aifaq_faq';

	const T_GENERATIONS = 'aifaq_generations';

	/**
	 * Pełna nazwa tabeli z prefiksem bazy.
	 *
	 * @param string $table Nazwa tabeli bez prefiksu (stała T_*).
	 */
	public static function table( string $table ): string {
		global $wpdb;
		return $wpdb->prefix . $table;
	}

	/**
	 * Tworzy/aktualizuje wszystkie tabele.
	 */
	public static function install(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( self::statements( $charset_collate ) as $sql ) {
			dbDelta( $sql );
		}
	}

	/**
	 * Zestaw instrukcji CREATE TABLE (po jednej na tabelę).
	 *
	 * @param string $charset_collate Kodowanie/porządkowanie z $wpdb.
	 * @return array<int,string>
	 */
	private static function statements( string $charset_collate ): array {
		$knowledge   = self::table( self::T_KNOWLEDGE );
		$qa_log      = self::table( self::T_QA_LOG );
		$cache       = self::table( self::T_CACHE );
		$faq         = self::table( self::T_FAQ );
		$generations = self::table( self::T_GENERATIONS );

		$out = array();

		// Fragmenty treści + embeddingi (RAG).
		$out[] = "CREATE TABLE {$knowledge} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			chunk_index smallint(5) unsigned NOT NULL DEFAULT 0,
			content longtext NOT NULL,
			content_hash char(64) NOT NULL DEFAULT '',
			embedding longtext NULL,
			tokens smallint(5) unsigned NOT NULL DEFAULT 0,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY post_chunk (post_id,chunk_index),
			KEY content_hash (content_hash)
		) ENGINE=InnoDB {$charset_collate};";

		// Dziennik pytań gości.
		// `created_id` (Krok 23 etap 4, test L5, DB_VERSION 5->6): `page()` sortuje
		// `ORDER BY created_at DESC, id DESC` — z samym `KEY created_at` MySQL robił
		// PEŁNY SKAN + filesort (potwierdzone EXPLAIN na realnym MySQL, 20 000 wierszy:
		// 25-71 ms wg offsetu). Indeks złożony dopasowany do sortowania: 0,82-48 ms
		// (offset=0 ~31x szybciej, offset=19000 ~1,5x szybciej) — zmierzone, nie zgadywane.
		$out[] = "CREATE TABLE {$qa_log} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			question text NOT NULL,
			answer longtext NULL,
			status varchar(20) NOT NULL DEFAULT 'answered',
			source varchar(20) NOT NULL DEFAULT 'ai',
			score float NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			ip_hash char(64) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY status (status),
			KEY created_id (created_at,id)
		) ENGINE=InnoDB {$charset_collate};";

		// Cache odpowiedzi (dedup po hashu pytania).
		// `score` (Krok 22-dług, D4): realny wynik podobieństwa z chwili WPISU do
		// cache — bez niego każde trafienie cache logowało do qa_log stały,
		// zmyślony `score=1.0` niezależnie od tego, jak dobre było dopasowanie.
		$out[] = "CREATE TABLE {$cache} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			question_hash char(64) NOT NULL DEFAULT '',
			question text NOT NULL,
			answer longtext NOT NULL,
			score float NOT NULL DEFAULT 0,
			hits int(10) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY question_hash (question_hash)
		) ENGINE=InnoDB {$charset_collate};";

		// Gotowe pary FAQ pod SEO.
		$out[] = "CREATE TABLE {$faq} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			question text NOT NULL,
			answer longtext NOT NULL,
			position smallint(5) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY post_id (post_id)
		) ENGINE=InnoDB {$charset_collate};";

		// Historia generowań FAQ (kokpit) + snapshot par jako JSON (Krok 11).
		// `created_id`: TA SAMA paginacja `ORDER BY created_at DESC, id DESC` co
		// `qa_log` (`GenerationRepository::page()`) — ten sam indeks złożony z tego
		// samego powodu (Krok 23 etap 4, test L5).
		$out[] = "CREATE TABLE {$generations} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			topic text NOT NULL,
			extra_desc longtext NULL,
			num_questions smallint(5) unsigned NOT NULL DEFAULT 0,
			language varchar(10) NOT NULL DEFAULT 'pl',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			pairs_json longtext NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY created_id (created_at,id)
		) ENGINE=InnoDB {$charset_collate};";

		return $out;
	}
}
