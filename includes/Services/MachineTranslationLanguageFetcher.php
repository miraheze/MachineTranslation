<?php

namespace Miraheze\MachineTranslation\Services;

use MediaWiki\Config\ConfigException;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MainConfigNames;
use Miraheze\MachineTranslation\ConfigNames;

class MachineTranslationLanguageFetcher {

	public const CONSTRUCTOR_OPTIONS = [
		ConfigNames::ServiceConfig,
		ConfigNames::Timeout,
		MainConfigNames::HTTPProxy,
	];

	private HttpRequestFactory $httpRequestFactory;
	private MachineTranslationUtils $machineTranslationUtils;
	private ServiceOptions $options;

	public function __construct(
		HttpRequestFactory $httpRequestFactory,
		MachineTranslationUtils $machineTranslationUtils,
		ServiceOptions $options
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );

		$this->httpRequestFactory = $httpRequestFactory;
		$this->machineTranslationUtils = $machineTranslationUtils;
		$this->options = $options;
	}

	public function getSupportedLanguages(): array {
		$serviceType = strtolower( $this->options->get( ConfigNames::ServiceConfig )['type'] ?? '' );

		static $supportedLanguages = null;
		$supportedLanguages ??= match ( $serviceType ) {
			'deepl' => $this->fetchDeepLSupportedLanguages(),
			'google' => $this->fetchGoogleSupportedLanguages(),
			'libretranslate' => $this->fetchLibreTranslateSupportedLanguages(),
			default => throw new ConfigException( 'Unsupported translation service configured.' ),
		};

		return $supportedLanguages;
	}

	private function fetchDeepLSupportedLanguages(): array {
		$url = $this->options->get( ConfigNames::ServiceConfig )['url'] . '/v2/languages';
		$apiKey = $this->options->get( ConfigNames::ServiceConfig )['apikey'];

		$query = [ 'type' => 'source' ];
		$headers = [ 'authorization' => 'DeepL-Auth-Key ' . $apiKey ];

		$response = $this->makeRequest( $url, $query, $headers );
		return $this->parseDeepLLanguages( $response );
	}

	private function fetchGoogleSupportedLanguages(): array {
		$url = 'https://translation.googleapis.com/language/translate/v2/languages';
		$query = [ 'key' => $this->options->get( ConfigNames::ServiceConfig )['apikey'] ];
		$response = $this->makeRequest( $url, $query, [] );
		return $this->parseGoogleLanguages( $response );
	}

	private function fetchLibreTranslateSupportedLanguages(): array {
		$url = $this->options->get( ConfigNames::ServiceConfig )['url'] . '/languages';
		$response = $this->makeRequest( $url, [], [] );
		return $this->parseLibreTranslateLanguages( $response );
	}

	private function makeRequest( string $url, array $query, array $headers ): array {
		if ( $this->machineTranslationUtils->getCache( $url ) ) {
			$cachedResponse = $this->machineTranslationUtils->getCache( $url );
			return json_decode( $cachedResponse, true );
		}

		$response = $this->httpRequestFactory->createMultiClient(
			[ 'proxy' => $this->options->get( MainConfigNames::HTTPProxy ) ]
		)->run( [
			'url' => $url,
			'query' => $query,
			'method' => 'GET',
			'headers' => [
				'user-agent' => MachineTranslationUtils::USER_AGENT,
			] + $headers
		], [ 'reqTimeout' => $this->options->get( ConfigNames::Timeout ) ] );

		if ( $response['code'] !== 200 ) {
			LoggerFactory::getInstance( 'MachineTranslation' )->error(
				'Request to {url} for languages returned {code}: {reason}',
				[
					'url' => $url,
					'code' => $response['code'],
					'reason' => $response['reason'],
				]
			);
			return [];
		}

		$this->machineTranslationUtils->storeCache( $url, $response['body'] );
		return json_decode( $response['body'], true );
	}

	private function parseDeepLLanguages( array $response ): array {
		$supportedLanguages = [];

		foreach ( $response as $lang ) {
			$supportedLanguages[strtolower( $lang['language'] )] = $lang['name'];
		}

		return $supportedLanguages;
	}

	private function parseGoogleLanguages( array $response ): array {
		$languages = $response['data']['languages'] ?? [];
		$supportedLanguages = [];

		foreach ( $languages as $lang ) {
			$supportedLanguages[strtolower( $lang['language'] )] = $lang['name'] ?? strtolower( $lang['language'] );
		}

		return $supportedLanguages;
	}

	private function parseLibreTranslateLanguages( array $response ): array {
		$supportedLanguages = [];
		$languageMap = $this->getLanguageCodeMap();

		foreach ( $response as $lang ) {
			$code = $languageMap[$lang['code']] ?? $lang['code'];
			$supportedLanguages[strtolower( $code )] = $lang['name'];
		}

		return $supportedLanguages;
	}

	public function getLanguageCodeMap(): array {
		$serviceType = strtolower( $this->options->get( ConfigNames::ServiceConfig )['type'] ?? '' );
		return match ( $serviceType ) {
			'libretranslate' => [
				'zt' => 'zh-hant',
			],
			default => [],
		};
	}

	public function getLanguageName( string $code ): string {
		$languages = $this->getSupportedLanguages();
		return $languages[$code] ?? '';
	}

	public function isLanguageSupported( string $code ): bool {
		$languages = $this->getSupportedLanguages();
		return isset( $languages[$code] );
	}
}
