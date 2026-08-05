<?php
/**
 * Ustawienia wtyczki: nazwy opcji i wartosci domyslne.
 *
 * ETAP 1.5. Na tym etapie klasa jest wylacznie magazynem: czyta, scala
 * z domyslnymi i zapisuje. Formularz Ustawien i sanityzacja wejscia powstaja
 * w Kroku 2 (etap 2.5) — dopisujemy je tutaj, nie w Adminie.
 *
 * Wszystkie cztery opcje wtyczki maja prefiks `ainp_`, rozlaczny z `aifaq_`
 * wtyczki 1. Rozlaczne prefiksy sa jedynym zabezpieczeniem przed tym, zeby
 * odinstalowanie jednej wtyczki skasowalo dane drugiej.
 *
 * @package AI_News_Portal
 */

namespace AINP;

// Blokada bezposredniego wywolania pliku.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Opcje wtyczki i ich wartosci domyslne.
 */
final class Settings {

	/** Ustawienia ogolne (tablica). */
	public const OPTION = 'ainp_settings';

	/** Lista adresow kanalow RSS (tablica). Wypelniana w Kroku 2. */
	public const OPTION_SOURCES = 'ainp_sources';

	/** Klucz API Gemini — osobna opcja, ZAWSZE `autoload = no`. */
	public const OPTION_KEY = 'ainp_key';

	/** Licznik wywolan AI na dobe (data + liczba). Kroku 4. */
	public const OPTION_USAGE = 'ainp_usage';

	/**
	 * Znacznik jednorazowego wstawienia artykulu demo.
	 *
	 * Zyje WEWNATRZ tablicy `ainp_settings`. Pilnuje, ze ponowna aktywacja
	 * nie zrobi drugiej kopii demo — przy tempie wlaczania i wylaczania
	 * wtyczki podczas budowy powstalyby ich kilkanascie.
	 */
	public const KEY_DEMO_DONE = 'ainp_demo_done';

	/**
	 * Domyslne kanaly RSS — cztery, ZWERYFIKOWANE 2026-08-05 przez otwarcie
	 * kazdego XML-a, nie wziete z wyszukiwarki.
	 *
	 * Wszystkie cztery podaja pelna tresc w `content:encoded`, wiec dzialaja
	 * bez scrapingu. Dwa z nich (ccw24.pl, pupilepodochrona.pl) MIESZAJA psy
	 * z kotami — stad warstwa 1 slow wykluczajacych nizej; bez niej portal
	 * o psach zapelnilby sie artykulami o kotach.
	 *
	 * Swiadomie POZA lista: accdog.pl (blog sklepowy, tresc sprzedazowa)
	 * i wymarzonypies.pl (glownie ogloszenia i obozy) — oba podaja same
	 * zajawki. Przy weryfikacji odpadly: psiedobre.pl (oddaje HTML zamiast
	 * RSS), weterynarzpluto.pl (404), superzoo.com.pl (403 dla kazdego
	 * nieprzegladarkowego User-Agenta — nasz tez by dostal 403).
	 *
	 * @return array<int,string>
	 */
	public static function default_sources(): array {
		return array(
			'https://www.psy.pl/feed/',
			'https://ccw24.pl/feed/',
			'https://pupilepodochrona.pl/feed/',
			'https://psiedszkole.pl/feed/',
		);
	}

	/**
	 * Wartosci domyslne `ainp_settings`.
	 *
	 * Kategorie i slowa wykluczajace sa zweryfikowane 2026-08-05 na czterech
	 * domyslnych kanalach RSS — nie sa zgadniete.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			/*
			 * Siedem kategorii. „Zycie z psem" jest CELOWO koszem zbiorczym:
			 * walidator odrzuca odpowiedz, w ktorej `topic` nie pasuje dokladnie
			 * do listy, wiec bez kosza artykul o podrozowaniu z psem szedlby
			 * na `failed` i marnowal jedno z 20 wywolan na dobe.
			 */
			'categories'          => array(
				'Żywienie',
				'Zdrowie',
				'Zachowanie',
				'Szkolenie',
				'Pielęgnacja',
				'Rasy',
				'Życie z psem',
			),

			/*
			 * Slowa wykluczajace — trzy warstwy, jedna plaska lista.
			 * BEZ znakow diakrytycznych: `Filter` robi `remove_accents` po obu
			 * stronach porownania. Dopasowanie jest z granica slowa, wiec formy
			 * odmienione trzeba wypisac osobno.
			 */
			'excluded_words'      => array(
				// Warstwa 1 — inne gatunki. Najwazniejsza: dwa z czterech
				// domyslnych kanalow mieszaja psy z kotami.
				'kot', 'kota', 'kotu', 'kotem', 'koty', 'kotow', 'kotom', 'kotami',
				'kotka', 'kotki', 'kocur', 'kocie', 'kocia', 'kociak',
				'chomik', 'krolik', 'papuga', 'rybki', 'akwarium', 'gryzon',
				'swinka morska', 'zolw', 'terrarium', 'fretka',

				// Warstwa 2 — ogloszenia i komercja.
				'zawody', 'obozy', 'oboz', 'webinar', 'konkurs', 'zapisy', 'bilety',
				'promocja', 'rabat', 'rabatowy', 'wyprzedaz', 'black friday',
				'kup teraz', 'zamow', 'newsletter',

				// Warstwa 3 — tresci nieodpowiednie dla portalu klienta.
				'adopcja', 'schronisko', 'zbiorka', 'wplac', 'eutanazja', 'uspienie',
				'znecanie', 'okrucienstwo', 'zwloki',
			),

			/** Model Gemini. Pole w Ustawieniach, nie stala — klient moze zmienic bez nowej wersji. */
			'model'               => 'gemini-2.5-flash',

			/*
			 * Sufit dobowy wywolan AI. 20 to wartosc ZMIERZONA w tym projekcie
			 * przy wtyczce 1, nie deklaracja Google. Zostaje polem w Ustawieniach,
			 * bo limity darmowej puli licza sie per PROJEKT Google, nie per klucz.
			 */
			'daily_cap'           => 20,

			/** Przelacznik „zapisuj jako szkice zamiast publikowac". Domyslnie wylaczony. */
			'save_as_draft'       => false,

			/** Znacznik wstawienia demo — patrz KEY_DEMO_DONE. */
			self::KEY_DEMO_DONE   => false,
		);
	}

	/**
	 * Komplet ustawien: zapisane wartosci na tle domyslnych.
	 *
	 * @return array<string,mixed>
	 */
	public static function all(): array {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return array_merge( self::defaults(), $saved );
	}

	/**
	 * Pojedyncze ustawienie.
	 *
	 * @param string $key     Klucz.
	 * @param mixed  $default Wartosc, gdy klucza nie ma ani w zapisie, ani w domyslnych.
	 *
	 * @return mixed
	 */
	public static function get( string $key, $default = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Zapisuje wybrane klucze, reszty nie rusza.
	 *
	 * @param array<string,mixed> $patch Klucze do nadpisania.
	 *
	 * @return array<string,mixed> Stan po zapisie.
	 */
	public static function update( array $patch ): array {
		$next = array_merge( self::all(), $patch );
		update_option( self::OPTION, $next );
		return $next;
	}
}
