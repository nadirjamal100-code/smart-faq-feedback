/**
 * Smart FAQ & Feedback — frontend behaviour (vanilla JS, no dependencies).
 *
 * The server is the source of truth: this script only sends "which FAQ" and
 * "like/dislike", then renders whatever counts the server returns.
 */
( function () {
	'use strict';

	if ( typeof window.sffData === 'undefined' ) {
		return;
	}

	var data = window.sffData;
	var i18n = data.i18n || {};

	function format( template, number ) {
		return String( template || '' ).replace( '%d', String( number ) );
	}

	function formatNumber( n ) {
		try {
			return Number( n ).toLocaleString( document.documentElement.lang || undefined );
		} catch ( e ) {
			return String( n );
		}
	}

	/* ---------------------------- Accordion ---------------------------- */

	function setOpen( item, open ) {
		var trigger = item.querySelector( '.sff-trigger' );
		item.classList.toggle( 'is-open', open );
		if ( trigger ) {
			trigger.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		}
	}

	function initAccordion( list ) {
		list.classList.add( 'sff-js' );

		list.addEventListener( 'click', function ( event ) {
			var trigger = event.target.closest( '.sff-trigger' );
			if ( ! trigger || ! list.contains( trigger ) ) {
				return;
			}
			var item = trigger.closest( '.sff-item' );
			setOpen( item, ! item.classList.contains( 'is-open' ) );
		} );

		// Optional arrow-key navigation between questions (WAI-ARIA accordion pattern).
		list.addEventListener( 'keydown', function ( event ) {
			var trigger = event.target.closest( '.sff-trigger' );
			if ( ! trigger ) {
				return;
			}
			var triggers = Array.prototype.slice.call( list.querySelectorAll( '.sff-trigger' ) );
			var index = triggers.indexOf( trigger );
			var next = -1;

			if ( 'ArrowDown' === event.key ) {
				next = ( index + 1 ) % triggers.length;
			} else if ( 'ArrowUp' === event.key ) {
				next = ( index - 1 + triggers.length ) % triggers.length;
			} else if ( 'Home' === event.key ) {
				next = 0;
			} else if ( 'End' === event.key ) {
				next = triggers.length - 1;
			}

			if ( next > -1 ) {
				event.preventDefault();
				triggers[ next ].focus();
			}
		} );
	}

	/* ------------------------------ Voting ----------------------------- */

	function request( url, options, useNonce ) {
		var headers = { Accept: 'application/json' };
		if ( options.body ) {
			headers[ 'Content-Type' ] = 'application/json';
		}
		if ( useNonce && data.nonce ) {
			headers[ 'X-WP-Nonce' ] = data.nonce;
		}

		var controller = typeof AbortController === 'function' ? new AbortController() : null;
		var timer = controller ? window.setTimeout( function () { controller.abort(); }, 15000 ) : null;

		return fetch( url, {
			method: options.method,
			body: options.body,
			headers: headers,
			credentials: 'same-origin',
			signal: controller ? controller.signal : undefined
		} ).then( function ( response ) {
			if ( timer ) {
				window.clearTimeout( timer );
			}
			return response.json().catch( function () {
				return {};
			} ).then( function ( json ) {
				return { ok: response.ok, status: response.status, json: json };
			} );
		}, function ( error ) {
			if ( timer ) {
				window.clearTimeout( timer );
			}
			throw error;
		} ).then( function ( result ) {
			// A stale nonce (e.g. cached page) must not block voting: retry once as a guest request.
			if ( ! result.ok && useNonce && result.json && 'rest_cookie_invalid_nonce' === result.json.code ) {
				return request( url, options, false );
			}
			return result;
		} );
	}

	function applyState( item, state, message ) {
		var likeBtn = item.querySelector( '.sff-like' );
		var dislikeBtn = item.querySelector( '.sff-dislike' );
		if ( ! likeBtn || ! dislikeBtn ) {
			return;
		}

		var likes = parseInt( state.likes, 10 ) || 0;
		var dislikes = parseInt( state.dislikes, 10 ) || 0;

		likeBtn.querySelector( '.sff-count' ).textContent = formatNumber( likes );
		dislikeBtn.querySelector( '.sff-count' ).textContent = formatNumber( dislikes );
		likeBtn.setAttribute( 'aria-label', format( i18n.likeLabel, likes ) );
		dislikeBtn.setAttribute( 'aria-label', format( i18n.dislikeLabel, dislikes ) );

		[ [ likeBtn, 'like' ], [ dislikeBtn, 'dislike' ] ].forEach( function ( pair ) {
			var selected = state.reaction === pair[ 1 ];
			pair[ 0 ].classList.toggle( 'is-selected', selected );
			pair[ 0 ].setAttribute( 'aria-pressed', selected ? 'true' : 'false' );
		} );

		if ( typeof message === 'string' ) {
			setStatus( item, message, false );
		}
	}

	function setStatus( item, text, isError ) {
		var status = item.querySelector( '.sff-status' );
		if ( ! status ) {
			return;
		}
		status.classList.toggle( 'is-error', !! isError );
		status.textContent = text;
	}

	function setBusy( item, busy ) {
		var box = item.querySelector( '.sff-feedback' );
		if ( box ) {
			box.classList.toggle( 'is-loading', busy );
			box.setAttribute( 'aria-busy', busy ? 'true' : 'false' );
		}
		item.setAttribute( 'data-busy', busy ? '1' : '' );
		Array.prototype.forEach.call( item.querySelectorAll( '.sff-vote' ), function ( btn ) {
			// aria-disabled keeps keyboard focus on the button while blocking repeat clicks.
			btn.setAttribute( 'aria-disabled', busy ? 'true' : 'false' );
		} );
	}

	function vote( item, reaction ) {
		if ( item.getAttribute( 'data-busy' ) === '1' ) {
			return; // Prevent repeated clicks while a request is in flight.
		}
		var faqId = parseInt( item.getAttribute( 'data-faq-id' ), 10 );
		if ( ! faqId ) {
			return;
		}

		setBusy( item, true );
		setStatus( item, i18n.saving || '', false );

		request( data.voteUrl, {
			method: 'POST',
			body: JSON.stringify( { faq_id: faqId, reaction: reaction } )
		}, true ).then( function ( result ) {
			setBusy( item, false );
			if ( result.ok && result.json && result.json.success ) {
				var message = i18n.removed;
				if ( 'like' === result.json.reaction ) {
					message = i18n.liked;
				} else if ( 'dislike' === result.json.reaction ) {
					message = i18n.disliked;
				}
				applyState( item, result.json, message );
			} else {
				var text = ( result.json && result.json.message ) ? result.json.message : i18n.error;
				setStatus( item, text, true );
			}
		} ).catch( function () {
			setBusy( item, false );
			setStatus( item, i18n.network || i18n.error, true );
		} );
	}

	function initVoting( root ) {
		root.addEventListener( 'click', function ( event ) {
			var btn = event.target.closest( '.sff-vote' );
			if ( ! btn || ! root.contains( btn ) ) {
				return;
			}
			var item = btn.closest( '.sff-item' );
			if ( item ) {
				vote( item, btn.getAttribute( 'data-reaction' ) );
			}
		} );
	}

	/* ---- Refresh counts + own reaction (keeps full-page-cached pages correct) ---- */

	function refresh( lists ) {
		var items = {};
		var ids = [];
		lists.forEach( function ( list ) {
			Array.prototype.forEach.call( list.querySelectorAll( '.sff-item' ), function ( item ) {
				if ( ! item.querySelector( '.sff-feedback' ) ) {
					return;
				}
				var id = item.getAttribute( 'data-faq-id' );
				if ( id ) {
					( items[ id ] = items[ id ] || [] ).push( item );
					if ( ids.indexOf( id ) === -1 ) {
						ids.push( id );
					}
				}
			} );
		} );
		if ( ! ids.length ) {
			return;
		}

		var url = data.stateUrl + ( data.stateUrl.indexOf( '?' ) === -1 ? '?' : '&' ) + 'ids=' + encodeURIComponent( ids.slice( 0, 100 ).join( ',' ) );

		request( url, { method: 'GET' }, true ).then( function ( result ) {
			if ( ! result.ok || ! result.json || ! result.json.items ) {
				return;
			}
			Object.keys( result.json.items ).forEach( function ( id ) {
				( items[ id ] || [] ).forEach( function ( item ) {
					if ( item.getAttribute( 'data-busy' ) !== '1' ) {
						applyState( item, result.json.items[ id ] );
					}
				} );
			} );
		} ).catch( function () {
			/* Silent: server-rendered values stay visible. */
		} );
	}

	/* ------------------------------ Init ------------------------------- */

	function init() {
		var lists = Array.prototype.slice.call( document.querySelectorAll( '[data-sff-list]' ) );
		lists.forEach( function ( list ) {
			initAccordion( list );
			initVoting( list );
		} );
		if ( lists.length ) {
			refresh( lists );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
