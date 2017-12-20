<?php namespace RancherizeBlueprintPhpCli;

use Pimple\Container;
use Rancherize\Blueprint\Factory\BlueprintFactory;
use Rancherize\Blueprint\Infrastructure\Service\Maker\PhpFpm\AlpineDebugImageBuilder;
use Rancherize\Blueprint\Scheduler\SchedulerParser\SchedulerParser;
use Rancherize\Blueprint\Services\Database\DatabaseBuilder\DatabaseBuilder;
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
		$this->container[PhpCliBlueprint::class] = function($c) {
			return new PhpCliBlueprint( $c[AlpineDebugImageBuilder::class], $c['database-builder'] );
		};
	}

	/**
	 */
	public function boot() {
		/**
		 * @var BlueprintFactory $blueprintFactory
		 */
		$blueprintFactory = $this->container[BlueprintFactory::class];

		$blueprintFactory->add('php-cli', function(Container $c) {
			/**
			 * @var PhpCliBlueprint $blueprint
			 */
			$blueprint = $c[PhpCliBlueprint::class];

			$blueprint->setRancherSchedulerParser( $c['scheduler-parser'] );

			return $blueprint;
		});
	}
}