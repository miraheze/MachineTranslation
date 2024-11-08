<?php

use MediaWiki\Config\ServiceOptions;
use MediaWiki\MediaWikiServices;
use Miraheze\MachineTranslation\Services\MachineTranslationUtils;

return [
	'MachineTranslationUtils' => static function ( MediaWikiServices $services ): MachineTranslationUtils {
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
