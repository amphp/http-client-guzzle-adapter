<?php

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Psr7\PsrAdapter;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\Loop;
use GuzzleHttp\Client;
use Laminas\Diactoros\RequestFactory;
use Laminas\Diactoros\ResponseFactory;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

require __DIR__ . '/../vendor/autoload.php';

Loop::run(
    static function () {
        $httpClient = (new HttpClientBuilder)
            ->build();

        $psrAdapter = new PsrAdapter();
        $psrResponseFactory = new ResponseFactory();
        $psrRequestFactory = new RequestFactory();

        $firstPsrRequest = $psrRequestFactory->createRequest('GET', 'https://google.com/');
        /** @var Request $firstAmpRequest */
        $firstAmpRequest = yield $psrAdapter->fromPsrRequest($firstPsrRequest);
        // TODO: Investigate if this client bug or if Host header must be cleaned automatically
        $firstAmpRequest->removeHeader('Host');
        /** @var Response $firstAmpResponse */
        $firstAmpResponse = yield $httpClient->request($firstAmpRequest);
        /** @var ResponseInterface $firstPsrResponse */
        $firstPsrResponse = yield $psrAdapter->toPsrResponse($psrResponseFactory, $firstAmpResponse);
        $body = $firstPsrResponse->getBody();
        $body->rewind();
        $body->getContents();

        $secondAmpRequest = new Request('https://google.com/');
        /** @var RequestInterface $secondPsrRequest */
        $secondPsrRequest = yield $psrAdapter->toPsrRequest($psrRequestFactory, $secondAmpRequest);
        $secondPsrResponse = (new Client)->send($secondPsrRequest);
        /** @var Response $secondAmpResponse */
        $secondAmpResponse = yield $psrAdapter->fromPsrResponse($secondPsrResponse, $secondAmpRequest);
        yield $secondAmpResponse->getBody()->buffer();
    }
);
