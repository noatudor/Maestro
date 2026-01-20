# Maestro Workflow

High-performance workflow orchestration for Laravel.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/maestro/workflow.svg?style=flat-square)](https://packagist.org/packages/maestro/workflow)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/maestro-php/workflow/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/maestro-php/workflow/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/maestro-php/workflow/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/maestro-php/workflow/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/maestro/workflow.svg?style=flat-square)](https://packagist.org/packages/maestro/workflow)

Maestro is a workflow orchestration package for Laravel designed to handle millions of workflows with typed data passing, explicit state tracking, and full operational transparency.

## Installation

You can install the package via composer:

```bash
composer require maestro/workflow
```

You can publish and run the migrations with:

```bash
php artisan vendor:publish --tag="maestro-migrations"
php artisan migrate
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag="maestro-config"
```

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Maestro Contributors](https://github.com/maestro-php/workflow/contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
