<?php namespace RancherizeBlueprintPhpCli\PhpCliBlueprint;

use Closure;
use Rancherize\Blueprint\Blueprint;
use Rancherize\Blueprint\Cron\CronService\CronService;
use Rancherize\Blueprint\Cron\Schedule\Exceptions\NoScheduleConfiguredException;
use Rancherize\Blueprint\Cron\Schedule\ScheduleParser;
use Rancherize\Blueprint\Events\AppServiceEvent;
use Rancherize\Blueprint\Events\MainServiceBuiltEvent;
use Rancherize\Blueprint\Flags\HasFlagsTrait;
use Rancherize\Blueprint\Healthcheck\HealthcheckConfigurationToService\HealthcheckConfigurationToService;
use Rancherize\Blueprint\Infrastructure\Dockerfile\Dockerfile;
use Rancherize\Blueprint\Infrastructure\Infrastructure;
use Rancherize\Blueprint\Infrastructure\Service\Maker\PhpFpm\AlpineDebugImageBuilder;
use Rancherize\Blueprint\Infrastructure\Service\Maker\PhpFpm\PhpFpmMaker;
use Rancherize\Blueprint\Infrastructure\Service\NetworkMode\ShareNetworkMode;
use Rancherize\Blueprint\Infrastructure\Service\Service;
use Rancherize\Blueprint\Infrastructure\Service\Services\AppService;
use Rancherize\Blueprint\Infrastructure\Service\Volume;
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
use Symfony\Component\EventDispatcher\EventDispatcher;
use Rancherize\Blueprint\Volumes\VolumeService\VolumeService;

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

	/**
	 * @var EventDispatcher
	 */
    protected $eventDispatcher;

	/**
	 * @var PhpFpmMaker
	 */
    protected $fpmMaker;
	/**
	 * @var HealthcheckConfigurationToService
	 */
	private $healthcheckService;

	/**
	 * PhpCliBlueprint constructor.
	 * @param EventDispatcher $eventDispatcher
	 * @param AlpineDebugImageBuilder $debugImageBuilder
	 * @param DatabaseBuilder $databaseBuilder
	 * @param PhpFpmMaker $fpmMaker
	 * @param HealthcheckConfigurationToService $healthcheckService
	 */
	public function __construct( EventDispatcher $eventDispatcher, AlpineDebugImageBuilder $debugImageBuilder,
	                             DatabaseBuilder $databaseBuilder, PhpFpmMaker $fpmMaker, HealthcheckConfigurationToService $healthcheckService) {
    	$this->debugImageBuilder = $debugImageBuilder;
    	$this->databaseBuilder = $databaseBuilder;
    	$this->eventDispatcher = $eventDispatcher;
    	$this->fpmMaker = $fpmMaker;
		$this->healthcheckService = $healthcheckService;
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
		$this->healthcheckService->parseToService($service, $projectConfigurable);

		if($projectConfigurable->get('healthcheck.enable', false)) {
			$httpService = new Service();
			$httpService->setName(function() use ($service) {
				return $service->getName().'-httpd';
			});
			$httpService->setNetworkMode( new ShareNetworkMode($service) );
			$httpService->setImage('busybox');
			$httpService->setCommand('httpd -f');
			$httpService->setRestart(Service::RESTART_UNLESS_STOPPED);
			$service->addSidekick($httpService);
			$infrastructure->addService($httpService);
		}

		$infrastructure->addService($service);
		$appContainer = $this->addAppContainer($version, $config, $service, $infrastructure);

		$this->parseCronSchedule( $config, $service );

		$this->databaseBuilder->setAppService( $appContainer );
		$this->databaseBuilder->setServerService($service);
		$this->databaseBuilder->addDatabaseService( $config, $service, $infrastructure);


        /**
         * @var VolumeService $volumesService
         */
        $volumesService = container('volume-service');
        $volumesService->parse($config, $appContainer);

		$this->fpmMaker->setAppService($appContainer);

		$mainServiceBuiltEvent = new MainServiceBuiltEvent($infrastructure, $service, $config);
		$this->eventDispatcher->dispatch($mainServiceBuiltEvent::NAME, $mainServiceBuiltEvent);

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
        $dockerfile->run('rm -Rf '.$this->targetDirectory.'/.rancherize && rm -Rf '.$this->targetDirectory.'/rancherize.json');


        return $dockerfile;
    }

	/**
	 * @param Configuration $config
	 * @param Configuration $default
	 * @return Service
	 */
	protected function makeServerService(Configuration $config, Configuration $default) : Service {

        $serviceName = $config->get('service-name');

        $command = 'php ' . $config->get( 'command', '-i' );
        if($config->get('no-php', false))
            $command = $config->get( 'command', '/bin/sh' );

		$serverService = $this->fpmMaker->makeCommand($serviceName, $command, new Service(), $config);

        $serverService->setName($serviceName);

        if($config->get('tty',false))
            $serverService->setTty(true);
        if($config->get('stdin',false))
            $serverService->setKeepStdin(true);

		if( $config->get('sync-user-into-container', false) ) {

		    $userId = getenv('USER_ID');
		    if( empty($userId) )
		        $userId = getmyuid();
			$serverService->setEnvironmentVariable('USER_ID', $userId);

			$groupId = getenv('GROUP_ID');
			if( empty($groupId) )
			    $groupId = getmygid();
			$serverService->setEnvironmentVariable('GROUP_ID', $groupId);
		}

		$serverService->setRestart( Service::RESTART_START_ONCE );
		switch( $config->get('restart') ) {
			case 'always':
				$serverService->setRestart(Service::RESTART_ALWAYS);
				break;
			case 'unless-stopped':
				$serverService->setRestart(Service::RESTART_UNLESS_STOPPED);
				break;
			default:
		}
		$serverService->setWorkDir( $this->targetDirectory );

        /**
         * @var VolumeService $volumesService
         */
        $volumesService = container('volume-service');
        $volumesService->parse($config, $serverService);

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

		$imageName = $config->get('docker.repository') . ':' . $config->get('docker.version-prefix') . $version;
		$imageNameWithServer = $this->applyServer($imageName);

		if ($config->get('use-app-container', true)) {

			$appService = new AppService($imageNameWithServer);
			$appService->setName($config->get('service-name') . 'App');

			$serverService->addSidekick($appService);
			$serverService->addVolumeFrom($appService);

			$infrastructure->addService($appService);


			return $appService;
		}


		if ($config->get('mount-workdir', false)) {

			$mountSuffix = $config->get('work-sub-directory', '');
			$targetSuffix = $config->get('target-sub-directory', '');

			$appService = new Service();
			$appService->setName($config->get('service-name' ).'App');
			$appService->setImage('busybox');
			$appService->setRestart(Service::RESTART_NEVER);

			$volume = new Volume();
			$hostDirectory = getcwd() . $mountSuffix;
			$containerDirectory = $this->targetDirectory . $targetSuffix;
			$volume->setExternalPath($hostDirectory);
			$volume->setInternalPath($containerDirectory);
			$appService->addVolume($volume);
			$serverService->addVolumeFrom($appService);

			$infrastructure->addService($appService);
		}

		if( $appService instanceof Service)
			$this->eventDispatcher->dispatch(AppServiceEvent::NAME, new AppServiceEvent($infrastructure, $appService, $config));

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
