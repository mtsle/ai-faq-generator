/**
 * Powłoka apki `/faqgenerator` dla właściciela — zakładki, indeksowanie, ustawienia.
 *
 * Ładowana tylko dla zalogowanego admina (patrz GeneratorPage::render_standalone).
 * Konfiguracja z window.aifaqApp: { isOwner, nonce, endpoints{status,reindex,
 * clear,settings,verify}, i18n }. Akcje panelu ida przez REST /aifaq/v1/admin/*
 * z naglowkiem X-WP-Nonce (cookie-auth), bramkowane serwerowo manage_options.
 */
( function () {
	'use strict';

	var cfg = window.aifaqApp;
	if ( ! cfg || ! cfg.isOwner ) {
		return;
	}
	var t   = cfg.i18n || {};
	var ep  = cfg.endpoints || {};

	// Wspólny POST JSON z nonce; zwraca { ok, data }.
	function post( url, payload ) {
		return fetch( url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'Accept': 'application/json',
				'X-WP-Nonce': cfg.nonce || ''
			},
			body: payload ? JSON.stringify( payload ) : undefined
		} ).then( function ( res ) {
			return res.json()
				.catch( function () { return {}; } )
				.then( function ( data ) { return { ok: res.ok, data: data || {} }; } );
		} );
	}

	function setText( id, value ) {
		var el = document.getElementById( id );
		if ( el ) { el.textContent = String( value ); }
	}

	// =========================================================================
	// Zakładki
	// =========================================================================
	var app = document.querySelector( '.aifaq-app' );
	if ( app ) {
		var tabs   = Array.prototype.slice.call( app.querySelectorAll( '.aifaq-app__tab' ) );
		var panels = Array.prototype.slice.call( app.querySelectorAll( '.aifaq-app__panel' ) );

		var activate = function ( key ) {
			tabs.forEach( function ( tab ) {
				var on = tab.getAttribute( 'data-tab-target' ) === key;
				tab.classList.toggle( 'is-active', on );
				tab.setAttribute( 'aria-selected', on ? 'true' : 'false' );
			} );
			panels.forEach( function ( panel ) {
				var on = panel.id === 'aifaq-panel-' + key;
				panel.classList.toggle( 'is-active', on );
				panel.hidden = ! on;
			} );
			app.setAttribute( 'data-tab', key );
		};

		tabs.forEach( function ( tab ) {
			tab.addEventListener( 'click', function () {
				activate( tab.getAttribute( 'data-tab-target' ) );
			} );
		} );
	}

	// =========================================================================
	// Indeksowanie (REST /admin/{reindex,clear})
	// =========================================================================
	var btnReindex = document.getElementById( 'aifaq-reindex' );
	var btnClear   = document.getElementById( 'aifaq-clear' );

	if ( btnReindex && btnClear ) {
		var statusEl = document.getElementById( 'aifaq-index-status' );
		var reportEl = document.getElementById( 'aifaq-index-report' );

		var setBusy = function ( busy ) {
			btnReindex.disabled = busy;
			btnClear.disabled   = busy;
		};
		var setStatus = function ( msg ) {
			if ( statusEl ) { statusEl.textContent = msg || ''; }
		};
		var updateStats = function ( stats ) {
			if ( ! stats ) { return; }
			setText( 'aifaq-stat-chunks', stats.chunks );
			setText( 'aifaq-stat-posts', stats.posts );
			setText( 'aifaq-stat-embedded', stats.embedded );
		};
		var num = function ( v ) { return ( v === undefined || v === null ) ? 0 : v; };
		var appendList = function ( items, label ) {
			if ( ! items || ! items.length ) { return; }
			var h = document.createElement( 'p' );
			var s = document.createElement( 'strong' );
			s.textContent = label;
			h.appendChild( s );
			reportEl.appendChild( h );
			var ul = document.createElement( 'ul' );
			items.forEach( function ( it ) {
				var li = document.createElement( 'li' );
				li.textContent = String( it );
				ul.appendChild( li );
			} );
			reportEl.appendChild( ul );
		};
		// Raport: liczby i listy budowane przez textContent (anti-XSS).
		var renderReport = function ( report ) {
			if ( ! report || ! reportEl ) { return; }
			reportEl.textContent = '';
			var line = document.createElement( 'p' );
			line.textContent = [
				'Wpisów: ' + num( report.posts ),
				'Zaindeksowano: ' + num( report.indexed ),
				'Pominięto: ' + num( report.skipped ),
				'Wyczyszczono: ' + num( report.cleared ),
				'Usunięto osierocone: ' + num( report.pruned ),
				'Fragmentów: ' + num( report.chunks )
			].join( ' · ' );
			reportEl.appendChild( line );
			appendList( report.warnings, 'Uwagi:' );
			appendList( report.errors, 'Błędy:' );
			reportEl.hidden = false;
		};

		btnReindex.addEventListener( 'click', function () {
			setBusy( true );
			setStatus( t.idxRunning || '' );
			if ( reportEl ) { reportEl.hidden = true; }
			post( ep.reindex ).then( function ( r ) {
				if ( r.ok && r.data && 'ok' === r.data.status ) {
					updateStats( r.data.stats );
					renderReport( r.data.report );
					setStatus( t.idxDone || '' );
				} else {
					setStatus( ( r.data && r.data.message ) ? r.data.message : ( t.idxError || '' ) );
				}
			} ).catch( function () {
				setStatus( t.idxError || '' );
			} ).then( function () { setBusy( false ); } );
		} );

		btnClear.addEventListener( 'click', function () {
			if ( ! window.confirm( t.idxConfirm || '' ) ) { return; }
			setBusy( true );
			setStatus( t.idxClearing || '' );
			post( ep.clear ).then( function ( r ) {
				if ( r.ok && r.data && 'ok' === r.data.status ) {
					updateStats( r.data.stats );
					if ( reportEl ) { reportEl.hidden = true; }
					setStatus( t.idxDone || '' );
				} else {
					setStatus( ( r.data && r.data.message ) ? r.data.message : ( t.idxError || '' ) );
				}
			} ).catch( function () {
				setStatus( t.idxError || '' );
			} ).then( function () { setBusy( false ); } );
		} );
	}

	// =========================================================================
	// Ustawienia (REST /admin/{settings,verify})
	// =========================================================================
	var setForm = document.getElementById( 'aifaq-set-form' );

	if ( setForm ) {
		var keyInput  = document.getElementById( 'aifaq-set-key' );
		var keyToggle = document.getElementById( 'aifaq-set-key-toggle' );
		var btnVerify = document.getElementById( 'aifaq-set-verify' );
		var vStatus   = document.getElementById( 'aifaq-set-verify-status' );
		var modelSel  = document.getElementById( 'aifaq-set-model' );
		var tempRange = document.getElementById( 'aifaq-set-temp' );
		var tempVal   = document.getElementById( 'aifaq-set-temp-val' );
		var langSel   = document.getElementById( 'aifaq-set-lang' );
		var btnSave   = document.getElementById( 'aifaq-set-save' );
		var sStatus   = document.getElementById( 'aifaq-set-save-status' );

		// Pokaż / ukryj klucz.
		if ( keyToggle && keyInput ) {
			keyToggle.addEventListener( 'click', function () {
				var show = 'password' === keyInput.type;
				keyInput.type = show ? 'text' : 'password';
				keyToggle.textContent = show ? ( keyToggle.getAttribute( 'data-hide' ) || '' ) : ( keyToggle.getAttribute( 'data-show' ) || '' );
			} );
		}

		// Suwak temperatury → podgląd wartości.
		if ( tempRange && tempVal ) {
			tempRange.addEventListener( 'input', function () {
				tempVal.textContent = parseFloat( tempRange.value ).toFixed( 1 );
			} );
		}

		// Test połączenia.
		if ( btnVerify ) {
			btnVerify.addEventListener( 'click', function () {
				btnVerify.disabled = true;
				if ( vStatus ) { vStatus.className = 'aifaq-set__status'; vStatus.textContent = t.setTesting || ''; }
				post( ep.verify, { api_key: keyInput ? keyInput.value : '' } ).then( function ( r ) {
					var okv = r.ok && r.data && 'ok' === r.data.status;
					if ( vStatus ) {
						vStatus.className = 'aifaq-set__status ' + ( okv ? 'is-ok' : 'is-err' );
						vStatus.textContent = ( r.data && r.data.message ) ? r.data.message : '';
					}
				} ).catch( function () {
					if ( vStatus ) { vStatus.className = 'aifaq-set__status is-err'; vStatus.textContent = t.setSaveErr || ''; }
				} ).then( function () { btnVerify.disabled = false; } );
			} );
		}

		// Zapis ustawień.
		setForm.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			if ( btnSave ) { btnSave.disabled = true; }
			if ( sStatus ) { sStatus.className = 'aifaq-set__status'; sStatus.textContent = t.setSaving || ''; }

			var payload = {
				model: modelSel ? modelSel.value : undefined,
				temperature: tempRange ? tempRange.value : undefined,
				language: langSel ? langSel.value : undefined
			};
			// Klucz wysyłamy tylko, gdy wpisano nowy (puste = zachowaj zapisany).
			if ( keyInput && keyInput.value.trim() !== '' ) {
				payload.api_key = keyInput.value.trim();
			}

			post( ep.settings, payload ).then( function ( r ) {
				var okv = r.ok && r.data && 'ok' === r.data.status;
				if ( sStatus ) {
					sStatus.className = 'aifaq-set__status ' + ( okv ? 'is-ok' : 'is-err' );
					sStatus.textContent = okv ? ( t.setSaved || '' ) : ( ( r.data && r.data.message ) ? r.data.message : ( t.setSaveErr || '' ) );
				}
				// Po zapisie czyścimy pole klucza (znów zamaskowane).
				if ( okv && keyInput ) { keyInput.value = ''; keyInput.type = 'password'; }
			} ).catch( function () {
				if ( sStatus ) { sStatus.className = 'aifaq-set__status is-err'; sStatus.textContent = t.setSaveErr || ''; }
			} ).then( function () {
				if ( btnSave ) { btnSave.disabled = false; }
			} );
		} );
	}
} )();
