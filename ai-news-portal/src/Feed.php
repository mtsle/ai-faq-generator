<?php
/**
 * Parser kanalow: RSS 2.0 i Atom, razem z `content:encoded`.
 *
 * ETAP 2.2. Klasa jest CZYSTA — dostaje tekst XML-a i oddaje tablice pozycji.
 * Nie pobiera niczego z sieci (to robi `Http`), nie zna bazy i nie filtruje
 * tresci (to `Filter` w Kroku 3).
 *
 * Trzy rzeczy, ktorych ta klasa pilnuje:
 *
 *   1. Nie rzuca wyjatkiem. Kanal moze byc pusty, uciety, w nieznanym formacie
 *      albo w ogole nie byc XML-em — zawsze wraca tablica `ok`/`error`/`items`.
 *   2. Nie daje sie nabrac na XML. `<!DOCTYPE>` jest odrzucany z gory (bomba
 *      encyjna rozwija sie w pamieci, zanim jakikolwiek limit zdazy zadzialac),
 *      a parser dostaje `LIBXML_NONET` i nigdy `LIBXML_NOENT`.
 *   3. Pozycja bez adresu http(s) nie wychodzi na zewnatrz. Adres jest kluczem
 *      calego odsiewu duplikatow (`Dedup`, etap 2.3) — pozycja bez niego jest
 *      dla wtyczki bezuzyteczna.
 *
 * ZAKRES: RSS 2.0 i Atom, zgodnie z planem. RSS 1.0 (RDF) NIE jest obslugiwany
 * swiadomie — zaden z czterech domyslnych kanalow go nie uzywa, a kanal w tym
 * formacie dostanie czytelny blad zamiast cichej pustki.
 *
 * @package AI_News_Portal
 */

namespace AINP;

// Blokada bezposredniego wywolania pliku.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Zamiana tekstu kanalu na liste pozycji.
 */
final class Feed {

	/** Przestrzen nazw modulu `content:` (RSS). */
	public const NS_CONTENT = 'http://purl.org/rss/1.0/modules/content/';

	/** Przestrzen nazw Dublin Core — bywa jedynym zrodlem daty. */
	public const NS_DC = 'http://purl.org/dc/elements/1.1/';

	/** Przestrzen nazw Atoma. */
	public const NS_ATOM = 'http://www.w3.org/2005/Atom';

	/**
	 * Rozklada kanal na pozycje.
	 *
	 * @param string $xml Tresc kanalu.
	 *
	 * @return array{ok:bool,error:string,format:string,title:string,items:array,skipped:int}
	 */
	public static function parse( string $xml ): array {
		$xml = self::strip_prolog( $xml );

		if ( '' === $xml ) {
			return self::result( false, 'Kanal jest pusty' );
		}

		/*
		 * Deklaracja typu dokumentu odrzucana ZANIM cokolwiek ja przetworzy.
		 * Nawet przy wylaczonym ladowaniu encji zewnetrznych encje wewnetrzne
		 * potrafia rozwinac kilkaset bajtow do gigabajtow pamieci, a limitu
		 * pamieci nie da sie zlapac blokiem `try`.
		 */
		if ( self::has_doctype( $xml ) ) {
			return self::result( false, 'Kanal zawiera deklaracje DOCTYPE — odrzucony' );
		}

		$poprzednie = libxml_use_internal_errors( true );
		libxml_clear_errors();

		// LIBXML_NOCDATA: tresc w CDATA ma byc zwyklym tekstem.
		// LIBXML_NONET: zakaz siegania po zasoby sieciowe w trakcie parsowania.
		// LIBXML_NOENT NIE jest uzywane — wlasnie ono podstawia encje.
		$doc = simplexml_load_string( $xml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET );

		$bledy = libxml_get_errors();
		libxml_clear_errors();
		libxml_use_internal_errors( $poprzednie );

		if ( false === $doc ) {
			$pierwszy = isset( $bledy[0] ) ? trim( $bledy[0]->message ) : 'nieznany blad';
			return self::result( false, 'Kanal nie jest poprawnym XML-em: ' . $pierwszy );
		}

		$root = strtolower( $doc->getName() );

		if ( 'rss' === $root ) {
			return self::parse_rss( $doc );
		}

		if ( 'feed' === $root ) {
			return self::parse_atom( $doc );
		}

		return self::result( false, 'Nieznany format kanalu: <' . $doc->getName() . '>' );
	}

