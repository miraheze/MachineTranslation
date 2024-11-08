<?php

namespace Miraheze\MachineTranslation\Services;

use ConfigException;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MainConfigNames;
use Miraheze\MachineTranslation\ConfigNames;
use ObjectCacheFactory;

class MachineTranslationUtils {

	public const CONSTRUCTOR_OPTIONS = [
		ConfigNames::Caching,
		ConfigNames::CachingTime,
		ConfigNames::ServiceConfig,
		ConfigNames::Timeout,
		MainConfigNames::HTTPProxy,
	];

	private const USER_AGENT = 'MachineTranslation, MediaWiki extension ' .
		'(https://github.com/miraheze/MachineTranslation)';

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
		switch ( strtolower( $this->options->get( ConfigNames::ServiceConfig )['type'] ?? '' ) ) {
			case 'deepl':
				return $this->doDeepL( $text, $targetLanguage );
			case 'libretranslate':
				return $this->doLibreTranslate( $text, $targetLanguage );
			default:
				throw new ConfigException( 'Unsupported machine translation service configured.' );
		}
	}

	private function doDeepL( string $text, string $targetLanguage ): string {
		// Check parameters
		if ( !$text || !$targetLanguage ) {
			return '';
		}

		if ( strlen( $text ) > 131072 ) {
			// Exit if content length is over 128KiB
			LoggerFactory::getInstance( 'MachineTranslation' )->error(
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
			'url' => $this->options->get( ConfigNames::ServiceConfig )['url'],
			'method' => 'POST',
			'body' => [
				'target_lang' => $targetLanguage,
				'tag_handling' => 'html',
				'text' => $text,
			],
			'headers' => [
				'authorization' => 'DeepL-Auth-Key ' . $this->options->get( ConfigNames::ServiceConfig )['apikey'],
				'user-agent' => self::USER_AGENT,
			]
		], [ 'reqTimeout' => $this->options->get( ConfigNames::Timeout ) ] );

		// Check if the HTTP response code is returning 200
		if ( $request['code'] !== 200 ) {
			LoggerFactory::getInstance( 'MachineTranslation' )->error(
				'Request to DeepL returned {code}: {reason}',
				[
					'code' => $request['code'],
					'reason' => $request['reason'],
				]
			);
			return '';
		}

		$json = json_decode( $request['body'], true );
		return $json['translations'][0]['text'] ?? '';
	}

	private function doLibreTranslate( string $text, string $targetLanguage ): string {
		// Check parameters
		if ( !$text || !$targetLanguage ) {
			return '';
		}

		if ( strlen( $text ) > 131072 ) {
			// Exit if content length is over 128KiB
			LoggerFactory::getInstance( 'MachineTranslation' )->error(
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
			'url' => $this->options->get( ConfigNames::ServiceConfig )['url'] . '/translate',
			'method' => 'POST',
			'body' => [
				'source' => 'auto',
				'target' => $targetLanguage,
				'format' => 'html',
				'q' => $text,
			],
			'headers' => [
				'user-agent' => self::USER_AGENT,
			]
		], [ 'reqTimeout' => $this->options->get( ConfigNames::Timeout ) ] );

		// Check if the HTTP response code is returning 200
		if ( $request['code'] !== 200 ) {
			LoggerFactory::getInstance( 'MachineTranslation' )->error(
				'Request to LibreTranslate returned {code}: {reason}',
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
		$cacheKey = $cache->makeKey( 'MachineTranslation', $key );
		return $cache->set( $cacheKey, $value, $this->options->get( ConfigNames::CachingTime ) );
	}

	public function getCache( string $key ): bool|string {
		if ( !$this->options->get( ConfigNames::Caching ) ) {
			return false;
		}

		$cache = $this->objectCacheFactory->getInstance( CACHE_ANYTHING );
		$cacheKey = $cache->makeKey( 'MachineTranslation', $key );

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
		$cacheKey = $cache->makeKey( 'MachineTranslation', $key );

		$cache->delete( $cacheKey );
	}
}
