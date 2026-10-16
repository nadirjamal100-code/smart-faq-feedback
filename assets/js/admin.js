/**
 * Smart FAQ & Feedback — admin list screen: drag-and-drop ordering and delete confirmations.
 */
( function ( $ ) {
	'use strict';

	if ( typeof window.sffAdmin === 'undefined' ) {
		return;
	}

	var cfg = window.sffAdmin;
	var $list = $( '#the-list' );

	/* ------------------------- Delete confirmations ------------------------- */

	$list.on( 'click', 'a.submitdelete', function ( event ) {
		var message = cfg.isTrash ? cfg.i18n.confirmDelete : cfg.i18n.confirmTrash;
		if ( ! window.confirm( message ) ) {
			event.preventDefault();
		}
	} );

	$( '#delete_all' ).on( 'click', function ( event ) {
		if ( ! window.confirm( cfg.i18n.confirmEmpty ) ) {
			event.preventDefault();
		}
	} );

	$( '#posts-filter' ).on( 'submit', function ( event ) {
		var top = $( '#bulk-action-selector-top' ).val();
		var bottom = $( '#bulk-action-selector-bottom' ).val();
		var action = ( top && top !== '-1' ) ? top : bottom;
		if ( ( 'trash' === action || 'delete' === action ) && $( 'input[name="post[]"]:checked' ).length ) {
			if ( ! window.confirm( cfg.i18n.confirmBulk ) ) {
				event.preventDefault();
			}
		}
	} );

	/* ----------------------------- Reordering ------------------------------ */

	if ( ! cfg.sortable || ! $.fn.sortable || ! $list.length ) {
		$( '.sff-drag-handle' ).addClass( 'is-disabled' );
		return;
	}

	var $notice = $( '<div class="sff-order-notice" role="status" aria-live="polite"></div>' ).insertBefore( '.wp-list-table' );

	$list.sortable( {
		items: '> tr',
		handle: '.sff-drag-handle',
		axis: 'y',
		cursor: 'move',
		helper: function ( e, tr ) {
			// Keep cell widths while dragging.
			var $cells = tr.children();
			var $helper = tr.clone();
			$helper.children().each( function ( i ) {
				$( this ).width( $cells.eq( i ).width() );
			} );
			return $helper;
		},
		update: function () {
			var ids = [];
			$list.find( '> tr' ).each( function () {
				var id = parseInt( this.id.replace( 'post-', '' ), 10 );
				if ( id ) {
					ids.push( id );
				}
			} );

			$notice.removeClass( 'is-error' ).text( '' );

			$.post( cfg.ajaxUrl, {
				action: 'sff_reorder',
				nonce: cfg.nonce,
				offset: cfg.offset,
				ids: ids
			} ).done( function ( response ) {
				if ( response && response.success ) {
					$notice.text( cfg.i18n.saved );
					// Refresh the visible "Order" column values.
					window.setTimeout( function () { window.location.reload(); }, 400 );
				} else {
					$notice.addClass( 'is-error' ).text( ( response && response.data && response.data.message ) || cfg.i18n.failed );
				}
			} ).fail( function () {
				$notice.addClass( 'is-error' ).text( cfg.i18n.failed );
			} );
		}
	} );
}( jQuery ) );
