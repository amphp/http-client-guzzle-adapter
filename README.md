# amphp/http-client-guzzle-adapter

AMPHP is a collection of event-driven libraries for PHP designed with fibers and concurrency in mind.
This package provides an adapter for Guzzle 7 to allow using [`amphp/http-client`](https://github.com/amphp/http-client) as the underlying HTTP transport, providing interoperability between libraries requiring Guzzle and libraries or applications built with AMPHP.

[![Latest Release](https://img.shields.io/github/release/amphp/http-client-guzzle-adapter.svg?style=flat-square)](https://github.com/amphp/http-client-guzzle-adapter/releases)
[![MIT License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](https://github.com/amphp/http-client-guzzle-adapter/blob/1.x/LICENSE)

## Installation

This package can be installed as a [Composer](https://getcomposer.org/) dependency.

```bash
composer require amphp/http-client-guzzle-adapter
```

## Requirements

- PHP 8.1+

## Usage

Set the Guzzle handler as shown below to use AMPHP's HTTP Client as the request handler for Guzzle HTTP requests. This allows libraries relying on a Guzzle HTTP client to be used within an async application built upon AMPHP.

```php
<?php

use Amp\Http\Client\GuzzleAdapter\GuzzleHandlerAdapter;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;

$client = new Client([
    'handler' => HandlerStack::create(new GuzzleHandlerAdapter()),
]);
```

## Examples

More extensive code examples reside in the [`examples`](./examples) directory.

## Versioning

`amphp/http-client-guzzle-adapter` follows the [semver](http://semver.org/) semantic versioning specification like all other `amphp` packages.

## Security

If you discover any security related issues, please use the private security issue reporter instead of using the public issue tracker.

## License

The MIT License (MIT). Please see [`LICENSE`](./LICENSE) for more information.
