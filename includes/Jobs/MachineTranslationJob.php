<?php

namespace Miraheze\MachineTranslation\Jobs;

use Job;
use MediaWiki\Config\Config;
use MediaWiki\Config\ConfigFactory;
use Miraheze\MachineTranslation\ConfigNames;
use Miraheze\MachineTranslation\Services\MachineTranslationUtils;

class MachineTranslationJob extends Job {

	public const JOB_NAME = 'MachineTranslationJob';

	private readonly Config $config;

	private readonly string $cacheKey;
	private readonly string $content;
	private readonly string $source;
	private readonly string $target;
	private readonly string $titleText;

	/** @param array<string, string> $params */
	public function __construct(
		array $params,
		ConfigFactory $configFactory,
		private readonly MachineTranslationUtils $machineTranslationUtils
	) {
		parent::__construct( self::JOB_NAME, $params );

		$this->config = $configFactory->makeConfig( 'MachineTranslation' );

		$this->cacheKey = $params['cachekey'];
		$this->content = $params['content'];
		$this->source = $params['source'];
		$this->target = $params['target'];
		$this->titleText = $params['titletext'];
	}

	public function run(): bool {
		$translatedText = $this->machineTranslationUtils->getCache( $this->cacheKey );
		if ( !$translatedText ) {
			$translatedText = $this->machineTranslationUtils->callTranslation(
				$this->content, $this->source, $this->target
			);
		}

		if ( !$translatedText ) {
			$this->machineTranslationUtils->deleteCache( $this->cacheKey . '-progress' );
			return true;
		}

		// Store cache if enabled
		$this->machineTranslationUtils->storeCache( $this->cacheKey, $translatedText );

		if ( $this->config->get( ConfigNames::TranslateTitle ) ) {
			$titleCacheKey = $this->cacheKey . '-title';
			$titleText = $this->machineTranslationUtils->getCache( $titleCacheKey );
			if ( !$titleText ) {
				$titleText = $this->machineTranslationUtils->callTranslation(
					$this->titleText, $this->source, $this->target
				);

				$this->machineTranslationUtils->storeCache( $titleCacheKey, $titleText );
			}
		}

		$this->machineTranslationUtils->deleteCache( $this->cacheKey . '-progress' );

		return true;
	}
}
