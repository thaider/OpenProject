$( function() {
	var url = mw.config.get( 'wgOpenProjectURL' ) + '/api/v3/work_packages';
	var password = mw.user.options.get( 'openproject-apikey' );

	$( '.op-wp-close' ).click( function(e) {
		$(this).toggleClass('fa-check fa-spin fa-spinner');
			var jqxhr = $.ajax({
				url: url + '/' + $(this).data('id'),
				username: 'apikey',
				password: password,
				context: $(this),
				type: 'PATCH',
				contentType: 'application/json',
				data: JSON.stringify( {
					_links: {
						status: {
							href: "/api/v3/statuses/10"
						}
					},
					lockVersion: $(this).data('lock')
				} )
			}).done( function(data) {
				$(this).parent().addClass('op-wp-closed');
				$(this).remove();
				if( typeof data._embedded.parent != 'undefined' ) {
					$.ajax({
						url: url + '/' + data._embedded.parent.id,
						username: 'apikey',
						password: password
					}).done( function(data) {
						$.ajax({
							url: url,
							username: 'apikey',
							password: password,
							contentType: 'application/json',
							data: {
								filters: JSON.stringify( [{ "parent": { "operator": "=", "values": [data.id] }},{ "status_id": { "operator": "o", "values": null }}] )
							}
						}).done( function(children) {
							if( children.count == 0 ) {
								$.ajax({
									url: url + '/' + data.id,
									username: 'apikey',
									password: password,
									type: 'PATCH',
									contentType: 'application/json',
									data: JSON.stringify( {
										_links: {
											status: {
												href: "/api/v3/statuses/10"
											}
										},
										lockVersion: data.lockVersion
									} )
								}).done( function(data) {
									//console.log(data);
								});
							}
						});
					});
				}
			});
	});

});
