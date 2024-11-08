<?php

use MediaWiki\Config\ServiceOptions;
use MediaWiki\MediaWikiServices;
use Miraheze\LibreTranslate\Services\LibreTranslateUtils;

return [
	'LibreTranslateUtils' => static function ( MediaWikiServices $services ): LibreTranslateUtils {
		return new LibreTranslateUtils(
			$services->getHttpRequestFactory(),
			$services->getObjectCacheFactory(),
			new ServiceOptions(
				LibreTranslateUtils::CONSTRUCTOR_OPTIONS,
				$services->getConfigFactory()->makeConfig( 'LibreTranslate' )
			)
		);
	},
];
