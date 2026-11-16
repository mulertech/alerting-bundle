# AlertingBundle

___
[![Latest Version on Packagist](https://img.shields.io/packagist/v/mulertech/alerting-bundle.svg?style=flat-square)](https://packagist.org/packages/mulertech/alerting-bundle)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/mulertech/alerting-bundle/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/mulertech/alerting-bundle/actions/workflows/tests.yml)
[![GitHub PHPStan Action Status](https://img.shields.io/github/actions/workflow/status/mulertech/alerting-bundle/phpstan.yml?branch=main&label=phpstan&style=flat-square)](https://github.com/mulertech/alerting-bundle/actions/workflows/phpstan.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/mulertech/alerting-bundle.svg?style=flat-square)](https://packagist.org/packages/mulertech/alerting-bundle)
[![Test Coverage](https://raw.githubusercontent.com/mulertech/alerting-bundle/badge/badge-coverage.svg)](https://packagist.org/packages/mulertech/alerting-bundle)
___

Reports whatever malfunctions in a Symfony application, over channels tried in order.

## Requirements

- PHP 8.4 or above
- Symfony 7 or 8

## Installation

```bash
composer require mulertech/alerting-bundle
```

Register the bundle for production only, in `config/bundles.php`:

```php
return [
    // ...
    MulerTech\AlertingBundle\MulerTechAlertingBundle::class => ['prod' => true],
];
```

## Testing

```bash
./vendor/bin/mtdocker test-ai
```
