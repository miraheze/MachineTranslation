<?php

namespace Miraheze\MachineTranslation\Services;

use MediaWiki\Config\ConfigException;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MainConfigNames;
use Miraheze\MachineTranslation\ConfigNames;
use ObjectCacheFactory;
use function array_filter;
use function json_decode;
use function strlen;
use function strtolower;
use const CACHE_ANYTHING;

class MachineTranslationUtils {

	public const CONSTRUCTOR_OPTIONS = [
		ConfigNames::Caching,
		ConfigNames::CachingTime,
		ConfigNames::ServiceConfig,
		ConfigNames::Timeout,
		MainConfigNames::HTTPProxy,
	];

	public const USER_AGENT = 'MachineTranslation, MediaWiki extension ' .
		'(https://github.com/miraheze/MachineTranslation)';

	public function __construct(
		private readonly HttpRequestFactory $httpRequestFactory,
		private readonly ObjectCacheFactory $objectCacheFactory,
		private readonly ServiceOptions $options
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
	}

	public function callTranslation(
		string $text,
		string $sourceLanguage,
		string $targetLanguage
	): string {
		// Check parameters
		if ( $text === '' || $targetLanguage === '' ) {
			return '';
		}

		if ( strlen( $text ) > 131072 ) {
			// Exit if content length is over 128KiB
			LoggerFactory::getInstance( 'MachineTranslation' )->error(
				'Text too large to translate. Length: {length}',
				[
					'length' => strlen( $text ),
				]
			);
			return '';
		}

		$serviceType = strtolower( $this->options->get( ConfigNames::ServiceConfig )['type'] ?? '' );

		return match ( $serviceType ) {
			'deepl' => $this->doDeepLTranslate( $text, $sourceLanguage, $targetLanguage ),
			'google' => $this->doGoogleTranslate( $text, $sourceLanguage, $targetLanguage ),
			'libretranslate' => $this->doLibreTranslate( $text, $sourceLanguage, $targetLanguage ),
			default => throw new ConfigException( 'Unsupported machine translation service configured.' ),
		};
	}

	private function doDeepLTranslate(
		string $text,
		string $sourceLanguage,
		string $targetLanguage
	): string {
		// Call API
		$request = $this->httpRequestFactory->createMultiClient(
			[ 'proxy' => $this->options->get( MainConfigNames::HTTPProxy ) ]
		)->run( [
			'url' => $this->options->get( ConfigNames::ServiceConfig )['url'] . '/v2/translate',
			'method' => 'POST',
			'body' => [
				'source_lang' => $sourceLanguage,
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

	private function doGoogleTranslate(
		string $text,
		string $sourceLanguage,
		string $targetLanguage
	): string {
		// Call API
		$request = $this->httpRequestFactory->createMultiClient(
			[ 'proxy' => $this->options->get( MainConfigNames::HTTPProxy ) ]
		)->run( [
			'url' => 'https://translation.googleapis.com/language/translate/v2',
			'method' => 'POST',
			'body' => [
				'q' => $text,
				'source' => $sourceLanguage,
				'target' => $targetLanguage,
				'format' => 'html',
				'key' => $this->options->get( ConfigNames::ServiceConfig )['apikey'],
			],
			'headers' => [
				'user-agent' => self::USER_AGENT,
			]
		], [ 'reqTimeout' => $this->options->get( ConfigNames::Timeout ) ] );

		// Check if the HTTP response code is returning 200
		if ( $request['code'] !== 200 ) {
			LoggerFactory::getInstance( 'MachineTranslation' )->error(
				'Request to Google Translate returned {code}: {reason}',
				[
					'code' => $request['code'],
					'reason' => $request['reason'],
				]
			);
			return '';
		}

		$json = json_decode( $request['body'], true );
		return $json['data']['translations'][0]['translatedText'] ?? '';
	}

	private function doLibreTranslate(
		string $text,
		string $sourceLanguage,
		string $targetLanguage
	): string {
		$apiKey = $this->options->get( ConfigNames::ServiceConfig )['apikey'] ?? null;
		// Call API
		$request = $this->httpRequestFactory->createMultiClient(
			[ 'proxy' => $this->options->get( MainConfigNames::HTTPProxy ) ]
		)->run( [
			'url' => $this->options->get( ConfigNames::ServiceConfig )['url'] . '/translate',
			'method' => 'POST',
			'body' => array_filter( [
				'q' => $text,
				'api_key' => $apiKey,
				'source' => $sourceLanguage,
				'target' => $targetLanguage,
				'format' => 'html',
			] ),
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

	public function getCache( string $key ): string|false {
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
