/**
 * AI FAQ Generator — Krok 16, panel „AI FAQ" w edytorze wpisu (metabox).
 *
 * Właściciel pisze wpis → klika „Generuj z treści wpisu" → z tytułu i treści
 * (branych PROSTO Z EDYTORA, także niezapisanych) powstają pary Q&A →
 * „Wstaw do wpisu" dokleja gotowe FAQ na koniec treści.
 *
 * Zero nowych tras: generowanie idzie na cfg.endpoint, a formatowanie wstawki
 * na cfg.exportEndpoint (Faq\Exporter po stronie serwera). Konfiguracja
 * z window.aifaqMetabox (endpoint, exportEndpoint, nonce, defaults,
 * maxContentChars, i18n).
 *
 * Uwagi wykonawcze:
 * - czysty ES5 (var / function) — plik jedzie bez toolchainu JS,
 * - bez wp.domReady: w edytorze KLASYCZNYM globalne `wp` nie istnieje,
 * - anti-XSS: pytania i odpowiedzi wchodzą do DOM wyłącznie przez textContent;
 *   w tym pliku nie ma i nie może być przypisania do innerHTML,
 * - wstawka do treści pochodzi z serwera (esc_html już zrobione) — JS jej
 *   NIE escapuje po raz drugi i nie skleja własnych znaczników z par.
 */
