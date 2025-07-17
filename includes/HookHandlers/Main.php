<?php

namespace Miraheze\MachineTranslation\HookHandlers;

use JobSpecification;
use MediaWiki\Config\Config;
use MediaWiki\Config\ConfigFactory;
use MediaWiki\Content\TextContent;
use MediaWiki\Html\Html;
use MediaWiki\JobQueue\JobQueueGroupFactory;
use MediaWiki\Languages\LanguageNameUtils;
use MediaWiki\Page\Hook\ArticleViewHeaderHook;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Title\TitleFactory;
use Miraheze\MachineTranslation\ConfigNames;
use Miraheze\MachineTranslation\Jobs\MachineTranslationJob;
use Miraheze\MachineTranslation\Services\MachineTranslationLanguageFetcher;
use Miraheze\MachineTranslation\Services\MachineTranslationUtils;
use function array_flip;
use function strtolower;
use function ucfirst;

class Main implements ArticleViewHeaderHook {

	private readonly Config $config;

	public function __construct(
		ConfigFactory $configFactory,
		private readonly JobQueueGroupFactory $jobQueueGroupFactory,
		private readonly LanguageNameUtils $languageNameUtils,
		private readonly MachineTranslationLanguageFetcher $machineTranslationLanguageFetcher,
		private readonly MachineTranslationUtils $machineTranslationUtils,
		private readonly TitleFactory $titleFactory,
		private readonly WikiPageFactory $wikiPageFactory
	) {
		$this->config = $configFactory->makeConfig( 'MachineTranslation' );
	}

	/** @inheritDoc */
	public function onArticleViewHeader( $article, &$outputDone, &$pcache ) {
		// Use parser cache
		$pcache = true;

		// Do not change if the (sub)page actually exists */
		if ( $article->getPage()->exists() ) {
			return;
		}

		$title = $article->getTitle();

		// Check if it is a content namespace
		if ( !$title->isContentPage() ) {
			return;
		}

		$basepage = $title->getBaseText();
		$subpage = $title->getSubpageText();

		// Not subpage if the $basepage is the same as $subpage
		if ( $basepage === $subpage ) {
			return;
		}

		$languageCode = strtolower( $subpage );

		// Language code check
		if ( !$this->languageNameUtils->isValidCode( $languageCode ) ) {
			return;
		}

		$baseTitle = $this->titleFactory->newFromText( $basepage, $title->getNamespace() );
		if ( $baseTitle === null || !$baseTitle->exists() ) {
			return;
		}

		$page = $this->wikiPageFactory->newFromTitle( $baseTitle );
		if ( !$page->exists() ) {
			return;
		}

		// Accept language?
		if ( !$this->machineTranslationLanguageFetcher->isLanguageSupported( $languageCode ) ) {
			return;
		}

		$cacheKey = $baseTitle->getArticleID() . '-' . $baseTitle->getLatestRevID() . '-' . $languageCode;
		$baseCode = $baseTitle->getPageLanguage()->getCode();

		$source = (string)( array_flip(
			$this->machineTranslationLanguageFetcher->getLanguageCodeMap()
		)[$baseCode] ?? $baseCode );

		$target = (string)( array_flip(
			$this->machineTranslationLanguageFetcher->getLanguageCodeMap()
		)[$languageCode] ?? $languageCode );

		$titleText = $baseTitle->getTitleValue()?->getText();
		if ( $this->config->get( ConfigNames::TranslateTitle ) ) {
			$titleCacheKey = "$cacheKey-title";
			$titleText = $this->machineTranslationUtils->getCache( $titleCacheKey );
			if ( $titleText === false || !$this->config->get( ConfigNames::UseJobQueue ) ) {
				$titleText = $this->machineTranslationUtils->callTranslation(
					$baseTitle->getTitleValue()?->getText(),
					$source, $target
				);

				$this->machineTranslationUtils->storeCache( $titleCacheKey, $titleText );
			}
		}

		$context = $article->getContext();
		$out = $context->getOutput();

		$languageTitle = $titleText !== '' ? $titleText : $baseTitle->getTitleValue()?->getText();
		if ( $this->config->get( ConfigNames::DisplayLanguageName ) ) {
			// Get title text for replace (the base page title + language name)
			$languageName = $context->msg( 'parentheses', ucfirst(
				$this->languageNameUtils->getLanguageName( $languageCode ) ?:
				$this->machineTranslationLanguageFetcher->getLanguageName( $languageCode )
			) )->text();

			$languageTitle .= Html::element( 'span',
				[ 'class' => 'ext-machinetranslation-language-name' ],
				' ' . $languageName
			);
		}

		// Get cache if enabled
		$contentCache = $this->machineTranslationUtils->getCache( $cacheKey );
		$text = $contentCache;

		$titleTextCache = $this->machineTranslationUtils->getCache( "$cacheKey-title" );
		$needsTitleText = $titleTextCache === false &&
			$this->config->get( ConfigNames::TranslateTitle ) &&
			$this->config->get( ConfigNames::UseJobQueue );

		// Translate if cache not found
		if ( $text === false || $needsTitleText ) {
			if ( $text === false ) {
				// Get content of the base page
				$content = $page->getContent();
				if ( !( $content instanceof TextContent ) ) {
					return;
				}

				$text = $content->getText();
				$page->clear();
			}

			// Do translation
			if ( $this->config->get( ConfigNames::UseJobQueue ) ) {
				if ( !$this->machineTranslationUtils->getCache( "$cacheKey-progress" ) ) {
					$jobQueueGroup = $this->jobQueueGroupFactory->makeJobQueueGroup();
					$jobQueueGroup->push(
						new JobSpecification(
							MachineTranslationJob::JOB_NAME,
							[
								'cachekey' => $cacheKey,
								'content' => $out->parseAsContent( $text ),
								'source' => $source,
								'target' => $target,
								'titletext' => $baseTitle->getTitleValue()?->getText(),
							]
						)
					);
				}

				if ( $contentCache === false ) {
					$message = 'machinetranslation-processing';

					// Store cache if enabled
					$this->machineTranslationUtils->storeCache( "$cacheKey-progress", $message );
					$text = Html::noticeBox( $context->msg( $message )->escaped(), '' );
				}
			} else {
				$text = $this->machineTranslationUtils->callTranslation(
					$out->parseAsContent( $text ),
					$source, $target
				);

				if ( $text === '' ) {
					return;
				}

				// Store cache if enabled
				$this->machineTranslationUtils->storeCache( $cacheKey, $text );
			}
		}

		// Output translated text
		$out->clearHTML();
		$out->addHTML( $text );

		// Page title (from base page) and language name (if enabled)
		$out->setPageTitle( $languageTitle );

		// Set robot policy
		if ( $this->config->get( ConfigNames::RobotPolicy ) ) {
			$out->setRobotPolicy( $this->config->get( ConfigNames::RobotPolicy ) );
		}

		// Stop to render default message
		$outputDone = true;
	}
}
