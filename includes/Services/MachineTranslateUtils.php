<?php

namespace Miraheze\MachineTranslate\Services;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MainConfigNames;
use Miraheze\MachineTranslate\ConfigNames;
use ObjectCacheFactory;

class MachineTranslateUtils {

	public const CONSTRUCTOR_OPTIONS = [
		ConfigNames::Caching,
		ConfigNames::CachingTime,
		ConfigNames::Timeout,
		ConfigNames::Url,
		MainConfigNames::HTTPProxy,
	];

	private HttpRequestFactory $httpRequestFactory;
	private ObjectCacheFactory $objectCacheFactory;
	private ServiceOptions $options;

	public function __construct(
		HttpRequestFactory $httpRequestFactory,
		ObjectCacheFactory $objectCacheFactory,
		ServiceOptions $options
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );

		$this->httpRequestFactory = $httpRequestFactory;
		$this->objectCacheFactory = $objectCacheFactory;
		$this->options = $options;
	}

	public function callTranslation( string $text, string $targetLanguage ): string {
		// Check parameters
		if ( !$text || !$targetLanguage ) {
			return '';
		}

		if ( strlen( $text ) > 131072 ) {
			// Exit if content length is over 128KiB
			LoggerFactory::getInstance( 'MachineTranslate' )->error(
				'Text to large to translate. Length: {length}',
				[
					'length' => strlen( $text ),
				]
			);
			return '';
		}

		$targetLanguage = strtolower( $targetLanguage );

		// Call API
		$request = $this->httpRequestFactory->createMultiClient(
			[ 'proxy' => $this->options->get( MainConfigNames::HTTPProxy ) ]
		)->run( [
			'url' => $this->options->get( ConfigNames::LibreTranslateUrl ) . '/translate',
			'method' => 'POST',
			'body' => [
				'source' => 'auto',
				'target' => $targetLanguage,
				'format' => 'html',
				'q' => $text,
			],
			'headers' => [
				'user-agent' => 'MachineTranslate, MediaWiki extension (https://github.com/miraheze/MachineTranslate)',
			]
		], [ 'reqTimeout' => $this->options->get( ConfigNames::Timeout ) ] );

		// Check if the HTTP response code is returning 200
		if ( $request['code'] !== 200 ) {
			LoggerFactory::getInstance( 'MachineTranslate' )->error(
				'Request to the machine translation service returned {code}: {reason}',
				[
					'code' => $request['code'],
					'reason' => $request['reason'],
				]
			);
			return '';
		}

		$json = json_decode( $request['body'], true );
		return $json['translatedText'] ?? '';
	}

	public function storeCache( string $key, string $value ): bool {
		if ( !$this->options->get( ConfigNames::Caching ) ) {
			return false;
		}

		$cache = $this->objectCacheFactory->getInstance( CACHE_ANYTHING );
		$cacheKey = $cache->makeKey( 'MachineTranslate', $key );
		return $cache->set( $cacheKey, $value, $this->options->get( ConfigNames::CachingTime ) );
	}

	public function getCache( string $key ): bool|string {
		if ( !$this->options->get( ConfigNames::Caching ) ) {
			return false;
		}

		$cache = $this->objectCacheFactory->getInstance( CACHE_ANYTHING );
		$cacheKey = $cache->makeKey( 'MachineTranslate', $key );

		if ( $this->options->get( ConfigNames::CachingTime ) === 0 ) {
			$cache->delete( $cacheKey );
			return false;
		}

		return $cache->get( $cacheKey );
	}

	public function deleteCache( string $key ): void {
		if ( !$this->options->get( ConfigNames::Caching ) ) {
			return;
		}

		$cache = $this->objectCacheFactory->getInstance( CACHE_ANYTHING );
		$cacheKey = $cache->makeKey( 'MachineTranslate', $key );

		$cache->delete( $cacheKey );
	}
}
