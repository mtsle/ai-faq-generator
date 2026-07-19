/**
 * Dashboard — indeksowanie bazy wiedzy (RAG).
 *
 * Woła akcje AJAX „reindex" i „clear", pokazuje status oraz raport.
 * Krok 17: odpytuje GET /admin/status i blokuje przycisk indeksowania, gdy
 * w tle trwa pobieranie podstron (crawl) — inaczej właściciel zwektoryzowałby
 * połowiczną treść za realne pieniądze.
 *
 * Konfiguracja wstrzyknięta przez wp_localize_script jako window.aifaqIndexer.
 * Raport i komunikaty budujemy wyłącznie przez DOM + textContent — zero wstrzykiwania
 * gotowego HTML-a (kontrakt k17-v3 §7 pkt 1).
 */
( function () {
	'use strict';

	var cfg = window.aifaqIndexer;
	if ( ! cfg ) {
		return;
	}

	var btnReindex = document.getElementById( 'aifaq-reindex' );
	var btnClear   = document.getElementById( 'aifaq-clear' );
	var statusEl   = document.getElementById( 'aifaq-index-status' );
	var reportEl   = document.getElementById( 'aifaq-index-report' );
	var crawlEl    = document.getElementById( 'aifaq-crawl-note' );

	if ( ! btnReindex || ! btnClear ) {
		return;
	}

	var i18n = cfg.i18n || {};

	// Dwa NIEZALEŻNE powody blokady przycisku indeksowania: trwa nasza własna
	// operacja (busy) albo w tle leci crawl (crawlBlocked). Trzymanie ich osobno
	// jest konieczne — inaczej koniec operacji AJAX odblokowałby przycisk
	// zablokowany przez crawl.
	var busy         = false;
	var crawlBlocked = ( true === btnReindex.disabled );
	var pollTimer    = null;

	/**
	 * Wysyła żądanie AJAX (application/x-www-form-urlencoded) i zwraca Promise z JSON.
	 *
	 * @param {string} action Nazwa akcji admin-ajax.
	 * @return {Promise<Object>}
	 */
	function post( action ) {
		var body = new URLSearchParams();
		body.append( 'action', action );
		body.append( 'nonce', cfg.nonce );

		return fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		} ).then( function ( res ) {
			return res.json();
		} );
	}

	/**
	 * Przepisuje stan blokad na przyciski.
	 */
	function applyState() {
		btnReindex.disabled = busy || crawlBlocked;
		btnClear.disabled   = busy;
	}

	/**
	 * Blokuje/odblokowuje przyciski na czas operacji.
	 *
	 * @param {boolean} value
	 */
	function setBusy( value ) {
		busy = !! value;
		applyState();
	}

	/**
	 * Aktualizuje liczniki statystyk w nagłówku karty.
	 *
	 * @param {Object} stats
	 */
	function updateStats( stats ) {
		if ( ! stats ) {
			return;
		}
		setText( 'aifaq-stat-chunks', stats.chunks );
		setText( 'aifaq-stat-posts', stats.posts );
		setText( 'aifaq-stat-embedded', stats.embedded );
	}

	function setText( id, value ) {
		var el = document.getElementById( id );
		if ( el ) {
			el.textContent = String( value );
		}
	}

	/**
	 * Podstawia %1$s / %2$s w napisie z PHP (wp_localize_script nie robi sprintf).
	 *
	 * @param {string} tpl
	 * @param {string|number} a
	 * @param {string|number} b
	 * @return {string}
	 */
	function fmt( tpl, a, b ) {
		return String( tpl || '' )
			.replace( '%1$s', String( a ) )
			.replace( '%2$s', String( b ) );
	}

	/**
	 * Tworzy element i wypełnia go przez textContent (nigdy wstrzyknięciem HTML-a).
	 *
	 * @param {string} tag
	 * @param {string} text
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
	 * Dokłada nagłówek + listę pozycji.
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
	 * Renderuje raport indeksowania.
	 *
	 * @param {Object} report
	 */
	function renderReport( report ) {
		if ( ! report || ! reportEl ) {
			return;
		}

		while ( reportEl.firstChild ) {
			reportEl.removeChild( reportEl.firstChild );
		}

		var lines = [];
		lines.push( 'Wpisów: ' + report.posts );
		lines.push( 'Zaindeksowano: ' + report.indexed );
		lines.push( 'Pominięto (bez zmian): ' + report.skipped );
		lines.push( 'Wyczyszczono: ' + report.cleared );
		lines.push( 'Usunięto osierocone: ' + report.pruned );
		lines.push( 'Fragmentów zapisanych: ' + report.chunks );

		reportEl.appendChild( el( 'p', lines.join( ' · ' ) ) );

		// Krok 17: skąd wzięła się treść (klucz = krótka nazwa klasy źródła).
		if ( report.sources && 'object' === typeof report.sources ) {
			var srcLines = Object.keys( report.sources ).map( function ( name ) {
				var s = report.sources[ name ] || {};
				return name + ': ' + ( s.docs || 0 ) + ' dok. / ' + ( s.chars || 0 ) + ' zn.';
			} );
			appendList( reportEl, i18n.reportSources || 'Źródła treści:', srcLines );
		}

		if ( report.filtered_lines ) {
			reportEl.appendChild( el(
				'p',
				( i18n.reportFilter || 'Usunięto powtarzalnych linii:' ) + ' ' + report.filtered_lines
			) );
		}

		appendList( reportEl, i18n.reportWarn || 'Uwagi:', report.warnings, '#996800' );
		appendList( reportEl, i18n.reportErrors || 'Błędy:', report.errors, '#b32d2e' );

		reportEl.hidden = false;
	}

	function setStatus( msg ) {
		if ( statusEl ) {
			statusEl.textContent = msg || '';
		}
	}

	function setCrawlNote( msg ) {
		if ( crawlEl ) {
			crawlEl.textContent = msg || '';
		}
	}

	// --- Krok 17: postęp pobierania stron (GET /admin/status) ---

	/**
	 * Odpytuje status i przestawia blokadę przycisku indeksowania.
	 */
	function pollStatus() {
		if ( ! cfg.statusUrl ) {
			return;
		}

		fetch( cfg.statusUrl, {
			method: 'GET',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': cfg.restNonce || '' }
		} ).then( function ( res ) {
			return res.json();
		} ).then( function ( json ) {
			var crawl = ( json && json.crawl ) ? json.crawl : null;
			if ( ! crawl ) {
				return;
			}

			if ( true === crawl.running ) {
				crawlBlocked = true;
				applyState();
				setCrawlNote( fmt( i18n.crawlRunning, crawl.done, crawl.total ) );
				schedulePoll();
				return;
			}

			if ( crawlBlocked ) {
				crawlBlocked = false;
				applyState();
				setCrawlNote( i18n.crawlDone || '' );
			}
		} ).catch( function () {
			// Błąd odpytania nie może zablokować panelu — zostawiamy stan bez zmian.
		} );
	}

	function schedulePoll() {
		if ( pollTimer ) {
			window.clearTimeout( pollTimer );
		}
		pollTimer = window.setTimeout( pollStatus, cfg.pollMs || 10000 );
	}

	// --- Zaindeksuj treść ---
	btnReindex.addEventListener( 'click', function () {
		setBusy( true );
		setStatus( i18n.running );
		if ( reportEl ) {
			reportEl.hidden = true;
		}

		post( cfg.actionReindex ).then( function ( json ) {
			if ( json && json.success ) {
				updateStats( json.data.stats );
				renderReport( json.data.report );
				setStatus( i18n.done );
			} else {
				setStatus( ( json && json.data && json.data.message ) ? json.data.message : i18n.error );
			}
		} ).catch( function () {
			setStatus( i18n.error );
		} ).then( function () {
			setBusy( false );
			// Reindeks mógł dopiero co wystartować kolejkę pobierania.
			pollStatus();
		} );
	} );

	// --- Wyczyść bazę ---
	btnClear.addEventListener( 'click', function () {
		if ( ! window.confirm( i18n.confirmClear ) ) {
			return;
		}
		setBusy( true );
		setStatus( i18n.clearing );

		post( cfg.actionClear ).then( function ( json ) {
			if ( json && json.success ) {
				updateStats( json.data.stats );
				if ( reportEl ) {
					reportEl.hidden = true;
				}
				setStatus( i18n.done );
			} else {
				setStatus( ( json && json.data && json.data.message ) ? json.data.message : i18n.error );
			}
		} ).catch( function () {
			setStatus( i18n.error );
		} ).then( function () {
			setBusy( false );
		} );
	} );

	// Stan startowy: dopytaj od razu (serwer wyrenderował go na wejściu, ale crawl
	// mógł się zakończyć między renderem a wczytaniem skryptu).
	pollStatus();
} )();
