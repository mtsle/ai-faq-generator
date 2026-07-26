/**
 * Render raportu indeksowania — WSPÓLNY dla kokpitu (indexer.js, wp-admin)
 * i panelu właściciela na froncie (app.js, `/faqgenerator`).
 *
 * R3 (dług sprzed Kroku 22): dwie niezależne implementacje tego samego
 * raportu dryfowały już w treści etykiet ("Fragmentów zapisanych:" vs
 * "Fragmentów:", "Pominięto (bez zmian):" vs "Pominięto:") i front nie miał
 * sekcji „Źródła treści"/„Usunięto powtarzalnych linii", którą kokpit ma od
 * Kroku 17. Jedno źródło prawdy eliminuje obie rozbieżności naraz.
 *
 * Budowa WYŁĄCZNIE przez textContent (zero innerHTML/wstrzykiwania HTML-a) —
 * ten sam wymóg bezpieczeństwa co w obu dotychczasowych implementacjach.
 *
 * Wystawia `window.AIFAQReport.render( reportEl, report, labels )`:
 *   - reportEl — kontener (może być null — wtedy no-op);
 *   - report   — surowy raport z IndexController::run_reindex();
 *   - labels   — opcjonalne nadpisania etykiet (i18n wywołującego ekranu);
 *                brakujące klucze dostają polski tekst domyślny.
 */
( function () {
	'use strict';

	/**
	 * Tworzy element i wypełnia go przez textContent.
	 *
	 * @param {string} tag
	 * @param {string} [text]
	 * @param {string} [color]
	 * @return {HTMLElement}
	 */
	function el( tag, text, color ) {
		var node = document.createElement( tag );
		if ( undefined !== text && null !== text ) {
			node.textContent = String( text );
		}
		if ( color ) {
			node.style.color = color;
		}
		return node;
	}

	/**
	 * Dokłada nagłówek + listę pozycji (pomija się, gdy lista jest pusta).
	 *
	 * @param {HTMLElement} parent
	 * @param {string} label
	 * @param {Array} items
	 * @param {string} [color]
	 */
	function appendList( parent, label, items, color ) {
		if ( ! items || ! items.length ) {
			return;
		}

		var head = el( 'p' );
		head.appendChild( el( 'strong', label ) );
		parent.appendChild( head );

		var list = document.createElement( 'ul' );
		list.style.listStyle = 'disc';
		list.style.marginLeft = '1.5em';
		if ( color ) {
			list.style.color = color;
		}

		items.forEach( function ( item ) {
			list.appendChild( el( 'li', item ) );
		} );

		parent.appendChild( list );
	}

	/**
	 * @param {number|undefined|null} v
	 * @return {number|string}
	 */
	function num( v ) {
		return ( undefined === v || null === v ) ? 0 : v;
	}

	/**
	 * Renderuje raport indeksowania w danym kontenerze.
	 *
	 * @param {HTMLElement} reportEl Kontener raportu.
	 * @param {Object} report Raport z IndexController::run_reindex().
	 * @param {Object} [labels] Nadpisania etykiet dla wywołującego ekranu.
	 */
	function render( reportEl, report, labels ) {
		if ( ! report || ! reportEl ) {
			return;
		}
		labels = labels || {};

		while ( reportEl.firstChild ) {
			reportEl.removeChild( reportEl.firstChild );
		}

		var lines = [
			( labels.posts || 'Wpisów:' ) + ' ' + num( report.posts ),
			( labels.indexed || 'Zaindeksowano:' ) + ' ' + num( report.indexed ),
			( labels.skipped || 'Pominięto (bez zmian):' ) + ' ' + num( report.skipped ),
			( labels.cleared || 'Wyczyszczono:' ) + ' ' + num( report.cleared ),
			( labels.pruned || 'Usunięto osierocone:' ) + ' ' + num( report.pruned ),
			( labels.chunks || 'Fragmentów zapisanych:' ) + ' ' + num( report.chunks )
		];

		reportEl.appendChild( el( 'p', lines.join( ' · ' ) ) );

		// Krok 17: skąd wzięła się treść (klucz = krótka nazwa klasy źródła).
		if ( report.sources && 'object' === typeof report.sources ) {
			var srcLines = Object.keys( report.sources ).map( function ( name ) {
				var s = report.sources[ name ] || {};
				return name + ': ' + ( s.docs || 0 ) + ' dok. / ' + ( s.chars || 0 ) + ' zn.';
			} );
			appendList( reportEl, labels.sources || 'Źródła treści:', srcLines );
		}

		if ( report.filtered_lines ) {
			reportEl.appendChild( el(
				'p',
				( labels.filtered || 'Usunięto powtarzalnych linii:' ) + ' ' + report.filtered_lines
			) );
		}

		appendList( reportEl, labels.warnings || 'Uwagi:', report.warnings, '#996800' );
		appendList( reportEl, labels.errors || 'Błędy:', report.errors, '#b32d2e' );

		reportEl.hidden = false;
	}

	window.AIFAQReport = { render: render };
}() );
