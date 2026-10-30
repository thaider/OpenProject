<?php

namespace MediaWiki\Extension\OpenProject;

use MediaWiki\MediaWikiServices;
use MediaWiki\User\UserIdentity;

/**
 * Minimal client for the OpenProject API v3, authenticating with the
 * API token stored in the user's preferences.
 */
class OpenProjectClient {
	const CACHE_TTL = 60;

	/**
	 * Send a request to the OpenProject API
	 *
	 * @param string $method HTTP method
	 * @param string $path Path below /api/v3/
	 * @param array $params Query parameters
	 * @param array|null $body JSON body (for PATCH/POST)
	 * @param UserIdentity $user User whose API token is used
	 *
	 * @return array [ HTTP status code, decoded response ] (code 0 on connection problems)
	 */
	public static function request( $method, $path, array $params, $body, UserIdentity $user ) {
		$apikey = self::getApiKey( $user );
		if( $apikey === '' ) {
			return [ 401, null ];
		}

		$url = $GLOBALS['wgOpenProjectURL'] . '/api/v3/' . ltrim( $path, '/' );
		if( $params ) {
			$url .= '?' . http_build_query( $params );
		}

		$headers = [ 'Accept: application/json' ];
		$curl = curl_init();
		curl_setopt( $curl, CURLOPT_URL, $url );
		curl_setopt( $curl, CURLOPT_USERPWD, 'apikey:' . $apikey );
		curl_setopt( $curl, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $curl, CURLOPT_TIMEOUT, 15 );
		curl_setopt( $curl, CURLOPT_CUSTOMREQUEST, $method );
		if( $body !== null ) {
			$headers[] = 'Content-Type: application/json';
			curl_setopt( $curl, CURLOPT_POSTFIELDS, json_encode( $body ) );
		}
		curl_setopt( $curl, CURLOPT_HTTPHEADER, $headers );

		$response = json_decode( (string)curl_exec( $curl ) );
		$code = curl_getinfo( $curl, CURLINFO_HTTP_CODE );
		curl_close( $curl );

		return [ $code, $response ];
	}

	/**
	 * GET request with a short-lived per-user cache (APCu)
	 *
	 * The cache key contains the user, because results depend on the user's token
	 * (e.g. assignee=me), and a generation counter that is bumped on every write.
	 */
	public static function cachedGet( $path, array $params, UserIdentity $user ) {
		$useApcu = function_exists( 'apcu_fetch' );
		if( $useApcu ) {
			$key = 'openproject:' . self::getGeneration() . ':' . $user->getId() . ':' . md5( json_encode( [ $path, $params ] ) );
			$cached = apcu_fetch( $key, $hit );
			if( $hit ) {
				return $cached;
			}
		}

		$result = self::request( 'GET', $path, $params, null, $user );
		if( $useApcu && $result[0] == 200 ) {
			apcu_store( $key, $result, self::CACHE_TTL );
		}
		return $result;
	}

	/**
	 * Invalidate all cached GET responses (call after writes)
	 */
	public static function bumpGeneration() {
		if( function_exists( 'apcu_store' ) ) {
			apcu_store( 'openproject:generation', self::getGeneration() + 1 );
		}
	}

	protected static function getGeneration() {
		$generation = apcu_fetch( 'openproject:generation', $hit );
		return $hit ? (int)$generation : 0;
	}

	public static function getApiKey( UserIdentity $user ) {
		$uom = MediaWikiServices::getInstance()->getUserOptionsManager();
		return trim( (string)$uom->getOption( $user, 'openproject-apikey' ) );
	}
}
