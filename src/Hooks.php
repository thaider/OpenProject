<?php

namespace MediaWiki\Extension\OpenProject;

/**
 * Hooks for OpenProject
 *
 * @file
 * @ingroup Extensions
 */
class Hooks {
	protected static $call_api_cache = [];

	static function onGetPreferences( $user, &$preferences ) {
		$preferences['openproject-apikey'] = array(
			'type' => 'text',
			'label-message' => 'openproject-apikey',
			'section' => 'personal/openproject',
			'help-message' => 'openproject-apikey-help'
		);
		return true;
	}


	static function onParserFirstCallInit( \Parser &$parser ) {
		$parser->setFunctionHook( 'opversion', [ self::class, 'Version' ] );
		$parser->setFunctionHook( 'opcurrentversionlink', [ self::class, 'getCurrentVersionLink' ] );
		$parser->setFunctionHook( 'opcurrentversionhours', [ self::class, 'getCurrentVersionHours' ] );
		$parser->setFunctionHook( 'opproject', [ self::class, 'Project' ] );
		$parser->setFunctionHook( 'opprojectinfo', [ self::class, 'Projectinfo' ] );
		$parser->setFunctionHook( 'opversionprojects', [ self::class, 'VersionProjects' ] );
		$parser->setFunctionHook( 'opwp', [ self::class, 'WorkPackage' ] );
		$parser->setFunctionHook( 'optasks', [ self::class, 'Tasks' ] );
		$parser->setFunctionHook( 'opmytasks', [ self::class, 'MyTasks' ] );
		$parser->setFunctionHook( 'op-backlog', [ self::class, 'renderBacklog' ] );
		$parser->setFunctionHook( 'op-storypoints', [ self::class, 'StoryPoints' ] );
		return true;
	}


	/**
	 * Load resources
	 */
	static function onBeforePageDisplay( \OutputPage &$out, \Skin &$skin ) {
		$out->addModules( [ 'ext.openproject' ] );
	}


	static function onResourceLoaderGetConfigVars( array &$vars, string $skin, \Config $config ) {
		$vars['wgOpenProjectURL'] = $config->get( 'OpenProjectURL' );
		return true;
	}


	/**
	 * Show Error
	 *
	 * @param String $error_msg Error message
	 */
	static function renderError( $error_msg, $function = null ) {
		$error_function = !is_null( $function ) ? ( ' <span class="op-error-function">(#op-' . $function . ')</span>' ) : '';
		$error_html = '<span class="op-error">' . $error_msg . $error_function . '</span>';
		return array( $error_html );
	}


	/**
	 * Show Backlog
	 *
	 * @param String $version Backlog version
	 * @param String $name Name of the Backlog version
	 */
	static function renderBacklog( \Parser &$parser ) {
		$options = self::extractOptions( array_slice(func_get_args(), 1) );
		if( !isset( $options['version'] ) ) {
			if( !isset( $options['name'] ) ) {
				return self::renderError( 'name not set', 'backlog' );
			} else {
				$options['version'] = self::getVersionIDFromName( $options['name'] );
			}
		}
		if( !$options['version'] ) {
			return self::renderError( 'version missing', 'backlog' );
		}

		$options['type_id'] = '6,7';
		$options['sortBy'] = ['storyPoints','asc'];
		$work_packages = self::getWorkPackages( $options );

		$options['story_points'] = true;
		$list = self::WorkPackageList( $work_packages, $options );

		return array( $list, 'noparse' => true, 'isHTML' => true );
	}



	/*
	 * Send call to RESTful API
	 *
	 * @param string $url
	 * @param array $params
	 *
	 * @return array code, response
	 *
	 * TODO: check for availability of cURL
	 */
	static function CallAPI( $url, $params = [] ) {
		$hash = md5( json_encode( [ $url, $params ] ) );
		// already in the cash for the current request?
		if( isset( self::$call_api_cache[$hash] ) ) {
			return array( '200', self::$call_api_cache[$hash] );
		}

		$cache_key = $url . '-' . implode('-',$params);
		if( apcu_exists( $cache_key ) ) {
			return apcu_fetch( $cache_key );
		}

		$curl = curl_init();
		$url = $GLOBALS['wgOpenProjectURL'] . '/api/v3/' . $url;
		$url .= '?' . http_build_query( $params );

		$userpwd = 'apikey:' . $GLOBALS['wgUser']->getOption('openproject-apikey');

		curl_setopt( $curl, CURLOPT_URL, $url );
		curl_setopt( $curl, CURLOPT_USERPWD, $userpwd );
		curl_setopt( $curl, CURLOPT_RETURNTRANSFER, true );
		$response = json_decode( curl_exec( $curl ) );
		$code = curl_getinfo( $curl, CURLINFO_HTTP_CODE );
		curl_close( $curl );
		if( $code == '200' ) {
			self::$call_api_cache[$hash] = $response;
		}
		$return = array( $code, $response );

		apcu_store( $cache_key, $return, 60*1);
		return $return;
	}


