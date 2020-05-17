<?php

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\Loop;

require __DIR__ . '/../vendor/autoload.php';

Loop::run(static function () {
    $httpClient = (new HttpClientBuilder)
        ->build();

    /** @var Response $firstResponse */
    $firstResponse = yield $httpClient->request(new Request('https://google.com/'));
    yield $firstResponse->getBody()->buffer();

    /** @var Response $secondResponse */
    $secondResponse = yield $httpClient->request(new Request('https://google.com/'));
    yield $secondResponse->getBody()->buffer();
});
