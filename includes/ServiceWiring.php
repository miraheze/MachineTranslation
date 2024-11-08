<?php

use MediaWiki\Config\ServiceOptions;
use MediaWiki\MediaWikiServices;
use Miraheze\MachineTranslation\Services\LanguageUtils;
use Miraheze\MachineTranslation\Services\MachineTranslationUtils;

return [
	'MachineTranslationLanguageUtils' => static function (
		MediaWikiServices $services
	): LanguageUtils {
		return new LanguageUtils(
			$services->getHttpRequestFactory(),
			new ServiceOptions(
				LanguageUtils::CONSTRUCTOR_OPTIONS,
				$services->getConfigFactory()->makeConfig( 'MachineTranslation' )
			)
		);
	},
	'MachineTranslationUtils' => static function (
		MediaWikiServices $services
	): MachineTranslationUtils {
		return new MachineTranslationUtils(
			$services->getHttpRequestFactory(),
			$services->getObjectCacheFactory(),
			new ServiceOptions(
				MachineTranslationUtils::CONSTRUCTOR_OPTIONS,
				$services->getConfigFactory()->makeConfig( 'MachineTranslation' )
			)
		);
	},
];
