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

if( !defined( 'MEDIAWIKI' ) ) {
	echo( "This file is an extension to the MediaWiki software and cannot be used standalone.\n" );
	die( 1 );
}

use MediaWiki\Languages\LanguageNameUtils;
use MediaWiki\MediaWikiServices;

class SubTranslate {
	/* Extension information */
	static $extname = "";
	static $extinfo = null;

	/* accepted language codes and captions */
	static $targetLangs = [
		'BG' => "български език",	/* Bulgarian */
		'CS' => "český jazyk",	/* Czech */
		'DA' => "dansk",	/* Danish */
		'DE' => "Deutsch",	/* German */
		'EL' => "ελληνικά",	/* Greek */
		'EN' => "English",	/* English */	/* unspecified variant for backward compatibility; please select EN-GB or EN-US instead */
		'EN-GB' => "British English",	/* English (British) */
		'EN-US' => "American English",	/* English (American) */
		'ES' => "español",	/* Spanish */
		'ET' => "eesti keel",	/* Estonian */
		'FI' => "suomi",	/* Finnish */
		'FR' => "français",	/* French */
		'HU' => "magyar nyelv",	/* Hungarian */
		'ID' => "Bahasa Indonesia",	/* Indonesian */
		'IT' => "italiano",	/* Italian */
		'JA' => "日本語",	/* Japanese */
		'KO' => "한국어",	/* Korean */
		'LT' => "lietuvių kalba",	/* Lithuanian */
		'LV' => "latviešu",	/* Latvian */
		'NB' => "norsk bokmål",	/* Norwegian (Bokmål) */
		'NL' => "Dutch",	/* Dutch */
		'PL' => "polski",	/* Polish */
		'PT' => "português",	/* Portuguese */	/* unspecified variant for backward compatibility; please select PT-BR or PT-PT instead */
		'PT-BR' => "português",	/* Portuguese (Brazilian) */
		'PT-PT' => "português",	/* Portuguese (all Portuguese varieties excluding Brazilian Portuguese) */
		'RO' => "limba română",	/* Romanian */
		'RU' => "русский язык",	/* Russian */
		'SK' => "slovenčina",	/* Slovak */
		'SL' => "slovenski jezik",	/* Slovenian */
		'SV' => "Svenska",	/* Swedish */
		'TR' => "Türkçe",	/* Turkish */
		'UK' => "українська мова",	/* Ukrainian */
		'ZH' => "中文"	/* Chinese (simplified) */
	];


	/**
	 * @param string $text
	 * string $tolang
	 * return string
	 *	""	failed
	 */
	private static function callDeepL( $text, $tolang ) {
		global $wgHTTPProxy, $wgSubTranslateTimeout, $wgSubTranslateAPIKey;

		/* parameter check */
		if( empty( $text ) or empty( $tolang ) ) {
			return "";
		}
		/* target language code */
		$tolang = strtoupper( $tolang );

		/* get API key and host */
		foreach( $wgSubTranslateAPIKey as $host => $key ) {
			if( preg_match( '/^api(-free)?.deepl.com$/', $host ) ) {
				break;
			}
			unset( $host );
		}
		if( empty( $host ) or empty( $key ) ) {
			return "";
		}

		/* get self info to use User-Agent */
		if( empty( self::$extname ) or empty( self::$extinfo ) ) {
			self::$extname = get_class();
			self::$extinfo = ( ExtensionRegistry::getInstance()->getAllThings() )[ self::$extname ];
		}

		/* make parameter to call API */
		$data = [
			'target_lang' => $tolang,
			'tag_handling' => "html",
			'text' => [ $text ]
		];
		$json = json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_IGNORE );
		/* for debug *	var_dump( $json ); */
		if( empty( $json ) ) {
			/* for debug */	var_dump( json_last_error() );
			return "";
		}
		if( strlen( $json ) > 131072 ) {
			/* encode error or content length over 128KiB */
			return "";
		}
		$param = [
			'http' => [
				'method' => "POST",
				'header' => [
					"Host: $host",
					"Authorization: DeepL-Auth-Key $key",
					"User-Agent: " . self::$extname . '/' . self::$extinfo['version'] . " (MediaWiki Extension)",
					"Content-Length: " . strlen( $json ),
					"Content-Type: application/json"
				],
				'content' => $json,
				'timeout' => (float)( $wgSubTranslateTimeout ?? 5 )
			]
		];
		if ( isset( $wgHTTPProxy ) ) {
			$param['http']['proxy'] = $wgHTTPProxy;
		}
		$stream = stream_context_create( $param );

		/* call API */
		/* https://www.deepl.com/ja/docs-api/translate-text/multiple-sentences */
		$ret = file_get_contents( "https://$host/v2/translate", false, $stream );
		if( empty( $ret ) ) {
			return "";
		}

		$json = json_decode( $ret, true );

