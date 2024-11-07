<?php

namespace Miraheze\LibreTranslate;

class LanguageUtils {

	private const SUPPORTED_LANGUAGES = [
		// Accepted language codes and captions

		/** Bulgarian */
		'BG' => 'български език',
		/** Czech */
		'CS' => 'český jazyk',
		/** Danish */
		'DA' => 'dansk',
		/** German */
		'DE' => 'Deutsch',
		/** Greek */
		'EL' => 'ελληνικά',
		/** English */
		'EN' => 'English',
		/** English (British) */
		'EN-GB' => 'British English',
		/** English (American) */
		'EN-US' => 'American English',
		/** Spanish */
		'ES' => 'español',
		/** Estonian */
		'ET' => 'eesti keel',
		/** Finnish */
		'FI' => 'suomi',
		/** French */
		'FR' => 'français',
		/** Hungarian */
		'HU' => 'magyar nyelv',
		/** Indonesian */
		'ID' => 'Bahasa Indonesia',
		/** Italian */
		'IT' => 'italiano',
		/** Japanese */
		'JA' => '日本語',
		/** Korean */
		'KO' => '한국어',
		/** Lithuanian */
		'LT' => 'lietuvių kalba',
		/** Latvian */
		'LV' => 'latviešu',
		/** Norwegian (Bokmål) */
		'NB' => 'norsk bokmål',
		/** Dutch */
		'NL' => 'Dutch',
		/** Polish */
		'PL' => 'polski',
		/** Portuguese */
		'PT' => 'português',
		/** Portuguese (Brazilian) */
		'PT-BR' => 'português',
		/** Portuguese (all other Portuguese variants) */
		'PT-PT' => 'português',
		/** Romanian */
		'RO' => 'limba română',
		/** Russian */
		'RU' => 'русский язык',
		/** Slovak */
		'SK' => 'slovenčina',
		/** Slovenian */
		'SL' => 'slovenski jezik',
		/** Swedish */
		'SV' => 'Svenska',
		/** Turkish */
		'TR' => 'Türkçe',
		/** Ukrainian */
		'UK' => 'українська мова',
		/** Chinese (simplified) */
		'ZH' => '中文',
		/** Chinese (traditional) */
		'ZT' => '中文 (繁體)',
	];

	public static function getAllLanguages(): array {
		return self::SUPPORTED_LANGUAGES;
	}

	public static function getLanguageCaption( string $code ): ?string {
		return self::SUPPORTED_LANGUAGES[$code] ?? null;
	}

	public static function isLanguageSupported( string $code ): bool {
		return isset( self::SUPPORTED_LANGUAGES[$code] );
	}

	public static function isValidLanguageCode( string $code ): bool {
		return (bool)preg_match( '/^[A-Za-z][A-Za-z](\-[A-Za-z][A-Za-z])?$/', $code );
	}
}
