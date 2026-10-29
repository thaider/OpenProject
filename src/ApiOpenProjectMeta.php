<?php

namespace MediaWiki\Extension\OpenProject;

use Wikimedia\ParamValidator\ParamValidator;

/**
 * API module providing the options for the "create work package" form
 */
class ApiOpenProjectMeta extends ApiOpenProjectBase {

	public function execute() {
		$this->checkUser();

		$id = $this->getParameter( 'id' );
		if( $this->getParameter( 'mode' ) === 'projects' ) {
			$projects = $this->call( 'GET', 'projects', [ 'pageSize' => 1000 ], null, true );
			$me = $this->call( 'GET', 'users/me', [], null, true );
			$result = [
				'projects' => self::idNameList( $projects ),
				'me' => (int)$me->id,
				'meName' => $me->name,
			];
		} else {
			if( !$id ) {
				$this->dieWithError( [ 'apierror-missingparam', 'id' ], 'missingparam' );
			}
			$types = $this->call( 'GET', 'projects/' . $id . '/types', [], null, true );
			$versions = $this->call( 'GET', 'projects/' . $id . '/versions', [], null, true );
			$assignees = $this->call( 'GET', 'projects/' . $id . '/available_assignees', [ 'pageSize' => 1000 ], null, true );
			$me = $this->call( 'GET', 'users/me', [], null, true );

			$open_versions = [];
			foreach( $versions->_embedded->elements ?? [] as $version ) {
				if( $version->status === 'open' ) {
					$open_versions[] = [ 'id' => (int)$version->id, 'name' => $version->name ];
				}
			}

			$result = [
				'types' => self::idNameList( $types ),
				'versions' => $open_versions,
				'assignees' => self::idNameList( $assignees ),
				'me' => (int)$me->id,
				'meName' => $me->name,
			];
		}
		$result['defaultType'] = (int)( $GLOBALS['wgOpenProjectDefaultTypeID'] ?? 0 );
		$result['defaultStoryPoints'] = $GLOBALS['wgOpenProjectDefaultStoryPoints'] ?? 1;

		$this->getResult()->addValue( null, $this->getModuleName(), $result );
	}

	public function getAllowedParams() {
		return [
			'mode' => [
				ParamValidator::PARAM_TYPE => [ 'projects', 'project' ],
				ParamValidator::PARAM_REQUIRED => true,
			],
			'id' => [
				ParamValidator::PARAM_TYPE => 'integer',
			],
		];
	}

	protected function getExamplesMessages() {
		return [
			'action=openproject-meta&mode=projects'
				=> 'apihelp-openproject-meta-example-1',
			'action=openproject-meta&mode=project&id=1'
				=> 'apihelp-openproject-meta-example-2',
		];
	}
}
