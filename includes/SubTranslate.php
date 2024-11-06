<?php
/**
 * SubTranslate MediaWiki extension  version 1.0.2
 *	for details please see: https://www.mediawiki.org/wiki/Extension:SubTranslate
 *
 * Copyright (c) 2023 Kimagurenote https://kimagurenote.net/
 * License: Revised BSD license http://opensource.org/licenses/BSD-3-Clause
 *
 * Function:
 *	Mediawiki extension that embed images from cloud storage services.
 *
 * Dependency:
 *	MediaWiki 1.35+
 *	PHP 7.2.0+ - call json_encode() with JSON_INVALID_UTF8_IGNORE
 *	https://www.php.net/manual/ja/json.constants.php
 *
 * History:
 * 2023.09.21 Version 1.0.2
 *	support setRobotpolicy
 * 2023.09.11 Version 1.0.1.2 (testing)
 *	get language caption from MediaWiki\Languages\LanguageNameUtils
 *	https://doc.wikimedia.org/mediawiki-core/master/php/classMediaWiki_1_1Languages_1_1LanguageNameUtils.html#aa253bf7eaeef5428f239ea71e81dbdbe
 * 2023.08.18 Version 1.0.1
 *	support to add language captions in page title <h1>…</h1>
 * 2023.08.17 Version 1.0.0
 *	1st test (support only DeepL)
 *
 * @file
 * @ingroup Extensions
 * @author Kimagurenote
 * @copyright © 2023 Kimagurenote
 * @license The BSD 3-Clause License
 */

use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

class SubTranslate {

	/* accepted language codes and captions */
	static $targetLangs = [
		'BG' => 'български език',	/* Bulgarian */
		'CS' => 'český jazyk',	/* Czech */
		'DA' => 'dansk',	/* Danish */
		'DE' => 'Deutsch',	/* German */
		'EL' => 'ελληνικά',	/* Greek */
		'EN' => 'English',	/* English */	/* unspecified variant for backward compatibility; please select EN-GB or EN-US instead */
		'EN-GB' => 'British English',	/* English (British) */
		'EN-US' => 'American English',	/* English (American) */
		'ES' => 'español',	/* Spanish */
		'ET' => 'eesti keel',	/* Estonian */
		'FI' => 'suomi',	/* Finnish */
		'FR' => 'français',	/* French */
		'HU' => 'magyar nyelv',	/* Hungarian */
		'ID' => 'Bahasa Indonesia',	/* Indonesian */
		'IT' => 'italiano',	/* Italian */
		'JA' => '日本語',	/* Japanese */
		'KO' => '한국어',	/* Korean */
		'LT' => 'lietuvių kalba',	/* Lithuanian */
		'LV' => 'latviešu',	/* Latvian */
		'NB' => 'norsk bokmål',	/* Norwegian (Bokmål) */
		'NL' => 'Dutch',	/* Dutch */
		'PL' => 'polski',	/* Polish */
		'PT' => 'português',	/* Portuguese */	/* unspecified variant for backward compatibility; please select PT-BR or PT-PT instead */
		'PT-BR' => 'português',	/* Portuguese (Brazilian) */
		'PT-PT' => 'português',	/* Portuguese (all Portuguese varieties excluding Brazilian Portuguese) */
		'RO' => 'limba română',	/* Romanian */
		'RU' => 'русский язык',	/* Russian */
		'SK' => 'slovenčina',	/* Slovak */
		'SL' => 'slovenski jezik',	/* Slovenian */
		'SV' => 'Svenska',	/* Swedish */
		'TR' => 'Türkçe',	/* Turkish */
		'UK' => 'українська мова',	/* Ukrainian */
		'ZH' => '中文'	/* Chinese (simplified) */
	];


	private static function callTranslation( string $text, string $tolang ): string {
		global $wgHTTPProxy, $wgSubTranslateTimeout;

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

		$requestFactory = MediaWikiServices::getInstance()->getHttpRequestFactory();
		$request = $requestFactory->createMultiClient( [ 'proxy' => $wgHTTPProxy ] )
			->run( [
				'url' => 'https://trans.zillyhuhn.com/translate',
				'method' => 'POST',
				'body' => [
					'source' => 'auto',
					'target' => $tolang,
					'format' => 'html',
					'q' => $text,
				],
				'headers' => [
					'Content-Type' => 'application/json',
					'User-Agent' => 'SubTranslate, MediaWiki extension (https://github.com/miraheze/SubTranslate)',
				]
			], [ 'reqTimeout' => $wgSubTranslateTimeout ] );

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
	private static function storeCache( string $key, string $value ): bool {
		global $wgSubTranslateCaching, $wgSubTranslateCachingTime;

		if ( !$wgSubTranslateCaching ) {
			return false;
		}

		/* Cache expiry time in seconds, default = 86400sec (1d) */
		$exptime = $wgSubTranslateCachingTime ?? 86400;

		$cache = ObjectCache::getInstance( CACHE_ANYTHING );
		$cachekey = $cache->makeKey( 'subtranslate', $key );
		return $cache->set( $cachekey, $value, $exptime );
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
	private static function getCache( string $key ): bool|string {
		global $wgSubTranslateCaching, $wgSubTranslateCachingTime;

		if ( !$wgSubTranslateCaching ) {
			return false;
		}

		$cache = ObjectCache::getInstance( CACHE_ANYTHING );
		$cachekey = $cache->makeKey( 'subtranslate', $key );

		if ( $wgSubTranslateCachingTime === false ) {
			$cache->delete( $cachekey );
			return false;
		}

		return $cache->get( $cachekey );
	}


	/**
	 * https://www.mediawiki.org/wiki/Manual:Hooks/ArticleViewHeader
	 *
	 * @param Article $article
	 * @param bool|ParserOutput|null &$outputDone
	 * @param bool &$pcache
	 */
	public static function onArticleViewHeader( $article, &$outputDone, &$pcache ) {
		global $wgSubTranslateSuppressLanguageCaption, $wgSubTranslateRobotPolicy;

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
		$basetitle = Title::newFromText( $basepage, $ns );
		if ( $basetitle === null || !$basetitle->exists() ) {
			return;
		}

		/* get title text for replace (basepage title + language caption ) */
		$langcaption = ucfirst( MediaWikiServices::getInstance()->getLanguageNameUtils()->getLanguageName( $subpage ) ?? self::$targetLangs[ strtoupper( $subpage ) ] );

		$langtitle = '';
		if ( !$wgSubTranslateSuppressLanguageCaption ) {
			$langtitle = $basetitle->getTitleValue()->getText() .
				Html::element( 'span',
					[
					      'class' => 'targetlang',
					],
					'(' . $langcaption . ')'
				);
		}

		/* create WikiPage of basepage */
		$page = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $basetitle );
		if ( $page === null || !$page->exists() ) {
			return;
		}

		/* https://www.mediawiki.org/wiki/Manual:OutputPage.php */
		$out = $article->getContext()->getOutput();

		/* get cache if enabled */
		$cachekey = $basetitle->getArticleID() . '-' . $basetitle->getLatestRevID() . '-' . strtoupper( $subpage );
		$text = self::getCache( $cachekey );

		/* translate if cache not found */
		if ( !$text ) {
			/* get content of basepage */
			$content = $page->getContent();
			$text = ContentHandler::getContentText( $content );

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
		if ( $wgSubTranslateRobotPolicy ) {
			/* https://www.mediawiki.org/wiki/Manual:Noindex */
			$out->setRobotpolicy( $wgSubTranslateRobotPolicy );
		}

		/* stop to render default message */
		$outputDone = true;
	}
}
