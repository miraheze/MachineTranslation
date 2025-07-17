<?php

namespace Miraheze\MachineTranslation\Services;

use MediaWiki\Config\ConfigException;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MainConfigNames;
use Miraheze\MachineTranslation\ConfigNames;
use function json_decode;
use function strtolower;

class MachineTranslationLanguageFetcher {

	public const CONSTRUCTOR_OPTIONS = [
		ConfigNames::ServiceConfig,
		ConfigNames::Timeout,
		MainConfigNames::HTTPProxy,
	];

	public function __construct(
		private readonly HttpRequestFactory $httpRequestFactory,
		private readonly MachineTranslationUtils $machineTranslationUtils,
		private readonly ServiceOptions $options
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
	}

	/** @return array<string, string> */
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

	/** @return array<string, string> */
	private function fetchDeepLSupportedLanguages(): array {
		$url = $this->options->get( ConfigNames::ServiceConfig )['url'] . '/v2/languages';

		$query = [ 'type' => 'source' ];
		$headers = [ 'authorization' => 'DeepL-Auth-Key ' . $this->getApiKey() ];

		$response = $this->makeRequest( $url, $query, $headers );
		return $this->parseDeepLLanguages( $response );
	}

	/** @return array<string, string> */
	private function fetchGoogleSupportedLanguages(): array {
		$url = 'https://translation.googleapis.com/language/translate/v2/languages';
		$query = [ 'key' => $this->getApiKey() ];
		$response = $this->makeRequest( $url, $query, [] );
		return $this->parseGoogleLanguages( $response );
	}

	/** @return array<string, string> */
	private function fetchLibreTranslateSupportedLanguages(): array {
		$url = $this->options->get( ConfigNames::ServiceConfig )['url'] . '/languages';
		$response = $this->makeRequest( $url, [], [] );
		return $this->parseLibreTranslateLanguages( $response );
	}

	private function getApiKey(): string {
		return $this->options->get( ConfigNames::ServiceConfig )['apikey'];
	}

	/**
	 * @param string $url
	 * @param array<string,string> $query
	 * @param array<string,string> $headers
	 * @return array<string,mixed>
	 */
	private function makeRequest( string $url, array $query, array $headers ): array {
		$cachedResponse = $this->machineTranslationUtils->getCache( $url );
		if ( $cachedResponse !== false ) {
			return (array)json_decode( $cachedResponse, true );
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
		return (array)json_decode( $response['body'], true );
	}

	/**
	 * @param array<array{language: string, name: string}> $response
	 * @return array{}|non-empty-array<string, string>
	 */
	private function parseDeepLLanguages( array $response ): array {
		$supportedLanguages = [];

		foreach ( $response as $lang ) {
			$supportedLanguages[strtolower( $lang['language'] )] = $lang['name'];
		}

		return $supportedLanguages;
	}

	/**
	 * @phan-param array{data?: array{languages?: array<array{language: string, name?: string}>}} $response
	 * @return array{}|non-empty-array<string, string>
	 */
	private function parseGoogleLanguages( array $response ): array {
		$languages = $response['data']['languages'] ?? [];
		$supportedLanguages = [];

		foreach ( $languages as $lang ) {
			$supportedLanguages[strtolower( $lang['language'] )] = $lang['name'] ?? strtolower( $lang['language'] );
		}

		return $supportedLanguages;
	}

	/**
	 * @param array<array{code: string, name: string}> $response
	 * @return array{}|non-empty-array<string, string>
	 */
	private function parseLibreTranslateLanguages( array $response ): array {
		$supportedLanguages = [];
		$languageMap = $this->getLanguageCodeMap();

		foreach ( $response as $lang ) {
			$code = $languageMap[$lang['code']] ?? $lang['code'];
			$supportedLanguages[strtolower( $code )] = $lang['name'];
		}

		return $supportedLanguages;
	}

	/** @return array<string, string> */
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
