/**
 * Publiczny generator FAQ (front) — logika pytań-odpowiedzi.
 *
 * Woła REST POST /aifaq/v1/ask (Krok 7) i renderuje stany:
 *   idle → loading → answered / refused / rate-limit (429) / błąd (400/502/sieć).
 * Odpowiedź ORAZ pytanie gościa wstawiane jako textContent (NIE innerHTML) —
 * ochrona przed XSS przy renderze treści od gościa/AI. Konfiguracja z
 * window.aifaqFront. Pole autorośnie z tekstem; licznik pojawia się przy pisaniu.
 */
( function () {
	'use strict';

	var cfg = window.aifaqFront;
	if ( ! cfg ) {
		return;
	}
	var t = cfg.i18n || {};

	var rootEl  = document.querySelector( '.aifaq' );
	var form    = document.getElementById( 'aifaq-form' );
	var input   = document.getElementById( 'aifaq-q' );
	var btn     = document.getElementById( 'aifaq-btn' );
	var meta    = document.getElementById( 'aifaq-meta' );
	var counter = document.getElementById( 'aifaq-counter' );
	var hint    = document.getElementById( 'aifaq-hint' );
	var answer  = document.getElementById( 'aifaq-answer' );
	var answerQ = document.getElementById( 'aifaq-answer-q' );
	var body    = document.getElementById( 'aifaq-answer-body' );
	var foot    = document.getElementById( 'aifaq-answer-foot' );

	if ( ! rootEl || ! form || ! input || ! btn || ! answer || ! body ) {
		return;
	}

	var maxLen = parseInt( cfg.maxLen, 10 ) || 2000;
	var busy   = false;

	function updateCounter() {
		if ( counter ) {
			counter.textContent = input.value.length + '/' + maxLen;
		}
	}
	function setHint( msg ) {
		if ( hint ) {
			hint.textContent = msg || '';
		}
	}
	// Licznik/„meta” tylko gdy jest co liczyć.
	function toggleMeta() {
		if ( meta ) {
			meta.hidden = ( 0 === input.value.length );
		}
	}
	// Autogrow: pole rośnie do treści (cap w CSS przez max-height).
	function autogrow() {
		input.style.height = 'auto';
		input.style.height = input.scrollHeight + 'px';
	}

	input.addEventListener( 'input', function () {
		updateCounter();
		toggleMeta();
		autogrow();
		setHint( '' );
	} );

	// Stan startowy.
	updateCounter();
	toggleMeta();
	autogrow();

	// Enter = wyślij, Shift+Enter = nowa linia.
	input.addEventListener( 'keydown', function ( e ) {
		if ( 'Enter' === e.key && ! e.shiftKey ) {
			e.preventDefault();
			submit();
		}
	} );

	form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		submit();
	} );

	function submit() {
		if ( busy ) {
			return;
		}
		var q = input.value.trim();
		if ( '' === q ) {
			setHint( t.errEmpty || '' );
			input.focus();
			return;
		}
		if ( q.length > maxLen ) {
			setHint( t.errTooLong || '' );
			return;
		}
		ask( q );
	}

	function setState( state ) {
		rootEl.setAttribute( 'data-state', state );
	}

	// Pytanie gościa nad odpowiedzią (kontekst). textContent — anti-XSS.
	function showQuestion( q ) {
		if ( answerQ ) {
			answerQ.textContent = q;
			answerQ.hidden = false;
		}
	}

	function showAnswer( variant, text, footText ) {
		answer.hidden = false;
		answer.className = 'aifaq__answer' + ( variant ? ' aifaq__answer--' + variant : '' );
		body.textContent = text || '';
		if ( foot ) {
			if ( footText ) {
				foot.textContent = footText;
				foot.hidden = false;
			} else {
				foot.textContent = '';
				foot.hidden = true;
			}
		}
	}

	function ask( q ) {
		busy = true;
		btn.disabled = true;
		setHint( '' );

		// Chat-like: pytanie „ląduje” nad odpowiedzią, pole czyści się na kolejne.
		showQuestion( q );
		input.value = '';
		updateCounter();
		toggleMeta();
		autogrow();

		setState( 'loading' );
		showAnswer( 'loading', t.thinking || '…', '' );

		var headers = { 'Content-Type': 'application/json', 'Accept': 'application/json' };
		if ( cfg.nonce ) {
			headers['X-WP-Nonce'] = cfg.nonce;
		}

		fetch( cfg.endpoint, {
			method: 'POST',
			credentials: 'same-origin',
			headers: headers,
			body: JSON.stringify( { question: q } )
		} )
			.then( function ( res ) {
				return res.json()
					.catch( function () { return {}; } )
					.then( function ( data ) { return { status: res.status, data: data || {} }; } );
			} )
			.then( function ( r ) { render( r.status, r.data ); } )
			.catch( function () { showAnswer( 'error', t.errGeneric || '', '' ); } )
			.then( function () {
				busy = false;
				btn.disabled = false;
				setState( 'idle' );
			} );
	}

	function render( status, data ) {
		data = data || {};

		if ( 200 === status && ( 'answered' === data.status || 'refused' === data.status ) ) {
			var refused = 'refused' === data.status;
			var footText = ( ! refused && data.cached ) ? ( t.cached || '' ) : '';
			showAnswer( refused ? 'refused' : '', data.answer || '', footText );
			return;
		}
		if ( 429 === status ) {
			showAnswer( 'error', t.errRate || '', '' );
			return;
		}
		if ( 400 === status ) {
			showAnswer( 'error', ( data && data.message ) ? data.message : ( t.errTooLong || '' ), '' );
			return;
		}
		showAnswer( 'error', t.errGeneric || '', '' );
	}
} )();
