
<?php

require 'vendor/autoload.php';

use Amp\Http\Client\Psr7\PsrAdapter;
use Laminas\Diactoros\RequestFactory;
use Laminas\Diactoros\ResponseFactory;

// PSR-17 request factory
$psrRequestFactory = new RequestFactory();
// PSR-17 response factory
$psrResponseFactory = new ResponseFactory();

$psrAdapter = new PsrAdapter($psrRequestFactory, $psrResponseFactory);

// Convert PSR-7 request to Amp request
$psrRequest = $psrRequestFactory->createRequest('GET', 'https://google.com/');
$ampRequest = $psrAdapter->fromPsrRequest($psrRequest);

// Convert Amp request to PSR-7 request
$psrRequest = $psrAdapter->toPsrRequest($ampRequest);

// Convert PSR-7 response to Amp response
$psrResponse = $psrResponseFactory->createResponse();
$ampResponse = $psrAdapter->fromPsrResponse($psrResponse, $ampRequest);

// Convert Amp response to PSR-7 response
$psrResponse = $psrAdapter->toPsrResponse($ampResponse);
