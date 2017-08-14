<?php namespace RancherizeBlueprintPhpCli\PhpCliBlueprint;

use Closure;
use Rancherize\Blueprint\Blueprint;
use Rancherize\Blueprint\Cron\CronService\CronService;
use Rancherize\Blueprint\Cron\Schedule\ScheduleParser;
use Rancherize\Blueprint\Flags\HasFlagsTrait;
use Rancherize\Blueprint\Infrastructure\Dockerfile\Dockerfile;
use Rancherize\Blueprint\Infrastructure\Infrastructure;
use Rancherize\Blueprint\Infrastructure\Service\Service;
use Rancherize\Blueprint\Scheduler\SchedulerInitializer\SchedulerInitializer;
use Rancherize\Blueprint\Validation\Exceptions\ValidationFailedException;
use Rancherize\Blueprint\Validation\Traits\HasValidatorTrait;
use Rancherize\Configuration\Configurable;
use Rancherize\Configuration\Configuration;
use Rancherize\Configuration\PrefixConfigurableDecorator;
use Rancherize\Configuration\PrefixConfigurationDecorator;
use Rancherize\Configuration\Services\ConfigurableFallback;
use Rancherize\Configuration\Services\ConfigurationFallback;
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

    use HasValidatorTrait;

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
            $initializer->init($fallbackConfigurable, 'mount-workdir', true);
		} else {
            $initializer->init($fallbackConfigurable, 'rancher.stack', 'Project');

            $schedulerInitializer = new SchedulerInitializer($initializer);
            $schedulerInitializer->init($fallbackConfigurable, $projectConfigurable);
        }

        /**
         * Provide defaults for rancher environments
         */
        $initializer->init($fallbackConfigurable, 'external_links', []);

        $initializer->init($fallbackConfigurable, 'docker.repository', 'repo/name', $projectConfigurable);
        $initializer->init($fallbackConfigurable, 'docker.version-prefix', '', $projectConfigurable);
        $initializer->init($fallbackConfigurable, 'service-name', 'Project', $projectConfigurable);
        $initializer->init($fallbackConfigurable, 'docker.base-image', 'php:7.0-alpine', $projectConfigurable);
        $initializer->init($fallbackConfigurable, 'environment', ["EXAMPLE" => 'value']);

        /**
         * Blueprint specific entries
         */
        $initializer->init($fallbackConfigurable, 'php', '7.0', $projectConfigurable);
