<?php

namespace Miraheze\MachineTranslation\Services;

use ConfigException;
use IntlBreakIterator;
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

	public const USER_AGENT = 'MachineTranslation, MediaWiki extension ' .
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

	public function callTranslation(
		string $text,
		string $sourceLanguage,
		string $targetLanguage
	): string {
		// Check parameters
		if ( !$text || !$targetLanguage ) {
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

		$targetLanguage = strtolower( $targetLanguage );
		$serviceType = strtolower( $this->options->get( ConfigNames::ServiceConfig )['type'] ?? '' );

		return match ( $serviceType ) {
			'deepl' => $this->doDeepLTranslate( $text, $sourceLanguage, $targetLanguage ),
			'google' => $this->doGoogleTranslate( $text, $sourceLanguage, $targetLanguage ),
			'libretranslate' => $this->doLibreTranslate( $text, $sourceLanguage, $targetLanguage ),
			'lingva' => $this->doLingvaTranslate( $text, $sourceLanguage, $targetLanguage ),
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

	private function doLingvaTranslate(
		string $text,
		string $sourceLanguage,
		string $targetLanguage
	): string {
		// Split text into chunks of max 6,000 characters each
		// Otherwise Lingva will not work for more than that.
		$chunks = $this->splitTextIntoChunks( $text, 6000 );
		$translatedText = '';

		foreach ( $chunks as $chunk ) {
			// Build GraphQL query for each chunk
			$query = <<<GQL
				{
					translation(source: "{$sourceLanguage}", target: "{$targetLanguage}", query: """{$chunk}""") {
						target {
							text
						}
					}
				}
			GQL;

			// Call API
			$request = $this->httpRequestFactory->createMultiClient(
				[ 'proxy' => $this->options->get( MainConfigNames::HTTPProxy ) ]
			)->run( [
				'url' => $this->options->get( ConfigNames::ServiceConfig )['url'] . '/api/graphql',
				'method' => 'POST',
				'body' => json_encode( [
					'query' => $query,
				] ),
				'headers' => [
					'user-agent' => self::USER_AGENT,
				]
			], [ 'reqTimeout' => $this->options->get( ConfigNames::Timeout ) ] );

			// Check if the HTTP response code is returning 200
			if ( $request['code'] !== 200 ) {
				LoggerFactory::getInstance( 'MachineTranslation' )->error(
					'Request to Lingva returned {code}: {reason}',
					[
						'code' => $request['code'],
						'reason' => $request['reason'],
					]
				);

				// If we have any errors, return nothing,
				// we don't want a half-translated page.
				return '';
			}

			$json = json_decode( $request['body'], true );
			if ( $json['errors'] ?? [] ) {
				LoggerFactory::getInstance( 'MachineTranslation' )->error(
					'Request to Lingva had errors: {errors}',
					[
						'errors' => json_encode( $json['errors'] ?? [] ),
					]
				);

				// If we have any errors, return nothing,
				// we don't want a half-translated page.
				return '';
			}

			// Append translated chunk text
			$translatedText .= $json['data']['translation']['target']['text'] ?? '';
		}

		return $translatedText;
	}

	private function splitTextIntoChunks( string $text, int $maxChunkSize ): array {
		$sentences = $this->splitSentencesHtmlSafe( $text );
		$chunks = [];
		$currentChunk = '';

		foreach ( $sentences as $sentence ) {
			// If adding the next sentence exceeds the limit, start a new chunk
			if ( mb_strlen( $currentChunk . ' ' . $sentence ) > $maxChunkSize ) {
				$chunks[] = trim( $currentChunk );
				$currentChunk = '';
			}

			$currentChunk .= ( $currentChunk ? ' ' : '' ) . $sentence;
		}

		// Add the last chunk if there is remaining text
		if ( $currentChunk ) {
			$chunks[] = trim( $currentChunk );
		}

		return $chunks;
	}

	private function splitSentencesHtmlSafe( string $text ): array {
		$htmlParts = preg_split( '/(<[^>]+>)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE );
		$iterator = IntlBreakIterator::createSentenceInstance();
		$sentences = [];
		$currentSentence = '';

		foreach ( $htmlParts as $part ) {
			if ( preg_match( '/<[^>]+>/', $part ) ) {
				$currentSentence .= $part;
				continue;
			}

			$iterator->setText( $part );
			$start = $iterator->first();
			for (
				$end = $iterator->next();
				$end !== IntlBreakIterator::DONE;
				$start = $end, $end = $iterator->next()
			) {
				$sentence = trim( mb_substr( $part, $start, $end - $start ) );
				$currentSentence .= $sentence;

				if ( $currentSentence ) {
					$sentences[] = $currentSentence;
					$currentSentence = '';
				}
			}

			if ( $start < mb_strlen( $part ) ) {
				$currentSentence .= trim( mb_substr( $part, $start ) );
			}
		}

		if ( $currentSentence ) {
			$sentences[] = $currentSentence;
		}

		return $sentences;
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
