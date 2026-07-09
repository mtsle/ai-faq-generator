/**
 * Dashboard — indeksowanie bazy wiedzy (RAG).
 *
 * Woła akcje AJAX „reindex" i „clear", pokazuje status oraz raport.
 * Konfiguracja wstrzyknięta przez wp_localize_script jako window.aifaqIndexer.
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

	if ( ! btnReindex || ! btnClear ) {
		return;
	}

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
	 * Blokuje/odblokowuje przyciski na czas operacji.
	 *
	 * @param {boolean} busy
	 */
	function setBusy( busy ) {
		btnReindex.disabled = busy;
		btnClear.disabled   = busy;
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
	 * Renderuje raport indeksowania.
	 *
	 * @param {Object} report
	 */
	function renderReport( report ) {
		if ( ! report || ! reportEl ) {
			return;
		}

		var lines = [];
		lines.push( 'Wpisów: ' + report.posts );
		lines.push( 'Zaindeksowano: ' + report.indexed );
		lines.push( 'Pominięto (bez zmian): ' + report.skipped );
		lines.push( 'Wyczyszczono: ' + report.cleared );
		lines.push( 'Usunięto osierocone: ' + report.pruned );
		lines.push( 'Fragmentów zapisanych: ' + report.chunks );

		var html = '<p>' + lines.join( ' · ' ) + '</p>';

		if ( report.errors && report.errors.length ) {
			html += '<p><strong>Błędy:</strong></p><ul style="color:#b32d2e;">';
			report.errors.forEach( function ( e ) {
				html += '<li>' + escapeHtml( e ) + '</li>';
			} );
			html += '</ul>';
		}

		reportEl.innerHTML = html;
		reportEl.hidden = false;
	}

	function escapeHtml( s ) {
		var d = document.createElement( 'div' );
		d.textContent = String( s );
		return d.innerHTML;
	}

	function setStatus( msg ) {
		if ( statusEl ) {
			statusEl.textContent = msg || '';
		}
	}

	// --- Zaindeksuj treść ---
	btnReindex.addEventListener( 'click', function () {
		setBusy( true );
		setStatus( cfg.i18n.running );
		if ( reportEl ) {
			reportEl.hidden = true;
		}

		post( cfg.actionReindex ).then( function ( json ) {
			if ( json && json.success ) {
				updateStats( json.data.stats );
				renderReport( json.data.report );
				setStatus( cfg.i18n.done );
			} else {
				setStatus( ( json && json.data && json.data.message ) ? json.data.message : cfg.i18n.error );
			}
		} ).catch( function () {
			setStatus( cfg.i18n.error );
		} ).then( function () {
			setBusy( false );
		} );
	} );

	// --- Wyczyść bazę ---
	btnClear.addEventListener( 'click', function () {
		if ( ! window.confirm( cfg.i18n.confirmClear ) ) {
			return;
		}
		setBusy( true );
		setStatus( cfg.i18n.clearing );

		post( cfg.actionClear ).then( function ( json ) {
			if ( json && json.success ) {
				updateStats( json.data.stats );
				if ( reportEl ) {
					reportEl.hidden = true;
				}
				setStatus( cfg.i18n.done );
			} else {
				setStatus( ( json && json.data && json.data.message ) ? json.data.message : cfg.i18n.error );
			}
		} ).catch( function () {
			setStatus( cfg.i18n.error );
		} ).then( function () {
			setBusy( false );
		} );
	} );
} )();
