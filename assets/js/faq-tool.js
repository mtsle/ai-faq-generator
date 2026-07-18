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
			onPairsChanged(); // pary się zmieniły → eksport dirty
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
			onPairsChanged(); // usunięto wiersz → eksport dirty
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
	// Eksport (Krok 14) — sekcja pod tabelą (#aifaq-ft-export w #aifaq-ft-results).
	// Formatowanie robi REST POST /admin/export (Exporter.php); JS tylko pokazuje,
	// kopiuje i pobiera gotowe stringi. Podgląd WYŁĄCZNIE przez textContent (anti-XSS).
	// Sekcja jest ADDYTYWNA: gdy DOM eksportu nie istnieje (starszy widok), wszystko
	// poniżej jest no-opem i K13 działa bez zmian.
	// -----------------------------------------------------------------------
	var exportRoot = document.getElementById( 'aifaq-ft-export' );
	var expOutput  = document.getElementById( 'aifaq-ft-exp-output' );
	var expCopyBtn = document.getElementById( 'aifaq-ft-exp-copy' );
	var expDownBtn = document.getElementById( 'aifaq-ft-exp-download' );
	var expStatus  = document.getElementById( 'aifaq-ft-exp-status' );
	var expBtns    = exportRoot ? exportRoot.querySelectorAll( '.aifaq-ft__exp-btn' ) : [];

	// Nazwa pliku + MIME dla „Pobierz" per format (KONTRAKT §5).
	var EXP_FILES = {
		html:      { name: 'faq.html',           mime: 'text/html' },
		gutenberg: { name: 'faq-gutenberg.html', mime: 'text/html' },
		elementor: { name: 'faq-elementor.json', mime: 'application/json' },
		json:      { name: 'faq.json',           mime: 'application/json' },
		jsonld:    { name: 'faq-jsonld.json',    mime: 'application/json' }
	};

	var expCache  = null;    // { html, gutenberg, elementor, json, jsonld } — stringi z REST
	var expFormat = 'html';  // aktualnie wybrany format (przycisk is-active)
	var expDirty  = true;    // pary zmienione od ostatniego pobrania → trzeba odświeżyć cache
	var expBusy   = false;
	var expShown  = false;   // czy podgląd był już raz pokazany
	var expTimer  = null;

	function expStr( v ) {
		return ( typeof v === 'string' ) ? v : '';
	}

	function setExpStatus( msg, state ) {
		if ( expTimer ) {
			clearTimeout( expTimer );
			expTimer = null;
		}
		if ( ! expStatus ) {
			return;
		}
		expStatus.textContent = msg || '';
		expStatus.classList.remove( 'is-loading', 'is-error', 'is-ok' );
		if ( state ) {
			expStatus.classList.add( 'is-' + state );
		}
	}
	function flashExpStatus( msg ) {
		if ( ! expStatus ) {
			return;
		}
		setExpStatus( msg, 'ok' );
		expTimer = setTimeout( function () {
			expStatus.textContent = '';
			expStatus.classList.remove( 'is-loading', 'is-error', 'is-ok' );
			expTimer = null;
		}, 1500 );
	}
	function setExpBusy( on ) {
		expBusy = on;
		if ( expCopyBtn ) {
			expCopyBtn.disabled = on;
		}
		if ( expDownBtn ) {
			expDownBtn.disabled = on;
		}
		for ( var i = 0; i < expBtns.length; i++ ) {
			expBtns[ i ].disabled = on;
		}
	}

	// Zaznacz aktywny przycisk formatu (is-active na jednym, zdejmij z reszty).
	function setActiveFormatBtn( fmt ) {
		for ( var i = 0; i < expBtns.length; i++ ) {
			var b = expBtns[ i ];
			if ( ( b.getAttribute( 'data-format' ) || '' ) === fmt ) {
				b.classList.add( 'is-active' );
			} else {
				b.classList.remove( 'is-active' );
			}
		}
	}

	function renderExport() {
		if ( expOutput ) {
			expOutput.textContent = ( expCache && expCache[ expFormat ] ) || ''; // anti-XSS
		}
	}

	// POST bieżących par → 5 stringów do cache. Zwraca Promise<bool ok>.
	function fetchExport() {
		var headers = {
			'Content-Type': 'application/json',
			'Accept': 'application/json'
		};
		if ( cfg.nonce ) {
			headers['X-WP-Nonce'] = cfg.nonce;
		}
		var body = { pairs: pairs.map( function ( p ) {
			return { question: p.q, answer: p.a };
		} ) };
		return fetch( cfg.exportEndpoint, {
			method: 'POST',
			credentials: 'same-origin',
			headers: headers,
			body: JSON.stringify( body )
		} )
			.then( function ( res ) {
				return res.json()
					.catch( function () { return {}; } )
					.then( function ( data ) { return { status: res.status, data: data || {} }; } );
			} )
			.then( function ( r ) {
				if ( 200 === r.status && 'ok' === r.data.status ) {
					expCache = {
						html:      expStr( r.data.html ),
						gutenberg: expStr( r.data.gutenberg ),
						elementor: expStr( r.data.elementor ),
						json:      expStr( r.data.json ),
						jsonld:    expStr( r.data.jsonld )
					};
					return true;
				}
				return false;
			} )
			.catch( function () { return false; } );
	}

	// Pokaż wybrany format: z cache albo dociągnij (gdy dirty / brak cache).
	function showExportFormat( fmt ) {
		if ( ! exportRoot ) {
			return;
		}
		if ( fmt ) {
			expFormat = fmt;
			setActiveFormatBtn( fmt );
		}
		if ( ! pairs.length ) {
			expCache = null;
			expShown = false;
			if ( expOutput ) {
				expOutput.textContent = '';
			}
			setExpStatus( t.expEmpty || '', 'error' );
			return;
		}
		if ( expBusy ) {
			return;
		}
		if ( expDirty || ! expCache ) {
			setExpBusy( true );
			setExpStatus( '' );
			fetchExport().then( function ( ok ) {
				if ( ok ) {
					expDirty = false;
					expShown = true;
					renderExport();
					setExpStatus( '' );
				} else {
					setExpStatus( t.errMsg || '', 'error' );
				}
				setExpBusy( false );
			} );
		} else {
			expShown = true;
			renderExport();
		}
	}

	// Nowe pary (po generacji): wyczyść cache, wróć do HTML i pokaż podgląd.
	function resetExportForNewPairs() {
		expCache = null;
		expDirty = true;
		expFormat = 'html';
		expShown = false;
		setActiveFormatBtn( 'html' );
		if ( expOutput ) {
			expOutput.textContent = '';
		}
		setExpStatus( '' );
		if ( exportRoot && pairs.length ) {
			showExportFormat( 'html' );
		}
	}

	// Zmiana istniejących par (edycja/usunięcie): oznacz dirty; jeśli podgląd był
	// już pokazany — odśwież bieżący format, żeby nie pokazywał nieaktualnej treści.
	function onPairsChanged() {
		expDirty = true;
		if ( ! exportRoot ) {
			return;
		}
		if ( ! pairs.length ) {
			expCache = null;
			expShown = false;
			if ( expOutput ) {
				expOutput.textContent = '';
			}
			return;
		}
		if ( expShown ) {
			showExportFormat( expFormat );
		}
	}

	// Przełączanie formatów.
	for ( var ei = 0; ei < expBtns.length; ei++ ) {
		expBtns[ ei ].addEventListener( 'click', function () {
			showExportFormat( this.getAttribute( 'data-format' ) || 'html' );
		} );
	}

	// Kopiuj bieżący format do schowka.
	if ( expCopyBtn ) {
		expCopyBtn.addEventListener( 'click', function () {
			if ( ! expCache || ! expCache[ expFormat ] ) {
				return;
			}
			var txt = expCache[ expFormat ];
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( txt ).then( function () {
					flashExpStatus( t.expCopied || '' );
				} ).catch( function () {
					setExpStatus( t.errMsg || '', 'error' );
				} );
			} else {
				setExpStatus( t.errMsg || '', 'error' );
			}
		} );
	}

	// Pobierz bieżący format jako plik (Blob + <a download>).
	if ( expDownBtn ) {
		expDownBtn.addEventListener( 'click', function () {
			if ( ! expCache || ! expCache[ expFormat ] ) {
				return;
			}
			var meta = EXP_FILES[ expFormat ] || EXP_FILES.html;
			try {
				var blob = new Blob( [ expCache[ expFormat ] ], { type: meta.mime + ';charset=utf-8' } );
				var url  = URL.createObjectURL( blob );
				var a    = document.createElement( 'a' );
				a.href = url;
				a.download = meta.name;
				document.body.appendChild( a );
				a.click();
				document.body.removeChild( a );
				setTimeout( function () { URL.revokeObjectURL( url ); }, 1000 );
				flashExpStatus( t.expDownloaded || '' );
			} catch ( e ) {
				setExpStatus( t.errMsg || '', 'error' );
			}
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
			resetExportForNewPairs(); // nowe pary → odśwież sekcję eksportu (domyślnie HTML)
			return;
		}

		// „empty" (model nic sensownego nie zwrócił) — komunikat, nie błąd.
		if ( 200 === httpStatus && ( 'empty' === data.status || 'ok' === data.status ) ) {
			pairs = [];
			tbody.textContent = '';
			results.hidden = true;
			setStatus( t.emptyMsg || '' );
			resetExportForNewPairs(); // brak par → wyczyść cache eksportu
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
			// Nazwy pól MUSZĄ być zgodne z args trasy /admin/generate-faq
			// (`description`, `count`) — nie z nazwami kolumn w bazie
			// (`extra_desc`, `num_questions`). Rozjazd = opis ignorowany i liczba
			// pytań spadająca do wartości z ustawień.
			body: JSON.stringify( {
				topic: topicVal,
				description: desc ? desc.value.trim() : '',
				count: num,
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

	// -----------------------------------------------------------------------
	// Prefill z historii generowań (Krok 15) — „Ponownie wygeneruj".
	// Panel historii linkuje tu z ?aifaq_regen=<id> (nazwa parametru pochodzi
	// ze stałej PHP w configu, nie jest wpisana literalnie). Wczytujemy wpis
	// przez GET /admin/generations/detail i wypełniamy formularz.
	//
	// Blok jest ADDYTYWNY i NO-OPEM bez `detailEndpoint` lub bez parametru w URL,
	// więc K13 (generowanie) i K14 (eksport) działają bez zmian. Stanu eksportu
	// nie dotykamy — odświeży się dopiero po realnej generacji.
	// -----------------------------------------------------------------------

	// Nazwa parametru trafia do RegExp — na wszelki wypadek neutralizujemy metaznaki.
	function regenEscRe( s ) {
		return String( s ).replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
	}

	function regenParamValue( name ) {
		var m = new RegExp( '[?&]' + regenEscRe( name ) + '=([^&#]*)' ).exec( window.location.search );
		return m ? decodeURIComponent( m[ 1 ].replace( /\+/g, ' ' ) ) : '';
	}

	// Usuwa parametr z query stringa (bez ruszania pozostałych).
	function regenStripParam( search, name ) {
		var parts = String( search ).replace( /^\?/, '' ).split( '&' );
		var keep  = [];
		for ( var i = 0; i < parts.length; i++ ) {
			if ( ! parts[ i ] ) {
				continue;
			}
			var k = parts[ i ].split( '=' )[ 0 ];
			try {
				k = decodeURIComponent( k );
			} catch ( e ) {
				// zostawiamy surowe — i tak porównanie się nie uda i klucz przetrwa
			}
			if ( k !== name ) {
				keep.push( parts[ i ] );
			}
		}
		return keep.length ? ( '?' + keep.join( '&' ) ) : '';
	}

	( function initRegenPrefill() {
		if ( ! cfg.detailEndpoint ) {
			return;
		}

		var pname = cfg.regenParam || 'aifaq_regen';
		var id    = parseInt( regenParamValue( pname ), 10 );
		if ( isNaN( id ) || id <= 0 ) {
			return;
		}

		// rest_url() bywa postaci ?rest_route=/aifaq/v1/... — naiwne `url + '?id='`
		// rozwaliłoby żądanie. Ta sama reguła co w panelu historii.
		var url = cfg.detailEndpoint +
			( cfg.detailEndpoint.indexOf( '?' ) === -1 ? '?' : '&' ) +
			'id=' + encodeURIComponent( id );

		var headers = { 'Accept': 'application/json' };
		if ( cfg.nonce ) {
			headers['X-WP-Nonce'] = cfg.nonce;
		}

		setStatus( t.regenLoading || '', 'loading' );

		fetch( url, {
			method: 'GET',
			credentials: 'same-origin',
			headers: headers
		} )
			.then( function ( res ) {
				return res.json()
					.catch( function () { return {}; } )
					.then( function ( data ) { return { status: res.status, data: data || {} }; } );
			} )
			.then( function ( r ) {
				var item = ( r.data && r.data.item ) || null;
				if ( 200 !== r.status || 'ok' !== r.data.status || ! item ) {
					// 400 / 404 / cokolwiek — formularz zostaje pusty i użyteczny.
					setStatus( t.regenErr || '', 'error' );
					return;
				}

				// Prefill WYŁĄCZNIE przez .value (anti-XSS) — nigdy innerHTML.
				topic.value = ( typeof item.topic === 'string' ) ? item.topic : '';
				if ( desc ) {
					desc.value = ( typeof item.extra_desc === 'string' ) ? item.extra_desc : '';
				}
				count.value = clamp( parseInt( item.num_questions, 10 ) );

				setStatus( t.regenLoaded || '', 'ok' );
				topic.focus();

				// Świadomie NIE wołamy submit() — generacja kosztuje wywołanie AI,
				// user musi sam kliknąć „Generuj FAQ".

				// Sprzątamy parametr z adresu, żeby F5 nie wczytywał wpisu ponownie.
				if ( window.history && window.history.replaceState ) {
					try {
						window.history.replaceState(
							null,
							'',
							window.location.pathname +
								regenStripParam( window.location.search, pname ) +
								window.location.hash
						);
					} catch ( e ) {
						// brak replaceState / ograniczenia przeglądarki — nieistotne
					}
				}
			} )
			.catch( function () {
				setStatus( t.regenErr || '', 'error' );
			} );
	} )();
} )();
