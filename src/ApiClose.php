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
class ApiClose extends ApiBase {

	public function __construct( $main, $action ) {
		parent::__construct( $main, $action );
	}

	public function execute() {
		$params = $this->extractRequestParams();
		
		$id = $params['id'];
		$lockVersion = $params['lockVersion'];
		
		$url = 'work_packages/'. $params['id'];
		$params = [ "_links" => [ "status" => [ "href" => "/api/v3/statuses/10" ] ], "lockVersion" => $params['lockVersion'] ];
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
			'lockVersion' => 'The work package\'s lock version',
		);
	}

	public function getDescription() {
		return array(
			'API module for closing OpenProject work packages.'
		);
	}

	public function getPossibleErrors() {
		return array_merge( parent::getPossibleErrors(), array(
			array( 'missingparam', 'id' ),
			array( 'missingparam', 'lockVersion' ),
		) );
	}

	protected function getExamples() {
		return array(
			'api.php?action=opclose&id=1'
		);
	}	

	public function getVersion() {
		return __CLASS__ . ': $Id$';
	}		

}

