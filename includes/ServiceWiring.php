<?php

namespace Miraheze\MachineTranslation;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\MediaWikiServices;
use Miraheze\MachineTranslation\Services\MachineTranslationLanguageFetcher;
use Miraheze\MachineTranslation\Services\MachineTranslationUtils;

return [
	'MachineTranslationLanguageFetcher' => static function (
		MediaWikiServices $services
	): MachineTranslationLanguageFetcher {
		return new MachineTranslationLanguageFetcher(
			$services->getHttpRequestFactory(),
			$services->get( 'MachineTranslationUtils' ),
			new ServiceOptions(
				MachineTranslationLanguageFetcher::CONSTRUCTOR_OPTIONS,
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