		return $json['translations'][0]['text'] ?? "";
	}


	/**
	 * store cache data in MediaWiki ObjectCache mechanism
	 * https://www.mediawiki.org/wiki/Object_cache
	 * https://doc.wikimedia.org/mediawiki-core/master/php/classObjectCache.html
	 * https://doc.wikimedia.org/mediawiki-core/master/php/classBagOStuff.html
	 *
	 * @param string $key
	 * @param string $value
	 * @param string $exptime Either an interval in seconds or a unix timestamp for expiry
	 * @return bool Success
	 */
	private static function storeCache( $key, $value, $exptime = 0 ) {
		global $wgSubTranslateCaching, $wgSubTranslateCachingTime;

		if( empty( $wgSubTranslateCaching ) ) {
			return false;
		}

		/* Cache expiry time in seconds, default = 86400sec (1d) */
		if( !$exptime ) {
			$exptime = $wgSubTranslateCachingTime ?? 86400;
		}

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
	 * @return mixed
	 */
	private static function getCache( $key ) {
		global $wgSubTranslateCaching, $wgSubTranslateCachingTime;

		if( empty( $wgSubTranslateCaching ) ) {
			return null;
		}

		$cache = ObjectCache::getInstance( CACHE_ANYTHING );
		$cachekey = $cache->makeKey( 'subtranslate', $key );
		if( $wgSubTranslateCachingTime === false ) {
			$cache->delete( $cachekey );
			return null;
		}
		return $cache->get( $cachekey );
	}


	/**
	 * https://www.mediawiki.org/wiki/Manual:Hooks/ArticleViewHeader
	 * @param Article &$article
	 *	https://www.mediawiki.org/wiki/Manual:Article.php
	 *	bool or ParserOutput &$outputDone
	 *	bool &$pcache
	 * return null
	 */
	public static function onArticleViewHeader( &$article, &$outputDone, bool &$pcache ) {
		global $wgContentNamespaces, $wgSubTranslateSuppressLanguageCaption, $wgSubTranslateRobotPolicy;

		/* use parser cache */
		$pcache = true;

		/* not change if (sub)page is exist */
		if( $article->getPage()->exists() ) {
			return;
		}

		/* check namespace */
		$title = $article->getTitle();
		$ns = $title->getNamespace();
		if( empty( $wgContentNamespaces ) ) {
			if( $ns != NS_MAIN ) {
				return;
			}
		} elseif ( !in_array( $ns, $wgContentNamespaces, true ) ) {
			return;
		}

		/* get title text */
		$fullpage = $title->getFullText();
		$basepage = $title->getBaseText();
		$subpage = $title->getSubpageText();

		/* not subpage if same $basepage as $subpage */
		if( strcmp( $basepage, $subpage ) === 0 ) {
			return;
		}

		/* language code check */
		if( !preg_match('/^[A-Za-z][A-Za-z](\-[A-Za-z][A-Za-z])?$/', $subpage ) ) {
			return;
		}
		/* accept language? */
		if( !array_key_exists( strtoupper( $subpage ), self::$targetLangs ) ) {
			return;
		}

		/* create new Title from basepagename */
		$basetitle = Title::newFromText( $basepage, $ns );
		if( $basetitle === null or !$basetitle->exists() ) {
			return;
		}
		/* get title text for replace (basepage title + language caption ) */
		$langcaption = ucfirst( MediaWikiServices::getInstance()->getLanguageNameUtils()->getLanguageName( $subpage ) ?? self::$targetLangs[ strtoupper( $subpage ) ] );
		$langtitle = $wgSubTranslateSuppressLanguageCaption ? "" : $basetitle->getTitleValue()->getText() . '<span class="targetlang"> (' . $langcaption . ')</span>';

		/* create WikiPage of basepage */
		$page = WikiPage::factory( $basetitle );
		if( $page === null or !$page->exists() ) {
			return;
		}

		/* https://www.mediawiki.org/wiki/Manual:OutputPage.php */
		$out = $article->getContext()->getOutput();

		/* get cache if enabled */
		$cachekey = $basetitle->getArticleID() . '-' . $basetitle->getLatestRevID() . '-' . strtoupper( $subpage );
		$text = self::getCache( $cachekey );

		/* translate if cache not found */
		if( empty( $text ) ) {

			/* get content of basepage */
			$content = $page->getContent();
			$text = ContentHandler::getContentText( $content );
			$page->clear();
			unset($page);
			unset($basetitle);

			/* translate */
			$text = self::callDeepL( $out->parseAsContent( $text ), $subpage );
			if( empty( $text ) ) {
				return;
			}

			/* store cache if enabled */
	 		self::storeCache( $cachekey, $text );
		}

		/* output translated text */
		$out->clearHTML();
		$out->addHTML( $text );
		/* language caption */
		if( $langtitle ) {
			$out->setPageTitle( $langtitle );
		}
		/* set robot policy */
		if( !empty( $wgSubTranslateRobotPolicy ) ) {
			/* https://www.mediawiki.org/wiki/Manual:Noindex */
			$out->setRobotpolicy( $wgSubTranslateRobotPolicy );
		}

		/* stop to render default message */
		$outputDone = true;

		return;
	}
}