	/*
	 * Get a property for a specific project
	 * 
	 * @param string $project ID of the project (version filter doesn't work without it)
	 * @param string $property Property to query
	 *
	 * TODO: cache http responses in static variables
	 * TODO: error handling (wrong parameters, non-existing property, link)
	 */
	static function Projectinfo( \Parser $parser, $project = '', $property = '' ) {
		$parser->getOutput()->updateCacheExpiry(0);
		if( $project == '' || $property == '' ) {
			return '<div class="op-warning">Parameter missing.</div>';
		}

		$params = array();
		list( $code, $response ) = self::CallAPI( 'projects/' . $project );

		if( $code != '200' ) {
			return '<div class="op-error">Abfrage fehlgeschlagen (Fehlercode: ' . $code . ')</div>';
		}

		$property_value = $response->$property;
		if( $property == 'description' ) {
			$property_value = $property_value->html;
		}
		$property_HTML = '<span class="op-projectinfo op-property-' . $property . '">' . $property_value . '</span>';

		$href = $GLOBALS['wgOpenProjectURL'] . '/projects/' . $project;
		if( $property == 'link' ) {
			$property_HTML = $href;
		}

		return array( $property_HTML, 'noparse' => true, 'isHTML' => true );
	}


	/*
	 * Show all projects for a specific version
	 * 
	 * @param string $version ID of the version
	 */
	static function VersionProjects( \Parser $parser, $version = '', $format = 'list', $template = '' ) {
		$parser->getOutput()->updateCacheExpiry(0);
		if( $version == '' ) {
			return '<div class="op-warning">Parameter missing.</div>';
		}

		$params = array();
		list( $code, $response ) = self::CallAPI( 'versions/' . $version . '/projects' );

		if( $code != '200' ) {
			return '<div class="op-error">Abfrage fehlgeschlagen (Fehlercode: ' . $code . ')</div>';
		}

		$projects = $response->_embedded->elements;
		$href = $GLOBALS['wgOpenProjectURL'] . '/versions/' . $version;
		$heading = '<p><b>Projects</b> (' . $response->count . ' from ' . $response->total . ' are shown – <a href="' . $href . '">see all on openproject</a>)</p>';
		$list = '<div class="op-version">' . $heading;
		if( $format == 'table' ) {
			$list .= self::ProjectTable( $projects );
		} elseif( $format == 'template' ) {
			return array( self::ProjectTemplateList( $parser, $projects, $template ), 'noparse' => false );
		} else {
			$list .= self::ProjectList( $projects );
		}
		$list .= '</div>';

		return array( $list, 'noparse' => true, 'isHTML' => true );
	}


	/*
	 * Show all work packages for a specific version
	 * 
	 * @param string $project ID of the project (version filter doesn't work without it)
	 * @param string $version ID of the version
	 */
	static function Version( \Parser $parser, $project = '', $version = '' ) {
		$parser->getOutput()->updateCacheExpiry(0);
		if( $project == '' || $version == '' ) {
			return '<div class="op-warning">Parameter missing.</div>';
		}

		$params = array( 'filters' => '[{"version":{"operator":"=","values":["' . $version . '"]}}]' );
		list( $code, $response ) = self::CallAPI( 'projects/' . $project . '/work_packages', $params );

		if( $code != '200' ) {
			return '<div class="op-error">Abfrage fehlgeschlagen (Fehlercode: ' . $code . ')</div>';
		}

		$work_packages = $response->_embedded->elements;
		$href = $GLOBALS['wgOpenProjectURL'] . '/versions/' . $version;
		$heading = '<p><b>Work Packages</b> (' . $response->count . ' from ' . $response->total . ' are shown – <a href="' . $href . '">see all on openproject</a>)</p>';
		$list = '<div class="op-version">' . $heading;
		$list .= self::WorkPackageList( $work_packages, $params );
		$list .= '</div>';

		return array( $list, 'noparse' => true, 'isHTML' => true );
	}


