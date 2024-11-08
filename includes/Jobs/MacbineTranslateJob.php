<?php

namespace Miraheze\MachineTranslate\Jobs;

use Job;
use MediaWiki\Config\Config;
use MediaWiki\Config\ConfigFactory;
use Miraheze\MachineTranslate\ConfigNames;
use Miraheze\MachineTranslate\Services\MachineTranslateUtils;

class MachineTranslateJob extends Job {

	public const JOB_NAME = 'MachineTranslateJob';

	private Config $config;
	private MachineTranslateUtils $machineTranslateUtils;

	private string $cacheKey;
	private string $content;
	private string $subpage;
	private string $titleText;

	public function __construct(
		array $params,
		ConfigFactory $configFactory,
		MachineTranslateUtils $machineTranslateUtils
	) {
		parent::__construct( self::JOB_NAME, $params );

		$this->config = $configFactory->makeConfig( 'MachineTranslate' );
		$this->machineTranslateUtils = $machineTranslateUtils;

		$this->cacheKey = $params['cachekey'];
		$this->content = $params['content'];
		$this->subpage = $params['subpage'];
		$this->titleText = $params['titletext'];
	}

	public function run(): bool {
		$translatedText = $this->machineTranslateUtils->getCache( $this->cacheKey );
		if ( !$translatedText ) {
			$translatedText = $this->machineTranslateUtils->callTranslation(
				$this->content, $this->subpage
			);
		}

		if ( !$translatedText ) {
			$this->machineTranslateUtils->deleteCache( $this->cacheKey . '-progress' );
			return true;
		}

		// Store cache if enabled
		$this->machineTranslateUtils->storeCache( $this->cacheKey, $translatedText );

		$hasCaption = !$this->config->get( ConfigNames::SuppressLanguageCaption );
		if ( $hasCaption && $this->config->get( ConfigNames::TranslateTitle ) ) {
			$titleCacheKey = $this->cacheKey . '-title';
			$titleText = $this->machineTranslateUtils->getCache( $titleCacheKey );
			if ( !$titleText ) {
				$titleText = $this->machineTranslateUtils->callTranslation(
					$this->titleText, $this->subpage
				);

				$this->machineTranslateUtils->storeCache( $titleCacheKey, $titleText );
			}
		}

		$this->machineTranslateUtils->deleteCache( $this->cacheKey . '-progress' );

		return true;
	}
}
