/**
 * Ustawienia wtyczki AI FAQ Generator — interakcje ekranu Ustawień.
 * Vanilla JS (bez jQuery). Ładowane tylko na podstronie Ustawienia.
 */
( function () {
	'use strict';

	var cfg = window.aifaqSettings || {};

	/* --- Pokaż / ukryj klucz API --- */
	var keyInput = document.getElementById( 'aifaq-api-key' );
	var toggle   = document.getElementById( 'aifaq-toggle-key' );
	if ( keyInput && toggle ) {
		toggle.addEventListener( 'click', function () {
			var show = keyInput.type === 'password';
			keyInput.type = show ? 'text' : 'password';
			toggle.textContent = show ? ( cfg.hide || 'Ukryj' ) : ( cfg.show || 'Pokaż' );
		} );
	}

	/* --- Suwak temperatury (podgląd wartości) --- */
	var temp    = document.getElementById( 'aifaq-temperature' );
	var tempVal = document.getElementById( 'aifaq-temp-value' );
	if ( temp && tempVal ) {
		temp.addEventListener( 'input', function () {
			tempVal.textContent = parseFloat( temp.value ).toFixed( 1 );
		} );
	}

	/* --- Test połączenia --- */
	var testBtn = document.getElementById( 'aifaq-test-connection' );
	var status  = document.getElementById( 'aifaq-test-status' );

	function setStatus( state, message ) {
		if ( ! status ) {
			return;
		}
		status.className = 'aifaq-test-status' + ( state ? ' is-' + state : '' );
		status.textContent = message;
	}

	if ( testBtn && keyInput ) {
		testBtn.addEventListener( 'click', function () {
			var key = keyInput.value.trim();
			setStatus( 'loading', cfg.checking || 'Sprawdzam połączenie…' );
			testBtn.disabled = true;

			var body = new URLSearchParams();
			body.append( 'action', 'aifaq_test_connection' );
			body.append( 'nonce', testBtn.getAttribute( 'data-nonce' ) || '' );
			body.append( 'api_key', key );

			fetch( ( cfg.ajaxUrl || window.ajaxurl ), {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString()
			} )
				.then( function ( r ) { return r.json(); } )
				.then( function ( res ) {
					if ( res && res.success ) {
						setStatus( 'ok', ( res.data && res.data.message ) || 'OK' );
					} else {
						setStatus( 'err', ( res && res.data && res.data.message ) || ( cfg.error || 'Błąd połączenia.' ) );
					}
				} )
				.catch( function () {
					setStatus( 'err', cfg.error || 'Błąd połączenia.' );
				} )
				.finally( function () {
					testBtn.disabled = false;
				} );
		} );
	}
} )();
