<?php

use Amp\Http\Client\Psr7\PsrAdapter;
use Amp\Http\Client\Request;
use Amp\Loop;
use GuzzleHttp\Client;
use Laminas\Diactoros\RequestFactory;
use Laminas\Diactoros\ResponseFactory;
use Psr\Http\Message\RequestInterface;

require __DIR__ . '/../vendor/autoload.php';

Loop::run(static function () {
    $psrAdapter = new PsrAdapter(new RequestFactory, new ResponseFactory);

    $request = new Request('https://api.github.com/');

    /** @var RequestInterface $psrRequest */
    $psrRequest = yield $psrAdapter->toPsrRequest($request);

    $psrResponse = (new Client)->send($psrRequest);

    $response = $psrAdapter->fromPsrResponse($psrResponse, $request);

    print yield $response->getBody()->buffer();
});