( function () {
	'use strict';

	var cfg = window.aifaqMetabox;
	if ( ! cfg ) {
		return;
	}
	var t  = cfg.i18n || {};
	var df = cfg.defaults || {};

	// wp_localize_script oddaje skalary najwyższego poziomu jako STRINGI —
	// bez parseInt porównanie długości treści byłoby leksykograficzne.
	var maxChars = parseInt( cfg.maxContentChars, 10 ) || 6000;

	var genBtn  = document.getElementById( 'aifaq-mb-generate' );
	if ( ! genBtn ) {
		return; // metaboksa nie ma na tej stronie — cały skrypt jest no-opem
	}

	var countEl = document.getElementById( 'aifaq-mb-count' );
	var status  = document.getElementById( 'aifaq-mb-status' );
	var results = document.getElementById( 'aifaq-mb-results' );
	var summary = document.getElementById( 'aifaq-mb-summary' );
	var note    = document.getElementById( 'aifaq-mb-note' );
	var list    = document.getElementById( 'aifaq-mb-list' );
	var insBtn  = document.getElementById( 'aifaq-mb-insert' );
	var copyBtn = document.getElementById( 'aifaq-mb-copy' );

	var minCount = parseInt( df.min, 10 ) || 5;
	var maxCount = parseInt( df.max, 10 ) || 20;
	var defCount = parseInt( df.count, 10 ) || 10;
	var lang     = df.language || 'pl';

	// Stan lokalny: pary { q, a }. Wiersze DOM domykają się na OBIEKCIE pary,
	// nie na indeksie — usunięcie środkowej pary nie rozjeżdża pozostałych.
	var pairs       = [];
	var busy        = false;
	var inserting   = false;
	var statusTimer = null;
	var trimmedTo   = 0; // >0 = treść przycięta do tylu znaków

	// -----------------------------------------------------------------------
	// Pomocnicze: status, busy, clamp, format
	// -----------------------------------------------------------------------

	// Klasy stanu lądują WYŁĄCZNIE na #aifaq-mb-status (nie na kontenerze,
	// nie na przycisku, nie na #aifaq-mb-note).
	function setStatus( text, state ) {
		if ( statusTimer ) {
			clearTimeout( statusTimer );
			statusTimer = null;
		}
		if ( ! status ) {
			return;
		}
		status.textContent = text || '';
		status.classList.remove( 'is-loading', 'is-error', 'is-ok' );
		if ( state ) {
			status.classList.add( state );
		}
	}

	// Komunikat chwilowy (np. „Skopiowano") — po ~1,5 s znika.
	function flashStatus( text ) {
		if ( ! status ) {
			return;
		}
		setStatus( text, 'is-ok' );
		statusTimer = setTimeout( function () {
			status.textContent = '';
			status.classList.remove( 'is-loading', 'is-error', 'is-ok' );
			statusTimer = null;
		}, 1500 );
	}

	function setBusy( on ) {
		busy = on;
		genBtn.disabled = on;
	}

	// Podstawienie %s zamiennikiem-FUNKCJĄ: treść od modelu może zawierać $&,
	// które w zwykłym replace zostałoby potraktowane jako wzorzec.
	function fmt( tpl, v ) {
		return String( tpl ).replace( '%s', function () {
			return String( v );
		} );
	}

	// isNaN sprawdzamy PRZED porównaniem z min/max — puste pole dałoby NaN,
	// a NaN < min jest false i do REST poleciałby null (400).
	function clamp( n ) {
		if ( isNaN( n ) ) {
			n = defCount;
		}
		if ( n < minCount ) {
			n = minCount;
		}
		if ( n > maxCount ) {
			n = maxCount;
		}
		return n;
	}

	// Nonce czytany PRZY KAŻDYM wywołaniu — user potrafi siedzieć w edytorze
	// dłużej niż życie nonce, a Heartbeat odświeża wpApiSettings.nonce.
	function nonce() {
		if ( window.wpApiSettings && window.wpApiSettings.nonce ) {
			return window.wpApiSettings.nonce;
		}
		return cfg.nonce;
	}

	// -----------------------------------------------------------------------
	// Ochrona formularza wpisu
	// -----------------------------------------------------------------------
	// W edytorze KLASYCZNYM metabox siedzi w <form id="post"> bez guardu —
	// Enter w polu liczby zapisałby/opublikował wpis.
	if ( countEl ) {
		countEl.addEventListener( 'keydown', function ( e ) {
			if ( 13 === ( e.keyCode || e.which ) ) {
				e.preventDefault();
			}
		} );
	}

	// -----------------------------------------------------------------------
	// Odczyt kontekstu wpisu
	// -----------------------------------------------------------------------

	// Liczone PRZY KAŻDYM wywołaniu, nigdy raz przy starcie: w edytorze
	// blokowym magazyny bywają gotowe dopiero po pierwszym renderze.
	function isBlock() {
		try {
			return !! ( window.wp && wp.data && wp.data.select && wp.data.select( 'core/editor' ) );
		} catch ( e ) {
			return false;
		}
	}

	function readTitle() {
		if ( isBlock() ) {
			try {
				return String( wp.data.select( 'core/editor' ).getEditedPostAttribute( 'title' ) || '' );
			} catch ( e ) {
				// spadamy do ścieżki klasycznej
			}
		}
		var el = document.getElementById( 'title' );
		return el ? el.value : '';
	}

	function readContent() {
		if ( isBlock() ) {
			try {
				return String( wp.data.select( 'core/editor' ).getEditedPostContent() || '' );
			} catch ( e ) {
				// spadamy do ścieżki klasycznej
			}
		}
		try {
			var ed = window.tinymce && tinymce.get( 'content' );
			if ( ed && ! ed.isHidden() ) {
				return String( ed.getContent() || '' );
			}
		} catch ( e2 ) {
			// brak TinyMCE — czytamy surowe pole
		}
		var el = document.getElementById( 'content' );
		return el ? el.value : '';
	}

	// Zamiana treści wpisu na czysty tekst dla modelu. Kolejność kroków jest
	// zamrożona: komentarze → DOMParser (dokument inertny, nie wykonuje
	// skryptów) → usunięcie węzłów bez treści → kolaps spacji → przycięcie.
	// Parsujemy DOMParserem, a nie przez przypisanie do innerHTML.
	function cleanContent( s ) {
		var out = String( s || '' );
		trimmedTo = 0;

		// 1. komentarze bloków i HTML
		out = out.replace( /<!--[\s\S]*?-->/g, ' ' );

		// 2. parsowanie
		var text = null;
		try {
			var doc = new DOMParser().parseFromString( out, 'text/html' );
			if ( doc && doc.body ) {
				// 2b. węzły bez treści dla czytelnika — inaczej CSS i kod JS
				// ze strony wjechałyby prosto do promptu.
				var kill = doc.querySelectorAll( 'script, style, noscript, template, svg' );
				var i;
				for ( i = 0; i < kill.length; i++ ) {
					if ( kill[ i ].parentNode ) {
						kill[ i ].parentNode.removeChild( kill[ i ] );
					}
				}
				text = doc.body.textContent;
			}
		} catch ( e ) {
			text = null;
		}

		if ( null === text ) {
			// fallback: najpierw wytnij CAŁE bloki skryptów/stylów wraz z treścią,
			// dopiero potem pozostałe znaczniki.
			out = out.replace( /<(script|style|noscript)[\s\S]*?<\/\1>/gi, ' ' );
			out = out.replace( /<[^>]*>/g, ' ' );
			text = out;
		}

		// 4. kolaps białych znaków
		text = String( text ).replace( /\s+/g, ' ' ).trim();

		// 5. przycięcie na granicy słowa
		if ( text.length > maxChars ) {
			var cut = text.slice( 0, maxChars );
			var sp  = cut.lastIndexOf( ' ' );
			if ( sp > 0 ) {
				cut = cut.slice( 0, sp );
			}
			text      = cut;
			trimmedTo = text.length;
		}

		return text;
	}

	function showNote() {
		if ( ! note ) {
			return;
		}
		if ( trimmedTo > 0 ) {
			note.textContent = fmt( t.mbTrimmed, trimmedTo );
			note.hidden = false;
		} else {
			note.textContent = '';
			note.hidden = true;
		}
	}

	// -----------------------------------------------------------------------
	// Lista par: render, Usuń, Kopiuj
	// -----------------------------------------------------------------------

	function renumber() {
		if ( ! list ) {
			return;
		}
		var rows = list.querySelectorAll( '.aifaq-mb__pair' );
		for ( var i = 0; i < rows.length; i++ ) {
			rows[ i ].setAttribute( 'data-i', i );
		}
	}

	function updateSummary() {
		if ( summary ) {
			summary.textContent = fmt( t.mbCountFmt, pairs.length );
		}
		if ( results && ! pairs.length ) {
			results.hidden = true;
		}
	}

	function buildPair( pair ) {
		var row = document.createElement( 'div' );
		row.className = 'aifaq-mb__pair';

		var q = document.createElement( 'p' );
		q.className = 'aifaq-mb__q';
		q.textContent = pair.q; // anti-XSS: treść od modelu, nigdy innerHTML
		row.appendChild( q );

		var a = document.createElement( 'p' );
		a.className = 'aifaq-mb__a';
		a.textContent = pair.a; // anti-XSS: treść od modelu, nigdy innerHTML
		row.appendChild( a );

		// createElement( 'button' ) daje type="submit" — w edytorze klasycznym
		// klik „Usuń" zapisałby wpis. Typ ustawiamy NATYCHMIAST, przed appendChild.
		var del = document.createElement( 'button' );
		del.type = 'button';
		del.className = 'button-link aifaq-mb__del';
		del.textContent = t.mbDelete || '';
		del.addEventListener( 'click', function () {
			if ( ! window.confirm( t.mbConfirmDel || '' ) ) {
				return;
			}
			var idx = pairs.indexOf( pair );
			if ( -1 !== idx ) {
				pairs.splice( idx, 1 );
			}
			if ( row.parentNode ) {
				row.parentNode.removeChild( row );
			}
			renumber();
			updateSummary();
		} );
		row.appendChild( del );

		return row;
	}

	function renderPairs() {
		if ( ! list ) {
			return;
		}
		list.textContent = '';
		for ( var i = 0; i < pairs.length; i++ ) {
			list.appendChild( buildPair( pairs[ i ] ) );
		}
		renumber();
		updateSummary();
		if ( results && pairs.length ) {
			results.hidden = false;
		}
	}

	function pairsAsText() {
		var blocks = pairs.map( function ( p ) {
			return p.q + '\n' + p.a;
		} );
		return blocks.join( '\n\n' ); // pary rozdzielone pustą linią
	}

	if ( copyBtn ) {
		copyBtn.addEventListener( 'click', function () {
			if ( ! pairs.length ) {
				setStatus( t.mbEmptyMsg || '', 'is-error' );
				return;
			}
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( pairsAsText() ).then( function () {
					flashStatus( t.mbCopied || '' );
				} ).catch( function () {
					setStatus( t.mbCopyErr || '', 'is-error' );
				} );
			} else {
				setStatus( t.mbCopyErr || '', 'is-error' );
			}
		} );
	}

	// -----------------------------------------------------------------------
	// „Generuj z treści wpisu"
	// -----------------------------------------------------------------------

	function handleGenerated( httpStatus, data ) {
		data = data || {};

		if ( 200 === httpStatus && 'ok' === data.status && data.pairs && data.pairs.length ) {
			// Każda generacja to PEŁNE ZASTĄPIENIE — doklejanie do poprzedniego
			// wyniku jest zabronione.
			pairs = [];
			if ( list ) {
				list.textContent = '';
			}
			inserting = false;

			pairs = data.pairs.map( function ( p ) {
				return { q: ( p && p.question ) || '', a: ( p && p.answer ) || '' };
			} );
			renderPairs();
			showNote();
			setStatus( fmt( t.mbDoneFmt, pairs.length ), 'is-ok' );
			return;
		}

		if ( 200 === httpStatus && 'empty' === data.status ) {
			setStatus( t.mbEmptyMsg || '', 'is-error' );
			return;
		}

		// 502, 401/403 (wygasły nonce) i wszystko inne — jeden komunikat błędu.
		setStatus( t.mbErrMsg || '', 'is-error' );
	}

	genBtn.addEventListener( 'click', function () {
		if ( busy || inserting ) {
			return;
		}

		var title = String( readTitle() || '' ).replace( /\s+/g, ' ' ).trim();
		if ( '' === title ) {
			setStatus( t.mbNeedTitle || '', 'is-error' );
			return;
		}

		var cleaned = cleanContent( readContent() );
		if ( '' === cleaned ) {
			setStatus( t.mbNeedContent || '', 'is-error' );
			return;
		}

		var num = clamp( parseInt( countEl ? countEl.value : '', 10 ) );
		if ( countEl ) {
			countEl.value = num;
		}

		// Obramowanie treści jest OBOWIĄZKOWE: bez niego generator traktuje
		// artykuł jak „dodatkowy opis" i potrafi ułożyć FAQ z samego tytułu.
		var desc = ( t.mbDescFrame || '' ) + '\n\n' + cleaned;

		setBusy( true );
		setStatus( t.mbGenerating || '', 'is-loading' );

		fetch( cfg.endpoint, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'Accept': 'application/json',
				'X-WP-Nonce': nonce()
			},
			// Nazwy pól API (topic/description/count/language), NIE nazwy kolumn
			// w bazie — rozjazd oznacza ciche zignorowanie przez WP REST.
			body: JSON.stringify( { topic: title, description: desc, count: num, language: lang } )
		} )
			.then( function ( res ) {
				return res.json()
					.catch( function () { return {}; } )
					.then( function ( data ) { return { status: res.status, data: data || {} }; } );
			} )
			.then( function ( r ) {
				handleGenerated( r.status, r.data );
				setBusy( false );
			} )
			.catch( function () {
				// odrzucone żądanie (offline, restart serwera) — bez tej gałęzi
				// przycisk zostałby martwy na zawsze
				setStatus( t.mbErrMsg || '', 'is-error' );
				setBusy( false );
			} );
	} );

	// -----------------------------------------------------------------------
	// „Wstaw do wpisu"
	// -----------------------------------------------------------------------

	// Wstawka jest gotowym HTML-em/serializacją bloków z serwera (esc_html już
	// zrobione) — NIE escapujemy jej ponownie i nie budujemy znaczników w JS.
	function insertIntoPost( data ) {
		if ( isBlock() && window.wp && wp.blocks && wp.data && wp.data.dispatch( 'core/block-editor' ) ) {
			var edSel = wp.data.select( 'core/editor' );
			if ( edSel && edSel.getEditorMode && 'text' === edSel.getEditorMode() ) {
				// „Edytor kodu": wstawione bloki zostałyby nadpisane treścią pola.
				setStatus( t.mbNoEditor || '', 'is-error' );
				return;
			}
			if ( ! data.gutenberg ) {
				setStatus( t.mbInsertErr || '', 'is-error' );
				return;
			}
			var beS    = wp.data.select( 'core/block-editor' );
			var blocks = wp.blocks.parse( data.gutenberg );
			if ( ! blocks || ! blocks.length ) {
				setStatus( t.mbInsertErr || '', 'is-error' );
				return;
			}
			var before = beS.getBlockCount();
			wp.data.dispatch( 'core/block-editor' ).insertBlocks( blocks, before, undefined, false );
			// insertBlocks nic nie zwraca i nie rzuca — bez tej kontroli
			// meldowalibyśmy sukces przy braku wstawki.
			if ( beS.getBlockCount() <= before ) {
				setStatus( t.mbInsertErr || '', 'is-error' );
				return;
			}
			setStatus( t.mbInserted || '', 'is-ok' );
			return;
		}

		// Edytor klasyczny: setContent na końcu treści (insertContent wstawiłby
		// w miejscu kursora, a komunikat obiecuje „na końcu").
		var ed = window.tinymce && tinymce.get( 'content' );
		if ( ed && ! ed.isHidden() ) {
			if ( ! data.html ) {
				setStatus( t.mbInsertErr || '', 'is-error' );
				return;
			}
			ed.setContent( ed.getContent() + '\n' + data.html );
			ed.save();
			setStatus( t.mbInserted || '', 'is-ok' );
			return;
		}

		var ta = document.getElementById( 'content' );
		if ( ta ) {
			if ( ! data.html ) {
				setStatus( t.mbInsertErr || '', 'is-error' );
				return;
			}
			ta.value = ta.value + '\n\n' + data.html;
			setStatus( t.mbInserted || '', 'is-ok' );
			return;
		}

		setStatus( t.mbNoEditor || '', 'is-error' );
	}

	function endInsert() {
		inserting = false;
		if ( insBtn ) {
			insBtn.disabled = false;
		}
		genBtn.disabled = busy;
	}

	if ( insBtn ) {
		insBtn.addEventListener( 'click', function () {
			if ( inserting || busy ) {
				return;
			}
			if ( ! pairs.length ) {
				setStatus( t.mbEmptyMsg || '', 'is-error' );
				return;
			}

			inserting = true;
			insBtn.disabled = true;
			genBtn.disabled = true;
			setStatus( t.mbInserting || '', 'is-loading' );

			var payload = pairs.map( function ( p ) {
				return { question: p.q, answer: p.a };
			} );

			fetch( cfg.exportEndpoint, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'Accept': 'application/json',
					'X-WP-Nonce': nonce()
				},
				body: JSON.stringify( { pairs: payload } )
			} )
				.then( function ( res ) {
					return res.json()
						.catch( function () { return {}; } )
						.then( function ( data ) { return { status: res.status, data: data || {} }; } );
				} )
				.then( function ( r ) {
					if ( 200 === r.status && 'ok' === r.data.status ) {
						try {
							insertIntoPost( r.data );
						} catch ( e ) {
							setStatus( t.mbInsertErr || '', 'is-error' );
						}
					} else {
						setStatus( t.mbInsertErr || '', 'is-error' );
					}
					// Lista par ZOSTAJE widoczna i niezmieniona — ponowne „Wstaw"
					// wstawi je drugi raz i jest to zachowanie dozwolone.
					endInsert();
				} )
				.catch( function () {
					setStatus( t.mbInsertErr || '', 'is-error' );
					endInsert();
				} );
		} );
	}
} )();
