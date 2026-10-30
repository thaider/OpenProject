<?php

namespace MediaWiki\Extension\OpenProject;

use ApiBase;

/**
 * Common checks and error handling for API modules talking to OpenProject
 */
abstract class ApiOpenProjectBase extends ApiBase {

	/**
	 * Abort unless the user is logged in and has an API token
	 */
	protected function checkUser() {
		$user = $this->getUser();
		if( !$user->isRegistered() ) {
			$this->dieWithError( 'apierror-mustbeloggedin-generic', 'notloggedin' );
		}
		if( OpenProjectClient::getApiKey( $user ) === '' ) {
			$this->dieWithError( 'openproject-api-nokey', 'nokey' );
		}
	}

	/**
	 * Call OpenProject, abort with an API error on failure
	 *
	 * @param bool $cached Use the cached GET (read-only calls)
	 */
	protected function call( $method, $path, array $params = [], $body = null, $cached = false ) {
		if( $cached ) {
			list( $code, $response ) = OpenProjectClient::cachedGet( $path, $params, $this->getUser() );
		} else {
			list( $code, $response ) = OpenProjectClient::request( $method, $path, $params, $body, $this->getUser() );
		}
		if( $code < 200 || $code >= 300 ) {
			$message = $response->message ?? '';
			switch( $code ) {
				case 0:
					$this->dieWithError( 'openproject-api-unreachable', 'unreachable' );
				case 401:
					$this->dieWithError( 'openproject-api-unauthenticated', 'unauthenticated' );
				case 409:
					$this->dieWithError( 'openproject-api-conflict', 'conflict' );
				case 422:
					$this->dieWithError( [ 'openproject-api-validation', $message ], 'validation' );
				default:
					$this->dieWithError( [ 'openproject-api-error', $code, $message ], 'openproject-' . $code );
			}
		}
		return $response;
	}

	/**
	 * Reduce a collection of OpenProject resources to id/name pairs
	 */
	protected static function idNameList( $collection, $name = 'name' ) {
		$list = [];
		foreach( $collection->_embedded->elements ?? [] as $element ) {
			$list[] = [ 'id' => (int)$element->id, 'name' => $element->$name ];
		}
		return $list;
	}
}
