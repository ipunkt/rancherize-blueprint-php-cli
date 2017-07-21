<?php namespace RancherizeBlueprintPhpCli\PhpCliBlueprint;

use Rancherize\Blueprint\Blueprint;
use Rancherize\Blueprint\Flags\HasFlagsTrait;
use Rancherize\Blueprint\Infrastructure\Dockerfile\Dockerfile;
use Rancherize\Blueprint\Infrastructure\Infrastructure;
use Rancherize\Blueprint\Validation\Exceptions\ValidationFailedException;
use Rancherize\Configuration\Configurable;
use Rancherize\Configuration\Configuration;
use Rancherize\Configuration\PrefixConfigurableDecorator;
use Rancherize\Configuration\Services\ConfigurableFallback;
use Rancherize\Configuration\Services\ConfigurationInitializer;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class PhpCliBlueprint
 * @package RancherizeBlueprintPhpCli\PhpCliBlueprint
 */
class PhpCliBlueprint implements Blueprint {

	/**
	 * Provider $this->getFlag('dev', false) to detect `rancherize init php-cli --dev` in init.
	 */
	use HasFlagsTrait;

	/**
	 * Fill the configurable with all possible options with explanatory default options set
	 *
	 * @param Configurable $configurable
	 * @param string $environment
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 */
	public function init( Configurable $configurable, string $environment, InputInterface $input, OutputInterface $output ) {

		/**
		 * config values from the selected environment
		 */
		$environmentConfigurable = new PrefixConfigurableDecorator($configurable, "project.environments.$environment.");

		/**
		 * config values from the project `default` section
		 */
		$projectConfigurable = new PrefixConfigurableDecorator($configurable, "project.default.");

		/**
		 * config values from the selected environment
		 * If no value is set then the project `default` is checked
		 * If no value is there then the default value is returned
		 */
		$fallbackConfigurable = new ConfigurableFallback($environmentConfigurable, $projectConfigurable);

		/**
		 * Convenience service
		 * Replaces
		 *
		 *  if( $config->has($key) )  {
		 *		echo info exists already
		 * }
		 * echo info setting default value
		 * $config->set($key, $defaultValue)
		 *
		 * with
		 * $initializer->init($$fallbackConfigurable, 'key', defaultValue) - sets in default value in environment
		 * or
		 * $initializer->init($fallbackConfigurable, 'key', defaultValue, ) - sets in default value in project defaults
		 */
		$initializer = new ConfigurationInitializer($output);

		/**
		 *
		 */
		if( $this->getFlag('dev', false) ) {
			/**
			 * Provide defaults for local development environment
			 */

			return;
		}

		/**
		 * Provide defaults for rancher environments
		 */
	}

	/**
	 * Ensure that the given environment has at least the minimal configuration options set to start and deploy this
	 * blueprint
	 *
	 * @param Configuration $configurable
	 * @param string $environment
	 * @throws ValidationFailedException
	 */
	public function validate( Configuration $configurable, string $environment ) {
		// TODO: Implement validate() method.

		/**
		 * Validation config.
		 * Throw ValidationFailedExceptions to indicate errors
		 */
	}

	/**
	 * @param Configuration $configuration
	 * @param string $environment
	 * @param string $version
	 * @return Infrastructure
	 */
	public function build( Configuration $configuration, string $environment, string $version = null ): Infrastructure {
		$infrastructure = new Infrastructure();

		$dockerfile = new Dockerfile();
		$infrastructure->setDockerfile($dockerfile);

		// TODO: Implement build() method.

		return $infrastructure;
	}
}