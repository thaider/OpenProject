<?php

namespace MediaWiki\Extension\OpenProject;

use Wikimedia\ParamValidator\ParamValidator;

/**
 * API module to create a work package in OpenProject
 */
class ApiOpenProjectCreate extends ApiOpenProjectBase {

	public function execute() {
		$this->checkUser();

		$project = $this->getParameter( 'project' );
		$assignee = $this->getParameter( 'assignee' );
		$version = $this->getParameter( 'version' );
		$type = $this->getParameter( 'type' ) ?: $this->getTaskType( $project );
		$description = $this->getParameter( 'description' );

		$body = [
			'subject' => $this->getParameter( 'subject' ),
			'storyPoints' => $this->getParameter( 'storypoints' ),
			'_links' => [
				// OpenProject resolves "me" from the API token
				'assignee' => [ 'href' => '/api/v3/users/' . ( $assignee ?: 'me' ) ],
			],
		];
		if( $description !== '' ) {
			$body['description'] = [ 'format' => 'markdown', 'raw' => $description ];
		}
		if( $version ) {
			// newer OpenProject versions have multiple target versions, "version" is the single legacy link
			$href = '/api/v3/versions/' . $version;
			$body['_links']['version'] = [ 'href' => $href ];
			$body['_links']['targetVersions'] = [ [ 'href' => $href ] ];
		}
		if( $type ) {
			$body['_links']['type'] = [ 'href' => '/api/v3/types/' . $type ];
		}

		$wp = $this->call( 'POST', 'projects/' . $project . '/work_packages', [], $body );

		if( $version && empty( $wp->_links->version->href ) && empty( $wp->_links->targetVersions ) ) {
			// OpenProject accepted the request but dropped the version
			$this->addWarning( [ 'openproject-create-noversion' ] );
		}

		OpenProjectClient::bumpGeneration();
		$this->getResult()->addValue( null, $this->getModuleName(), [
			'id' => (int)$wp->id,
			'href' => $GLOBALS['wgOpenProjectURL'] . '/work_packages/' . $wp->id,
		] );
	}

	/**
	 * Tasks are always of the type "Aufgabe" (or "Task"), unless configured otherwise
	 */
	protected function getTaskType( $project ) {
		$default = (int)( $GLOBALS['wgOpenProjectDefaultTypeID'] ?? 0 );
		if( $default ) {
			return $default;
		}
		$types = $this->call( 'GET', 'projects/' . $project . '/types', [], null, true );
		foreach( self::idNameList( $types ) as $type ) {
			if( in_array( mb_strtolower( $type['name'] ), [ 'aufgabe', 'task' ] ) ) {
				return $type['id'];
			}
		}
		return 0;
	}

	public function mustBePosted() {
		return true;
	}

	public function needsToken() {
		return 'csrf';
	}

	public function getAllowedParams() {
		return [
			'project' => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'subject' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'description' => [
				ParamValidator::PARAM_TYPE => 'text',
				ParamValidator::PARAM_DEFAULT => '',
			],
			'assignee' => [
				ParamValidator::PARAM_TYPE => 'integer',
			],
			'storypoints' => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_DEFAULT => $GLOBALS['wgOpenProjectDefaultStoryPoints'] ?? 1,
			],
			'version' => [
				ParamValidator::PARAM_TYPE => 'integer',
			],
			'type' => [
				ParamValidator::PARAM_TYPE => 'integer',
			],
		];
	}

	protected function getExamplesMessages() {
		return [
			'action=openproject-create&project=1&subject=Example&token=123ABC'
				=> 'apihelp-openproject-create-example-1',
		];
	}
}
