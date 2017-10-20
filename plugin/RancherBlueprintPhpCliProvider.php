<?php namespace RancherizeBlueprintPhpCli;

use Rancherize\Blueprint\Factory\BlueprintFactory;
use Rancherize\Plugin\Provider;
use Rancherize\Plugin\ProviderTrait;
use RancherizeBlueprintPhpCli\PhpCliBlueprint\PhpCliBlueprint;

/**
 * Class RancherBlueprintPhpCliProvider
 * @package RancherizeBlueprintPhpCli
 */
class RancherBlueprintPhpCliProvider implements Provider {

	use ProviderTrait;

	/**
	 */
	public function register() {
	}

	/**
	 */
	public function boot() {
		/**
		 * @var BlueprintFactory $blueprintFactory
		 */
		$blueprintFactory = $this->container[BlueprintFactory::class];

		$blueprintFactory->add('php-cli', PhpCliBlueprint::class);
	}
}