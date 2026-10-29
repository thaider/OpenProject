$( function() {
	$( '.op-wp-close' ).click( function(e) {
		var $icon = $(this);
		$icon.toggleClass('fa-check fa-spin fa-spinner');

		new mw.Api().postWithToken( 'csrf', {
			action: 'openproject-close',
			id: $icon.data('id')
		} ).done( function() {
			$icon.parent().addClass('op-wp-closed');
			$icon.remove();
		} ).fail( function( code, result ) {
			$icon.toggleClass('fa-check fa-spin fa-spinner');
			mw.notify( ( new mw.Api() ).getErrorMessage( result ), { type: 'error' } );
		} );
	});
});
