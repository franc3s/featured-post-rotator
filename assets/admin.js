( function () {
	'use strict';

	function debounce( fn, delay ) {
		var timer = null;
		return function () {
			var args = arguments;
			var context = this;
			clearTimeout( timer );
			timer = setTimeout( function () {
				fn.apply( context, args );
			}, delay );
		};
	}

	function initPostSearch( root ) {
		var input   = root.querySelector( '.bmfr-post-search-input' );
		var hidden  = root.querySelector( '.bmfr-post-search-id' );
		var results = root.querySelector( '.bmfr-post-search-results' );
		var button  = root.querySelector( '.bmfr-post-search-submit' );

		if ( ! input || ! hidden || ! results || ! button || typeof window.bmfrAdmin === 'undefined' ) {
			return;
		}

		function clearResults() {
			results.innerHTML = '';
			results.hidden = true;
		}

		function selectResult( item ) {
			input.value = item.title + ' (#' + item.id + ')';
			hidden.value = item.id;
			button.disabled = false;
			clearResults();
		}

		var doSearch = debounce( function () {
			var term = input.value.trim();

			hidden.value = '';
			button.disabled = true;

			if ( term.length < 2 ) {
				clearResults();
				return;
			}

			var url = window.bmfrAdmin.ajaxUrl +
				'?action=bmfr_search_posts' +
				'&nonce=' + encodeURIComponent( window.bmfrAdmin.searchNonce ) +
				'&term=' + encodeURIComponent( term );

			fetch( url, { credentials: 'same-origin' } )
				.then( function ( response ) { return response.json(); } )
				.then( function ( data ) {
					clearResults();

					if ( ! data || ! data.success || ! data.data || ! data.data.length ) {
						return;
					}

					data.data.forEach( function ( item ) {
						var li = document.createElement( 'li' );
						li.textContent = item.title + ' (#' + item.id + ')';
						li.tabIndex = 0;
						li.addEventListener( 'click', function () {
							selectResult( item );
						} );
						results.appendChild( li );
					} );

					results.hidden = false;
				} )
				.catch( clearResults );
		}, 300 );

		input.addEventListener( 'input', doSearch );

		// Delay the blur-triggered close just enough for a click on a result
		// (a 'click' on the list fires after 'blur' on the input otherwise).
		input.addEventListener( 'blur', function () {
			setTimeout( clearResults, 150 );
		} );
	}

	// Shows only the "Giorni" or "Data" field that actually applies to the
	// selected "Età massima" mode, instead of always showing both.
	function initAgeModeToggle( select ) {
		var cell = select.closest( 'td' );
		if ( ! cell ) {
			return;
		}
		var fields = cell.querySelectorAll( '[data-age-field]' );

		function sync() {
			for ( var i = 0; i < fields.length; i++ ) {
				fields[ i ].hidden = fields[ i ].getAttribute( 'data-age-field' ) !== select.value;
			}
		}

		select.addEventListener( 'change', sync );
		sync();
	}

	// Keeps the "Totale normalizzato" readout under the weight inputs in sync
	// as the user edits them, so they don't have to do the normalization math.
	function initWeightsPreview( group ) {
		var inputs = group.querySelectorAll( '[data-weight]' );
		var output = group.parentNode.querySelector( '[data-weights-total]' );
		if ( ! inputs.length || ! output ) {
			return;
		}

		function sync() {
			var values = [];
			var sum = 0;

			for ( var i = 0; i < inputs.length; i++ ) {
				var value = parseFloat( inputs[ i ].value );
				if ( isNaN( value ) || value < 0 ) {
					value = 0;
				}
				values.push( { label: inputs[ i ].getAttribute( 'data-weight-label' ), value: value } );
				sum += value;
			}

			var parts = values.map( function ( entry ) {
				var share = sum > 0 ? entry.value / sum : 1 / values.length;
				return entry.label + ' ' + Math.round( share * 100 ) + '%';
			} );

			output.textContent = parts.join( ' · ' );
		}

		for ( var i = 0; i < inputs.length; i++ ) {
			inputs[ i ].addEventListener( 'input', sync );
		}
	}

	// Priorità e pesi page's weight editor: 4 colored range sliders whose
	// dots double as the composition bars' legend below them. Separate from
	// initWeightsPreview() above (different markup, different attributes)
	// so neither page's weight form can accidentally match the other's
	// selectors. Drives a live stacked preview bar in the same
	// bmfr-composition-seg language as every ranked post's own bar, so
	// moving a slider previews its effect before "Salva pesi" reloads the
	// real ranking.
	function initWeightEditor( root ) {
		var inputs  = root.querySelectorAll( '[data-weight]' );
		var pctOuts = root.querySelectorAll( '[data-weight-editor-pct]' );
		var segs    = root.querySelectorAll( '[data-weight-editor-seg]' );

		if ( ! inputs.length ) {
			return;
		}

		function sync() {
			var values = {};
			var sum = 0;
			var i;

			for ( i = 0; i < inputs.length; i++ ) {
				var value = parseFloat( inputs[ i ].value );
				if ( isNaN( value ) || value < 0 ) {
					value = 0;
				}
				values[ inputs[ i ].getAttribute( 'data-weight' ) ] = value;
				sum += value;
			}

			for ( i = 0; i < pctOuts.length; i++ ) {
				var pctKey   = pctOuts[ i ].getAttribute( 'data-weight-editor-pct' );
				var pctShare = sum > 0 ? values[ pctKey ] / sum : 1 / inputs.length;
				pctOuts[ i ].textContent = Math.round( pctShare * 100 ) + '%';
			}

			for ( i = 0; i < segs.length; i++ ) {
				var segKey   = segs[ i ].getAttribute( 'data-weight-editor-seg' );
				var segShare = sum > 0 ? values[ segKey ] / sum : 1 / inputs.length;
				segs[ i ].style.width = ( segShare * 100 ) + '%';
			}
		}

		for ( var j = 0; j < inputs.length; j++ ) {
			inputs[ j ].addEventListener( 'input', sync );
		}

		sync();
	}

	// Lets the user narrow a long <select multiple> of categories by typing,
	// without touching which options are actually selected.
	function initCategoryFilter( input ) {
		var targetId = input.getAttribute( 'data-filter-target' );
		var select = targetId ? document.getElementById( targetId ) : null;
		if ( ! select ) {
			return;
		}

		input.addEventListener( 'input', function () {
			var term = input.value.trim().toLowerCase();
			var options = select.options;
			for ( var i = 0; i < options.length; i++ ) {
				options[ i ].hidden = term.length > 0 && options[ i ].text.toLowerCase().indexOf( term ) === -1;
			}
		} );
	}

	// Tour page: most row actions (global Escludi/Riammetti, and the
	// row-level "Mantieni tutti"/"Escludi tutti" fast paths) move a post
	// from the tab you're looking at to a different one — AJAX + in-place
	// row removal, with a real-navigation fallback on any failure, so
	// scrubbing a long list doesn't lose your scroll position and page
	// number after every single decision.
	//
	// Per-cycle actions (one card's Mantieni/Escludi/cambia, the score
	// stepper/reset) usually don't move the row at all: they patch just
	// that one card in place. The server tells us which case we're in via
	// the response shape — see applyTourPayload().
	function removeTourRow( row ) {
		if ( ! row ) {
			return;
		}

		var table   = row.closest( 'table' );
		var counter = document.querySelector( '[data-tour-remaining]' );
		var tbody   = row.parentNode;

		row.classList.add( 'bmfr-tour-row-removed' );

		setTimeout( function () {
			row.remove();

			if ( counter ) {
				var remaining = Math.max( 0, parseInt( counter.getAttribute( 'data-tour-remaining' ), 10 ) - 1 );
				counter.setAttribute( 'data-tour-remaining', remaining );
				counter.textContent = remaining;
			}

			if ( tbody && ! tbody.querySelector( 'tr' ) ) {
				var emptyCell = document.createElement( 'td' );
				emptyCell.colSpan = 4;
				emptyCell.textContent = ( table && table.getAttribute( 'data-empty-text' ) ) || '—';

				var emptyRow = document.createElement( 'tr' );
				emptyRow.appendChild( emptyCell );
				tbody.appendChild( emptyRow );
			}
		}, 200 );
	}

	// Swaps just the score line and/or the decision line of one cycle card
	// in place, using the HTML fragments the server already rendered with
	// BMFR_Tour_Page::render_cycle_score()/render_cycle_bottom() — the
	// client never hand-builds that markup (nonces, labels, hidden fields),
	// it only places what the server sent.
	function applyTourPayload( row, payload ) {
		if ( payload.slot ) {
			var card = row.querySelector( '[data-bmfr-cycle-card][data-slot="' + payload.slot + '"]' );

			if ( card ) {
				if ( payload.cycle_score_html ) {
					var scoreEl = card.querySelector( '[data-bmfr-cycle-score]' );
					if ( scoreEl ) {
						scoreEl.outerHTML = payload.cycle_score_html;
					}
				}

				if ( payload.cycle_bottom_html ) {
					var bottomEl = card.querySelector( '[data-bmfr-cycle-bottom]' );
					if ( bottomEl ) {
						bottomEl.innerHTML = payload.cycle_bottom_html;
					}
				}

				if ( payload.decision ) {
					card.classList.remove( 'bmfr-cycle-kept', 'bmfr-cycle-excluded' );
					if ( 'kept' === payload.decision ) {
						card.classList.add( 'bmfr-cycle-kept' );
					} else if ( 'excluded' === payload.decision ) {
						card.classList.add( 'bmfr-cycle-excluded' );
					}
				}
			}
		}

		if ( payload.row_resolved ) {
			removeTourRow( row );
		}
	}

	function tourActionAjax( form ) {
		var row = form.closest( 'tr' );
		var ajaxAction = form.getAttribute( 'data-bmfr-ajax-action' );
		var data = new FormData( form );
		data.set( 'action', ajaxAction );

		fetch( window.bmfrAdmin.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: data
		} )
			.then( function ( response ) { return response.json(); } )
			.then( function ( result ) {
				if ( ! result || ! result.success ) {
					form.submit();
					return;
				}

				var payload = result.data || {};

				// New Tour actions always carry a "slot" (per-cycle card to
				// patch) and/or a "row_resolved" flag (whether to remove the
				// row). Legacy actions (global exclude/un-exclude) carry
				// neither — just a bare post_id — and always move the post,
				// same as before this per-cycle feature existed.
				if ( payload.slot || payload.hasOwnProperty( 'row_resolved' ) ) {
					applyTourPayload( row, payload );
				} else {
					removeTourRow( row );
				}
			} )
			.catch( function () {
				form.submit();
			} );
	}

	// Forms without data-bmfr-confirm (Mantieni, Riammetti, Rimuovi dai
	// candidati) act immediately — only Escludi, which affects what's live
	// on the site right now, goes through the confirm modal first.
	//
	// Delegated on document instead of bound per-form: applyTourPayload()
	// replaces a card's score/decision markup with fresh forms from the
	// server (outerHTML/innerHTML), so any listener attached directly to
	// the original form elements would miss those replacements — leaving
	// their next submit to fall through as a real, un-intercepted page
	// navigation.
	function initTourActions() {
		document.addEventListener( 'submit', function ( event ) {
			var form = event.target;

			if ( ! form.classList || ! form.classList.contains( 'bmfr-tour-action-form' ) ) {
				return;
			}

			if ( form.hasAttribute( 'data-bmfr-confirm' ) ) {
				return; // initConfirmModals() owns this form's submit instead.
			}

			event.preventDefault();

			if ( window.bmfrAdmin ) {
				tourActionAjax( form );
			} else {
				form.submit();
			}
		} );
	}

	// Replaces the native confirm() dialog on forms marked with
	// data-bmfr-confirm with a themed modal, since the browser's own dialog
	// can't be styled and looks out of place inside wp-admin.
	function initConfirmModals() {
		if ( ! document.querySelector( '[data-bmfr-confirm]' ) ) {
			return;
		}

		var strings = ( window.bmfrAdmin && window.bmfrAdmin.strings ) || {};

		var overlay = document.createElement( 'div' );
		overlay.className = 'bmfr-modal-overlay';
		overlay.hidden = true;
		overlay.innerHTML =
			'<div class="bmfr-modal" role="alertdialog" aria-modal="true" aria-labelledby="bmfr-modal-message">' +
				'<p id="bmfr-modal-message" class="bmfr-modal-message"></p>' +
				'<div class="bmfr-modal-actions">' +
					'<button type="button" class="button bmfr-modal-cancel"></button>' +
					'<button type="button" class="button button-primary bmfr-modal-confirm"></button>' +
				'</div>' +
			'</div>';
		document.body.appendChild( overlay );

		var message       = overlay.querySelector( '.bmfr-modal-message' );
		var cancelButton  = overlay.querySelector( '.bmfr-modal-cancel' );
		var confirmButton = overlay.querySelector( '.bmfr-modal-confirm' );
		var pendingForm   = null;
		var lastFocused   = null;

		cancelButton.textContent  = strings.cancel || 'Cancel';
		confirmButton.textContent = strings.confirm || 'OK';

		function close() {
			overlay.hidden = true;
			pendingForm = null;
			if ( lastFocused ) {
				lastFocused.focus();
			}
		}

		function open( form ) {
			pendingForm = form;
			lastFocused = document.activeElement;
			message.textContent = form.getAttribute( 'data-bmfr-confirm' );
			overlay.hidden = false;
			confirmButton.focus();
		}

		// Delegated for the same reason as initTourActions(): cards whose
		// decision markup gets replaced in place (e.g. after a score nudge)
		// get fresh Escludi forms that a one-time per-form binding would miss.
		document.addEventListener( 'submit', function ( event ) {
			var form = event.target;
			if ( ! form.hasAttribute || ! form.hasAttribute( 'data-bmfr-confirm' ) ) {
				return;
			}
			event.preventDefault();
			open( form );
		} );

		cancelButton.addEventListener( 'click', close );

		overlay.addEventListener( 'click', function ( event ) {
			if ( event.target === overlay ) {
				close();
			}
		} );

		document.addEventListener( 'keydown', function ( event ) {
			if ( ! overlay.hidden && 'Escape' === event.key ) {
				close();
			}
		} );

		confirmButton.addEventListener( 'click', function () {
			var form = pendingForm;
			overlay.hidden = true;
			pendingForm = null;

			if ( ! form ) {
				return;
			}

			if ( form.hasAttribute( 'data-bmfr-ajax-action' ) && window.bmfrAdmin ) {
				tourActionAjax( form );
				return;
			}

			form.submit();
		} );
	}

	// Anteprima page "Esegui ora" card: the button acts on whichever of the
	// Trend/Spotlight checkboxes are checked, so the confirm message names
	// them explicitly instead of a fixed "questo slot" that no longer
	// matches what the button actually does. Also blocks submitting with
	// nothing checked, since the server would otherwise just no-op.
	function initRotateForm( form ) {
		var checkboxes = form.querySelectorAll( 'input[name="slots[]"]' );
		var submit     = form.querySelector( '[type="submit"]' );
		var template   = form.getAttribute( 'data-bmfr-confirm-template' );

		if ( ! checkboxes.length || ! submit || ! template ) {
			return;
		}

		function labelFor( checkbox ) {
			var label = checkbox.closest( 'label' );
			return label ? label.textContent.trim() : checkbox.value;
		}

		function sync() {
			var checked = [];
			for ( var i = 0; i < checkboxes.length; i++ ) {
				if ( checkboxes[ i ].checked ) {
					checked.push( labelFor( checkboxes[ i ] ) );
				}
			}

			submit.disabled = checked.length === 0;

			if ( checked.length ) {
				form.setAttribute( 'data-bmfr-confirm', template.replace( '%s', checked.join( ', ' ) ) );
			} else {
				form.removeAttribute( 'data-bmfr-confirm' );
			}
		}

		for ( var j = 0; j < checkboxes.length; j++ ) {
			checkboxes[ j ].addEventListener( 'change', sync );
		}

		sync();
	}

	/*
	 * Priorità e pesi: one ranking table for every post in the slots' age
	 * windows. Everything is recomputed here, in the browser, from each
	 * post's raw signals (see BMFR_Priority_Page::recompute()):
	 *
	 *   base  = sum(w_i * signal_i) / sum(w)        (BMFR_Scoring::combine)
	 *   final = clamp01( base + shared + offset[slot] )
	 *
	 * so a slider move, a +/- click or a drag re-ranks instantly; only the
	 * priority itself is saved, through bmfr_priority_set.
	 */
	function initPriorityBoard( root ) {
		var dataEl = document.getElementById( 'bmfr-priority-data' );
		var list   = root.querySelector( '[data-board-list]' );
		var status = root.querySelector( '[data-board-status]' );

		if ( ! dataEl || ! list ) {
			return;
		}

		var D;
		try {
			D = JSON.parse( dataEl.textContent );
		} catch ( e ) {
			return;
		}

		var S        = D.strings;
		var slotKeys = Object.keys( D.slots );
		var view     = slotKeys[ 0 ];
		var byId     = {};
		var weights  = {};
		var seq      = {};
		var lastChange = null;
		var flashId  = null;
		var dragId   = null;
		var confirmId = null;
		var busy     = false;
		var search   = root.querySelector( '[data-board-search]' );
		var onlyPrio = root.querySelector( '[data-board-only-priority]' );
		var showOut  = root.querySelector( '[data-board-show-out]' );

		D.posts.forEach( function ( p ) {
			byId[ p.id ] = p;
			slotKeys.forEach( function ( k ) {
				if ( typeof p.o[ k ] !== 'number' ) {
					p.o[ k ] = 0;
				}
			} );
		} );

		slotKeys.forEach( function ( k ) {
			weights[ k ] = D.slots[ k ].weights.slice();
		} );

		function sprintf( str ) {
			var args = Array.prototype.slice.call( arguments, 1 );
			var i = 0;
			return str.replace( /%(\d)\$[sd]|%[sd]/g, function ( match, pos ) {
				return pos ? args[ pos - 1 ] : args[ i++ ];
			} );
		}

		function esc( str ) {
			return String( str ).replace( /[&<>"']/g, function ( c ) {
				return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
			} );
		}

		function r2( v ) {
			return Math.round( v * 100 ) / 100;
		}

		function clamp( v, max ) {
			var out = r2( Math.max( -max, Math.min( max, v ) ) );
			return Math.abs( out ) < 0.005 ? 0 : out;
		}

		function signed( v ) {
			if ( Math.abs( v ) < 0.005 ) {
				return '0';
			}
			return ( v > 0 ? '+' : '−' ) + Math.abs( v ).toFixed( 2 ).replace( '.', D.decimal || '.' );
		}

		function num( v ) {
			return v.toFixed( 2 ).replace( '.', D.decimal || '.' );
		}

		function base( p, k ) {
			var e = p.s[ k ];
			if ( ! e || ! e.sig ) {
				return null;
			}
			var w = weights[ k ];
			var sum = 0;
			var total = 0;
			for ( var i = 0; i < 4; i++ ) {
				sum += w[ i ];
			}
			for ( var j = 0; j < 4; j++ ) {
				total += ( sum > 0 ? w[ j ] : 0.25 ) * e.sig[ j ];
			}
			return sum > 0 ? total / sum : total;
		}

		function boost( p, k ) {
			return p.p + p.o[ k ];
		}

		function finalScore( p, k ) {
			var b = base( p, k );
			return null === b ? null : Math.max( 0, Math.min( 1, b + boost( p, k ) ) );
		}

		function ranking( k ) {
			return D.posts.filter( function ( p ) {
				return p.s[ k ] && p.s[ k ].sig;
			} ).sort( function ( a, b ) {
				return ( finalScore( b, k ) - finalScore( a, k ) ) || ( a.id - b.id );
			} ).map( function ( p ) {
				return p.id;
			} );
		}

		function positions() {
			var map = {};
			slotKeys.forEach( function ( k ) {
				map[ k ] = {};
				ranking( k ).forEach( function ( id, i ) {
					map[ k ][ id ] = i + 1;
				} );
			} );
			return map;
		}

		function otherSlots( k ) {
			return slotKeys.filter( function ( x ) {
				return x !== k && D.slots[ x ];
			} );
		}

		// Short, distinct label prefixes for the "Altri slot" column when
		// there are 3+ slots: the first letter, lengthened only for slots
		// whose prefix would collide (e.g. "Spo" / "Spe"). Computed across
		// every slot so a slot keeps the same abbreviation in every view.
		function slotAbbrevs() {
			var names = {};
			var len   = {};
			slotKeys.forEach( function ( sk ) {
				var label = D.slots[ sk ] ? String( D.slots[ sk ].label ) : sk;
				names[ sk ] = label.replace( /\s+/g, '' ) || sk;
				len[ sk ]   = 1;
			} );
			function abbr( sk ) {
				return names[ sk ].slice( 0, len[ sk ] );
			}
			for ( var guard = 0; guard < 40; guard++ ) {
				var groups = {};
				slotKeys.forEach( function ( sk ) {
					var key = abbr( sk ).toLowerCase();
					( groups[ key ] = groups[ key ] || [] ).push( sk );
				} );
				var grew = false;
				Object.keys( groups ).forEach( function ( key ) {
					if ( groups[ key ].length > 1 ) {
						groups[ key ].forEach( function ( sk ) {
							if ( len[ sk ] < names[ sk ].length ) {
								len[ sk ]++;
								grew = true;
							}
						} );
					}
				} );
				if ( ! grew ) {
					break;
				}
			}
			var out = {};
			slotKeys.forEach( function ( sk ) {
				var a = abbr( sk );
				out[ sk ] = a.charAt( 0 ).toUpperCase() + a.slice( 1 );
			} );
			return out;
		}

		// The "other slot" cell. With exactly one other slot (two slots in
		// all) it is the same markup as always: initial + rank, or
		// "fuori gara". With more, one compact entry per other slot.
		function otherCell( p, others, pos, abbrevs ) {
			if ( others.length < 2 ) {
				var o = others[ 0 ];
				var m = o && pos[ o ] ? pos[ o ][ p.id ] : null;
				return '<span class="bmfr-board-other">' + ( m ? esc( D.slots[ o ].label.charAt( 0 ) ) + m : ( o && p.s[ o ] ? esc( S.out ) : '' ) ) + '</span>';
			}
			var items = [];
			var full  = [];
			others.forEach( function ( o ) {
				var m = pos[ o ] ? pos[ o ][ p.id ] : null;
				if ( m ) {
					items.push( '<span class="bmfr-board-other-item">' + esc( abbrevs[ o ] ) + m + '</span>' );
					full.push( D.slots[ o ].label + ' ' + m );
				} else if ( p.s[ o ] ) {
					items.push( '<span class="bmfr-board-other-item is-out">' + esc( abbrevs[ o ] ) + '–</span>' );
					full.push( D.slots[ o ].label + ': ' + S.out );
				}
			} );
			if ( ! items.length ) {
				return '<span class="bmfr-board-other"></span>';
			}
			var text = full.join( ' · ' );
			return '<span class="bmfr-board-other is-many" title="' + esc( text ) + '">' +
				'<span class="bmfr-board-other-items" aria-hidden="true">' + items.join( '' ) + '</span>' +
				'<span class="screen-reader-text">' + esc( text ) + '</span>' +
				'</span>';
		}

		function dial( p, act, value, label ) {
			var cls = value > 0.004 ? ' is-pos' : ( value < -0.004 ? ' is-neg' : '' );
			var out = '<span class="bmfr-dial bmfr-dial-' + act + '">';
			if ( D.canEdit ) {
				out += '<button type="button" class="bmfr-dial-btn" data-act="' + act + '" data-d="-1" aria-label="' + esc( sprintf( S.decrease, label ) ) + '"><span class="dashicons dashicons-minus" aria-hidden="true"></span></button>';
			}
			out += '<b class="bmfr-dial-value' + cls + '">' + signed( value ) + '</b>';
			if ( D.canEdit ) {
				out += '<button type="button" class="bmfr-dial-btn" data-act="' + act + '" data-d="1" aria-label="' + esc( sprintf( S.increase, label ) ) + '"><span class="dashicons dashicons-plus" aria-hidden="true"></span></button>';
			}
			return out + '</span>';
		}

		function bar( p, k ) {
			var b = base( p, k );
			var f = finalScore( p, k );
			var w = weights[ k ];
			var sum = w[ 0 ] + w[ 1 ] + w[ 2 ] + w[ 3 ];
			var segs = '';
			var tip = [];

			for ( var i = 0; i < 4; i++ ) {
				var share = ( sum > 0 ? w[ i ] / sum : 0.25 ) * p.s[ k ].sig[ i ];
				segs += '<span class="bmfr-composition-seg" style="width:' + ( share * 100 ).toFixed( 2 ) + '%;background:' + D.colors[ i ] + '"></span>';
				tip.push( D.labels[ i ] + ' ' + Math.round( share * 100 ) + '%' );
			}

			if ( f > b ) {
				segs += '<span class="bmfr-composition-seg bmfr-composition-boost" style="width:' + ( ( f - b ) * 100 ).toFixed( 2 ) + '%"></span>';
			}

			var cut = f < b ? '<span class="bmfr-composition-cut" style="left:' + ( f * 100 ).toFixed( 2 ) + '%;width:' + ( ( b - f ) * 100 ).toFixed( 2 ) + '%"></span>' : '';

			if ( Math.abs( boost( p, k ) ) > 0.004 ) {
				tip.push( sprintf( S.priorityNote, signed( boost( p, k ) ) ) );
			}

			return '<span class="bmfr-composition-bar bmfr-board-bar" title="' + esc( tip.join( ' · ' ) ) + '">' + segs + cut + '</span>';
		}

		function render() {
			var k     = view;
			var others  = otherSlots( k );
			var many    = others.length > 1;
			var abbrevs = many ? slotAbbrevs() : {};
			var o     = others[ 0 ];
			var pos   = positions();
			var term  = search ? search.value.trim().toLowerCase() : '';
			var only  = onlyPrio && onlyPrio.checked;
			var outOn = ! showOut || showOut.checked;
			var cut   = D.slots[ k ].count;
			var filtering = '' !== term || only;
			var offsetLabel = sprintf( S.onlySlot, D.slots[ k ].label );

			var rows = ranking( k ).map( function ( id ) {
				return byId[ id ];
			} );

			if ( outOn ) {
				rows = rows.concat( D.posts.filter( function ( p ) {
					return p.s[ k ] && p.s[ k ].out;
				} ).sort( function ( a, b ) {
					return ( boost( b, k ) - boost( a, k ) ) || a.t.localeCompare( b.t );
				} ) );
			}

			var windowCount = D.posts.filter( function ( p ) {
				return p.s[ k ];
			} ).length;

			rows = rows.filter( function ( p ) {
				if ( term && -1 === p.t.toLowerCase().indexOf( term ) ) {
					return false;
				}
				if ( only && Math.abs( p.p ) < 0.005 && Math.abs( p.o[ k ] ) < 0.005 ) {
					return false;
				}
				return true;
			} );

			var html = '<div class="bmfr-board-row bmfr-board-head' + ( D.canEdit ? '' : ' is-readonly' ) + '" aria-hidden="true">' +
				( D.canEdit ? '<span></span>' : '' ) +
				'<span>#</span>' +
				'<span>' + esc( many ? S.otherSlots : ( D.slots[ o ] ? D.slots[ o ].label : '' ) ) + '</span>' +
				'<span>' + esc( S.title ) + ' · ' + esc( sprintf( S.inWindow, windowCount ) ) + '</span>' +
				'<span>' + esc( S.signals ) + '</span>' +
				'<span>' + esc( S.shared ) + '</span>' +
				'<span class="bmfr-board-offset-head">' + esc( offsetLabel ) + '</span>' +
				'<span class="bmfr-board-num">' + esc( S.score ) + '</span>' +
				( D.canEdit ? '<span></span><span></span>' : '' ) +
				'</div>';

			if ( ! rows.length ) {
				html += '<p class="bmfr-board-empty">' + esc( filtering ? S.noResults : S.empty ) + '</p>';
			}

			rows.forEach( function ( p ) {
				var isOut = ! ( p.s[ k ] && p.s[ k ].sig );
				var n     = pos[ k ][ p.id ];
				var f     = finalScore( p, k );
				var cls   = 'bmfr-board-row';
				var pills = '';

				if ( isOut ) {
					cls += ' is-out';
				}
				if ( n === cut && ! filtering ) {
					cls += ' is-cut';
				}
				if ( flashId === p.id ) {
					cls += ' is-flash';
				}
				if ( ! D.canEdit ) {
					cls += ' is-readonly';
				}

				slotKeys.forEach( function ( sk ) {
					if ( -1 !== D.slots[ sk ].live.indexOf( p.id ) ) {
						pills += '<span class="bmfr-pill bmfr-pill-on bmfr-board-pill">' + esc( sprintf( S.liveIn, D.slots[ sk ].label ) ) + '</span>';
					}
				} );

				var reason = isOut ? p.s[ k ].out : '';

				if ( isOut && 'live' !== reason ) {
					pills += '<span class="bmfr-board-pill bmfr-board-pill-out' + ( 0 === reason.indexOf( 'excluded' ) ? ' is-excluded' : '' ) + '"' + ( 'excluded_cat' === reason ? ' title="' + esc( S.catHint ) + '"' : '' ) + '>' + esc( S.reasons[ reason ] || S.reasons.other ) + '</span>';
				}

				// Exclusion control: readmit for a post excluded by id, nothing
				// for a whole excluded category (that lives on Esclusioni),
				// exclude for everything else.
				var exclusionCell = '<span></span>';
				if ( 'excluded' === reason || 'excluded_global' === reason ) {
					exclusionCell = '<button type="button" class="bmfr-board-icon bmfr-board-readmit" data-act="readmit" aria-label="' + esc( S.readmit + ': ' + p.t ) + '" title="' + esc( S.readmitHint ) + '"><span class="dashicons dashicons-visibility" aria-hidden="true"></span></button>';
				} else if ( 'excluded_cat' !== reason ) {
					exclusionCell = '<button type="button" class="bmfr-board-icon bmfr-board-exclude" data-act="exclude" aria-expanded="' + ( confirmId === p.id ? 'true' : 'false' ) + '" aria-label="' + esc( S.exclude + ': ' + p.t ) + '" title="' + esc( S.exclude ) + '"><span class="dashicons dashicons-hidden" aria-hidden="true"></span></button>';
				}

				var hasPriority = Math.abs( p.p ) > 0.004 || slotKeys.some( function ( sk ) {
					return Math.abs( p.o[ sk ] ) > 0.004;
				} );

				html += '<div class="' + cls + '" data-id="' + p.id + '">' +
					( D.canEdit ? ( isOut ? '<span></span>' : '<span class="bmfr-board-handle" title="' + esc( S.drag ) + '" aria-hidden="true"><span class="dashicons dashicons-menu"></span></span>' ) : '' ) +
					'<span class="bmfr-board-rank">' + ( isOut ? '—' : n ) + '</span>' +
					otherCell( p, others, pos, abbrevs ) +
					'<span class="bmfr-board-title"><a href="' + esc( p.url || '#' ) + '">' + esc( p.t ) + '</a>' + pills + '</span>' +
					( isOut ? '<span></span>' : bar( p, k ) ) +
					dial( p, 'shared', p.p, S.shared ) +
					dial( p, 'offset', p.o[ k ], offsetLabel ) +
					'<span class="bmfr-board-num">' + ( null === f ? '—' : num( f ) ) + '</span>' +
					( D.canEdit ? ( hasPriority ? '<button type="button" class="bmfr-board-icon bmfr-board-reset" data-act="reset" aria-label="' + esc( S.resetAll ) + '" title="' + esc( S.resetAll ) + '"><span class="dashicons dashicons-undo" aria-hidden="true"></span></button>' : '<span></span>' ) + exclusionCell : '' ) +
					'</div>';

				if ( D.canEdit && confirmId === p.id ) {
					var liveNow = slotKeys.some( function ( sk ) {
						return -1 !== D.slots[ sk ].live.indexOf( p.id );
					} );
					html += '<div class="bmfr-board-confirm" data-confirm-for="' + p.id + '" role="group" aria-label="' + esc( sprintf( S.excludeAsk, p.t ) ) + '">' +
						'<span class="bmfr-board-confirm-text">' + esc( sprintf( S.excludeAsk, p.t ) ) +
						( liveNow ? ' <span class="bmfr-board-confirm-warn">' + esc( S.excludeLive ) + '</span>' : '' ) + '</span>' +
						'<span class="bmfr-board-confirm-actions">' +
						'<button type="button" class="button button-small" data-exclude-op="exclude_slot">' + esc( sprintf( S.excludeSlot, D.slots[ k ].label ) ) + '</button>' +
						'<button type="button" class="button button-small" data-exclude-op="exclude_all">' + esc( S.excludeAll ) + '</button>' +
						'<button type="button" class="button-link" data-exclude-op="cancel">' + esc( S.cancel ) + '</button>' +
						'</span></div>';
				}
			} );

			list.classList.toggle( 'has-many-others', many );
			list.innerHTML = html;
		}

		function setStatus( html, isError ) {
			status.innerHTML = html;
			status.classList.toggle( 'is-error', !! isError );
		}

		function describeMove( p, before ) {
			var after = positions();
			return slotKeys.map( function ( sk ) {
				var b = before[ sk ][ p.id ];
				var a = after[ sk ][ p.id ];
				if ( ! a && ! b ) {
					return null;
				}
				return b === a ? sprintf( S.stays, D.slots[ sk ].label, a ) : sprintf( S.moves, D.slots[ sk ].label, b || '—', a || '—' );
			} ).filter( Boolean ).join( ', ' );
		}

		function save( p, prev ) {
			seq[ p.id ] = ( seq[ p.id ] || 0 ) + 1;
			var mine = seq[ p.id ];
			var body = new FormData();
			body.append( 'action', 'bmfr_priority_set' );
			body.append( 'nonce', D.nonce );
			body.append( 'post_id', p.id );
			body.append( 'shared', p.p );
			slotKeys.forEach( function ( sk ) {
				body.append( 'offsets[' + sk + ']', p.o[ sk ] );
			} );

			fetch( D.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( result ) {
					if ( mine !== seq[ p.id ] ) {
						return;
					}
					if ( ! result || ! result.success ) {
						throw new Error( result && result.data && result.data.message ? result.data.message : '' );
					}
					var changed = r2( result.data.shared ) !== r2( p.p );
					p.p = r2( result.data.shared );
					slotKeys.forEach( function ( sk ) {
						var v = r2( result.data.offsets[ sk ] || 0 );
						changed = changed || v !== r2( p.o[ sk ] );
						p.o[ sk ] = v;
					} );
					if ( changed ) {
						render();
					}
					var savedNote = status.querySelector( '[data-board-saving]' );
					if ( savedNote ) {
						savedNote.textContent = S.saved;
					}
				} )
				.catch( function ( err ) {
					if ( mine !== seq[ p.id ] ) {
						return;
					}
					p.p = prev.p;
					p.o = prev.o;
					lastChange = null;
					render();
					setStatus( esc( sprintf( S.saveFailed, err && err.message ? err.message : '' ) ), true );
				} );
		}

		function apply( p, shared, offsets, note, isUndo ) {
			var prev   = { p: p.p, o: JSON.parse( JSON.stringify( p.o ) ) };
			var before = positions();

			p.p = clamp( shared, D.limits.shared );
			Object.keys( offsets ).forEach( function ( sk ) {
				p.o[ sk ] = clamp( offsets[ sk ], D.limits.offset );
			} );

			flashId = p.id;
			render();
			setTimeout( function () {
				if ( flashId === p.id ) {
					flashId = null;
					var row = list.querySelector( '.bmfr-board-row[data-id="' + p.id + '"]' );
					if ( row ) {
						row.classList.remove( 'is-flash' );
					}
				}
			}, 900 );

			lastChange = isUndo ? null : { id: p.id, prev: prev };

			var msg = '<strong>' + esc( p.t ) + '</strong>: ' + esc( describeMove( p, before ) ) + '.';
			if ( note ) {
				msg += ' ' + esc( note );
			}
			if ( isUndo ) {
				msg = esc( S.undone ) + ' ' + msg;
			}
			msg += ' <span class="bmfr-board-saving" data-board-saving>' + esc( S.saving ) + '</span>';
			if ( ! isUndo ) {
				msg += ' <button type="button" class="button-link" data-board-undo>' + esc( S.undo ) + '</button>';
			}
			setStatus( msg, false );

			save( p, prev );
			return before;
		}

		function focusControl( id, act, d ) {
			var selector = '.bmfr-board-row[data-id="' + id + '"] [data-act="' + act + '"]' + ( d ? '[data-d="' + d + '"]' : '' );
			var el = list.querySelector( selector );
			if ( el ) {
				el.focus();
			}
		}

		// Replaces the table data with a fresh server payload (after an
		// exclusion), keeping the viewer's unsaved slider weights.
		function loadBoard( board ) {
			D.slots = board.slots;
			D.posts = board.posts;
			byId = {};
			D.posts.forEach( function ( p ) {
				byId[ p.id ] = p;
				slotKeys.forEach( function ( k ) {
					if ( typeof p.o[ k ] !== 'number' ) {
						p.o[ k ] = 0;
					}
				} );
			} );
			slotKeys.forEach( function ( k ) {
				if ( ! weights[ k ] && D.slots[ k ] ) {
					weights[ k ] = D.slots[ k ].weights.slice();
				}
			} );
		}

		function exclusion( p, op, slotKey, isUndo, prevReason ) {
			if ( busy ) {
				return;
			}
			busy = true;
			confirmId = null;
			root.classList.add( 'is-busy' );
			render();
			setStatus( esc( S.working ), false );

			var body = new FormData();
			body.append( 'action', 'bmfr_priority_exclusion' );
			body.append( 'nonce', D.nonce );
			body.append( 'post_id', p.id );
			body.append( 'slot', slotKey );
			body.append( 'op', op );

			fetch( D.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( result ) {
					if ( ! result || ! result.success ) {
						throw new Error( result && result.data && result.data.message ? result.data.message : '' );
					}
					loadBoard( result.data.board );
					flashId = p.id;
					render();

					var title = byId[ p.id ] ? byId[ p.id ].t : p.t;
					var msg;
					if ( 'exclude_slot' === op ) {
						msg = sprintf( S.excludedSlot, title, D.slots[ slotKey ].label );
					} else if ( 'exclude_all' === op ) {
						msg = sprintf( S.excludedAll, title );
					} else {
						msg = sprintf( S.readmitted, title );
					}
					if ( result.data.replaced ) {
						msg += ' ' + S.replaced;
					}
					if ( isUndo ) {
						msg = S.undone + ' ' + msg;
					}

					lastChange = isUndo ? null : { kind: 'exclusion', id: p.id, op: op, slot: slotKey, prevReason: prevReason };
					setStatus( esc( msg ) + ( isUndo ? '' : ' <button type="button" class="button-link" data-board-undo>' + esc( S.undo ) + '</button>' ), false );
				} )
				.catch( function ( err ) {
					setStatus( esc( sprintf( S.actionFailed, err && err.message ? err.message : '' ) ), true );
				} )
				.then( function () {
					busy = false;
					root.classList.remove( 'is-busy' );
					setTimeout( function () {
						flashId = null;
						var row = list.querySelector( '.bmfr-board-row.is-flash' );
						if ( row ) {
							row.classList.remove( 'is-flash' );
						}
					}, 900 );
				} );
		}

		list.addEventListener( 'click', function ( event ) {
			var opBtn = event.target.closest( '[data-exclude-op]' );
			if ( opBtn && D.canEdit ) {
				var confirmRow = opBtn.closest( '[data-confirm-for]' );
				var target     = byId[ confirmRow.getAttribute( 'data-confirm-for' ) ];
				var op         = opBtn.getAttribute( 'data-exclude-op' );
				if ( 'cancel' === op ) {
					confirmId = null;
					render();
					focusControl( target.id, 'exclude' );
					return;
				}
				exclusion( target, op, view, false, target.s[ view ] ? target.s[ view ].out || '' : '' );
				return;
			}

			var btn = event.target.closest( '[data-act]' );
			if ( ! btn || ! D.canEdit || busy ) {
				return;
			}
			var p   = byId[ btn.closest( '.bmfr-board-row' ).getAttribute( 'data-id' ) ];
			var act = btn.getAttribute( 'data-act' );
			var d   = parseInt( btn.getAttribute( 'data-d' ), 10 ) || 0;
			var offsets = {};
			var note = '';

			if ( 'exclude' === act ) {
				confirmId = confirmId === p.id ? null : p.id;
				render();
				var firstOp = list.querySelector( '[data-confirm-for="' + p.id + '"] [data-exclude-op]' );
				if ( firstOp ) {
					firstOp.focus();
				} else {
					focusControl( p.id, 'exclude' );
				}
				return;
			}

			if ( 'readmit' === act ) {
				exclusion( p, 'readmit', view, false, p.s[ view ].out );
				return;
			}

			if ( 'reset' === act ) {
				slotKeys.forEach( function ( sk ) {
					offsets[ sk ] = 0;
				} );
				apply( p, 0, offsets, '', false );
				return;
			}

			if ( 'shared' === act ) {
				var nextShared = clamp( p.p + d * D.limits.step, D.limits.shared );
				if ( nextShared === p.p ) {
					setStatus( esc( S.limitShared ), false );
					return;
				}
				apply( p, nextShared, offsets, note, false );
			} else {
				var nextOffset = clamp( p.o[ view ] + d * D.limits.step, D.limits.offset );
				if ( nextOffset === p.o[ view ] ) {
					setStatus( esc( S.limitOffset ), false );
					return;
				}
				offsets[ view ] = nextOffset;
				apply( p, p.p, offsets, note, false );
			}

			focusControl( p.id, act, String( d ) );
		} );

		status.addEventListener( 'click', function ( event ) {
			if ( ! event.target.closest( '[data-board-undo]' ) || ! lastChange || busy ) {
				return;
			}
			var p = byId[ lastChange.id ];
			if ( 'exclusion' === lastChange.kind ) {
				// Undo an exclusion = readmit; undo a readmission = exclude
				// again with the same reach it had before.
				var undoOp = 'readmit';
				if ( 'readmit' === lastChange.op ) {
					undoOp = 'excluded_global' === lastChange.prevReason ? 'exclude_all' : 'exclude_slot';
				}
				exclusion( p, undoOp, lastChange.slot, true, '' );
				return;
			}
			apply( p, lastChange.prev.p, lastChange.prev.o, '', true );
		} );

		list.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key && null !== confirmId ) {
				var id = confirmId;
				confirmId = null;
				render();
				focusControl( id, 'exclude' );
			}
		} );

		// Drag only starts from the handle, so links, buttons and text
		// selection inside a row keep working normally.
		list.addEventListener( 'mousedown', function ( event ) {
			var handle = event.target.closest( '.bmfr-board-handle' );
			if ( handle ) {
				handle.closest( '.bmfr-board-row' ).setAttribute( 'draggable', 'true' );
			}
		} );

		function clearDropMarks() {
			var marked = list.querySelectorAll( '.is-drop-before, .is-drop-after' );
			for ( var i = 0; i < marked.length; i++ ) {
				marked[ i ].classList.remove( 'is-drop-before', 'is-drop-after' );
			}
		}

		list.addEventListener( 'dragstart', function ( event ) {
			var row = event.target.closest( '.bmfr-board-row' );
			if ( ! row || 'true' !== row.getAttribute( 'draggable' ) || busy ) {
				return;
			}
			dragId = parseInt( row.getAttribute( 'data-id' ), 10 );
			row.classList.add( 'is-dragging' );
			event.dataTransfer.effectAllowed = 'move';
			try {
				event.dataTransfer.setData( 'text/plain', String( dragId ) );
			} catch ( e ) {}
		} );

		list.addEventListener( 'dragend', function () {
			clearDropMarks();
			var rows = list.querySelectorAll( '[draggable="true"]' );
			for ( var i = 0; i < rows.length; i++ ) {
				rows[ i ].removeAttribute( 'draggable' );
				rows[ i ].classList.remove( 'is-dragging' );
			}
			dragId = null;
		} );

		function dropTarget( event ) {
			var row = event.target.closest( '.bmfr-board-row' );
			if ( ! row || row.classList.contains( 'is-out' ) || row.classList.contains( 'bmfr-board-head' ) ) {
				return null;
			}
			var rect = row.getBoundingClientRect();
			return { row: row, after: event.clientY > rect.top + rect.height / 2 };
		}

		list.addEventListener( 'dragover', function ( event ) {
			if ( null === dragId ) {
				return;
			}
			var t = dropTarget( event );
			if ( ! t ) {
				return;
			}
			event.preventDefault();
			clearDropMarks();
			t.row.classList.add( t.after ? 'is-drop-after' : 'is-drop-before' );
		} );

		list.addEventListener( 'drop', function ( event ) {
			if ( null === dragId ) {
				return;
			}
			event.preventDefault();
			var t = dropTarget( event );
			var p = byId[ dragId ];
			clearDropMarks();
			if ( ! t || ! p ) {
				return;
			}

			var targetId = parseInt( t.row.getAttribute( 'data-id' ), 10 );
			var k        = view;
			var order    = ranking( k ).filter( function ( id ) {
				return id !== p.id;
			} );
			var ins = order.indexOf( targetId ) + ( t.after ? 1 : 0 );

			if ( targetId === p.id ) {
				return;
			}

			var above  = ins > 0 ? finalScore( byId[ order[ ins - 1 ] ], k ) : null;
			var below  = ins < order.length ? finalScore( byId[ order[ ins ] ], k ) : null;
			var target;

			if ( null === above ) {
				target = Math.min( 1, below + 0.02 );
			} else if ( null === below ) {
				target = Math.max( 0, above - 0.02 );
			} else {
				target = ( above + below ) / 2;
			}

			var b         = base( p, k );
			var needTotal = target - b;
			var needSlot  = needTotal - p.p;
			var offsets   = {};

			// Stored values have 0.01 precision, so the exact midpoint is
			// rarely reachable: of the two neighbouring 0.01 steps, keep the
			// one whose final score still sits strictly between the
			// neighbours (if either does).
			function fits( total ) {
				var f = Math.max( 0, Math.min( 1, b + total ) );
				return ( null === above || f < above ) && ( null === below || f > below );
			}
			function snap( ideal, fixed ) {
				var lo = Math.floor( ideal * 100 ) / 100;
				var hi = Math.ceil( ideal * 100 ) / 100;
				var nearest = Math.abs( ideal - lo ) <= Math.abs( hi - ideal ) ? lo : hi;
				var other   = nearest === lo ? hi : lo;
				return fits( fixed + nearest ) || ! fits( fixed + other ) ? nearest : other;
			}

			if ( Math.abs( needSlot ) <= D.limits.offset + 1e-9 ) {
				offsets[ k ] = snap( needSlot, p.p );
				apply( p, p.p, offsets, sprintf( S.offsetOnly, D.slots[ k ].label ), false );
			} else {
				offsets[ k ] = needSlot > 0 ? D.limits.offset : -D.limits.offset;
				apply( p, snap( needTotal - offsets[ k ], offsets[ k ] ), offsets, S.offsetSpill, false );
			}

			var landed = positions()[ k ][ p.id ];
			if ( landed !== ins + 1 ) {
				var extra = ' ' + esc( sprintf( S.maxReached, landed ) );
				var saving = status.querySelector( '[data-board-saving]' );
				if ( saving ) {
					saving.insertAdjacentHTML( 'beforebegin', extra.trim() + ' ' );
				}
			}
		} );

		var viewButtons = root.querySelectorAll( '[data-board-view]' );
		for ( var i = 0; i < viewButtons.length; i++ ) {
			viewButtons[ i ].addEventListener( 'click', function () {
				view = this.getAttribute( 'data-board-view' );
				for ( var j = 0; j < viewButtons.length; j++ ) {
					viewButtons[ j ].setAttribute( 'aria-pressed', String( viewButtons[ j ] === this ) );
				}
				render();
			} );
		}

		if ( search ) {
			search.addEventListener( 'input', debounce( render, 120 ) );
		}
		if ( onlyPrio ) {
			onlyPrio.addEventListener( 'change', render );
		}
		if ( showOut ) {
			showOut.addEventListener( 'change', render );
		}

		// Weight sliders re-rank the table live (preview only until saved).
		var editors = document.querySelectorAll( '[data-weight-editor][data-slot]' );
		Array.prototype.forEach.call( editors, function ( form ) {
			var k = form.getAttribute( 'data-slot' );
			var inputs = form.querySelectorAll( '[data-weight]' );
			var dirty = form.querySelector( '[data-weight-dirty]' );
			var initial = Array.prototype.map.call( inputs, function ( input ) {
				return input.value;
			} );

			if ( ! weights[ k ] ) {
				return;
			}

			Array.prototype.forEach.call( inputs, function ( input, idx ) {
				input.addEventListener( 'input', function () {
					weights[ k ][ idx ] = Math.max( 0, parseFloat( input.value ) || 0 );
					if ( dirty ) {
						dirty.hidden = Array.prototype.every.call( inputs, function ( el, n ) {
							return el.value === initial[ n ];
						} );
					}
					render();
				} );
			} );
		} );

		render();
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var priorityBoard = document.querySelector( '[data-priority-board]' );
		if ( priorityBoard ) {
			initPriorityBoard( priorityBoard );
		}

		var rotateForm = document.querySelector( '.bmfr-rotate-form' );
		if ( rotateForm ) {
			initRotateForm( rotateForm );
		}

		initConfirmModals();
		initTourActions();

		var roots = document.querySelectorAll( '.bmfr-post-search' );
		for ( var i = 0; i < roots.length; i++ ) {
			initPostSearch( roots[ i ] );
		}

		var ageSelects = document.querySelectorAll( '[data-age-mode-select]' );
		for ( var j = 0; j < ageSelects.length; j++ ) {
			initAgeModeToggle( ageSelects[ j ] );
		}

		var weightGroups = document.querySelectorAll( '[data-weights-group]' );
		for ( var k = 0; k < weightGroups.length; k++ ) {
			initWeightsPreview( weightGroups[ k ] );
		}

		var weightEditors = document.querySelectorAll( '[data-weight-editor]' );
		for ( var m = 0; m < weightEditors.length; m++ ) {
			initWeightEditor( weightEditors[ m ] );
		}

		var categoryFilters = document.querySelectorAll( '.bmfr-category-filter' );
		for ( var l = 0; l < categoryFilters.length; l++ ) {
			initCategoryFilter( categoryFilters[ l ] );
		}
	} );
} )();
