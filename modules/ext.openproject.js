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
		this.storyPoints = new OO.ui.NumberInputWidget( { min: 0, step: 1, value: 1 } );
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
		field( this.storyPoints, 'openproject-create-storypoints' );
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
		this.actions.setAbilities( { save: !!( this.project || this.projectSelect.getValue() ) } );
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
			dialog.storyPoints.setValue( meta.defaultStoryPoints );
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
				storypoints: dialog.storyPoints.getValue(),
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

	$( '.op-wp-add' ).click( function() {
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