	/*
	 * Get Work packages
	 *
	 * @param Array $options Filter criteria
	 *
	 * @return Array Work package list
	 */
	static function getWorkPackages( $options ) {
		if( !isset( $options['project'] ) || !isset( $options['version'] ) ) {
			return '<div class="op-warning">Parameter missing.</div>';
		}

		$filters = [
			[
				'version' => [
					'operator' => '=',
					'values' => [ $options['version'] ]
				]
			]
		];
		if( isset( $options['closed'] ) && $options['closed'] == false ) {
			$filters[] = [
				'status' => [
					'operator' => '!',
					'values' => [ '10' ]
				]
			];
		}
		if( isset( $options['assignee'] ) ) {
			if( $options['assignee'] != '*' ) {
				$filters[] = [
					'assignee' => [
						'operator' => '=',
						'values' => explode( ',', $options['assignee'] )
					]
				];
			} else {
				$filters[] = [
					'assignee' => [
						'operator' => '*',
						'values' => []
					]
				];
			}
		}
		if( isset( $options['type_id'] ) ) {
			$filters[] = [
				'type_id' => [
					'operator' => '=',
					'values' => explode( ',', $options['type_id'] )
				]
			];
		}

		$params = [
			'filters' => json_encode( $filters ), 
			'pageSize' => 1000
		];

		// sorting
		if( isset( $options['sortBy'] ) ) {
			$params['sortBy'] = json_encode( [ $options['sortBy'] ] );
		}

		$url = 'projects/' . $options['project'] . '/work_packages';
		list( $code, $response ) = self::CallAPI( $url, $params );

		if( $code != '200' ) {
			return '<div class="op-error">Abfrage fehlgeschlagen (Fehlercode: ' . $code . ')</div>';
		}

		$work_packages = $response->_embedded->elements;
		return $work_packages;
	}


	/**
	 * Get versions
	 *
	 * @param Integer $project Specific project
	 * @param Array $params Parameters
	 *
	 * @return Object Versions
	 */
	static function getVersions( $params= [], $project = null ) {
		$url = 'versions';
		$project = self::getProjectID( $project );
		if( !is_null( $project ) ) {
			$url = 'projects/' . $project . '/versions';
		}
		list( $code, $response ) = self::CallAPI( $url, $params );
		if( !is_null( $response ) && property_exists($response, '_embedded') && property_exists($response->_embedded, 'elements') ) {
			$versions = $response->_embedded->elements;
		} else {
			$versions = [];
		}
		return $versions;
	}


	/*
	 * Get all versions and their work packages for a specific date
	 *
	 * @param DateTime $date Date to be looked at
	 * @param $project Specific project
	 * @param Array $params Parameters
	 *
	 * @return Array Versions and their work packages
	 */
	static function getCurrentVersions( $date = null, $project = null, $params = [] ) {
		$future = 7;
		if( is_null( $date ) || $date == '' ) {
			$date = time();
		}
		$versions = self::getVersions( $params, $project );
		usort( $versions, function($a, $b) {
			return $a->startDate <=> $b->startDate;
		});
		$work_packages = [];
		foreach( $versions as $version ) {
			if( 
				!is_null( $version->startDate ) &&
				$version->status == 'open' &&
				strtotime( $version->startDate ) < $date + $future * 60 * 60 * 24 && 
				strtotime( $version->endDate ) + 60 * 60 * 24 > $date &&
				( !isset( $params['name'] ) || preg_match( '/' . $params['name'] . '/', $version->name ) )
			) {
				$project_href = $version->_links->definingProject->href;
				$project = substr( $project_href, strrpos($project_href, '/')+1);
				$work_packages[] = [
					'version' => $version,
					'work_packages' => self::getWorkPackages( array_merge( $params, [ 'project' => $project, 'version' => $version->id ] ) )
				];
			}
		}
		return $work_packages;
	}

