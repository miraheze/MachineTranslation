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

	private static $targetLangs = [
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
		/* parameter check */
		if ( !$text || !$tolang ) {
			return '';
		}

		if ( strlen( $text ) > 131072 ) {
			/* encode error or content length over 128KiB */
			return '';
		}

		/* target language code */
		$tolang = strtolower( $tolang );

		/* call API */
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

		/* status here refers to the HTTP response code */
		if ( $request['code'] !== 200 ) {
			return '';
		}

		$json = json_decode( $request['body'], true );
		return $json['translatedText'] ?? '';
	}

	/**
	 * store cache data in MediaWiki ObjectCache mechanism
	 * https://www.mediawiki.org/wiki/Object_cache
	 * https://doc.wikimedia.org/mediawiki-core/master/php/classObjectCache.html
	 * https://doc.wikimedia.org/mediawiki-core/master/php/classBagOStuff.html
	 *
	 * @param string $key
	 * @param string $value
	 * @return bool Success
	 */
	private function storeCache( string $key, string $value ): bool {
		if ( !$this->config->get( 'SubTranslateCaching' ) ) {
			return false;
		}

		$cache = $this->objectCacheFactory->getInstance( CACHE_ANYTHING );
		$cachekey = $cache->makeKey( 'subtranslate', $key );
		return $cache->set( $cachekey, $value, $this->config->get( 'SubTranslateCachingTime' ) );
	}

	/**
	 * get cached data from MediaWiki ObjectCache mechanism
	 * https://www.mediawiki.org/wiki/Object_cache
	 * https://doc.wikimedia.org/mediawiki-core/master/php/classObjectCache.html
	 * https://doc.wikimedia.org/mediawiki-core/master/php/classBagOStuff.html
	 *
	 * @param string $key
	 * @return bool|string
	 */
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
		/* use parser cache */
		$pcache = true;

		/* not change if (sub)page is exist */
		if ( $article->getPage()->exists() ) {
			return;
		}

		$title = $article->getTitle();
		$ns = $title->getNamespace();

		/* check namespace */
		if ( !$title->isContentPage() ) {
			return;
		}

		/* get title text */
		$fullpage = $title->getFullText();
		$basepage = $title->getBaseText();
		$subpage = $title->getSubpageText();

		/* not subpage if same $basepage as $subpage */
		if ( strcmp( $basepage, $subpage ) === 0 ) {
			return;
		}

		/* language code check */
		if ( !preg_match( '/^[A-Za-z][A-Za-z](\-[A-Za-z][A-Za-z])?$/', $subpage ) ) {
			return;
		}

		/* accept language? */
		if ( !array_key_exists( strtoupper( $subpage ), self::$targetLangs ) ) {
			return;
		}

		/* create new Title from basepagename */
		$basetitle = $this->titleFactory->newFromText( $basepage, $ns );
		if ( $basetitle === null || !$basetitle->exists() ) {
			return;
		}

		/* get title text for replace (basepage title + language caption ) */
		$langcaption = ucfirst(
			$this->languageNameUtils->getLanguageName( $subpage ) ??
			self::$targetLangs[ strtoupper( $subpage ) ]
		);

		$langtitle = '';
		if ( !$this->config->get( 'SubTranslateSuppressLanguageCaption' ) ) {
			$langtitle = $basetitle->getTitleValue()->getText() .
				Html::element( 'span',
					[
						  'class' => 'targetlang',
					],
					' (' . $langcaption . ')'
				);
		}

		/* create WikiPage of basepage */
		$page = $this->wikiPageFactory->newFromTitle( $basetitle );
		if ( !$page->exists() ) {
			return;
		}

		$out = $article->getContext()->getOutput();

		/* get cache if enabled */
		$cachekey = $basetitle->getArticleID() . '-' . $basetitle->getLatestRevID() . '-' . strtoupper( $subpage );
		$text = self::getCache( $cachekey );

		/* translate if cache not found */
		if ( !$text ) {
			/* get content of basepage */
			$content = $page->getContent();
			if ( !( $content instanceof TextContent ) ) {
				return;
			}

			$text = $content->getText();

			$page->clear();

			unset( $page );
			unset( $basetitle );

			/* translate */
			$text = self::callTranslation( $out->parseAsContent( $text ), $subpage );
			if ( !$text ) {
				return;
			}

			/* store cache if enabled */
			self::storeCache( $cachekey, $text );
		}

		/* output translated text */
		$out->clearHTML();
		$out->addHTML( $text );

		/* language caption */
		if ( $langtitle ) {
			$out->setPageTitle( $langtitle );
		}

		/* set robot policy */
		if ( $this->config->get( 'SubTranslateRobotPolicy' ) ) {
			/* https://www.mediawiki.org/wiki/Manual:Noindex */
			$out->setRobotPolicy( $this->config->get( 'SubTranslateRobotPolicy' ) );
		}

		/* stop to render default message */
		$outputDone = true;
	}
}
