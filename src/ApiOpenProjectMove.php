<?php

namespace MediaWiki\Extension\OpenProject;

use Wikimedia\ParamValidator\ParamValidator;

/**
 * API module to move a work package to the next sprint ("Sprint 12" -> "Sprint 13")
 */
class ApiOpenProjectMove extends ApiOpenProjectBase {

	/**
	 * Name of the sprint following the given one, null if the name does not end in a number
	 */
	public static function nextSprintName( $name ) {
		if( !preg_match( '/^(.*\D)?(\d+)$/', trim( (string)$name ), $matches ) ) {
			return null;
		}
		return $matches[1] . ( (int)$matches[2] + 1 );
	}

	public function execute() {
		$this->checkUser();

		// fetch fresh data instead of relying on the (possibly cached) lockVersion from the page
		$wp = $this->call( 'GET', 'work_packages/' . $this->getParameter( 'id' ) );

		$current = $wp->_links->version->title ?? $wp->_links->targetVersions[0]->title ?? '';
		$next_name = self::nextSprintName( $current );
		if( $next_name === null ) {
			$this->dieWithError( 'openproject-api-nosprint', 'nosprint' );
		}

		$from_href = $wp->_links->version->href ?? $wp->_links->targetVersions[0]->href ?? '';
		$project_href = $wp->_links->project->href;
		$project = substr( $project_href, strrpos( $project_href, '/' ) + 1 );
		$versions = $this->call( 'GET', 'projects/' . $project . '/versions' );
		$next = null;
		foreach( $versions->_embedded->elements ?? [] as $version ) {
			if( $version->name === $next_name ) {
				$next = $version;
				break;
			}
		}
		if( $next === null ) {
			$this->dieWithError( [ 'openproject-api-nonextsprint', $next_name ], 'nonextsprint' );
		}

		// newer OpenProject versions have multiple target versions, "version" is the single legacy link
		$href = '/api/v3/versions/' . $next->id;
		$moved = $this->call( 'PATCH', 'work_packages/' . $wp->id, [], [
			'_links' => [
				'version' => [ 'href' => $href ],
				'targetVersions' => [ [ 'href' => $href ] ],
			],
			'lockVersion' => $wp->lockVersion
		] );

		OpenProjectClient::bumpGeneration();

		// render the item like the lists do, so it can be inserted into the target sprint
		Hooks::enrichWorkPackages( [ $moved ] );
		$sums = Hooks::summarizeWorkPackages( [ $moved ] );
		$html = Hooks::WorkPackageListItem( $moved, [ 'closable' => true, 'version_name' => $next->name ] );

		$this->getResult()->addValue( null, $this->getModuleName(), [
			'id' => (int)$wp->id,
			'version' => (int)$next->id,
			'name' => $next->name,
			'from' => (int)basename( $from_href ),
			'project' => (int)$moved->project_id,
			'projectTitle' => $moved->project,
			'projectHref' => $GLOBALS['wgOpenProjectURL'] . '/projects/' . $moved->project_id . '/backlogs',
			'hours' => $sums['hours']['nochildren'],
			'html' => $html,
		] );
	}

	public function mustBePosted() {
		return true;
	}

	public function needsToken() {
		return 'csrf';
	}

	public function getAllowedParams() {
		return [
			'id' => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => true,
			],
		];
	}

	protected function getExamplesMessages() {
		return [
			'action=openproject-move&id=123&token=123ABC'
				=> 'apihelp-openproject-move-example-1',
		];
	}
}