	/*
	 * Get the link for the current active version
	 *
	 * @param $project Specific project
	 *
	 * @return String Link to the active version
	 */
	static function getCurrentVersionLink( \Parser &$parser, $project = null ) {
		$params = self::extractOptions( array_slice(func_get_args(), 2) );
		$date = time();
		$future = 7;
		$versions = self::getVersions( $params, $project );
		usort( $versions, function($a, $b) {
			return $a->startDate <=> $b->startDate;
		});
		foreach( $versions as $version ) {
			if( 
				!is_null( $version->startDate ) &&
				$version->status == 'open' &&
				strtotime( $version->startDate ) < $date + $future * 60 * 60 * 24 && 
				strtotime( $version->endDate ) + 60 * 60 * 24 > $date &&
				( !isset( $params['name'] ) || preg_match( '/' . $params['name'] . '/', $version->name ) )
			) {
				return $GLOBALS['wgOpenProjectURL'] . '/versions/' . $version->id . '/';
			}
		}
		return self::renderError( 'No active version found', 'active_version' );
	}


	/*
	 * Get the hours planned for the current active version
	 *
	 * @param $project Specific project
	 *
	 * @return Float Planned hours
	 */
	static function getCurrentVersionHours( \Parser &$parser, $project = null ) {
		$params = self::extractOptions( array_slice(func_get_args(), 2) );
		$date = time();
		$future = 7;
		$versions = self::getVersions( $params, $project );
		usort( $versions, function($a, $b) {
			return $a->startDate <=> $b->startDate;
		});
		foreach( $versions as $version ) {
			if( 
				!is_null( $version->startDate ) &&
				$version->status == 'open' &&
				strtotime( $version->startDate ) < $date + $future * 60 * 60 * 24 && 
				strtotime( $version->endDate ) + 60 * 60 * 24 > $date &&
				( !isset( $params['name'] ) || preg_match( '/' . $params['name'] . '/', $version->name ) )
			) {
				$project_href = $version->_links->definingProject->href;
				$project = substr( $project_href, strrpos($project_href, '/')+1);
				$work_packages = self::getWorkPackages( array_merge( $params, [ 'project' => $project, 'version' => $version->id ] ) );
				self::enrichWorkPackages( $work_packages );
				$sums = self::summarizeWorkPackages( $work_packages );
				return $sums['hours']['nochildren'];
			}
		}
		return self::renderError( 'No active version found', 'active_version' );
	}


	/**
	 * Show all open work packages for a project
	 */
	static function Tasks( \Parser &$parser ) {
		$options = self::extractOptions( array_slice(func_get_args(), 1) );
		$list = self::getVersionsWithWorkPackages( $options );
		return array( $list, 'noparse' => true, 'isHTML' => true );
	}


	/**
	 * Show all my open work packages
	 */
	static function MyTasks( \Parser &$parser ) {
		$options = self::extractOptions( array_slice(func_get_args(), 1) );
		if( !isset( $options['assignee'] ) ) {
			$options['assignee'] = 'me';
		}
		$options['hours'] = true;
		$list = self::getVersionsWithWorkPackages( $options );
		return array( $list, 'noparse' => true, 'isHTML' => true );
	}


	/**
	 * Get Version ID for a version with a specific name
	 *
	 * @param string $name Name of the version
	 */
	static function getVersionIDFromName( $name ) {
		$versions = self::getVersions();
		foreach( $versions as $version ) {
			if( preg_match( '|' . $name . '|', $version->name ) ) {
				return $version->id;
			}
		}
		return false;
	}


	/**
	 * Get Project ID (if explicitly or globally set)
	 *
	 * @param String $project Explicitly set project
	 */
	static function getProjectID( $project ) {
		if( !$project ) {
			$project = $GLOBALS['wgOpenProjectProjectID'] ?? null;
		}
		return $project;
	}


