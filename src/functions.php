<?php declare(strict_types=1);

namespace Amp\Http\Client\GuzzleAdapter;

use Amp\Http\Client\HttpClientBuilder;
use GuzzleHttp\RedirectMiddleware;

/**
 * Builds an {@see HttpClientBuilder} with Guzzle 7 defaults applied.
 */
function createHttpClientBuilder(): HttpClientBuilder
{
    return (new HttpClientBuilder())
        ->followRedirects(RedirectMiddleware::$defaultSettings['max'])
        ->retry(0); // Guzzle doesn't support retries. Sad!
}