	// -----------------------------------------------------------------------
	// Formaty
	// -----------------------------------------------------------------------

	/**
	 * RSS 2.0.
	 *
	 * @param \SimpleXMLElement $doc Dokument.
	 *
	 * @return array<string,mixed>
	 */
	private static function parse_rss( \SimpleXMLElement $doc ): array {
		if ( ! isset( $doc->channel ) ) {
			return self::result( false, 'Kanal RSS bez elementu <channel>' );
		}

		$items   = array();
		$skipped = 0;

		foreach ( $doc->channel->item as $item ) {
			$url = self::clean_text( (string) $item->link );

			/*
			 * Niektore kanaly trzymaja adres wylacznie w <guid>. Wolno go wziac
			 * TYLKO wtedy, gdy jest oznaczony jako trwaly odnosnik — w RSS 2.0
			 * `isPermaLink` domyslnie znaczy „true", ale WordPress i sporo
			 * innych systemow publikuje `isPermaLink="false"` z adresem
			 * w postaci `https://serwis.pl/?p=123`. To identyfikator, nie adres
			 * artykulu: prowadzi po przekierowaniu do tej samej strony, wiec
			 * canonical w Kroku 3 zrobilby z niego DRUGA kopie tego samego
			 * materialu.
			 */
			if ( ! Http::is_http_url( $url ) && isset( $item->guid ) && self::guid_is_permalink( $item->guid ) ) {
				$kandydat = self::clean_text( (string) $item->guid );
				if ( Http::is_http_url( $kandydat ) ) {
					$url = $kandydat;
				}
			}

			if ( ! Http::is_http_url( $url ) ) {
				$skipped++;
				continue;
			}

			$tresc = self::clean_text( (string) $item->children( self::NS_CONTENT )->encoded );

			$items[] = array(
				'title'     => self::clean_title( (string) $item->title ),
				'url'       => $url,
				'summary'   => self::clean_text( (string) $item->description ),
				'content'   => $tresc,
				'guid'      => self::clean_text( (string) $item->guid ),
				'published' => self::to_timestamp(
					(string) $item->pubDate,
					(string) $item->children( self::NS_DC )->date
				),
			);
		}

		return self::result(
			true,
			'',
			'rss',
			self::clean_title( (string) $doc->channel->title ),
			$items,
			$skipped
		);
	}

	/**
	 * Atom.
	 *
	 * @param \SimpleXMLElement $doc Dokument.
	 *
	 * @return array<string,mixed>
	 */
	private static function parse_atom( \SimpleXMLElement $doc ): array {
		$items   = array();
		$skipped = 0;

		foreach ( $doc->entry as $entry ) {
			$url = self::atom_link( $entry );

			if ( ! Http::is_http_url( $url ) ) {
				$skipped++;
				continue;
			}

			$items[] = array(
				'title'     => self::clean_title( (string) $entry->title ),
				'url'       => $url,
				'summary'   => self::clean_text( (string) $entry->summary ),
				'content'   => self::atom_content( $entry ),
				'guid'      => self::clean_text( (string) $entry->id ),
				'published' => self::to_timestamp(
					(string) $entry->published,
					(string) $entry->updated
				),
			);
		}

		return self::result( true, '', 'atom', self::clean_title( (string) $doc->title ), $items, $skipped );
	}

	/**
	 * Czy `<guid>` jest trwalym odnosnikiem, czy tylko identyfikatorem.
	 *
	 * Brak atrybutu znaczy „true" — tak mowi RSS 2.0. Odrzucamy wylacznie
	 * jawne `isPermaLink="false"`; wartosc zapisana inaczej niz malymi literami
	 * albo z bialymi znakami wokol tez sie liczy.
	 *
	 * @param \SimpleXMLElement $guid Element `<guid>`.
	 *
	 * @return bool
	 */
	private static function guid_is_permalink( \SimpleXMLElement $guid ): bool {
		$atrybuty = $guid->attributes();

		if ( ! isset( $atrybuty['isPermaLink'] ) ) {
			return true;
		}

		return 'false' !== strtolower( trim( (string) $atrybuty['isPermaLink'] ) );
	}