	/*
	 * Get Versions with Work Packages
	 *
	 * @param array $options Options
	 */
	static function getVersionsWithWorkPackages( $options ) {
		//$options['overview'] = true;
		$options['cluster'] = true;
		$project = self::getProjectID( $options['project'] ?? null );
		$date = isset( $options['date'] ) ? strtotime( $options['date'] ) : null;

		$versions = self::getCurrentVersions( $date, $project, $options );
		$list = '';
		if( is_array( $versions ) ) {
			foreach( $versions as $version ) {
				$start_date = !is_null( $version['version']->startDate ) ? new \DateTime( $version['version']->startDate ) : null;
				$end_date = !is_null( $version['version']->endDate ) ? new \DateTime( $version['version']->endDate ) : null;
				if( is_null( $start_date ) ) {
					$interval = is_null( $end_date ) ? '' : '(until ' . $end_date->format('j.n.') . ')';
				} else {
					$interval = is_null( $end_date ) ? ( '(from ' . $start_date->format('j.n.') . ')' ) : ( '(' . $start_date->format('j.n.') . '-' . $end_date->format('j.n.') . ')' );
				}
				$href = $GLOBALS['wgOpenProjectURL'] . '/versions/' . $version['version']->id;
				$list .= '<tr><th colspan="2" class="border-right"><a href="' . $href . '">' . $version['version']->name . '</a> <small>' . $interval . '</small>';
				$version['work_packages'] = self::enrichWorkPackages( $version['work_packages'] );
				$sums = self::summarizeWorkPackages( $version['work_packages'] );
				$list .= ' - <span style="text-transform:none">' . $sums['hours']['nochildren'] . 'h verbleibend</span>';
				$list .= '</th><th class="semorg-showedit"></th></tr>';
				$list .= self::WorkPackageList( $version['work_packages'], $options );
			}
		}
		$list = '<div class="semorg-list"><div class="semorg-list-table"><table class="table table-bordered table-sm">' . $list . '</table></div></div>';
		return $list;
	}


	/*
	 * Show all work packages for a specific project
	 * 
	 * @param string $project ID of the project
	 */
	static function Project( \Parser $parser, $project = '' ) {
		$parser->getOutput()->updateCacheExpiry(0);
		if( $project == '' ) {
			return '<div class="op-warning">No parameter has been set.</div>';
		}

		$params = [];
		list( $code, $response ) = self::CallAPI( 'projects/' . $project . '/work_packages', $params );

		if( $code != '200' ) {
			return '<div class="op-error">Abfrage fehlgeschlagen (Fehlercode: ' . $code . ')</div>';
		}

		$work_packages = $response->_embedded->elements;
		$href = $GLOBALS['wgOpenProjectURL'] . '/projects/' . $project;
		$heading = '<p><b>Work Packages</b> (' . $response->count . ' from ' . $response->total . ' are shown – <a href="' . $href . '">see all on openproject</a>)</p>';
		$list = '<div class="op-version">' . $heading;
		$list .= self::WorkPackageList( $work_packages, $params );
		$list .= '</div>';

		return array( $list, 'noparse' => true, 'isHTML' => true );
	}


	/**
	 * Return Story Points for a project and version
	 */
	static function StoryPoints( \Parser $parser ) {
		$parser->getOutput()->updateCacheExpiry(0);
		$options = self::extractOptions( array_slice(func_get_args(), 1) );

		if( !isset( $options['version'] ) ) {
			if( !isset( $options['name'] ) ) {
				return self::renderError( 'name not set', 'storypoints' );
			} else {
				$options['version'] = self::getVersionIDFromName( $options['name'] );
			}
		}
		if( !$options['version'] ) {
			return self::renderError( 'version missing', 'storypoints' );
		}

		$project = $options['project'] ?? false;
		$options['project'] = 1;

		$options['type_id'] = '6,7';
		$work_packages = self::getWorkPackages( $options );

		self::enrichWorkPackages( $work_packages );

		if( $project ) {
			$cluster = self::clusterWorkPackagesByProject( $work_packages );
			$story_points = isset( $cluster[$project] ) ?  $cluster[$project]['storyPoints'] : 0;
		} else {
			$sums = self::summarizeWorkPackages( $work_packages );
			$story_points = $sums['storyPoints'];
		}

		return [ $story_points ];
	}


	/*
	 * Show work package
	 * 
	 * @param string $wp ID of the work package
	 */
	static function WorkPackage( \Parser $parser, $wp = '' ) {
		$parser->getOutput()->updateCacheExpiry(0);
		if( $wp == '' ) {
			return '<div class="op-warning">No parameter has been set.</div>';
		}

		$params = array();
		list( $code, $response ) = self::CallAPI( '/work_packages/' . $wp, $params );

		if( $code != '200' ) {
			return '<div class="op-error">Abfrage fehlgeschlagen (Fehlercode: ' . $code . ')</div>';
		}

		$status = $response->_embedded->status->name;
		$closed = $response->_embedded->status->isClosed;
		$href = $GLOBALS['wgOpenProjectURL'] . '/work_packages/' . $wp;
		$classes = ['op-wp'];
		if( $closed ) {
			$classes[] = 'op-wp-closed';
		}
		$wp_link = '<span class="' . implode( ' ', $classes ) . '"><a href="' . $href . '" target="_blank">#' . $wp . '</a></span>';

		return array( $wp_link, 'noparse' => true, 'isHTML' => true );
	}


