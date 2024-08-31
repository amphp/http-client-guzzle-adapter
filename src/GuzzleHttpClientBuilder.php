<?php
declare(strict_types=1);

namespace Amp\Http\Client\Psr7;

use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use GuzzleHttp\RedirectMiddleware;

/**
 * Builds an HttpClient with Guzzle 7 defaults applied.
 */
final class GuzzleHttpClientBuilder
{
    public function build(): HttpClient
    {
        return $this->createPrototype()->build();
    }

    public function createPrototype(): HttpClientBuilder
    {
        return (new HttpClientBuilder())
            ->followRedirects(RedirectMiddleware::$defaultSettings['max'])
            ->retry(0); // Guzzle doesn't support retries. Sad!
    }
}
