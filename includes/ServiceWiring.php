<?php

use MediaWiki\Config\ServiceOptions;
use MediaWiki\MediaWikiServices;
use Miraheze\MachineTranslation\Services\MachineTranslationLanguages;
use Miraheze\MachineTranslation\Services\MachineTranslationUtils;

return [
	'MachineTranslationLanguages' => static function (
		MediaWikiServices $services
	): MachineTranslationLanguages {
		return new MachineTranslationLanguages(
			$services->getHttpRequestFactory(),
			$services->get( 'MachineTranslationUtils' ),
			new ServiceOptions(
				MachineTranslationLanguages::CONSTRUCTOR_OPTIONS,
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