	/*
	 * Create a html list of projects
	 *
	 * @param array $projects
	 *
	 * @return string HTML code for project list
	 */
	static function ProjectList( $projects ) {
		$list = '<ul class="op-pr-list">';

		foreach( $projects as $project ) {
			$href = $GLOBALS['wgOpenProjectURL'] . '/projects/' . $project->id;
			$link = '<a href="' . $href . '" target="_blank">' . $project->name . '</a>';
			if( $project->description != '' ) {
				$link .= '<div class="op-pr-desc">' . $project->description . '</div>';
			}
			$list .= '<li class="op-pr-listitem">' . $link . '</li>';
		}

		$list .= '</ul>';
		return $list;
	}


	/*
	 * Create a html table of projects
	 *
	 * @param array $projects
	 *
	 * @return string HTML code for project table
	 */
	static function ProjectTable( $projects ) {
		$table = '<table class="table table-bordered table-condensed sortable"><tr><th>ID</th><th>Projekt</th><th>Beschreibung</th></tr>';

		foreach( $projects as $project ) {
			$href = $GLOBALS['wgOpenProjectURL'] . '/projects/' . $project->id;
			$link = '<a href="' . $href . '" target="_blank">' . $project->name . '</a>';
			$table .= '<tr><td>' . $project->id . '</td><td>' . $link . '</td><td>' . $project->description . '</td></tr>';
		}

		$table .= '</table>';
		return $table;
	}


	/*
	 * Create a template list of projects
	 *
	 * @param array $projects
	 *
	 * @return string HTML code for project list
	 */
	static function ProjectTemplateList( $parser, $projects, $template ) {
		$templatelist = '';

		foreach( $projects as $project ) {
			$href = $GLOBALS['wgOpenProjectURL'] . '/projects/' . $project->id;
			$link = '<a href="' . $href . '" target="_blank">' . $project->name . '</a>';
			$name = $project->name;
			$id = $project->id;
			$description = $project->description->html;
			$templatelist .= '{{' . $template . '|op-href=' . $href . '|op-id=' . $id . '|op-name=' . $name . '|op-description=' . $description . '|op-link=' . $link . '}}';
		}

		return $templatelist;
	}


	/*
	 * Create a html list of work packages
	 *
	 * @param array $work_packages
	 * @param array $options Options
	 *
	 * @return string HTML code for work package list
	 */
	static function WorkPackageList( $work_packages, $options ) {
		$list = '';

		self::enrichWorkPackages( $work_packages );
		$sums = self::summarizeWorkPackages( $work_packages );

		if( isset( $options['cluster'] ) && $options['cluster'] ) {
			$cluster = self::clusterWorkPackagesByProject( $work_packages );

			foreach( $cluster as $id => &$project ) {
				$cluster_hours = self::summarizeWorkPackages( $project['work_packages'] );
				$cluster_heading = '<a href="' . $project['href'] . '">' . $project['title'] . '</a>' . ( isset( $options['story_points'] ) && $options['story_points'] ? ' <small>(' . $project['storyPoints'] . ')</small>' : '' );
				$cluster_heading_template = \Title::newFromText( 'Template:op-cluster-heading' );
				if( $cluster_heading_template->exists() ) {
					$cluster_heading = $GLOBALS['wgParser']->recursiveTagParse( '{{op-cluster-heading
						|href=' . $project['href'] . '
						|title=' . $project['title'] . '
						|story_points=' . $project['storyPoints'] . '
						|id=' . $id . '
				}}' );
				}
				$list .= '<tr><td style="font-size:small">' . $cluster_heading . '</td>';

				$work_package_list = '';
				foreach( $project['work_packages'] as $package ) {
					// exclude work packages with children
					if( isset( $package->_links->children ) ) {
				 		continue;		
					}
					$link = self::WorkPackageListItem( $package, $options );

					if( $package->closed ) {
						$work_package_list .= $link;
					} else {
						$work_package_list = $link . $work_package_list;
					}
				}

				$list .= '<td style="font-size:small" class="border-right"><ul class="op-wp-list">' . $work_package_list . '</ul></td><td class="semorg-showedit"></td></tr>';
			}
		} else {
			foreach( $work_packages as $package ) {
				$link = self::WorkPackageListItem( $package, $options );

				if( $package->closed ) {
					$list .= $link;
				} else {
					$list = $link . $list;
				}
			}
			$list = '<ul class="op-wp-list">' . $list . '</ul>';
		}

