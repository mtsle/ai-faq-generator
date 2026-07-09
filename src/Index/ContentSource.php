<?php
/**
 * Kontrakt źródła treści do indeksowania.
 *
 * Abstrahuje „skąd bierzemy tekst strony". Indexer zależy od tego interfejsu,
 * nie od WordPressa — dzięki czemu w testach można podstawić atrapę zwracającą
 * gotowe dokumenty bez bazy.
 *
 * @package AI_FAQ_Generator
 */

namespace AIFAQ\Index;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interfejs źródła treści.
 */
interface ContentSource {

	/**
	 * Zwraca listę dokumentów do zaindeksowania.
	 *
	 * @return array<int,array{post_id:int,title:string,url:string,text:string}>
	 *         Każdy dokument: `post_id`, `title`, `url`, `text` (zwykły tekst bez HTML).
	 */
	public function documents(): array;
}
