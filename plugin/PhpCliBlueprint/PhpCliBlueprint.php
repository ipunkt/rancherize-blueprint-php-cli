<?php namespace RancherizeBlueprintPhpCli\PhpCliBlueprint;

use Closure;
use Rancherize\Blueprint\Blueprint;
use Rancherize\Blueprint\Cron\CronService\CronService;
use Rancherize\Blueprint\Cron\Schedule\Exceptions\NoScheduleConfiguredException;
use Rancherize\Blueprint\Cron\Schedule\ScheduleParser;
use Rancherize\Blueprint\Flags\HasFlagsTrait;
use Rancherize\Blueprint\Infrastructure\Dockerfile\Dockerfile;
use Rancherize\Blueprint\Infrastructure\Infrastructure;
use Rancherize\Blueprint\Infrastructure\Service\Maker\PhpFpm\AlpineDebugImageBuilder;
use Rancherize\Blueprint\Infrastructure\Service\Service;
use Rancherize\Blueprint\Infrastructure\Service\Services\AppService;
use Rancherize\Blueprint\Scheduler\SchedulerInitializer\SchedulerInitializer;
use Rancherize\Blueprint\Scheduler\SchedulerParser\SchedulerParser;
use Rancherize\Blueprint\Services\Database\DatabaseBuilder\DatabaseBuilder;
use Rancherize\Blueprint\TakesDockerAccount;
use Rancherize\Blueprint\Validation\Exceptions\ValidationFailedException;
use Rancherize\Blueprint\Validation\Traits\HasValidatorTrait;
use Rancherize\Configuration\Configurable;
use Rancherize\Configuration\Configuration;
use Rancherize\Configuration\PrefixConfigurableDecorator;
use Rancherize\Configuration\PrefixConfigurationDecorator;
use Rancherize\Configuration\Services\ConfigurableFallback;
use Rancherize\Configuration\Services\ConfigurationFallback;
use Rancherize\Configuration\Services\ConfigurationInitializer;
use Rancherize\Docker\DockerAccount;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class PhpCliBlueprint
 * @package RancherizeBlueprintPhpCli\PhpCliBlueprint
 */
class PhpCliBlueprint implements Blueprint, TakesDockerAccount {

	private $targetDirectory = '/var/cli/app';

	/**
	 * Provider $this->getFlag('dev', false) to detect `rancherize init php-cli --dev` in init.
	 */
	use HasFlagsTrait;

    use HasValidatorTrait;

	/**
	 * @var SchedulerParser
	 */
    protected $rancherSchedulerParser;

	/**
	 * @var AlpineDebugImageBuilder
	 */
    protected $debugImageBuilder;

	/**
	 * @var DatabaseBuilder
	 */
    protected $databaseBuilder;

    public function __construct(AlpineDebugImageBuilder $debugImageBuilder, DatabaseBuilder $databaseBuilder) {
    	$this->debugImageBuilder = $debugImageBuilder;
    	$this->databaseBuilder = $databaseBuilder;
    }

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
			$initializer->init($fallbackConfigurable, 'use-app-container', false);
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
		$appContainer = $this->addAppContainer($version, $config, $service, $infrastructure);

		$this->parseCronSchedule( $config, $service );

		$this->databaseBuilder->setAppService( $appContainer );
		$this->databaseBuilder->setServerService($service);
		$this->databaseBuilder->addDatabaseService( $config, $service, $infrastructure);

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

        $dockerfile->addVolume( $this->targetDirectory );

        $dockerfile->setWorkdir( $this->targetDirectory );

        $copySuffix = $config->get('work-sub-directory', '');
        $targetSuffix = $config->get('target-sub-directory', '');
        $dockerfile->copy('.'.$copySuffix, $this->targetDirectory .$targetSuffix);

