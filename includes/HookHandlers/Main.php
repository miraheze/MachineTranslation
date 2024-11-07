<?php

namespace Miraheze\LibreTranslate\HookHandlers;

use Article;
use MediaWiki\Config\Config;
use MediaWiki\Config\ConfigFactory;
use MediaWiki\Html\Html;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Languages\LanguageNameUtils;
use MediaWiki\MainConfigNames;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Title\TitleFactory;
use Miraheze\LibreTranslate\ConfigNames;
use ObjectCacheFactory;
use TextContent;

class Main {

	private const TARGET_LANGUAGES = [
		// Accepted language codes and captions

		/** Bulgarian */
		'BG' => 'български език',
		/** Czech */
		'CS' => 'český jazyk',
		/** Danish */
		'DA' => 'dansk',
		/** German */
		'DE' => 'Deutsch',
		/** Greek */
		'EL' => 'ελληνικά',
		/** English */
		'EN' => 'English',
		/** English (British) */
		'EN-GB' => 'British English',
		/** English (American) */
		'EN-US' => 'American English',
		/** Spanish */
		'ES' => 'español',
		/** Estonian */
		'ET' => 'eesti keel',
		/** Finnish */
		'FI' => 'suomi',
		/** French */
		'FR' => 'français',
		/** Hungarian */
		'HU' => 'magyar nyelv',
		/** Indonesian */
		'ID' => 'Bahasa Indonesia',
		/** Italian */
		'IT' => 'italiano',
		/** Japanese */
		'JA' => '日本語',
		/** Korean */
		'KO' => '한국어',
		/** Lithuanian */
		'LT' => 'lietuvių kalba',
		/** Latvian */
		'LV' => 'latviešu',
		/** Norwegian (Bokmål) */
		'NB' => 'norsk bokmål',
		/** Dutch */
		'NL' => 'Dutch',
		/** Polish */
		'PL' => 'polski',
		/** Portuguese */
		'PT' => 'português',
		/** Portuguese (Brazilian) */
		'PT-BR' => 'português',
		/** Portuguese (all other Portuguese variants) */
		'PT-PT' => 'português',
		/** Romanian */
		'RO' => 'limba română',
		/** Russian */
		'RU' => 'русский язык',
		/** Slovak */
		'SK' => 'slovenčina',
		/** Slovenian */
		'SL' => 'slovenski jezik',
		/** Swedish */
		'SV' => 'Svenska',
		/** Turkish */
		'TR' => 'Türkçe',
		/** Ukrainian */
		'UK' => 'українська мова',
		/** Chinese (simplified) */
		'ZH' => '中文',
		/** Chinese (traditional) */
		'ZT' => '中文 (繁體)',
	];

	private Config $config;
	private HttpRequestFactory $httpRequestFactory;
	private LanguageNameUtils $languageNameUtils;
	private ObjectCacheFactory $objectCacheFactory;
	private TitleFactory $titleFactory;
	private WikiPageFactory $wikiPageFactory;

	public function __construct(
		ConfigFactory $configFactory,
		HttpRequestFactory $httpRequestFactory,
		LanguageNameUtils $languageNameUtils,
		ObjectCacheFactory $objectCacheFactory,
		TitleFactory $titleFactory,
		WikiPageFactory $wikiPageFactory
	) {
		$this->httpRequestFactory = $httpRequestFactory;
		$this->languageNameUtils = $languageNameUtils;
		$this->objectCacheFactory = $objectCacheFactory;
		$this->titleFactory = $titleFactory;
		$this->wikiPageFactory = $wikiPageFactory;

		$this->config = $configFactory->makeConfig( 'LibreTranslate' );
	}

	private function callTranslation( string $text, string $targetLanguage ): string {
		// Check parameters
		if ( !$text || !$targetLanguage ) {
			return '';
		}

		if ( strlen( $text ) > 131072 ) {
			// Exit if content length is over 128KiB
			return '';
		}

		$targetLanguage = strtolower( $targetLanguage );

		// Call API
		$request = $this->httpRequestFactory->createMultiClient(
			[ 'proxy' => $this->config->get( MainConfigNames::HTTPProxy ) ]
		)->run( [
			'url' => $this->config->get( ConfigNames::Url ) . '/translate',
			'method' => 'POST',
			'body' => [
				'source' => 'auto',
				'target' => $targetLanguage,
				'format' => 'html',
				'q' => $text,
			],
			'headers' => [
				'User-Agent' => 'LibreTranslate MediaWiki extension (https://github.com/miraheze/LibreTranslate)',
			]
		], [ 'reqTimeout' => $this->config->get( ConfigNames::Timeout ) ] );

		// Check if the HTTP response code is returning 200
		if ( $request['code'] !== 200 ) {
			return '';
		}

		$json = json_decode( $request['body'], true );
		return $json['translatedText'] ?? '';
	}

