<?php

namespace MediaWiki\Extension\OpenProject;

use ApiBase;
use MediaWiki\Extension\OpenProject\OpenProjectHooks;

/**
 * API module for closing tasks.
 *
 * @since 0.1.0
 *
 * @ingroup API
 *
 * @licence GNU GPL v2++
 */
class ApiMove extends ApiBase {

	public function __construct( $main, $action ) {
		parent::__construct( $main, $action );
	}

	public function execute() {
		$params = $this->extractRequestParams();
		
		$url = 'work_packages/'. $params['id'];
		$params = [ "_links" => [ "targetVersions" => [ [ "href" => "/api/v3/versions/" . $params['targetVersion'] ] ] ], "lockVersion" => $params['lockVersion'] ];
        	$params = json_encode( $params );

		$user = self::getUser();
	 	$result = OpenProjectHooks::callApiPUT( $user, $url, $params );
		if( $result[0] !== 200 ) {
			$this->dieWithError( 'Error' );
		}

		$this->getResult()->addValue(
			null,
			'result',
			'success'
		);
	}


	public function getAllowedParams() {
		return array(
			'id' => array(
				ApiBase::PARAM_TYPE => 'integer',
				ApiBase::PARAM_REQUIRED => true,
				ApiBase::PARAM_ISMULTI => false,
			),
			'targetVersion' => array(
				ApiBase::PARAM_TYPE => 'integer',
				ApiBase::PARAM_REQUIRED => true,
				ApiBase::PARAM_ISMULTI => false,
			),
			'lockVersion' => array(
				ApiBase::PARAM_TYPE => 'integer',
				ApiBase::PARAM_REQUIRED => true,
				ApiBase::PARAM_ISMULTI => false,
			),
		);
	}

	public function getParamDescription() {
		return array(
			'id' => 'The work package id',
			'targetVersion' => 'The work package\'s target version',
			'lockVersion' => 'The work package\'s lock version',
		);
	}

	public function getDescription() {
		return array(
			'API module for moving OpenProject work packages to next Sprint.'
		);
	}

	public function getPossibleErrors() {
		return array_merge( parent::getPossibleErrors(), array(
			array( 'missingparam', 'id' ),
			array( 'missingparam', 'targetVersion' ),
			array( 'missingparam', 'lockVersion' ),
		) );
	}

	protected function getExamples() {
		return array(
			'api.php?action=opmove&id=1'
		);
	}	

	public function getVersion() {
		return __CLASS__ . ': $Id$';
	}		

}