	/**
	 * Adres wpisu Atoma.
	 *
	 * Atom pozwala na wiele elementow `<link>`. Bierzemy `rel="alternate"`
	 * albo `<link>` bez atrybutu `rel` (domyslnie znaczy to samo). `rel="self"`,
	 * `rel="replies"` i `rel="enclosure"` prowadza gdzie indziej niz do
	 * artykulu, wiec sa pomijane.
	 *
	 * @param \SimpleXMLElement $entry Wpis.
	 *
	 * @return string
	 */
	private static function atom_link( \SimpleXMLElement $entry ): string {
		$zapasowy = '';

		foreach ( $entry->link as $link ) {
			$rel  = isset( $link['rel'] ) ? strtolower( (string) $link['rel'] ) : '';
			$href = self::clean_text( (string) $link['href'] );

			if ( '' === $href ) {
				continue;
			}

			if ( '' === $rel || 'alternate' === $rel ) {
				return $href;
			}

			if ( '' === $zapasowy && 'self' !== $rel && 'replies' !== $rel && 'enclosure' !== $rel ) {
				$zapasowy = $href;
			}
		}

		return $zapasowy;
	}

	/**
	 * Tresc wpisu Atoma.
	 *
	 * Przy `type="xhtml"` tresc siedzi jako WEZLY POTOMNE, nie jako tekst —
	 * `(string)` zwrocilby wtedy pusty lancuch i artykul poszedlby do
	 * scrapingu bez potrzeby.
	 *
	 * @param \SimpleXMLElement $entry Wpis.
	 *
	 * @return string
	 */
	private static function atom_content( \SimpleXMLElement $entry ): string {
		if ( ! isset( $entry->content ) ) {
			return '';
		}

		$content = $entry->content;
		$type    = isset( $content['type'] ) ? strtolower( (string) $content['type'] ) : '';

		if ( 'xhtml' === $type ) {
			$html = '';
			foreach ( $content->children() as $dziecko ) {
				$html .= $dziecko->asXML();
			}
			return self::clean_text( $html );
		}

		return self::clean_text( (string) $content );
	}

	// -----------------------------------------------------------------------
	// Pomocnicze
	// -----------------------------------------------------------------------

	/**
	 * Czy dokument ma deklaracje typu — szukana WYLACZNIE w prologu.
	 *
	 * Skan calego dokumentu (`preg_match('/<!DOCTYPE/i', $xml)`) mial jedna
	 * wade: kanal, w ktorym ktorykolwiek artykul cytuje kod HTML w sekcji
	 * CDATA — a to normalna tresc na blogu o technologii — bywal odrzucany
	 * w calosci, razem z wszystkimi pozostalymi pozycjami.
	 *
	 * XML dopuszcza `<!DOCTYPE` tylko przed elementem glownym, wiec skan
	 * konczy sie na pierwszym elemencie. Po drodze przeskakujemy deklaracje
	 * XML i instrukcje przetwarzania (`<?...?>`) oraz komentarze (`<!--...-->`)
	 * — one same moga zawierac slowo DOCTYPE i nic z niego nie wynika.
	 *
	 * @param string $xml Tresc kanalu (juz po `strip_prolog()`).
	 *
	 * @return bool
	 */
	private static function has_doctype( string $xml ): bool {
		$dlugosc = strlen( $xml );
		$i       = 0;

		while ( $i < $dlugosc ) {
			// Biale znaki miedzy elementami prologu sa dozwolone.
			while ( $i < $dlugosc && 1 === preg_match( '/\s/', $xml[ $i ] ) ) {
				$i++;
			}

			if ( $i >= $dlugosc || '<' !== $xml[ $i ] ) {
				// Smiec przed elementem glownym — nie nasza sprawa, zglosi to parser.
				return false;
			}

			$nastepny = ( $i + 1 < $dlugosc ) ? $xml[ $i + 1 ] : '';

			if ( '?' === $nastepny ) {
				$koniec = strpos( $xml, '?>', $i );
				if ( false === $koniec ) {
					return false;
				}
				$i = $koniec + 2;
				continue;
			}

			if ( '!' === $nastepny ) {
				if ( 0 === strncasecmp( substr( $xml, $i, 9 ), '<!DOCTYPE', 9 ) ) {
					return true;
				}

				if ( '<!--' === substr( $xml, $i, 4 ) ) {
					$koniec = strpos( $xml, '-->', $i );
					if ( false === $koniec ) {
						return false;
					}
					$i = $koniec + 3;
					continue;
				}

				// Inna deklaracja `<!` w prologu jest niepoprawna — zostawiamy parserowi.
				return false;
			}

			// Znacznik otwierajacy element glowny: prolog sie skonczyl.
			return false;
		}

		return false;
	}

