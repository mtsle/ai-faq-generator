/**
 * AI FAQ Generator — Krok 13, ekran „Narzędzie FAQ" w kokpicie.
 *
 * Właściciel wpisuje Temat + Opis + Liczbę pytań → „Generuj FAQ" woła
 * REST POST /admin/generate-faq (Krok 12) i renderuje tabelę par Q&A
 * z akcjami Edytuj / Usuń / Skopiuj (+ „Skopiuj wszystko").
 *
 * Pary trzymane w stanie lokalnym (tablica w pamięci) — Edytuj/Usuń operują
 * na niej, „Skopiuj wszystko" czyta z niej. Krok 13 NIE zapisuje zmian do bazy
 * (zapis generacji zrobił K12 przy „Generuj"). Konfiguracja z window.aifaqFaqTool.
 * Pytania i odpowiedzi wstawiane WYŁĄCZNIE przez textContent (anti-XSS).
 */
( function () {
	'use strict';

	var cfg = window.aifaqFaqTool;
	if ( ! cfg ) {
		return;
	}
	var t  = cfg.i18n || {};
	var df = cfg.defaults || {};

	var form   = document.getElementById( 'aifaq-ft-form' );
	var topic  = document.getElementById( 'aifaq-ft-topic' );
	var desc   = document.getElementById( 'aifaq-ft-desc' );
	var count  = document.getElementById( 'aifaq-ft-count' );
	var btn    = document.getElementById( 'aifaq-ft-generate' );
	var status = document.getElementById( 'aifaq-ft-status' );

	var results = document.getElementById( 'aifaq-ft-results' );
	var copyAll = document.getElementById( 'aifaq-ft-copyall' );
	var tbody   = document.getElementById( 'aifaq-ft-tbody' );

	if ( ! form || ! topic || ! count || ! btn || ! results || ! tbody ) {
		return;
	}

	var min = parseInt( df.min, 10 ) || 5;
	var max = parseInt( df.max, 10 ) || 20;
	var def = parseInt( df.count, 10 ) || 10;
	var lang = df.language || 'pl';

	// Stan lokalny: tablica par { q, a }. Wiersze DOM zamykają się na swoim
	// obiekcie pary (referencja, nie indeks), więc usuwanie środkowego wiersza
	// nie rozjeżdża logiki — data-i tylko odświeżamy dla porządku (Etap 3/CSS).
	var pairs = [];
	var busy  = false;
	var statusTimer = null;

	// -----------------------------------------------------------------------
	// Status / stany
	// -----------------------------------------------------------------------
	function setStatus( msg, state ) {
		if ( statusTimer ) {
			clearTimeout( statusTimer );
			statusTimer = null;
		}
		if ( status ) {
			status.textContent = msg || '';
			status.classList.remove( 'is-loading', 'is-error', 'is-ok' );
			if ( state ) {
				status.classList.add( 'is-' + state );
			}
		}
	}
	// Komunikat chwilowy (np. „Skopiowano") — po ~1.5 s wraca do pustego.
	function flashStatus( msg ) {
		if ( ! status ) {
			return;
		}
		setStatus( msg, 'ok' );
		statusTimer = setTimeout( function () {
			status.textContent = '';
			status.classList.remove( 'is-loading', 'is-error', 'is-ok' );
			statusTimer = null;
		}, 1500 );
	}
	function setBusy( on ) {
		busy = on;
		btn.disabled = on;
	}
	function clamp( n ) {
		if ( isNaN( n ) ) {
			n = def;
		}
		if ( n < min ) {
			n = min;
		}
		if ( n > max ) {
			n = max;
		}
		return n;
	}

	// -----------------------------------------------------------------------
	// Kopiowanie do schowka
	// -----------------------------------------------------------------------
	function copyText( text ) {
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( text ).then( function () {
				flashStatus( t.copied || '' );
			} ).catch( function () {
				setStatus( t.errMsg || '', 'error' );
			} );
		} else {
			setStatus( t.errMsg || '', 'error' );
		}
	}

	// -----------------------------------------------------------------------
	// Renderowanie wiersza (struktura wg KONTRAKT §4)
	// -----------------------------------------------------------------------
	function renumber() {
		var rows = tbody.querySelectorAll( '.aifaq-ft__row' );
		for ( var i = 0; i < rows.length; i++ ) {
			rows[ i ].setAttribute( 'data-i', i );
		}
	}

	function buildActBtn( cls, label ) {
		var b = document.createElement( 'button' );
		b.type = 'button';
		b.className = 'aifaq-ft__act ' + cls;
		b.textContent = label || '';
		return b;
	}

	function buildRow( pair ) {
		var tr = document.createElement( 'tr' );
		tr.className = 'aifaq-ft__row';

		// Komórka pytania.
		var tdQ = document.createElement( 'td' );
		tdQ.className = 'aifaq-ft__q';
		var spanQ = document.createElement( 'span' );
		spanQ.className = 'aifaq-ft__text';
		spanQ.textContent = pair.q; // anti-XSS: treść od modelu
		tdQ.appendChild( spanQ );

		// Komórka odpowiedzi.
		var tdA = document.createElement( 'td' );
		tdA.className = 'aifaq-ft__a';
		var spanA = document.createElement( 'span' );
		spanA.className = 'aifaq-ft__text';
		spanA.textContent = pair.a; // anti-XSS: treść od modelu
		tdA.appendChild( spanA );

		// Komórka akcji.
		var tdActs = document.createElement( 'td' );
		tdActs.className = 'aifaq-ft__acts';
		var bEdit   = buildActBtn( 'aifaq-ft__act--edit', t.edit || '' );
		var bCopy   = buildActBtn( 'aifaq-ft__act--copy', t.copy || '' );
		var bDel    = buildActBtn( 'aifaq-ft__act--del', t.del || '' );
		var bSave   = buildActBtn( 'aifaq-ft__act--save', t.save || '' );
		var bCancel = buildActBtn( 'aifaq-ft__act--cancel', t.cancel || '' );
		bSave.hidden   = true;
		bCancel.hidden = true;
		tdActs.appendChild( bEdit );
		tdActs.appendChild( bCopy );
		tdActs.appendChild( bDel );
		tdActs.appendChild( bSave );
		tdActs.appendChild( bCancel );

		tr.appendChild( tdQ );
		tr.appendChild( tdA );
		tr.appendChild( tdActs );

		// Uchwyty edycji (żywe tylko w trybie is-editing).
		var editQ = null;
		var editA = null;

		function enterEdit() {
			if ( tr.classList.contains( 'is-editing' ) ) {
				return;
			}
			editQ = document.createElement( 'textarea' );
			editQ.className = 'aifaq-ft__edit';
			editQ.value = pair.q;
			tdQ.replaceChild( editQ, spanQ );

			editA = document.createElement( 'textarea' );
			editA.className = 'aifaq-ft__edit';
			editA.value = pair.a;
			tdA.replaceChild( editA, spanA );

			bEdit.hidden   = true;
			bCopy.hidden   = true;
			bSave.hidden   = false;
			bCancel.hidden = false;
			tr.classList.add( 'is-editing' );
			editQ.focus();
		}

		function leaveEdit() {
			if ( editQ ) {
				tdQ.replaceChild( spanQ, editQ );
				editQ = null;
			}
			if ( editA ) {
				tdA.replaceChild( spanA, editA );
				editA = null;
			}
			bEdit.hidden   = false;
			bCopy.hidden   = false;
			bSave.hidden   = true;
			bCancel.hidden = true;
			tr.classList.remove( 'is-editing' );
		}

		bEdit.addEventListener( 'click', enterEdit );

		// Zapis: do stanu lokalnego i z powrotem do tekstu (bez zapisu do bazy).
		bSave.addEventListener( 'click', function () {
			if ( editQ ) {
				pair.q = editQ.value;
				spanQ.textContent = pair.q;
			}
			if ( editA ) {
				pair.a = editA.value;
				spanA.textContent = pair.a;
			}
			leaveEdit();
		} );

		// Anuluj: przywróć poprzednie wartości (textarea po prostu porzucamy).
		bCancel.addEventListener( 'click', leaveEdit );

		bCopy.addEventListener( 'click', function () {
			copyText( pair.q + '\n' + pair.a );
		} );

		bDel.addEventListener( 'click', function () {
			if ( ! window.confirm( t.confirmDel || '' ) ) {
				return;
			}
			var idx = pairs.indexOf( pair );
			if ( idx !== -1 ) {
				pairs.splice( idx, 1 );
			}
			tr.parentNode.removeChild( tr );
			renumber();
			if ( ! pairs.length ) {
				results.hidden = true;
			}
		} );

		return tr;
	}

	function renderTable() {
		tbody.textContent = '';
		for ( var i = 0; i < pairs.length; i++ ) {
			tbody.appendChild( buildRow( pairs[ i ] ) );
		}
		renumber();
		results.hidden = false;
	}

	// -----------------------------------------------------------------------
	// „Skopiuj wszystko"
	// -----------------------------------------------------------------------
	if ( copyAll ) {
		copyAll.addEventListener( 'click', function () {
			if ( ! pairs.length ) {
				return;
			}
			var blocks = pairs.map( function ( p ) {
				return p.q + '\n' + p.a;
			} );
			copyText( blocks.join( '\n\n' ) ); // pary rozdzielone pustą linią
		} );
	}

	// -----------------------------------------------------------------------
	// Odpowiedź z REST
	// -----------------------------------------------------------------------
	function handleResponse( httpStatus, data ) {
		data = data || {};

		if ( 200 === httpStatus && 'ok' === data.status && data.pairs && data.pairs.length ) {
			pairs = data.pairs.map( function ( p ) {
				return { q: ( p && p.question ) || '', a: ( p && p.answer ) || '' };
			} );
			renderTable();
			setStatus( ( t.doneFmt || '%s' ).replace( '%s', pairs.length ), 'ok' );
			return;
		}

		// „empty" (model nic sensownego nie zwrócił) — komunikat, nie błąd.
		if ( 200 === httpStatus && ( 'empty' === data.status || 'ok' === data.status ) ) {
			pairs = [];
			tbody.textContent = '';
			results.hidden = true;
			setStatus( t.emptyMsg || '' );
			return;
		}

		// 502 / inne — błąd generacji (bez surowej treści błędu).
		setStatus( t.errMsg || '', 'error' );
	}

	// -----------------------------------------------------------------------
	// Submit formularza
	// -----------------------------------------------------------------------
	form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		if ( busy ) {
			return;
		}

		var topicVal = topic.value.trim();
		if ( '' === topicVal ) {
			setStatus( t.needTopic || '', 'error' );
			topic.focus();
			return;
		}

		// Klamp liczby pytań do [min, max]; zapisz z powrotem do pola.
		var num = clamp( parseInt( count.value, 10 ) );
		count.value = num;

		setBusy( true );
		setStatus( t.generating || '', 'loading' );

		var headers = {
			'Content-Type': 'application/json',
			'Accept': 'application/json'
		};
		if ( cfg.nonce ) {
			headers['X-WP-Nonce'] = cfg.nonce;
		}

		fetch( cfg.endpoint, {
			method: 'POST',
			credentials: 'same-origin',
			headers: headers,
			body: JSON.stringify( {
				topic: topicVal,
				extra_desc: desc ? desc.value.trim() : '',
				num_questions: num,
				language: lang
			} )
		} )
			.then( function ( res ) {
				return res.json()
					.catch( function () { return {}; } )
					.then( function ( data ) { return { status: res.status, data: data || {} }; } );
			} )
			.then( function ( r ) { handleResponse( r.status, r.data ); } )
			.catch( function () { setStatus( t.errMsg || '', 'error' ); } )
			.then( function () { setBusy( false ); } );
	} );
} )();
