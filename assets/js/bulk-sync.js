(function ( $, window, undefined ) {
	'use strict';

	var started = false;

	function done() {
		$( '.bulk-sync-submit' ).text( 'Done' ).attr('disabled','disabled');
	}

	function syncItem( item, next, nonce ) {
		var $item = $( item );
		var $status = $item.find( '.bulk-sync-list-item-status' );
		var id = +$item.data( 'post-id' ); // fetch the post-id and cast to integer

		$status.removeClass( 'pending' ).addClass( 'in-progress' ).text( 'Publishing...' );

		// Send a GET request to ajaxurl, which is WordPress endpoint for AJAX
		// requests. Expects JSON as response.
		$.getJSON(
			ajaxurl,
			{
				action: 'sync_post',
				id: id,
				_ajax_nonce: nonce
			},
			function( res ) {
				if ( res.success ) {
					$status.removeClass( 'in-progress' ).addClass( 'success' ).text( 'Success' );
				} else {
					$status.removeClass( 'in-progress' ).addClass( 'failed' ).text( res.error );
				}
				next();
			},
			function( err ) {
				$status.removeClass( 'in-progress' ).addClass( 'failed' ).text( 'Server Error' );
				next();
			}
		);
	}

	function bulkSync() {
		// Fetch all the li's that must be synced
		var items = $( '.bulk-sync-list-item' );
		// The next function will sync the next item in queue
		var index = -1;
		var next = function () {
			index += 1;
			if ( index < items.length ) {
				syncItem( items.get( index ), next, $( '.bulk-sync-list' ).data( 'nonce' ) );
			} else {
				done();
			}
		};

		// Initial sync
		next();
	}

	$('.bulk-sync-submit').click(function (e) {
		e.preventDefault();

		if ( started ) {
			return;
		}

		started = true;
		bulkSync();
	});

})( jQuery, window );
