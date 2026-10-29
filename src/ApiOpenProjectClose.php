<?php

namespace MediaWiki\Extension\OpenProject;

use ApiBase;
use ApiMain;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * API module to close a work package in OpenProject (and its parent,
 * if no open siblings are left)
 */
class ApiOpenProjectClose extends ApiBase {

	public function __construct( ApiMain $main, $action ) {
		parent::__construct( $main, $action );
	}

	public function execute() {
		$user = $this->getUser();
		if( !$user->isRegistered() ) {
			$this->dieWithError( 'apierror-mustbeloggedin-generic', 'notloggedin' );
		}
		if( OpenProjectClient::getApiKey( $user ) === '' ) {
			$this->dieWithError( 'openproject-api-nokey', 'nokey' );
		}

		$id = $this->getParameter( 'id' );
		$closed = [];

		// fetch fresh data instead of relying on the (possibly cached) lockVersion from the page
		$wp = $this->call( 'GET', 'work_packages/' . $id );
		$this->closeWorkPackage( $wp );
		$closed[] = $id;

		$parent_href = $wp->_links->parent->href ?? null;
		if( $parent_href ) {
			$parent_id = (int)substr( $parent_href, strrpos( $parent_href, '/' ) + 1 );
			$filters = [
				[ 'parent' => [ 'operator' => '=', 'values' => [ (string)$parent_id ] ] ],
				[ 'status_id' => [ 'operator' => 'o', 'values' => null ] ]
			];
			$children = $this->call( 'GET', 'work_packages', [ 'filters' => json_encode( $filters ) ] );
			if( $children->count == 0 ) {
				$parent = $this->call( 'GET', 'work_packages/' . $parent_id );
				$this->closeWorkPackage( $parent );
				$closed[] = $parent_id;
			}
		}

		OpenProjectClient::bumpGeneration();
		$this->getResult()->addValue( null, $this->getModuleName(), [ 'closed' => $closed ] );
	}

	protected function closeWorkPackage( $wp ) {
		$status_id = $GLOBALS['wgOpenProjectClosedStatusID'] ?? 10;
		$this->call( 'PATCH', 'work_packages/' . $wp->id, [], [
			'_links' => [ 'status' => [ 'href' => '/api/v3/statuses/' . $status_id ] ],
			'lockVersion' => $wp->lockVersion
		] );
	}

	/**
	 * Call OpenProject, abort with an API error on failure
	 */
	protected function call( $method, $path, array $params = [], $body = null ) {
		list( $code, $response ) = OpenProjectClient::request( $method, $path, $params, $body, $this->getUser() );
		if( $code < 200 || $code >= 300 ) {
			$message = $response->message ?? '';
			switch( $code ) {
				case 401:
					$this->dieWithError( 'openproject-api-unauthenticated', 'unauthenticated' );
				case 409:
					$this->dieWithError( 'openproject-api-conflict', 'conflict' );
				default:
					$this->dieWithError( [ 'openproject-api-error', $code, $message ], 'openproject-' . $code );
			}
		}
		return $response;
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
			'action=openproject-close&id=123&token=123ABC'
				=> 'apihelp-openproject-close-example-1',
		];
	}
}
