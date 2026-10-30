$( function( mw ) {
	var url = mw.config.get( 'wgOpenProjectURL' ) + '/api/v3/work_packages';
	var password = mw.user.options.get( 'openproject-apikey' );

	$( '.op-wp-close' ).click( function(e) {
		$(this).toggleClass('fa-check fa-spin fa-spinner');
		var api = new mw.Api();
		var $spinner = $(this);
		api.get({
			action: 'opclose',
			id: $(this).data('id'),
			lockVersion: $(this).data('lock'),
			format: 'json'
		}).done(function( data, status ) {
			$spinner.tooltip('hide');
			$spinner.parent().addClass('op-wp-closed');
			$spinner.remove();
		});

		return false;
	});

	$( '.op-wp-move' ).click( function(e) {
		$(this).toggleClass('fa-check fa-spin fa-spinner');
		var api = new mw.Api();
		var $spinner = $(this);
		api.get({
			action: 'opmove',
			id: $(this).data('id'),
			targetVersion: $(this).data('targetversion'),
			lockVersion: $(this).data('lock'),
			format: 'json'
		}).done(function( data, status ) {
			$spinner.tooltip('hide');
			$spinner.parent().remove();
		});

		return false;
	});
}( mediaWiki ));