//        $initializer->init($fallbackConfigurable, 'extensions', []);
        $initializer->init($fallbackConfigurable, 'add-composer', false, $projectConfigurable);
        $initializer->init($fallbackConfigurable, 'command', "-i", $projectConfigurable);

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

        $projectConfigurable = new PrefixConfigurationDecorator($configurable, "project.default.");
        $environmentConfigurable = new PrefixConfigurationDecorator($configurable, "project.environments.$environment.");
        $config = new ConfigurationFallback($environmentConfigurable, $projectConfigurable);

        $this->getValidator()->validate($config, [
            'service-name' => 'required',
        ]);
	}

	/**
	 * @param Configuration $configuration
	 * @param string $environment
	 * @param string $version
	 * @return Infrastructure
	 */
	public function build( Configuration $configuration, string $environment, string $version = null ): Infrastructure {
		$infrastructure = new Infrastructure();

        $projectConfigurable = new PrefixConfigurationDecorator($configuration, "project.default.");
        $environmentConfigurable = new PrefixConfigurationDecorator($configuration, "project.environments.$environment.");
        $config = new ConfigurationFallback($environmentConfigurable, $projectConfigurable);

		$dockerfile = $this->makeDockerfile($config);
		$infrastructure->setDockerfile($dockerfile);

		// TODO: Implement build() method.
		$service = $this->makeServerService($config, $projectConfigurable);
		$infrastructure->addService($service);

		/**
		 * @var ScheduleParser $scheduleParser
		 */
		$scheduleParser = container('schedule-parser');
		$schedule = $scheduleParser->parseSchedule($config);

		/**
		 * @var CronService $cronService
		 */
		$cronService = container('cron-service');
		$cronService->makeCron($service, $schedule);

		return $infrastructure;
	}

    /**
     * @param $config
     * @return Dockerfile
     */
    protected function makeDockerfile(Configuration $config):Dockerfile {
        $dockerfile = new Dockerfile();

	    /**
	     * @IMPORTANT
	     *
	     * This is NOT the php version that will later run the image.
	     * It is the base of the app DATA image.
	     * The php image that will actually run the app is the one set for the ServerService with $serverService->setImage()
	     */
        $baseimage = $config->get('docker.base-image', 'php:7.0-alpine');
        $dockerfile->setFrom($baseimage);

        $dockerfile->addVolume('/var/www/app');

        $dockerfile->setWorkdir('/var/www/app');

        $copySuffix = $config->get('work-sub-directory', '');
        $targetSuffix = $config->get('target-sub-directory', '');
        $dockerfile->copy('.'.$copySuffix, '/var/www/app'.$targetSuffix);

        if ($config->get('add-composer', false)) {
            $dockerfile->run('php -r "copy(\'https://getcomposer.org/installer\', \'composer-setup.php\');" \
	&& php -r "if (hash_file(\'SHA384\', \'composer-setup.php\') === \'669656bab3166a7aff8a7506b8cb2d1c292f042046c5a994c43155c0be6190fa0355160742ab2e1c88d40d5be660b410\') { echo \'Installer verified\'; } else { echo \'Installer corrupt\'; unlink(\'composer-setup.php\'); } echo PHP_EOL;" \
	&& php composer-setup.php \
	php -r "unlink(\'composer-setup.php\');" \
	&& cd /var/www/app && ./composer.phar install && rm composer.phar');
        }

        $additionalFiles = $config->get('add-files');
        if( is_array($additionalFiles) ) {
            foreach($additionalFiles as $file => $path) {
                $dockerfile->copy($file, $path);
            }
        }
        $additionalVolumes = $config->get('add-volumes');
        if( is_array($additionalVolumes) ) {
            foreach($additionalFiles as $path) {
                $dockerfile->addVolume($path);
            }
        }
        $dockerfile->run('rm -Rf /var/www/app/.rancherize');

        $dockerfile->setCommand('php '.$config->get('command', '-i'));


        return $dockerfile;
    }

	/**
	 * @param Configuration $config
	 * @param Configuration $default
	 * @return Service
	 */
	protected function makeServerService(Configuration $config, Configuration $default) : Service {
		$serverService = new Service();
		$serverService->setName($config->get('service-name'));

		$phpImage = $config->has('php')
			? 'php:' . $config->get('php', '7.0') . '-alpine'
			: $config->get('docker.base-image', 'php:7.0-alpine');
		$serverService->setImage($config->get('docker.image', $phpImage));

		if( $config->get('sync-user-into-container', false) ) {
			$serverService->setEnvironmentVariable('USER_ID', getmyuid());
			$serverService->setEnvironmentVariable('GROUP_ID', getmygid());
		}

		if ($config->get('mount-workdir', false)) {
			$mountSuffix = $config->get('work-sub-directory', '');
			$targetSuffix = $config->get('target-sub-directory', '');

			$hostDirectory = getcwd() . $mountSuffix;
			$containerDirectory = '/var/www/app' . $targetSuffix;
			$serverService->addVolume($hostDirectory, $containerDirectory);
		}

		$persistentDriver = $config->get('docker.persistent-driver', 'pxd');
		$persistentOptions = $config->get('docker.persistent-options', [
			'repl' => '3',
			'shared' => 'true',
		]);
		foreach( $config->get('persistent-volumes', []) as $volumeName => $path ) {
			$volume = new \Rancherize\Blueprint\Infrastructure\Service\Volume();
			$volume->setDriver($persistentDriver);
			$volume->setOptions($persistentOptions);
			$volume->setExternalPath($volumeName);
			$volume->setInternalPath($path);
			$serverService->addVolume( $volume );
		}

		$this->addAll([$default, $config], 'environment', function(string $name, $value) use ($serverService) {
			$serverService->setEnvironmentVariable($name, $value);
		});

		$this->addAll([$default, $config], 'labels', function(string $name, $value) use ($serverService) {
			$serverService->addLabel($name, $value);
		});

		if ($config->has('external_links')) {
			foreach ($config->get('external_links') as $name => $value)
				$serverService->addExternalLink($value, $name);
		}

		return $serverService;
	}

	/**
	 * @param Configuration[] $configs
	 * @param string $label
	 * @param Closure $closure
	 */
	private function addAll(array $configs, string $label, Closure $closure) {
		foreach($configs as $c) {
			if(!$c->has($label))
				continue;

			foreach ($c->get($label) as $name => $value)
				$closure($name, $value);
		}
	}
}