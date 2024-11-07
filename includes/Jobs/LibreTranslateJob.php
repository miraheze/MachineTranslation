<?php

namespace Miraheze\LibreTranslate\Jobs;

use Job;
use Miraheze\LibreTranslate\Services\LibreTranslateUtils;

class LibreTranslateJob extends Job {

	public const JOB_NAME = 'LibreTranslateJob';

	private LibreTranslateUtils $libreTranslateUtils;

	private string $cacheKey;
	private string $content;
	private string $subpage;

	public function __construct(
		array $params,
		LibreTranslateUtils $libreTranslateUtils
	) {
		parent::__construct( self::JOB_NAME, $params );

		$this->libreTranslateUtils = $libreTranslateUtils;

		$this->cacheKey = $params['cachekey'];
		$this->content = $params['content'];
		$this->subpage = $params['subpage'];
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
		$this->libreTranslateUtils->deleteCache( $this->cacheKey . '-progress' );

		return true;
	}
}
