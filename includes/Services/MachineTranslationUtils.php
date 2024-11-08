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
		$serviceConfig = $this->options->get( ConfigNames::ServiceConfig );
		$serviceType = strtolower( $serviceConfig['type'] ?? '' );

		switch ( $serviceType ) {
			case 'deepl':
			case 'googletranslate':
			case 'libretranslate':
				return $this->callApiService(
					$serviceType,
					$text,
					$targetLanguage,
					$serviceConfig
				);
			default:
				throw new ConfigException( 'Unsupported machine translation service configured.' );
		}
	}

	private function callApiService(
		string $serviceType,
		string $text,
		string $targetLanguage,
		array $serviceConfig
	): string {
		// Check parameters
		if ( !$text || !$targetLanguage || strlen( $text ) > 131072 ) {
			LoggerFactory::getInstance( 'MachineTranslation' )->error(
				'Text too large to translate. Length: {length}',
				[
					'length' => strlen( $text ),
				]
			);
			return '';
		}

		$targetLanguage = strtolower( $targetLanguage );
		$url = $serviceConfig['url'] ?? '';
		$apiKey = $serviceConfig['apikey'] ?? '';

		// Build the request body and headers based on service type
		$body = [];
		$headers = [ 'user-agent' => self::USER_AGENT ];
		switch ( $serviceType ) {
			case 'deepl':
				$body = [
					'target_lang' => $targetLanguage,
					'tag_handling' => 'html',
					'text' => $text,
				];
				$headers['authorization'] = 'DeepL-Auth-Key ' . $apiKey;
				break;
			case 'googletranslate':
				$body = [
					'q' => $text,
					'target' => $targetLanguage,
					'format' => 'html',
					'key' => $apiKey,
				];
				break;
			case 'libretranslate':
				$body = [
					'source' => 'auto',
					'target' => $targetLanguage,
					'format' => 'html',
					'q' => $text,
				];
				break;
		}

		$request = $this->httpRequestFactory->createMultiClient( [
			'proxy' => $this->options->get( MainConfigNames::HTTPProxy )
		] )->run( [
			'url' => $url,
			'method' => 'POST',
			'body' => $body,
			'headers' => $headers
		], [ 'reqTimeout' => $this->options->get( ConfigNames::Timeout ) ] );

		// Check if the HTTP response code is returning 200
		if ( $request['code'] !== 200 ) {
			LoggerFactory::getInstance( 'MachineTranslation' )->error(
				'Request to {service} returned {code}: {reason}',
				[
					'code' => $request['code'],
					'reason' => $request['reason'],
					'service' => ucfirst( $serviceType ),
				]
			);
			return '';
		}

		// Return the translated text in the correct format based on the service type
		$json = json_decode( $request['body'], true );
		return match ( $serviceType ) {
			'deepl' => $json['translations'][0]['text'] ?? '',
			'googletranslate' => $json['data']['translations'][0]['translatedText'] ?? '',
			'libretranslate' => $json['translatedText'] ?? '',
			default => '',
		};
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