	/**
	 * Usuwa BOM i wszystko przed deklaracja XML.
	 *
	 * Pusta linia albo znak konca linii przed `<?xml` to najczestsza przyczyna
	 * bledu „XML declaration allowed only at the start of the document" —
	 * kanal jest poprawny, psuje go doklejka z motywu albo z wtyczki serwera.
	 *
	 * @param string $xml Tresc.
	 *
	 * @return string
	 */
	private static function strip_prolog( string $xml ): string {
		$xml = (string) preg_replace( '/^\xEF\xBB\xBF/', '', $xml );
		$xml = ltrim( $xml );

		$pozycja = strpos( $xml, '<?xml' );
		if ( false !== $pozycja && $pozycja > 0 ) {
			$xml = substr( $xml, $pozycja );
		}

		return trim( $xml );
	}

	/**
	 * Tekst bez znacznikow, encji i nadmiarowych bialych znakow.
	 *
	 * @param string $tekst Wejscie.
	 *
	 * @return string
	 */
	private static function clean_text( string $tekst ): string {
		return trim( $tekst );
	}

	/**
	 * Tytul: bez znacznikow, z rozwinietymi encjami, w jednej linii.
	 *
	 * Tytuly w kanalach bywaja podwojnie kodowane (`&amp;#8211;`), stad
	 * rozwijanie encji PO tym, jak zrobil to parser XML.
	 *
	 * @param string $tytul Wejscie.
	 *
	 * @return string
	 */
	private static function clean_title( string $tytul ): string {
		$tytul = html_entity_decode( $tytul, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$tytul = strip_tags( $tytul );
		$tytul = (string) preg_replace( '/\s+/u', ' ', $tytul );

		return trim( $tytul );
	}

	/**
	 * Pierwsza data, ktora da sie zrozumiec.
	 *
	 * @param string ...$daty Kandydaci w kolejnosci pierwszenstwa.
	 *
	 * @return int Znacznik czasu albo `0`.
	 */
	private static function to_timestamp( string ...$daty ): int {
		foreach ( $daty as $data ) {
			$data = trim( $data );

			if ( '' === $data ) {
				continue;
			}

			$ts = strtotime( $data );

			if ( false !== $ts && $ts > 0 ) {
				return $ts;
			}
		}

		return 0;
	}

	/**
	 * Staly ksztalt wyniku.
	 *
	 * @param bool   $ok      Czy kanal dal sie rozlozyc.
	 * @param string $error   Komunikat dla czlowieka.
	 * @param string $format  `rss`, `atom` albo pusty.
	 * @param string $title   Tytul kanalu.
	 * @param array  $items   Pozycje.
	 * @param int    $skipped Pozycje pominiete z braku adresu http(s).
	 *
	 * @return array<string,mixed>
	 */
	private static function result(
		bool $ok,
		string $error,
		string $format = '',
		string $title = '',
		array $items = array(),
		int $skipped = 0
	): array {
		return array(
			'ok'      => $ok,
			'error'   => $error,
			'format'  => $format,
			'title'   => $title,
			'items'   => $items,
			'skipped' => $skipped,
		);
	}
}
