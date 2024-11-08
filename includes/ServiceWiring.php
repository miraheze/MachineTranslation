<?php

use MediaWiki\Config\ServiceOptions;
use MediaWiki\MediaWikiServices;
use Miraheze\MachineTranslate\Services\MachineTranslateUtils;

return [
	'MachineTranslateUtils' => static function ( MediaWikiServices $services ): MachineTranslateUtils {
		return new MachineTranslateUtils(
			$services->getHttpRequestFactory(),
			$services->getObjectCacheFactory(),
			new ServiceOptions(
				MachineTranslateUtils::CONSTRUCTOR_OPTIONS,
				$services->getConfigFactory()->makeConfig( 'MachineTranslate' )
			)
		);
	},
];