	private function storeCache( string $key, string $value ): bool {
		if ( !$this->config->get( ConfigNames::Caching ) ) {
			return false;
		}

		$cache = $this->objectCacheFactory->getInstance( CACHE_ANYTHING );
		$cacheKey = $cache->makeKey( 'LibreTranslate', $key );
		return $cache->set( $cacheKey, $value, $this->config->get( ConfigNames::CachingTime ) );
	}

	private function getCache( string $key ): bool|string {
		if ( !$this->config->get( ConfigNames::Caching ) ) {
			return false;
		}

		$cache = $this->objectCacheFactory->getInstance( CACHE_ANYTHING );
		$cacheKey = $cache->makeKey( 'LibreTranslate', $key );

		if ( $this->config->get( ConfigNames::CachingTime ) === 0 ) {
			$cache->delete( $cacheKey );
			return false;
		}

		return $cache->get( $cacheKey );
	}

	/**
	 * https://www.mediawiki.org/wiki/Manual:Hooks/ArticleViewHeader
	 *
	 * @param Article $article
	 * @param bool|ParserOutput|null &$outputDone
	 * @param bool &$pcache
	 */
	public function onArticleViewHeader( $article, &$outputDone, &$pcache ) {
		// Use parser cache
		$pcache = true;

		// Do not change if the (sub)page actually exists */
		if ( $article->getPage()->exists() ) {
			return;
		}

		$title = $article->getTitle();

		// Check if it is a content namespace
		if ( !$title->isContentPage() ) {
			return;
		}

		$basepage = $title->getBaseText();
		$subpage = $title->getSubpageText();

		// Not subpage if the $basepage is the same as $subpage
		if ( strcmp( $basepage, $subpage ) === 0 ) {
			return;
		}

		// Language code check
		if ( !preg_match( '/^[A-Za-z][A-Za-z](\-[A-Za-z][A-Za-z])?$/', $subpage ) ) {
			return;
		}

		// Accept language?
		if ( !array_key_exists( strtoupper( $subpage ), self::TARGET_LANGUAGES ) ) {
			return;
		}

		$baseTitle = $this->titleFactory->newFromText( $basepage, $title->getNamespace() );
		if ( $baseTitle === null || !$baseTitle->exists() ) {
			return;
		}

		$cacheKey = $baseTitle->getArticleID() . '-' . $baseTitle->getLatestRevID() . '-' . strtoupper( $subpage );

		// Get title text for replace (the base page title + language caption)
		$languageCaption = ucfirst(
			$this->languageNameUtils->getLanguageName( $subpage ) ?:
			self::TARGET_LANGUAGES[ strtoupper( $subpage ) ]
		);

		$languageTitle = '';
		if ( !$this->config->get( ConfigNames::SuppressLanguageCaption ) ) {
			$titleText = $baseTitle->getTitleValue()->getText();
			if ( $this->config->get( ConfigNames::TranslateTitle ) ) {
				$titleCacheKey = $cacheKey . '-title';
				$titleText = $this->getCache( $titleCacheKey );
				if ( !$titleText ) {
					$titleText = $this->callTranslation( $baseTitle->getTitleValue()->getText(), $subpage );
					$this->storeCache( $titleCacheKey, $titleText );
				}
			}

			$languageTitle = ( $titleText ?: $baseTitle->getTitleValue()->getText() ) .
				Html::element( 'span',
					[
						  'class' => 'target-language',
					],
					' (' . $languageCaption . ')'
				);
		}

		$page = $this->wikiPageFactory->newFromTitle( $baseTitle );
		if ( !$page->exists() ) {
			return;
		}

		$out = $article->getContext()->getOutput();

		// Get cache if enabled
		$text = $this->getCache( $cacheKey );

		// Translate if cache not found
		if ( !$text ) {
			// Get content of the base page
			$content = $page->getContent();
			if ( !( $content instanceof TextContent ) ) {
				return;
			}

			$text = $content->getText();

			$page->clear();

			// Do translation
			$text = $this->callTranslation( $out->parseAsContent( $text ), $subpage );
			if ( !$text ) {
				return;
			}

			// Store cache if enabled
			$this->storeCache( $cacheKey, $text );
		}

		// Output translated text
		$out->clearHTML();
		$out->addHTML( $text );

		// Language caption
		if ( $languageTitle ) {
			$out->setPageTitle( $languageTitle );
		}

		// Set robot policy
		if ( $this->config->get( ConfigNames::RobotPolicy ) ) {
			$out->setRobotPolicy( $this->config->get( ConfigNames::RobotPolicy ) );
		}

		// Stop to render default message
		$outputDone = true;
	}
}