		if( isset( $options['overview'] ) && $options['overview'] ) {
			if( isset( $options['story_points'] ) && $options['story_points'] ) {
				$list .= wfMessage( 'openproject-overview-storypoints', $sums['storyPoints'] );
			} else {
				$list .= wfMessage( 'openproject-estimated' ) . ': ' . $sums['hours']['open']['estimated'] . 'h, ' . wfMessage( 'openproject-remaining' ) . ': ' . $sums['hours']['open']['remaining'] . 'h, ' . wfMessage( 'openproject-completed' ) . ': ' . ($sums['hours']['open']['estimated']-$sums['hours']['open']['remaining']) . 'h';
			}
		}
		return $list;
	}


	/**
	 * Get totals for hours, story points etc. for work packages
	 *
	 * @param Array $work_packages
	 *
	 * @return Array Sums
	 */
	static function summarizeWorkPackages( $work_packages,  ) {
		$hours = [
			'open' => [
				'remaining' => 0,
				'estimated' => 0
			],
			'closed' => [
				'remaining' => 0,
				'estimated' => 0
			],
			'total' => [
				'remaining' => 0,
				'estimated' => 0
			],
			'nochildren' => 0 // remaining hours plus story points (if a feature has no children defined)
		];

		$storyPoints = 0;

		foreach( $work_packages as &$package ) {
			foreach( [ 'total', $package->closed ? 'closed' : 'open' ] as $status ) {
				$hours[$status]['remaining'] += $package->hours['remaining'];
				$hours[$status]['estimated'] += $package->hours['estimated'];
			}

			if( !isset( $package->_links->children ) && !$package->closed ) {
				if( $package->hours['estimated'] > 0 ) {
					$hours['nochildren'] += $package->hours['remaining'];
				} else {
					$hours['nochildren'] += $package->storyPoints ?? 0;
				}
			}

			$storyPoints += $package->storyPoints ?? 0;
		}

		return [
			'hours' => $hours,
			'storyPoints' => $storyPoints
		];
	}


	/**
	 * Enrich work packages with useful extracted information
	 *
	 * @param Array $work_packages
	 *
	 * @return Array Enriched work packages
	 */
	static function enrichWorkPackages( $work_packages ) {
		foreach( $work_packages as $package ) {
			$package->project = $package->_links->project->title;
			$package->project_id = explode( '/', $package->_links->project->href );
			$package->project_id = end( $package->project_id );

			$status_href = $package->_links->status->href;
			$package->status_id = substr( $status_href, strrpos( $status_href, '/' ) + 1 );

			$package->href = $GLOBALS['wgOpenProjectURL'] . '/work_packages/' . $package->id;

			// TODO: make statuses considered "closed" configurable
			$package->closed = $package->status_id === '10';

			$package->hours = [
				'estimated' => is_null( $package->estimatedTime ) ? 0 : ((new \DateInterval($package->estimatedTime))->format('%h') + (new \DateInterval($package->estimatedTime))->format('%i')/60),
				'derivedEstimated' => is_null( $package->derivedEstimatedTime ) ? 0 : ((new \DateInterval($package->derivedEstimatedTime))->format('%h') + (new \DateInterval($package->derivedEstimatedTime))->format('%i')/60),
				'remaining' => is_null( $package->remainingTime ) ? 0 : ((new \DateInterval($package->remainingTime))->format('%h') + (new \DateInterval($package->remainingTime))->format('%i')/60)
			];
		}
		return $work_packages;
	}


	/**
	 * Cluster work packages by project
	 *
	 * @param Array $work_packages
	 *
	 * @return Array Clustered work packages
	 */
	static function clusterWorkPackagesByProject( $work_packages ) {
		$cluster = [];
		foreach( $work_packages as $package ) {
			if( !isset( $cluster[$package->project_id] ) ) {
				$href = $GLOBALS['wgOpenProjectURL'] . '/projects/' . $package->project_id . '/backlogs';

				$cluster[$package->project_id] = [
					'title' => $package->project,
					'href' => $href,
					'hours' => [
						'open' => [
							'estimated' => 0,
							'derivedEstimated' => 0,
							'remaining' => 0
						],
						'closed' => [
							'estimated' => 0,
							'derivedEstimated' => 0,
							'remaining' => 0
						],
					],
					'storyPoints' => 0,
					'work_packages' => []
				];
			}
			$cluster[$package->project_id]['hours'][$package->closed ? 'closed' :'open']['estimated'] += is_null( $package->estimatedTime ) ? 0 : (new \DateInterval($package->estimatedTime))->format('%h');
			$cluster[$package->project_id]['hours'][$package->closed ? 'closed' :'open']['derivedEstimated'] += is_null( $package->derivedEstimatedTime ) ? 0 : (new \DateInterval($package->derivedEstimatedTime))->format('%h');
			$cluster[$package->project_id]['hours'][$package->closed ? 'closed' :'open']['remaining'] += is_null( $package->remainingTime ) ? 0 : (new \DateInterval($package->remainingTime))->format('%h');
			$cluster[$package->project_id]['storyPoints'] += $package->storyPoints ?? 0;
			$cluster[$package->project_id]['work_packages'][] = $package;
		}
		return $cluster;
	}


	/*
	 * Create a html list for work package list item
	 *
	 * @param StdObject $package
	 * @param array $options Options
	 *
	 * @return string HTML code for work package list item
	 */
	static function WorkPackageListItem( $package, $options ) {
		$link = '<a href="' . $package->href . '" target="_blank" class="op-wp-link">' . $package->subject . '</a>';

		if( isset( $package->_links->assignee->title ) 
			&& $package->_links->assignee->title != '' 
			&& ( !isset( $options['assignee'] ) || $options['assignee'] != 'me' )
		) {
			$link .= ' <span class="op-wp-project">(' . $package->_links->assignee->title . ')</span> ';
		}

		if( isset( $options['story_points'] ) && $options['story_points'] ) {
			$link .= ' <small class="op-wp-storypoints">(' . $package->storyPoints . ')</small> ';
		}
		if( isset( $options['hours'] ) && !$package->closed ) {
			$hours = ( $package->hours['estimated'] > 0 ) ? $package->hours['remaining'] : ( $package->storyPoints ?? 0 );
			$link .= ' <small class="op-wp-hours">(' . $hours . ')</small> ';
		}

	/*	if( $package->description->raw != '' ) {
			$link .= '<div class="op-wp-desc">' . $package->description->raw . '</div>';
		}
	 */	
		if( isset( $options['closable'] ) && !$package->closed ) {
			$link .= ' <i class="fa fa-check op-wp-close" data-toggle="tooltip" data-id="' . $package->id . '" data-lock="' . $package->lockVersion . '" title="' . wfMessage('openproject-close-workpackage')->text() . '"></i>';
		}

		$link = '<li class="op-wp-listitem op-wp-status-' . $package->status_id . ( $package->closed ? ' op-wp-closed' : '' ) . '">' . $link . '</li>';
		return $link;
	}


	/**
	 * Converts an array of values in form [0] => "name=value" into a real
	 * associative array in form [name] => value. If no = is provided,
	 * true is assumed like this: [name] => true
	 * taken from https://www.mediawiki.org/wiki/Manual:Parser_functions#Named_parameters
	 *
	 * @param array string $options
	 *
	 * @return array $results
	 */
	static function extractOptions( array $options ) {
		$results = array();

		foreach ( $options as $option ) {
			$pair = explode( '=', $option, 2 );
			if ( count( $pair ) === 2 ) {
				$name = trim( $pair[0] );
				$value = trim( $pair[1] );
				// don't store empty values
				if( $value !== '' ) {
					$results[$name] = $value;
				}
			}

			if ( count( $pair ) === 1 ) {
				$name = trim( $pair[0] );
				if( $name !== '' ) {
					$results[$name] = true;
				}
			}
		}

		return $results;
	}
}
