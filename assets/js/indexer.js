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
	 * Wysyła żądanie AJAX (application/x-www-form-urlencoded) i zwraca Promise
	 * z `{status, json}` — NIGDY nie odrzuca przez samo nieudane parsowanie JSON-a.
	 *
	 * `check_ajax_referer()` przy wygasłym nonce kończy żądanie surowym „-1"
	 * (status 401/403), nie JSON-em — bez tego opakowania wywołujący nie miał
	 * jak odróżnić „sesja wygasła" od „serwer padł" (obie lądowały w tym samym
	 * catch-u niżej).
	 *
	 * @param {string} action Nazwa akcji admin-ajax.
	 * @return {Promise<{status:number,json:Object}>}
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
			return res.json()
				.catch( function () { return null; } )
				.then( function ( json ) { return { status: res.status, json: json }; } );
		} );
	}

	/**
	 * Komunikat błędu: 401/403 (sesja/nonce wygasły) dostają WŁASNY tekst
	 * zamiast ogólnego — `admin-ajax.php` nie zwraca tu treści do pokazania.
	 *
	 * @param {number} status
	 * @return {string}
	 */
	function errorMessage( status ) {
		if ( 401 === status || 403 === status ) {
			return i18n.sessionExpired || i18n.error || '';
		}
		return i18n.error || '';
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
	 * Renderuje raport indeksowania — implementacja WSPÓLNA z app.js,
	 * patrz {@see window.AIFAQReport} w `report-render.js` (R3).
	 *
	 * @param {Object} report
	 */
	function renderReport( report ) {
		if ( ! window.AIFAQReport ) {
			return;
		}
		window.AIFAQReport.render( reportEl, report, {
			sources:  i18n.reportSources,
			filtered: i18n.reportFilter,
			warnings: i18n.reportWarn,
			errors:   i18n.reportErrors
		} );
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

		post( cfg.actionReindex ).then( function ( r ) {
			var json = r.json;
			if ( json && json.success ) {
				updateStats( json.data.stats );
				renderReport( json.data.report );
				setStatus( i18n.done );
			} else {
				setStatus( ( json && json.data && json.data.message ) ? json.data.message : errorMessage( r.status ) );
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

		post( cfg.actionClear ).then( function ( r ) {
			var json = r.json;
			if ( json && json.success ) {
				updateStats( json.data.stats );
				if ( reportEl ) {
					reportEl.hidden = true;
				}
				setStatus( i18n.done );
			} else {
				setStatus( ( json && json.data && json.data.message ) ? json.data.message : errorMessage( r.status ) );
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
