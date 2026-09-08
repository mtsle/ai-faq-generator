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

	/** Przestrzen nazw XHTML — tresc wpisu Atoma przy `type="xhtml"`. */
	public const NS_XHTML = 'http://www.w3.org/1999/xhtml';

	/**
	 * Ile poczatkowych bajtow kanalu liczy sie jako prolog: 4 KB.
	 *
	 * Deklaracja XML stoi w pierwszej linii, a doklejka z motywu, ktora ja
	 * spycha (komunikat PHP, pusta linia), ma kilkadziesiat bajtow. Granica
	 * jest po to, zeby `strip_prolog()` nie szukal deklaracji w tresci
	 * artykulow — patrz komentarz przy tej metodzie.
	 */
	private const MAX_PROLOG_BYTES = 4096;

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
	 * Wpisy czytane przez `children( NS_ATOM )`, nie przez sama nazwe lokalna.
	 * Do wersji 1.0.0 stala `NS_ATOM` byla MARTWA (jedno trafienie w calym
	 * repozytorium — wlasna deklaracja), a dostep szedl przez `$doc->entry`.
	 * Kanal `<atom:feed>`/`<atom:entry>` — prefiksowany, calkowicie poprawny —
	 * konczyl sie wiec wynikiem ok=true z ZEREM pozycji i pustym polem bledu:
	 * wlasciciel widzial „kanal bez nowosci" zamiast informacji o formacie.
	 * Wariant z przestrzenia domyslna przechodzi ta sama galezia, bo dla niego
	 * `children( NS_ATOM )` tez zwraca wpisy.
	 *
	 * @param \SimpleXMLElement $doc Dokument.
	 *
	 * @return array<string,mixed>
	 */
	private static function parse_atom( \SimpleXMLElement $doc ): array {
		$items   = array();
		$skipped = 0;
		$wpisy   = self::atom_kids( $doc );

		foreach ( $wpisy->entry as $entry ) {
			$url = self::atom_link( $entry );

			if ( ! Http::is_http_url( $url ) ) {
				$skipped++;
				continue;
			}

			$pola = self::atom_kids( $entry );

			$items[] = array(
				'title'     => self::clean_title( (string) $pola->title ),
				'url'       => $url,
				'summary'   => self::clean_text( (string) $pola->summary ),
				'content'   => self::atom_content( $entry ),
				'guid'      => self::clean_text( (string) $pola->id ),
				'published' => self::to_timestamp(
					(string) $pola->published,
					(string) $pola->updated
				),
			);
		}

		/*
		 * ZERO POZYCJI BEZ POWODU JEST BLEDEM, nie sukcesem. Naglowek tej klasy
		 * obiecuje „czytelny blad zamiast cichej pustki" — a pusta lista przy
		 * `skipped === 0` znaczy, ze w kanale nie bylo ANI JEDNEGO elementu
		 * `<entry>`, ktory umiemy odczytac. Gdy wpisy byly, ale odpadly na
		 * adresie, `skipped` jest dodatni i to juz jest opisany wynik — takiego
		 * kanalu nie zglaszamy jako bledu.
		 */
		if ( 0 === count( $items ) && 0 === $skipped ) {
			return self::result( false, 'Kanal Atom bez ani jednego wpisu <entry>' );
		}

		return self::result( true, '', 'atom', self::clean_title( (string) self::atom_kids( $doc )->title ), $items, $skipped );
	}

	/**
	 * Dzieci elementu w przestrzeni Atoma, z odwrotem na przestrzen domyslna.
	 *
	 * Kanal deklarujacy Atom jako przestrzen domyslna ORAZ kanal prefiksowany
	 * (`<atom:feed>`) przechodza pierwsza galezia. Odwrot jest dla kanalow,
	 * ktore przestrzeni nie deklaruja wcale — takie w naturze wystepuja i do
	 * wersji 1.0.0 dzialaly.
	 *
	 * @param \SimpleXMLElement $element Element.
	 *
	 * @return \SimpleXMLElement
	 */
	private static function atom_kids( \SimpleXMLElement $element ): \SimpleXMLElement {
		$w_przestrzeni = $element->children( self::NS_ATOM );

		return ( null !== $w_przestrzeni && $w_przestrzeni->count() > 0 ) ? $w_przestrzeni : $element;
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

		// Atrybuty `rel` i `href` przestrzeni NIE maja — tylko sam element.
		foreach ( self::atom_kids( $entry )->link as $link ) {
			/*
			 * Atrybuty pobierane JAWNIE przez `attributes()`. Zapis `$link['rel']`
			 * szuka atrybutu w biezacej przestrzeni nazw elementu, a po przejsciu
			 * na `children( NS_ATOM )` ta przestrzen to Atom — natomiast `rel`
			 * i `href` sa w Atomie NIEPRZESTRZENNE. Bez tego oba wychodzily null
			 * i kazdy wpis odpadal na bramce adresu.
			 */
			$atrybuty = $link->attributes();
			$rel      = isset( $atrybuty['rel'] ) ? strtolower( (string) $atrybuty['rel'] ) : '';
			$href     = isset( $atrybuty['href'] ) ? self::clean_text( (string) $atrybuty['href'] ) : '';

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
		$pola = self::atom_kids( $entry );

		if ( ! isset( $pola->content ) ) {
			return '';
		}

		$content  = $pola->content;
		$atrybuty = $content->attributes();
		$type     = isset( $atrybuty['type'] ) ? strtolower( (string) $atrybuty['type'] ) : '';

		if ( 'xhtml' === $type ) {
			/*
			 * Tresc `type="xhtml"` siedzi w przestrzeni XHTML, nie w Atomie —
			 * a `children()` bez argumentu bierze dzieci w przestrzeni BIEZACEJ,
			 * czyli po `atom_kids()` w Atomie. Odwrot jest dla kanalow, ktore
			 * przestrzeni XHTML nie deklaruja.
			 */
			$wezly = $content->children( self::NS_XHTML );

			if ( null === $wezly || 0 === $wezly->count() ) {
				$wezly = $content->children();
			}

			$html = '';
			foreach ( $wezly as $dziecko ) {
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
	 * Deklaracja szukana WYLACZNIE w prologu — ta sama poprawka, ktora przeszla
	 * obok `has_doctype()`. Do wersji 1.0.0 `strpos()` szukal `<?xml` w CALYM
	 * dokumencie i obcinal wszystko przed pierwszym trafieniem, wiec kanal bez
	 * deklaracji, ktory cytowal ja dalej w CDATA — normalna tresc na blogu
	 * o technologii — zostawal przyciety do smiecia i odrzucany w CALOSCI,
	 * razem z wszystkimi pozycjami.
	 *
	 * Prolog konczy sie na pierwszym `<`: dalej stoi juz element (albo cytat
	 * w jego wnetrzu) i nic tam nie jest deklaracja dokumentu. Drugi warunek to
	 * `MAX_PROLOG_BYTES` — doklejka z motywu ma kilkadziesiat bajtow, a skan
	 * bez granicy jest kosztem liniowym na kazdym kanale.
	 *
	 * @param string $xml Tresc.
	 *
	 * @return string
	 */
	private static function strip_prolog( string $xml ): string {
		$xml = (string) preg_replace( '/^\xEF\xBB\xBF/', '', $xml );
		$xml = ltrim( $xml );

		$prolog  = substr( $xml, 0, self::MAX_PROLOG_BYTES );
		$pozycja = strpos( $prolog, '<?xml' );

		// `<` przed deklaracja znaczy, ze dokument juz sie zaczal — wtedy to nie
		// jest prolog, tylko cytat w tresci.
		if ( false !== $pozycja && $pozycja > 0 && false === strpos( substr( $prolog, 0, $pozycja ), '<' ) ) {
			$xml = substr( $xml, $pozycja );
		}

		return trim( $xml );
	}

	/**
	 * Tekst bez znacznikow, encji i nadmiarowych bialych znakow.
	 *
	 * Do wersji 1.0.0 cialo tej metody bylo jednym `return trim()`, mimo tego
	 * opisu — a to ona czysci adres, zajawke, tresc i guida w OBU galeziach
	 * parsera. Skutek: `<description>` i `<content:encoded>` z pelnym HTML-em
	 * (norma w kanalach z pelna trescia) trafialy ze znacznikami do tablicy
	 * pozycji, do bazy i do sufitu dlugosci w `Filter::haystack()`.
	 *
	 * PODZIAL NA AKAPITY ZOSTAJE. Zwykle zwiniecie bialych znakow sklejaloby
	 * caly artykul w jeden blok — dlatego znaczniki konca bloku zamieniaja sie
	 * najpierw w znak konca linii, a zwijanie dziala w obrebie linii.
	 *
	 * @param string $tekst Wejscie.
	 *
	 * @return string
	 */
	private static function clean_text( string $tekst ): string {
		if ( '' === $tekst ) {
			return '';
		}

		$tekst = Dedup::valid_utf8( $tekst );

		// Tresc skryptu i stylu nie jest tekstem — znika RAZEM ze znacznikiem.
		$tekst = (string) preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $tekst );

		// Koniec bloku i lamanie linii to granica akapitu, nie spacja.
		$tekst = (string) preg_replace( '#<br\b[^>]*/?>#i', "\n", $tekst );
		$tekst = (string) preg_replace( '#</(p|div|li|tr|h[1-6]|blockquote|pre|section|article)\s*>#i', "\n\n", $tekst );

		$tekst = strip_tags( $tekst );
		$tekst = html_entity_decode( $tekst, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// Twarda spacja z `&nbsp;` to inny bajt niz spacja.
		$tekst = str_replace( "\xC2\xA0", ' ', $tekst );

		// Zwijanie W OBREBIE LINII, potem ograniczenie pustych linii do jednej.
		$tekst = (string) preg_replace( '/[^\S\n]+/u', ' ', $tekst );
		$tekst = (string) preg_replace( '/[^\S\n]*\n[^\S\n]*/u', "\n", $tekst );
		$tekst = (string) preg_replace( '/\n{3,}/u', "\n\n", $tekst );

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
