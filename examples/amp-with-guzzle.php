<?php declare(strict_types=1);

use Amp\Http\Client\Psr7\PsrAdapter;
use Amp\Http\Client\Request;
use GuzzleHttp\Client;
use Laminas\Diactoros\RequestFactory;
use Laminas\Diactoros\ResponseFactory;

require __DIR__ . '/../vendor/autoload.php';

$psrAdapter = new PsrAdapter(new RequestFactory, new ResponseFactory);

$request = new Request('https://api.github.com/');

$psrResponse = (new Client)->send($psrAdapter->toPsrRequest($request));
$response = $psrAdapter->fromPsrResponse($psrResponse, $request);

print $response->getBody()->buffer();
