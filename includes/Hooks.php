<?php

namespace Miraheze\SubTranslate;

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
use ObjectCacheFactory;
use TextContent;

class Hooks {

	private const TARGET_LANGUAGES = [
		// Accepted language codes and captions
		// phpcs:disable MediaWiki.WhiteSpace.SpaceBeforeSingleLineComment.NewLineComment
		'BG' => 'български език', /* Bulgarian */
		'CS' => 'český jazyk', /* Czech */
		'DA' => 'dansk', /* Danish */
		'DE' => 'Deutsch', /* German */
		'EL' => 'ελληνικά', /* Greek */
		'EN' => 'English', /* English */
		'EN-GB' => 'British English', /* English (British) */
		'EN-US' => 'American English', /* English (American) */
		'ES' => 'español', /* Spanish */
		'ET' => 'eesti keel', /* Estonian */
		'FI' => 'suomi', /* Finnish */
		'FR' => 'français', /* French */
		'HU' => 'magyar nyelv',	/* Hungarian */
		'ID' => 'Bahasa Indonesia', /* Indonesian */
		'IT' => 'italiano', /* Italian */
		'JA' => '日本語', /* Japanese */
		'KO' => '한국어', /* Korean */
		'LT' => 'lietuvių kalba', /* Lithuanian */
		'LV' => 'latviešu', /* Latvian */
		'NB' => 'norsk bokmål',	/* Norwegian (Bokmål) */
		'NL' => 'Dutch', /* Dutch */
		'PL' => 'polski', /* Polish */
		'PT' => 'português', /* Portuguese */
		'PT-BR' => 'português',	/* Portuguese (Brazilian) */
		'PT-PT' => 'português',	/* Portuguese (all Portuguese varieties excluding Brazilian Portuguese) */
		'RO' => 'limba română',	/* Romanian */
		'RU' => 'русский язык',	/* Russian */
		'SK' => 'slovenčina', /* Slovak */
		'SL' => 'slovenski jezik', /* Slovenian */
		'SV' => 'Svenska', /* Swedish */
		'TR' => 'Türkçe', /* Turkish */
		'UK' => 'українська мова', /* Ukrainian */
		'ZH' => '中文', /* Chinese (simplified) */
		// phpcs:enable
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

		$this->config = $configFactory->makeConfig( 'SubTranslate' );
	}

	private function callTranslation( string $text, string $tolang ): string {
		// Check parameters
		if ( !$text || !$tolang ) {
			return '';
		}

		if ( strlen( $text ) > 131072 ) {
			// Exit if content length is over 128KiB
			return '';
		}

		// Target language code
		$tolang = strtolower( $tolang );

		// Call API
		$request = $this->httpRequestFactory->createMultiClient(
			[ 'proxy' => $this->config->get( MainConfigNames::HTTPProxy ) ]
		)->run( [
			'url' => $this->config->get( 'SubTranslateLibreTranslateUrl' ) . '/translate',
			'method' => 'POST',
			'body' => [
				'source' => 'auto',
				'target' => $tolang,
				'format' => 'html',
				'q' => $text,
			],
			'headers' => [
				'User-Agent' => 'SubTranslate, MediaWiki extension (https://github.com/miraheze/SubTranslate)',
			]
		], [ 'reqTimeout' => $this->config->get( 'SubTranslateTimeout' ) ] );

		// Check if the HTTP response code is returning 200
		if ( $request['code'] !== 200 ) {
			return '';
		}

		$json = json_decode( $request['body'], true );
		return $json['translatedText'] ?? '';
	}

	private function storeCache( string $key, string $value ): bool {
		if ( !$this->config->get( 'SubTranslateCaching' ) ) {
			return false;
		}

		$cache = $this->objectCacheFactory->getInstance( CACHE_ANYTHING );
		$cacheKey = $cache->makeKey( 'subtranslate', $key );
		return $cache->set( $cacheKey, $value, $this->config->get( 'SubTranslateCachingTime' ) );
	}

	private function getCache( string $key ): bool|string {
		if ( !$this->config->get( 'SubTranslateCaching' ) ) {
			return false;
		}

		$cache = $this->objectCacheFactory->getInstance( CACHE_ANYTHING );
		$cacheKey = $cache->makeKey( 'subtranslate', $key );

		if ( $this->config->get( 'SubTranslateCachingTime' ) === 0 ) {
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

		// Get title text for replace (the base page title + language caption)
		$languageCaption = ucfirst(
			$this->languageNameUtils->getLanguageName( $subpage ) ??
			self::TARGET_LANGUAGES[ strtoupper( $subpage ) ]
		);

		$languageTitle = '';
		if ( !$this->config->get( 'SubTranslateSuppressLanguageCaption' ) ) {
			$languageTitle = $basetitle->getTitleValue()->getText() .
				Html::element( 'span',
					[
						  'class' => 'targetlang',
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
		$cacheKey = $baseTitle->getArticleID() . '-' . $baseTitle->getLatestRevID() . '-' . strtoupper( $subpage );
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

			unset( $page );
			unset( $baseTitle );

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
		if ( $this->config->get( 'SubTranslateRobotPolicy' ) ) {
			$out->setRobotPolicy( $this->config->get( 'SubTranslateRobotPolicy' ) );
		}

		// Stop to render default message
		$outputDone = true;
	}
}
