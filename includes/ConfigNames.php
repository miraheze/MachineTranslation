<?php

// phpcs:disable Generic.NamingConventions.UpperCaseConstantName.ClassConstantNotUpperCase
namespace Miraheze\MachineTranslation;

/**
 * A class containing constants representing the names of configuration variables,
 * to protect against typos.
 */
class ConfigNames {

	public const Caching = 'MachineTranslationCaching';

	public const CachingTime = 'MachineTranslationCachingTime';

	public const DisplayLanguageName = 'MachineTranslationDisplayLanguageName';

	public const RobotPolicy = 'MachineTranslationRobotPolicy';

	public const ServiceConfig = 'MachineTranslationServiceConfig';

	public const Timeout = 'MachineTranslationTimeout';

	public const TranslateTitle = 'MachineTranslationTranslateTitle';

	public const UseJobQueue = 'MachineTranslationUseJobQueue';
}
