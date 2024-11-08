<?php

namespace Miraheze\MachineTranslation\Jobs;

use Job;
use MediaWiki\Config\Config;
use MediaWiki\Config\ConfigFactory;
use Miraheze\MachineTranslation\ConfigNames;
use Miraheze\MachineTranslation\Services\MachineTranslationUtils;

class MachineTranslationJob extends Job {

	public const JOB_NAME = 'MachineTranslationJob';

	private Config $config;
	private MachineTranslationUtils $machineTranslationUtils;

	private string $cacheKey;
	private string $content;
	private string $subpage;
	private string $titleText;

	public function __construct(
		array $params,
		ConfigFactory $configFactory,
		MachineTranslationUtils $machineTranslationUtils
	) {
		parent::__construct( self::JOB_NAME, $params );

		$this->config = $configFactory->makeConfig( 'MachineTranslation' );
		$this->machineTranslationUtils = $machineTranslationUtils;

		$this->cacheKey = $params['cachekey'];
		$this->content = $params['content'];
		$this->subpage = $params['subpage'];
		$this->titleText = $params['titletext'];
	}

	public function run(): bool {
		$translatedText = $this->machineTranslationUtils->getCache( $this->cacheKey );
		if ( !$translatedText ) {
			$translatedText = $this->machineTranslationUtils->callTranslation(
				$this->content, $this->subpage
			);
		}

		if ( !$translatedText ) {
			$this->machineTranslationUtils->deleteCache( $this->cacheKey . '-progress' );
			return true;
		}

		// Store cache if enabled
		$this->machineTranslationUtils->storeCache( $this->cacheKey, $translatedText );

		$hasCaption = !$this->config->get( ConfigNames::SuppressLanguageCaption );
		if ( $hasCaption && $this->config->get( ConfigNames::TranslateTitle ) ) {
			$titleCacheKey = $this->cacheKey . '-title';
			$titleText = $this->machineTranslationUtils->getCache( $titleCacheKey );
			if ( !$titleText ) {
				$titleText = $this->machineTranslationUtils->callTranslation(
					$this->titleText, $this->subpage
				);

				$this->machineTranslationUtils->storeCache( $titleCacheKey, $titleText );
			}
		}

		$this->machineTranslationUtils->deleteCache( $this->cacheKey . '-progress' );

		return true;
	}
}
