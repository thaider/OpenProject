$( function() {
	$( document ).on( 'click', '.op-wp-close', function(e) {
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

	/**
	 * Change the remaining hours shown in a sprint header
	 */
	function addVersionHours( version, hours ) {
		var $hours = $( 'th[data-version="' + version + '"] .op-version-hours' );
		if ( $hours.length ) {
			$hours.text( Math.round( ( parseFloat( $hours.text() ) + hours ) * 100 ) / 100 );
		}
	}

	/**
	 * Put the moved item into the list of its new sprint, where a reload would show it:
	 * open items first (newest first), closed ones after them.
	 */
	function insertMovedItem( data ) {
		var $item = $( data.html );
		var $add = $( 'li.op-wp-add-item[data-version="' + data.version + '"][data-project="' + data.project + '"]' );

		if ( $add.length ) {
			var $list = $add.parent();
			var $before = $list.children( 'li.op-wp-listitem' ).filter( function() {
				var $li = $( this );
				return $li.hasClass( 'op-wp-closed' ) || $li.data( 'id' ) < data.id;
			} ).first();
			if ( $before.length ) {
				$item.insertBefore( $before );
			} else {
				$item.insertBefore( $add );
			}
			return;
		}

		// the project has no tasks in the sprint yet: add a row above the sprint's "+" row
		var $row = $( 'a.op-wp-add[data-version="' + data.version + '"]' ).not( '[data-project]' ).closest( 'tr' );
		if ( $row.length ) {
			var $newAdd = $( '<li>' ).addClass( 'op-wp-add op-wp-add-item' )
				.attr( { title: mw.msg( 'openproject-add-task' ), 'data-version': data.version, 'data-project': data.project } );
			var $project = $( '<tr>' ).append(
				$( '<td>' ).css( 'font-size', 'small' ).append( $( '<a>' ).attr( 'href', data.projectHref ).text( data.projectTitle ) ),
				$( '<td>' ).addClass( 'border-right' ).css( 'font-size', 'small' ).append( $( '<ul>' ).addClass( 'op-wp-list' ).append( $item, $newAdd ) ),
				$( '<td>' ).addClass( 'semorg-showedit' )
			);
			$row.before( $project );
		}
	}

	$( document ).on( 'click', '.op-wp-move', function(e) {
		var $icon = $(this);
		$icon.toggleClass('fa-arrow-right fa-spin fa-spinner');

		new mw.Api().postWithToken( 'csrf', {
			action: 'openproject-move',
			id: $icon.data('id')
		} ).done( function( response ) {
			var data = response[ 'openproject-move' ];
			$icon.parent().remove();
			addVersionHours( data.from, -data.hours );
			addVersionHours( data.version, data.hours );
			insertMovedItem( data );
		} ).fail( function( code, result ) {
			$icon.toggleClass('fa-arrow-right fa-spin fa-spinner');
			mw.notify( ( new mw.Api() ).getErrorMessage( result ), { type: 'error' } );
		} );
	});

	/**
	 * Dialog for creating a work package. Without a project, the project can be picked.
	 */
	function CreateTaskDialog( config ) {
		CreateTaskDialog.super.call( this, config );
		this.project = config.project;
		this.version = config.version;
		this.versionName = config.versionName;
	}
	OO.inheritClass( CreateTaskDialog, OO.ui.ProcessDialog );
	CreateTaskDialog.static.name = 'openprojectCreateTask';
	CreateTaskDialog.static.title = mw.msg( 'openproject-create-title' );
	CreateTaskDialog.static.size = 'medium';
	CreateTaskDialog.static.actions = [
		{ action: 'save', label: mw.msg( 'openproject-create-submit' ), flags: [ 'primary', 'progressive' ] },
		{ label: mw.msg( 'openproject-create-cancel' ), flags: 'safe' }
	];

	function options( list ) {
		return list.map( function( item ) {
			return { data: String( item.id ), label: item.name };
		} );
	}

	CreateTaskDialog.prototype.initialize = function() {
		CreateTaskDialog.super.prototype.initialize.call( this );

		this.subject = new OO.ui.TextInputWidget( { required: true } );
		this.description = new OO.ui.MultilineTextInputWidget( { rows: 4, autosize: true } );
		this.projectSelect = new OO.ui.DropdownInputWidget( { $overlay: this.$overlay } );
		this.assignee = new OO.ui.DropdownInputWidget( { $overlay: this.$overlay } );
		this.estimatedTime = new OO.ui.NumberInputWidget( { min: 0, step: 0.5, value: 1 } );
		this.versionSelect = new OO.ui.DropdownInputWidget( { $overlay: this.$overlay } );

		var fields = [];
		function field( widget, msg ) {
			var f = new OO.ui.FieldLayout( widget, { label: mw.msg( msg ), align: 'top' } );
			fields.push( f );
			return f;
		}
		this.projectField = field( this.projectSelect, 'openproject-create-project' );
		this.projectField.toggle( !this.project );
		field( this.subject, 'openproject-create-subject' );
		field( this.description, 'openproject-create-description' );
		field( this.assignee, 'openproject-create-assignee' );
		field( this.estimatedTime, 'openproject-create-estimatedtime' );
		field( this.versionSelect, 'openproject-create-version' );

		this.projectSelect.on( 'change', this.loadProject.bind( this ) );
		this.projectSelect.on( 'change', this.updateSaveAbility.bind( this ) );

		this.content = new OO.ui.PanelLayout( { padded: true, expanded: false } );
		this.content.$element.append( fields.map( function( f ) { return f.$element; } ) );
		this.$body.append( this.content.$element );
	};

	/**
	 * Saving needs a project
	 */
	CreateTaskDialog.prototype.updateSaveAbility = function() {
		var hasProject = !!( this.project || this.projectSelect.getValue() );
		this.actions.setAbilities( { save: hasProject } );
		// the version list stays empty without a project, so it is not usable either
		this.versionSelect.setDisabled( !hasProject );
	};

	CreateTaskDialog.prototype.getBodyHeight = function() {
		return this.content.$element.outerHeight( true );
	};

	CreateTaskDialog.prototype.getSetupProcess = function( data ) {
		var dialog = this;
		return CreateTaskDialog.super.prototype.getSetupProcess.call( this, data ).next( function() {
			var api = new mw.Api(), request;
			dialog.updateSaveAbility();
			if ( dialog.project ) {
				request = dialog.loadProject();
			} else {
				request = api.get( { action: 'openproject-meta', mode: 'projects' } ).then( function( result ) {
					var projects = result[ 'openproject-meta' ].projects;
					dialog.projectSelect.setOptions( [ { data: '', label: mw.msg( 'openproject-create-project-select' ) } ].concat( options( projects ) ) );
					dialog.projectSelect.setValue( '' );
					// preselect the current user until a project (and its assignees) is chosen
					dialog.assignee.setOptions( [ { data: String( result[ 'openproject-meta' ].me ), label: result[ 'openproject-meta' ].meName } ] );
					dialog.assignee.setValue( String( result[ 'openproject-meta' ].me ) );
				} );
			}
			dialog.pushPending();
			// a failed load is reported but must not abort the setup (and thereby the dialog)
			return request.then( null, function() {
				mw.notify( mw.msg( 'openproject-create-loading-failed' ), { type: 'error' } );
			} ).always( function() {
				dialog.popPending();
			} );
		} );
	};

	/**
	 * Load versions and assignees of the selected project
	 */
	CreateTaskDialog.prototype.loadProject = function() {
		var dialog = this,
			project = this.project || this.projectSelect.getValue(),
			requestId = ( this.loadRequestId = ( this.loadRequestId || 0 ) + 1 );
		if ( !project ) {
			return $.Deferred().resolve().promise();
		}
		return new mw.Api().get( { action: 'openproject-meta', mode: 'project', id: project } ).then( function( result ) {
			if ( requestId !== dialog.loadRequestId ) {
				// the project was changed meanwhile, this response is outdated
				return;
			}
			var meta = result[ 'openproject-meta' ];
			var assignees = options( meta.assignees );
			if ( !assignees.some( function( a ) { return a.data === String( meta.me ); } ) ) {
				assignees.unshift( { data: String( meta.me ), label: meta.meName } );
			}
			dialog.assignee.setOptions( assignees );
			dialog.assignee.setValue( String( meta.me ) );
			var versions = options( meta.versions );
			var hasVersion = versions.some( function( v ) { return v.data === String( dialog.version ); } );
			// with a fixed project the preselected sprint must stay selectable even if the version list lacks it;
			if ( dialog.version && !hasVersion && dialog.project ) {
				versions.unshift( { data: String( dialog.version ), label: dialog.versionName || '#' + dialog.version } );
				hasVersion = true;
			}
			dialog.versionSelect.setOptions( [ { data: '', label: '-' } ].concat( versions ) );
			dialog.versionSelect.setValue( hasVersion ? String( dialog.version ) : '' );
			dialog.estimatedTime.setValue( meta.defaultEstimatedHours );
		} );
	};

	CreateTaskDialog.prototype.getActionProcess = function( action ) {
		var dialog = this;
		if ( action !== 'save' ) {
			return CreateTaskDialog.super.prototype.getActionProcess.call( this, action );
		}
		return new OO.ui.Process( function() {
			var api = new mw.Api(),
				subject = dialog.subject.getValue().trim();
			if ( !subject ) {
				dialog.subject.focus();
				return $.Deferred().reject( new OO.ui.Error( mw.msg( 'openproject-create-subject-missing' ), { recoverable: true } ) ).promise();
			}
			dialog.pushPending();
			return api.postWithToken( 'csrf', {
				action: 'openproject-create',
				project: dialog.project || dialog.projectSelect.getValue(),
				subject: subject,
				description: dialog.description.getValue(),
				assignee: dialog.assignee.getValue(),
				estimatedtime: dialog.estimatedTime.getValue(),
				version: dialog.versionSelect.getValue()
			} ).then( function( result ) {
				// reload so the new task shows up in the list
				dialog.close( { reload: true } );
				return dialog.closing;
			}, function( code, result ) {
				dialog.popPending();
				return $.Deferred().reject( new OO.ui.Error( api.getErrorMessage( result ), { recoverable: true } ) ).promise();
			} );
		} );
	};

	CreateTaskDialog.prototype.getTeardownProcess = function( data ) {
		return CreateTaskDialog.super.prototype.getTeardownProcess.call( this, data ).next( function() {
			if ( data && data.reload ) {
				location.reload();
			}
		} );
	};

	// one window manager for all "+" buttons
	var windowManager;

	$( document ).on( 'click', '.op-wp-add', function() {
		var $button = $( this );
		if ( !windowManager ) {
			windowManager = new OO.ui.WindowManager();
			$( document.body ).append( windowManager.$element );
		}
		var dialog = new CreateTaskDialog( {
			project: $button.data( 'project' ) || null,
			version: $button.data( 'version' ) || '',
			versionName: $button.data( 'version-name' ) || ''
		} );
		windowManager.addWindows( [ dialog ] );
		windowManager.openWindow( dialog ).closed.then( function() {
			windowManager.clearWindows();
		} );
	} );
});
