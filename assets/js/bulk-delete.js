(function ( $, window, undefined ) {
	'use strict';

	var started = false;

	function done() {
		$( '.bulk-delete-submit' ).text( 'Done' ).attr('disabled','disabled');
	}

	function deleteItem( item, next, nonce ) {
		var $item = $( item );
		var $status = $item.find( '.bulk-delete-list-item-status' );
		var id = +$item.data( 'post-id' ); // fetch the post-id and cast to integer

		$status.removeClass( 'pending' ).addClass( 'in-progress' ).text( 'Deleting...' );

		// Send a GET request to ajaxurl, which is WordPress endpoint for AJAX
		// requests. Expects JSON as response.
		$.getJSON(
			ajaxurl,
			{
				action: 'delete_post',
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

	function bulkDelete() {
		// Fetch all the li's that must be deleted
		var items = $( '.bulk-delete-list-item' );
		// The next function will delete the next item in queue
		var index = -1;
		var next = function () {
			index += 1;
			if ( index < items.length ) {
				deleteItem( items.get( index ), next, $( '.bulk-delete-list' ).data( 'nonce' ) );
			} else {
				done();
			}
		};

		// Initial delete
		next();
	}

	$('.bulk-delete-submit').click(function (e) {
		e.preventDefault();

		if ( started ) {
			return;
		}

		started = true;
		bulkDelete();
	});

})( jQuery, window );
