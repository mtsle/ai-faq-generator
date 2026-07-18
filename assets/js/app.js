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

	// =========================================================================
	// Historia generowań (REST /admin/generations) — Krok 15.
	// Ten sam panel na froncie (zakładka `gh`) i w kokpicie („Historia generowań").
	// Blok jest NO-OPEM, gdy powłoki panelu nie ma na stronie — dzięki temu
	// pozostałe ekrany (dashboard, ustawienia, dziennik pytań) działają bez zmian.
	//
	// UWAGA na nazwy: cały plik to jedna IIFE, więc `state`/`note`/`buildRow`/
	// `load` z bloku dziennika pytań żyją w TYM SAMYM zakresie. Wszystko poniżej
	// ma prefiks `gh`, żeby niczego nie nadpisać.
	// =========================================================================
	var ghRoot = document.getElementById( 'aifaq-gh' );
	var ghList = document.getElementById( 'aifaq-gh-list' );

	if ( ghRoot && ghList && ep.generations ) {
		var ghCount  = document.getElementById( 'aifaq-gh-count' );
		var ghPager  = document.getElementById( 'aifaq-gh-pager' );
		var ghPageEl = document.getElementById( 'aifaq-gh-page' );
		var ghPrev   = document.getElementById( 'aifaq-gh-prev' );
		var ghNext   = document.getElementById( 'aifaq-gh-next' );

		var ghState = { page: 1, pages: 0, busy: false };

		// Cache szczegółów per wiersz: id → { pairs: [], desc: '' }.
		// Drugie rozwinięcie wiersza NIE wysyła żądania (KONTRAKT §5 pkt 4).
		var ghCache = {};
		var ghCached = function ( id ) {
			return Object.prototype.hasOwnProperty.call( ghCache, id );
		};

		// Podstawienie do wzorca i18n. Zamiennik podajemy jako FUNKCJĘ — inaczej
		// `$&` w treści od usera/modelu (opis, temat) zostałoby zinterpretowane
		// przez String.replace jako wzorzec.
		var ghFmt = function ( tpl, value ) {
			return String( tpl || '%s' ).replace( '%s', function () { return String( value ); } );
		};

		// Komunikat zastępczy (wczytywanie / pusto / błąd) — KONTRAKT §3c.
		var ghNote = function ( parent, msg ) {
			parent.textContent = '';
			var p = document.createElement( 'p' );
			p.className = 'aifaq-gh__note';
			p.textContent = msg || '';
			parent.appendChild( p );
		};

		// Adres „Ponownie wygeneruj" — KONTRAKT §3b pkt 4. Nazwa parametru NIE jest
		// wpisana literalnie: bierzemy ją ze stałej PHP wystawionej w configu.
		var ghRegenHref = function ( id ) {
			var base = cfg.faqToolUrl || '';
			if ( ! base ) { return ''; }
			var name = cfg.regenParam || 'aifaq_regen';
			return base + ( base.indexOf( '?' ) === -1 ? '?' : '&' ) +
				encodeURIComponent( name ) + '=' + encodeURIComponent( id );
		};

		// Pary w podglądzie — KONTRAKT §3c. Pytanie i odpowiedź to treść od modelu
		// → wyłącznie textContent.
		var ghRenderPairs = function ( container, list ) {
			container.textContent = '';
			if ( ! list || ! list.length ) {
				ghNote( container, t.ghPairsEmpty || '' );
				return;
			}
			list.forEach( function ( p ) {
				var pair = document.createElement( 'div' );
				pair.className = 'aifaq-gh__pair';

				var q = document.createElement( 'p' );
				q.className = 'aifaq-gh__q';
				q.textContent = ( p && p.question ) || '';
				pair.appendChild( q );

				var a = document.createElement( 'p' );
				a.className = 'aifaq-gh__a';
				a.textContent = ( p && p.answer ) || '';
				pair.appendChild( a );

				container.appendChild( pair );
			} );
		};

		// Jeden wiersz historii — KONTRAKT §3b.
		// `.aifaq-gh__acts` jest RODZEŃSTWEM nagłówka, nie dzieckiem: nagłówek to
		// <button>, a zagnieżdżony <button> to nieprawidłowy HTML (parser rozerwałby drzewo).
		var ghBuildRow = function ( item ) {
			var id     = parseInt( item.id, 10 ) || 0;
			var headId = 'aifaq-gh-head-' + id;
			var bodyId = 'aifaq-gh-body-' + id;

			var row = document.createElement( 'article' );
			row.className = 'aifaq-gh__row';
			row.setAttribute( 'data-id', String( id ) );

			// --- nagłówek (klikalny, rozwija podgląd) ---
			var head = document.createElement( 'button' );
			head.type      = 'button';
			head.className = 'aifaq-gh__head';
			head.id        = headId;
			head.setAttribute( 'aria-expanded', 'false' );
			head.setAttribute( 'aria-controls', bodyId );

			var topicEl = document.createElement( 'span' );
			topicEl.className = 'aifaq-gh__topic';
			topicEl.textContent = item.topic || ''; // anti-XSS: treść od usera
			head.appendChild( topicEl );

			var meta = document.createElement( 'span' );
			meta.className = 'aifaq-gh__meta';

			var nEl = document.createElement( 'span' );
			nEl.className = 'aifaq-gh__n';
			nEl.textContent = ghFmt( t.ghPairsFmt, item.num_questions || 0 );
			meta.appendChild( nEl );

			var langEl = document.createElement( 'span' );
			langEl.className = 'aifaq-gh__lang';
			langEl.textContent = item.language || '';
			meta.appendChild( langEl );

			var userEl = document.createElement( 'span' );
			userEl.className = 'aifaq-gh__user';
			userEl.textContent = item.user || ( t.ghNoUser || '' ); // anti-XSS: nazwa usera
			meta.appendChild( userEl );

			var dateEl = document.createElement( 'time' );
			dateEl.className = 'aifaq-gh__date';
			dateEl.textContent = item.date || '';
			if ( item.iso ) { dateEl.setAttribute( 'datetime', String( item.iso ).replace( ' ', 'T' ) ); }
			meta.appendChild( dateEl );

			head.appendChild( meta );
			row.appendChild( head );

			// --- akcje (rodzeństwo nagłówka!) ---
			var acts = document.createElement( 'div' );
			acts.className = 'aifaq-gh__acts';

			var regenHref = ghRegenHref( id );
			if ( regenHref ) {
				// Zwykły odnośnik — żadnego preventDefault, żadnego fetcha (§5 pkt 7).
				var regen = document.createElement( 'a' );
				regen.className = 'aifaq-btn2 aifaq-gh__regen';
				regen.href = regenHref;
				regen.textContent = t.ghRegen || '';
				acts.appendChild( regen );
			}

			var del = document.createElement( 'button' );
			del.type = 'button';
			del.className = 'aifaq-btn2 aifaq-gh__del';
			del.textContent = t.ghDelete || '';
			acts.appendChild( del );

			row.appendChild( acts );

			// --- podgląd (zwinięty) ---
			var body = document.createElement( 'div' );
			body.className = 'aifaq-gh__body';
			body.id = bodyId;
			body.setAttribute( 'role', 'region' );
			body.setAttribute( 'aria-labelledby', headId );
			body.hidden = true;

			var descEl = document.createElement( 'p' );
			descEl.className = 'aifaq-gh__desc';
			descEl.hidden = true;
			body.appendChild( descEl );

			var pairsEl = document.createElement( 'div' );
			pairsEl.className = 'aifaq-gh__pairs';
			pairsEl.setAttribute( 'aria-busy', 'false' );
			body.appendChild( pairsEl );

			var foot = document.createElement( 'div' );
			foot.className = 'aifaq-gh__foot';

			var copyBtn = document.createElement( 'button' );
			copyBtn.type = 'button';
			copyBtn.className = 'aifaq-btn2 aifaq-gh__copy';
			copyBtn.textContent = t.ghCopyAll || '';
			copyBtn.disabled = true; // aktywny dopiero po wczytaniu par (§5 pkt 5)
			foot.appendChild( copyBtn );

			var stat = document.createElement( 'span' );
			stat.className = 'aifaq-gh__status';
			stat.setAttribute( 'role', 'status' );
			stat.setAttribute( 'aria-live', 'polite' );
			foot.appendChild( stat );

			body.appendChild( foot );
			row.appendChild( body );

			// --- logika wiersza ---
			var loading  = false;
			var rendered = false;
			var statTimer = null;

			var ghFlash = function ( msg ) {
				if ( statTimer ) { clearTimeout( statTimer ); statTimer = null; }
				stat.textContent = msg || '';
				statTimer = setTimeout( function () {
					stat.textContent = '';
					statTimer = null;
				}, 1500 );
			};

			var showDesc = function ( text ) {
				var s = ( text === undefined || text === null ) ? '' : String( text );
				if ( '' === s ) {
					descEl.textContent = '';
					descEl.hidden = true;
					return;
				}
				descEl.textContent = ghFmt( t.ghDescFmt, s ); // anti-XSS: opis od usera
				descEl.hidden = false;
			};

			var applyDetail = function ( data ) {
				showDesc( data.desc );
				ghRenderPairs( pairsEl, data.pairs );
				copyBtn.disabled = ! ( data.pairs && data.pairs.length );
				rendered = true;
			};

			// Lazy-load par: pierwsze rozwinięcie pobiera, kolejne biorą z cache.
			// Po przeładowaniu listy (np. po usunięciu) wiersz jest budowany od nowa,
			// więc `rendered` jest znów false — wtedy renderujemy Z CACHE, bez żądania.
			var ensurePairs = function () {
				if ( rendered || loading ) { return; }

				if ( ghCached( id ) ) {
					applyDetail( ghCache[ id ] );
					return;
				}
				if ( ! ep.generationDetail ) {
					ghNote( pairsEl, t.ghPairsErr || '' );
					return;
				}

				loading = true;
				pairsEl.setAttribute( 'aria-busy', 'true' );
				ghNote( pairsEl, t.ghPairsLoad || '' );

				get( ep.generationDetail, { id: id } ).then( function ( r ) {
					var detail = ( r.ok && r.data && 'ok' === r.data.status ) ? r.data.item : null;
					if ( ! detail ) {
						ghNote( pairsEl, t.ghPairsErr || '' );
						return;
					}
					ghCache[ id ] = {
						pairs: detail.pairs || [],
						desc:  detail.extra_desc || ''
					};
					applyDetail( ghCache[ id ] );
				} ).catch( function () {
					ghNote( pairsEl, t.ghPairsErr || '' );
				} ).then( function () {
					loading = false;
					pairsEl.setAttribute( 'aria-busy', 'false' );
				} );
			};

			head.addEventListener( 'click', function () {
				var open = body.hidden;
				body.hidden = ! open;
				head.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
				row.classList.toggle( 'is-open', open );
				if ( open ) { ensurePairs(); }
			} );

			// „Kopiuj wszystko" — wyłącznie z cache (przycisk żyje dopiero po wczytaniu).
			copyBtn.addEventListener( 'click', function () {
				var data = ghCached( id ) ? ghCache[ id ] : null;
				if ( ! data || ! data.pairs || ! data.pairs.length ) { return; }

				var text = data.pairs.map( function ( p ) {
					return ( ( p && p.question ) || '' ) + '\n' + ( ( p && p.answer ) || '' );
				} ).join( '\n\n' ); // pary rozdzielone pustą linią

				if ( navigator.clipboard && navigator.clipboard.writeText ) {
					navigator.clipboard.writeText( text ).then( function () {
						ghFlash( t.ghCopied || '' );
					} ).catch( function () {
						ghFlash( t.ghError || '' );
					} );
				} else {
					ghFlash( t.ghError || '' );
				}
			} );

			del.addEventListener( 'click', function () {
				if ( ! ep.generationsDelete ) { return; }
				if ( ! window.confirm( t.ghDeleteConf || '' ) ) { return; }

				del.disabled = true;
				stat.textContent = t.ghDeleting || '';

				post( ep.generationsDelete, { id: id } ).then( function ( r ) {
					if ( r.ok && r.data && 'ok' === r.data.status ) {
						delete ghCache[ id ];
						stat.textContent = t.ghDeleted || '';
						// Przeładowanie BIEŻĄCEJ strony — serwer sam cofa `page`,
						// gdy po usunięciu wypadła poza zakres (§5 pkt 6).
						ghLoad();
						return;
					}
					stat.textContent = t.ghDeleteErr || '';
					del.disabled = false;
				} ).catch( function () {
					stat.textContent = t.ghDeleteErr || '';
					del.disabled = false;
				} );
			} );

			return row;
		};

		var ghRenderPager = function ( data ) {
			ghState.pages = data.pages || 0;
			ghState.page  = data.page || 1;

			if ( ghCount ) {
				ghCount.textContent = ghFmt( t.ghCountFmt, data.total || 0 );
			}
			if ( ! ghPager ) { return; }

			ghPager.hidden = ghState.pages < 2;
			if ( ghPageEl ) {
				ghPageEl.textContent = String( t.ghPageFmt || '%1$s / %2$s' )
					.replace( '%1$s', function () { return String( ghState.page ); } )
					.replace( '%2$s', function () { return String( ghState.pages ); } );
			}
			if ( ghPrev ) { ghPrev.disabled = ghState.page <= 1; }
			if ( ghNext ) { ghNext.disabled = ghState.page >= ghState.pages; }
		};

		var ghLoad = function () {
			if ( ghState.busy ) { return; }
			ghState.busy = true;
			ghList.setAttribute( 'aria-busy', 'true' );
			ghNote( ghList, t.ghLoading || '' );

			get( ep.generations, {
				page: ghState.page,
				per_page: cfg.genPerPage || 20
			} ).then( function ( r ) {
				if ( ! r.ok || ! r.data || 'ok' !== r.data.status ) {
					ghNote( ghList, t.ghError || '' );
					if ( ghPager ) { ghPager.hidden = true; }
					return;
				}

				ghRenderPager( r.data );

				var items = r.data.items || [];
				if ( ! items.length ) {
					ghNote( ghList, t.ghEmpty || '' );
					return;
				}

				ghList.textContent = '';
				items.forEach( function ( item ) {
					ghList.appendChild( ghBuildRow( item ) );
				} );
			} ).catch( function () {
				ghNote( ghList, t.ghError || '' );
			} ).then( function () {
				ghState.busy = false;
				ghList.setAttribute( 'aria-busy', 'false' );
			} );
		};

		if ( ghPrev ) {
			ghPrev.addEventListener( 'click', function () {
				if ( ghState.page > 1 ) { ghState.page--; ghLoad(); }
			} );
		}
		if ( ghNext ) {
			ghNext.addEventListener( 'click', function () {
				if ( ghState.page < ghState.pages ) { ghState.page++; ghLoad(); }
			} );
		}

		ghLoad();
	}
} )();
