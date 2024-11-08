<?php

namespace Miraheze\LibreTranslate\HookHandlers;

use Article;
use JobSpecification;
use MediaWiki\Config\Config;
use MediaWiki\Config\ConfigFactory;
use MediaWiki\Context\RequestContext;
use MediaWiki\Html\Html;
use MediaWiki\JobQueue\JobQueueGroupFactory;
use MediaWiki\Languages\LanguageNameUtils;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Title\TitleFactory;
use MessageLocalizer;
use Miraheze\LibreTranslate\ConfigNames;
use Miraheze\LibreTranslate\Jobs\LibreTranslateJob;
use Miraheze\LibreTranslate\LanguageUtils;
use Miraheze\LibreTranslate\Services\LibreTranslateUtils;
use TextContent;

class Main {

	private Config $config;
	private JobQueueGroupFactory $jobQueueGroupFactory;
	private LanguageNameUtils $languageNameUtils;
	private LibreTranslateUtils $libreTranslateUtils;
	private MessageLocalizer $messageLocalizer;
	private TitleFactory $titleFactory;
	private WikiPageFactory $wikiPageFactory;

	public function __construct(
		ConfigFactory $configFactory,
		JobQueueGroupFactory $jobQueueGroupFactory,
		LanguageNameUtils $languageNameUtils,
		LibreTranslateUtils $libreTranslateUtils,
		TitleFactory $titleFactory,
		WikiPageFactory $wikiPageFactory
	) {
		$this->jobQueueGroupFactory = $jobQueueGroupFactory;
		$this->languageNameUtils = $languageNameUtils;
		$this->libreTranslateUtils = $libreTranslateUtils;
		$this->titleFactory = $titleFactory;
		$this->wikiPageFactory = $wikiPageFactory;

		$this->config = $configFactory->makeConfig( 'LibreTranslate' );
		$this->messageLocalizer = RequestContext::getMain();
	}

	/**
	 * https://www.mediawiki.org/wiki/Manual:Hooks/ArticleViewHeader
	 *
	 * @param Article $article
	 * @param bool|ParserOutput|null &$outputDone
	 * @param bool &$pcache
	 */
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
		if ( strcmp( $basepage, $subpage ) === 0 ) {
			return;
		}

		// Language code check
		if ( !LanguageUtils::isValidLanguageCode( $subpage ) ) {
			return;
		}

		// Accept language?
		if ( !LanguageUtils::isLanguageSupported( strtoupper( $subpage ) ) ) {
			return;
		}

		$baseTitle = $this->titleFactory->newFromText( $basepage, $title->getNamespace() );
		if ( $baseTitle === null || !$baseTitle->exists() ) {
			return;
		}

		$cacheKey = $baseTitle->getArticleID() . '-' . $baseTitle->getLatestRevID() . '-' . strtoupper( $subpage );

		// Get title text for replace (the base page title + language caption)
		$languageCaption = ucfirst(
			$this->languageNameUtils->getLanguageName( $subpage ) ?:
			LanguageUtils::getLanguageCaption( strtoupper( $subpage ) )
		);

		$languageTitle = '';
		if ( !$this->config->get( ConfigNames::SuppressLanguageCaption ) ) {
			$titleText = $baseTitle->getTitleValue()->getText();
			if ( $this->config->get( ConfigNames::TranslateTitle ) ) {
				$titleCacheKey = $cacheKey . '-title';
				$titleText = $this->libreTranslateUtils->getCache( $titleCacheKey );
				if ( !$titleText && !$this->config->get( ConfigNames::UseJobQueue ) ) {
					$titleText = $this->libreTranslateUtils->callTranslation(
						$baseTitle->getTitleValue()->getText(),
						$subpage
					);

					$this->libreTranslateUtils->storeCache( $titleCacheKey, $titleText );
				}
			}

			$languageTitle = ( $titleText ?: $baseTitle->getTitleValue()->getText() ) .
				Html::element( 'span',
					[
						  'class' => 'target-language',
					],
					' (' . $languageCaption . ')'
				);
		}

		$page = $this->wikiPageFactory->newFromTitle( $baseTitle );
		if ( !$page->exists() ) {
			return;
		}

		$out = $article->getContext()->getOutput();

		// Get cache if enabled
		$contentCache = $this->libreTranslateUtils->getCache( $cacheKey );
		$text = $contentCache;

		$titleTextCache = $this->libreTranslateUtils->getCache( $cacheKey . '-title' );
		$needsTitleText = !$titleTextCache && !$this->config->get( ConfigNames::SuppressLanguageCaption ) &&
			$this->config->get( ConfigNames::TranslateTitle ) &&
			$this->config->get( ConfigNames::UseJobQueue );

		// Translate if cache not found
		if ( !$contentCache || $needsTitleText ) {
			if ( !$contentCache ) {
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
				if ( !$this->libreTranslateUtils->getCache( $cacheKey . '-progress' ) ) {
					$jobQueueGroup = $this->jobQueueGroupFactory->makeJobQueueGroup();
					$jobQueueGroup->push(
						new JobSpecification(
							LibreTranslateJob::JOB_NAME,
							[
								'cachekey' => $cacheKey,
								'content' => $out->parseAsContent( $text ),
								'subpage' => $subpage,
								'titletext' => $baseTitle->getTitleValue()->getText(),
							]
						)
					);
				}

				if ( !$contentCache ) {
					$message = 'libretranslate-processing';

					// Store cache if enabled
					$this->libreTranslateUtils->storeCache( $cacheKey . '-progress', $message );
					$text = Html::noticeBox(
						$this->messageLocalizer->msg( $message )->escaped(), ''
					);
				}
			} else {
				$text = $this->libreTranslateUtils->callTranslation(
					$out->parseAsContent( $text ),
					$subpage
				);

				if ( !$text ) {
					return;
				}

				// Store cache if enabled
				$this->libreTranslateUtils->storeCache( $cacheKey, $text );
			}
		}

		// Output translated text
		$out->clearHTML();
		$out->addHTML( $text );

		// Language caption
		if ( $languageTitle ) {
			$out->setPageTitle( $languageTitle );
		}

		// Set robot policy
		if ( $this->config->get( ConfigNames::RobotPolicy ) ) {
			$out->setRobotPolicy( $this->config->get( ConfigNames::RobotPolicy ) );
		}

		// Stop to render default message
		$outputDone = true;
	}
}
