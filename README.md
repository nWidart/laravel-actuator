# Laravel Actuator

[![Latest Version on Packagist](https://img.shields.io/packagist/v/nwidart/laravel-actuator.svg?style=flat-square)](https://packagist.org/packages/nwidart/laravel-actuator)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE.md)
[![Build Status](https://img.shields.io/travis/nwidart/laravel-actuator/master.svg?style=flat-square)](https://travis-ci.org/nwidart/laravel-actuator)
[![Scrutinizer Coverage](https://img.shields.io/scrutinizer/coverage/g/nWidart/laravel-actuator.svg?maxAge=86400&style=flat-square)](https://scrutinizer-ci.com/g/nWidart/laravel-actuator/?branch=master)
[![Quality Score](https://img.shields.io/scrutinizer/g/nwidart/laravel-actuator.svg?style=flat-square)](https://scrutinizer-ci.com/g/nwidart/laravel-actuator)
[![Total Downloads](https://img.shields.io/packagist/dt/nwidart/laravel-actuator.svg?style=flat-square)](https://packagist.org/packages/nwidart/laravel-actuator)


Laravel-Actuator is a package that will provide endpoint to monitor the status of your application and its dependencies (database, cache, etc.).
Custom contributors can be added and customised.

Example usage would be with Kubernetes readiness and liveliness probes.

This package has been heavily inspired by the [Spring Boot Actuator](https://docs.spring.io/spring-boot/docs/current/reference/html/production-ready-features.html#production-ready) project.

## Installation

You can install the package via composer:

```bash
composer require nwidart/laravel-actuator
```

## Usage

New endpoints will be available:

```shell script
/actuator/health
```

### Testing

``` bash
composer test
```

### Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

### Security

If you discover any security related issues, please email n.widart@gmail.com instead of using the issue tracker.

## Credits

- [Nicolas Widart](https://github.com/nwidart)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
