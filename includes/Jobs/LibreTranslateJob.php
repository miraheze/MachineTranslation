<?php

namespace Miraheze\LibreTranslate\Jobs;

use Job;
use MediaWiki\Config\Config;
use MediaWiki\Config\ConfigFactory;
use Miraheze\LibreTranslate\ConfigNames;
use Miraheze\LibreTranslate\Services\LibreTranslateUtils;

class LibreTranslateJob extends Job {

	public const JOB_NAME = 'LibreTranslateJob';

	private Config $config;
	private LibreTranslateUtils $libreTranslateUtils;

	private string $cacheKey;
	private string $content;
	private string $subpage;
	private string $titleText;

	public function __construct(
		array $params,
		ConfigFactory $configFactory,
		LibreTranslateUtils $libreTranslateUtils
	) {
		parent::__construct( self::JOB_NAME, $params );

		$this->config = $configFactory->makeConfig( 'LibreTranslate' );
		$this->libreTranslateUtils = $libreTranslateUtils;

		$this->cacheKey = $params['cachekey'];
		$this->content = $params['content'];
		$this->subpage = $params['subpage'];
		$this->titleText = $params['titletext'];
	}

	public function run(): bool {
		$translatedText = $this->libreTranslateUtils->callTranslation(
			$this->content, $this->subpage
		);

		if ( !$translatedText ) {
			$this->libreTranslateUtils->deleteCache( $this->cacheKey . '-progress' );
			return true;
		}

		// Store cache if enabled
		$this->libreTranslateUtils->storeCache( $this->cacheKey, $translatedText );

		if ( $this->config->get( ConfigNames::TranslateTitle ) ) {
			$titleCacheKey = $this->cacheKey . '-title';
			$titleText = $this->libreTranslateUtils->getCache( $titleCacheKey );
			if ( !$titleText ) {
				$titleText = $this->libreTranslateUtils->callTranslation(
					$this->titleText, $this->subpage
				);

				$this->libreTranslateUtils->storeCache( $titleCacheKey, $titleText );
			}
		}

		$this->libreTranslateUtils->deleteCache( $this->cacheKey . '-progress' );

		return true;
	}
}