        if ($config->get('add-composer', false)) {
		/**
		 * use composer programmatical
		 * see https://getcomposer.org/doc/faqs/how-to-install-composer-programmatically.md
		 */
            $dockerfile->run('curl -sSL "https://gist.githubusercontent.com/justb81/1006b89e41e41e1c848fe91969af7a0b/raw/c12faf968e659356ec1cb53f313e7f8383836be3/getcomposer.sh" | sh && COMPOSER_ALLOW_SUPERUSER=1 ./composer.phar install --no-dev && rm composer.phar');
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
        $dockerfile->run('rm -Rf '.$this->targetDirectory.'/.rancherize');


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
			? 'ipunktbs/php:' . $config->get('php', '7.0')
			: $config->get('docker.base-image', 'ipunktbs/php:7.0');

		$imageName = $config->get( 'docker.image', $phpImage );
		$serverService->setImage( $imageName );
		if( $config->has('debug') ) {
			$serverService->setImage( $this->debugImageBuilder->makeImage($imageName, $config->get('xdebug-version') ) );
			$serverService->setEnvironmentVariable('XDEBUG_REMOTE_HOST', $config->get('debug-listener', gethostname()) );
		}

		if( $config->get('sync-user-into-container', false) ) {
			$serverService->setEnvironmentVariable('USER_ID', getmyuid());
			$serverService->setEnvironmentVariable('GROUP_ID', getmygid());
		}

		if ($config->get('mount-workdir', false)) {
			$mountSuffix = $config->get('work-sub-directory', '');
			$targetSuffix = $config->get('target-sub-directory', '');

			$hostDirectory = getcwd() . $mountSuffix;
			$containerDirectory = $this->targetDirectory . $targetSuffix;
			$serverService->addVolume($hostDirectory, $containerDirectory);
		}


		$command = 'php ' . $config->get( 'command', '-i' );
		$serverService->setCommand($command);
		$serverService->setRestart( Service::RESTART_START_ONCE );
		$serverService->setWorkDir( $this->targetDirectory );

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

		$this->rancherSchedulerParser->parse($serverService, $config);

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

	/**
	 * @param $config
	 * @param $service
	 */
	protected function parseCronSchedule( $config, $service ) {
		/**
		 * @var ScheduleParser $scheduleParser
		 */
		$scheduleParser = container( 'schedule-parser' );
		try {
			$schedule = $scheduleParser->parseSchedule( $config );
		} catch ( NoScheduleConfiguredException $e) {
			return;
		}

		/**
		 * @var CronService $cronService
		 */
		$cronService = container( 'cron-service' );
		$cronService->makeCron( $service, $schedule );
	}

	/**
	 * @param string $version
	 * @param Configuration $config
	 * @param Service $serverService
	 * @param Infrastructure $infrastructure
	 */
	protected function addAppContainer($version, Configuration $config, Service $serverService, Infrastructure $infrastructure) {
		if (!$config->get('use-app-container', true))
			return;

		$imageName = $config->get('docker.repository') . ':' . $config->get('docker.version-prefix') . $version;
		$imageNameWithServer = $this->applyServer($imageName);

		$appService = new AppService($imageNameWithServer);
		$appService->setName($config->get('service-name') . 'App');

		$serverService->addSidekick($appService);
		$serverService->addVolumeFrom($appService);
		$infrastructure->addService($appService);

		return $appService;
	}

	/**
	 * @var DockerAccount
	 */
	protected $dockerAccount = null;

	protected function applyServer(string $imageName) {
		if( $this->dockerAccount === null)
			return $imageName;

		$server = $this->dockerAccount->getServer();
		if( empty($server) )
			return $imageName;

		$serverHost = parse_url($server, PHP_URL_HOST);
		$imageNameWithServer = $serverHost.'/'.$imageName;

		return $imageNameWithServer;
	}

	/**
	 * @param DockerAccount $dockerAccount
	 * @return $this
	 */
	public function setDockerAccount( DockerAccount $dockerAccount ) {
		$this->dockerAccount = $dockerAccount;
		return $this;
	}

	/**
	 * @param SchedulerParser $rancherSchedulerParser
	 */
	public function setRancherSchedulerParser( SchedulerParser $rancherSchedulerParser ) {
		$this->rancherSchedulerParser = $rancherSchedulerParser;
	}
}
