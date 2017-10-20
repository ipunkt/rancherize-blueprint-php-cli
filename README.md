# rancherize-blueprint-php-cli
Rancherize blueprint to run a single php command, optionally with cron label to run regularly.

## Use

### Install

	rancherize plugin:install ipunkt/rancherize-blueprint-php-cli:1.0.0

### Init
Init local development environment

	rancherize init php-cli --dev local
	
Init push environment to be used with a rancher server	

	rancherize init php-cli production
	
Note that as of the time of writing all environments will use the same blueprint
	
### Configuration

#### Differences from WebserverBlueprint
- `command`: The command that will be executed. The exact command that will be run is `php VALUEGIVEN` inside `/var/cli/app` where your app is mounted.
- `add-composer`: Add a composer.phar to your app directory

#### Supported rancherize services
- [scheduler](https://github.com/ipunkt/rancherize/tree/master/app/Blueprint/Scheduler)
- Cron [schedule](https://github.com/ipunkt/rancherize/tree/master/app/Blueprint/Cron) IMPORTANT: Not the full `cron` syntax is supported. Only the `schedule` part is used on the top level of the environment

#### Example:
```json
{
    "default": {
        "rancher": {
            "account": "accountname",
            "in-service": true
        },
        "docker": {
            "account": "accountname",
            "repository": "dockername\/reponame",
            "version-prefix": "cli_test_",
            "base-image": "php:7.0-alpine"
        },
        "service-name": "ServiceName",
        "php": "7.0",
        "add-composer": false,
        "command": "artisan",
        "schedule":{
          "hour":"*/2"
        },
        "scheduler": {
            "enable": true
        }
    },
    "environments": {
        "local": {
            "mount-workdir": true,
            "external_links": [],
            "environment": {
                "EXAMPLE": "value"
            }
        },
        "staging": {
            "rancher": {
                "stack": "Cli-Test"
            },
            "scheduler": {
                "tags": {
                    "apps": "true"
                }
            },
            "external_links": [],
            "environment": {
                "EXAMPLE": "value"
            }
        }
    }
}
```
