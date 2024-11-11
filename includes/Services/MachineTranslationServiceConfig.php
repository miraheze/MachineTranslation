<?php

namespace Miraheze\MachineTranslation\Services;

use ConfigException;
use MediaWiki\Config\ServiceOptions;
use Miraheze\MachineTranslation\ConfigNames;

class MachineTranslationServiceConfig {

	public const CONSTRUCTOR_OPTIONS = [
		ConfigNames::ServiceConfig,
	];

	private array $serviceConfig;

	public function __construct( ServiceOptions $options ) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
		$this->serviceConfig = $options->get( ConfigNames::ServiceConfig );
	}

	public function getType(): string {
		if ( !array_key_exists( $this->serviceConfig['type'] ?? '', $this->getRequired() ) ) {
			throw new ConfigException( 'Unsupported machine translation service configured.' );
		}

		return $this->serviceConfig['type'];
	}

	public function getUrl(): string {
		if ( in_array( 'url', $this->getRequired()[$this->getType()] ) ) {
			throw new ConfigException( 'URL is required for the configured type.' );
		}

		return $this->serviceConfig['url'];
	}

	public function getApiKey(): string {
		if ( in_array( 'apikey', $this->getRequired()[$this->getType()] ) ) {
			throw new ConfigException( 'API key is required for the configured type.' );
		}

		return $this->serviceConfig['apikey'];
	}

	private function getRequired(): array {
		return MachineTranslationUtils::REQUIRED_SERVICE_OPTIONS;
	}
}
