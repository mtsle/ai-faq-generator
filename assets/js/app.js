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

	// Wspólny GET z nonce + parametrami; zwraca { ok, data }.
	// Doklejamy '&', gdy adres ma juz query — przy zwyklych odnosnikach REST
	// wyglada to jak ?rest_route=/aifaq/v1/... i '?' by go rozwalilo.
	function get( url, params ) {
		var qs = Object.keys( params || {} )
			.filter( function ( k ) {
				return params[ k ] !== '' && params[ k ] !== undefined && params[ k ] !== null;
			} )
			.map( function ( k ) {
				return encodeURIComponent( k ) + '=' + encodeURIComponent( params[ k ] );
			} )
			.join( '&' );
		var full = qs ? ( url + ( url.indexOf( '?' ) === -1 ? '?' : '&' ) + qs ) : url;

		return fetch( full, {
			method: 'GET',
			credentials: 'same-origin',
			headers: {
				'Accept': 'application/json',
				'X-WP-Nonce': cfg.nonce || ''
			}
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

	// =========================================================================
	// Historia (REST /admin/history) — ten sam panel na froncie i w kokpicie
	// =========================================================================
	var hist = document.getElementById( 'aifaq-hist' );

	if ( hist && ep.history ) {
		var hList   = document.getElementById( 'aifaq-hist-list' );
		var hSel    = document.getElementById( 'aifaq-hist-status' );
		var hCount  = document.getElementById( 'aifaq-hist-count' );
		var hPurge  = document.getElementById( 'aifaq-hist-purge' );
		var hPager  = document.getElementById( 'aifaq-hist-pager' );
		var hPage   = document.getElementById( 'aifaq-hist-page' );
		var hPrev   = document.getElementById( 'aifaq-hist-prev' );
		var hNext   = document.getElementById( 'aifaq-hist-next' );

		var state = { page: 1, status: '', pages: 0, busy: false };

		var STATUS_LABEL = { answered: 'stAnswered', refused: 'stRefused', error: 'stError' };
		var SOURCE_LABEL = { ai: 'srcAi', cache: 'srcCache', rate_limit: 'srcRateLimit' };

		// Komunikat na miejscu listy (wczytywanie / pusto / błąd).
		var note = function ( msg ) {
			hList.textContent = '';
			var p = document.createElement( 'p' );
			p.className = 'aifaq-hist__note';
			p.textContent = msg || '';
			hList.appendChild( p );
		};

		var renderStats = function ( s ) {
			if ( ! s ) { return; }
			setText( 'aifaq-hist-total', s.total );
			setText( 'aifaq-hist-today', s.today );
			setText( 'aifaq-hist-week', s.week );
			setText( 'aifaq-hist-refused', s.refused );
			setText( 'aifaq-hist-cached', s.cached );
			setText( 'aifaq-hist-avgscore', s.total ? Number( s.avg_score ).toFixed( 2 ) : '–' );
		};

		// Jeden wiersz: nagłówek (pytanie + metryki) rozwijający odpowiedź.
		// WSZYSTKO przez textContent — to treść od gościa i od modelu.
		var buildRow = function ( item ) {
			var row = document.createElement( 'article' );
			row.className = 'aifaq-hist__row';

			var head = document.createElement( 'button' );
			head.type = 'button';
			head.className = 'aifaq-hist__head';
			head.setAttribute( 'aria-expanded', 'false' );

			var q = document.createElement( 'span' );
			q.className = 'aifaq-hist__q';
			q.textContent = item.question || '';
			head.appendChild( q );

			var meta = document.createElement( 'span' );
			meta.className = 'aifaq-hist__meta';

			var badge = document.createElement( 'span' );
			badge.className = 'aifaq-hist__badge is-' + ( item.status || 'error' );
			badge.textContent = t[ STATUS_LABEL[ item.status ] ] || item.status || '';
			meta.appendChild( badge );

			var src = document.createElement( 'span' );
			src.className = 'aifaq-hist__src';
			src.textContent = t[ SOURCE_LABEL[ item.source ] ] || item.source || '';
			meta.appendChild( src );

			if ( 'error' !== item.status ) {
				var score = document.createElement( 'span' );
				score.className = 'aifaq-hist__score';
				score.textContent = Number( item.score ).toFixed( 2 );
				meta.appendChild( score );
			}

			var date = document.createElement( 'time' );
			date.className = 'aifaq-hist__date';
			date.textContent = item.date || '';
			if ( item.iso ) { date.setAttribute( 'datetime', String( item.iso ).replace( ' ', 'T' ) ); }
			meta.appendChild( date );

			head.appendChild( meta );
			row.appendChild( head );

			var ans = document.createElement( 'div' );
			ans.className = 'aifaq-hist__answer';
			ans.hidden = true;
			ans.textContent = item.answer ? item.answer : ( t.histNoAnswer || '' );
			if ( ! item.answer ) { ans.classList.add( 'is-empty' ); }
			row.appendChild( ans );

			head.addEventListener( 'click', function () {
				var open = ans.hidden;
				ans.hidden = ! open;
				head.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
				row.classList.toggle( 'is-open', open );
			} );

			return row;
		};

		var renderPager = function ( data ) {
			state.pages = data.pages || 0;
			state.page  = data.page || 1;

			if ( hCount ) {
				hCount.textContent = ( t.histCountFmt || '%s' ).replace( '%s', data.total || 0 );
			}
			if ( ! hPager ) { return; }

			hPager.hidden = state.pages < 2;
			if ( hPage ) {
				hPage.textContent = ( t.histPageFmt || '%1$s / %2$s' )
					.replace( '%1$s', state.page )
					.replace( '%2$s', state.pages );
			}
			if ( hPrev ) { hPrev.disabled = state.page <= 1; }
			if ( hNext ) { hNext.disabled = state.page >= state.pages; }
		};

		var load = function () {
			if ( state.busy ) { return; }
			state.busy = true;
			hList.setAttribute( 'aria-busy', 'true' );
			note( t.histLoading || '' );

			get( ep.history, {
				page: state.page,
				per_page: cfg.perPage || 20,
				status: state.status
			} ).then( function ( r ) {
				if ( ! r.ok || ! r.data || 'ok' !== r.data.status ) {
					note( t.histError || '' );
					if ( hPager ) { hPager.hidden = true; }
					return;
				}

				renderStats( r.data.stats );
				renderPager( r.data );

				var items = r.data.items || [];
				if ( ! items.length ) {
					note( t.histEmpty || '' );
					return;
				}

				hList.textContent = '';
				items.forEach( function ( item ) {
					hList.appendChild( buildRow( item ) );
				} );
			} ).catch( function () {
				note( t.histError || '' );
			} ).then( function () {
				state.busy = false;
				hList.setAttribute( 'aria-busy', 'false' );
			} );
		};

		if ( hSel ) {
			hSel.addEventListener( 'change', function () {
				state.status = hSel.value;
				state.page   = 1;
				load();
			} );
		}
		if ( hPrev ) {
			hPrev.addEventListener( 'click', function () {
				if ( state.page > 1 ) { state.page--; load(); }
			} );
		}
		if ( hNext ) {
			hNext.addEventListener( 'click', function () {
				if ( state.page < state.pages ) { state.page++; load(); }
			} );
		}
		if ( hPurge && ep.historyClear ) {
			hPurge.addEventListener( 'click', function () {
				if ( ! window.confirm( t.histPurgeConf || '' ) ) { return; }
				hPurge.disabled = true;
				note( t.histPurging || '' );
				post( ep.historyClear ).then( function ( r ) {
					if ( r.ok && r.data && 'ok' === r.data.status ) {
						state.page = 1;
						load();
					} else {
						note( t.histError || '' );
					}
				} ).catch( function () {
					note( t.histError || '' );
				} ).then( function () { hPurge.disabled = false; } );
			} );
		}

		load();
	}
} )();
