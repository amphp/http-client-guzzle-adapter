<?php declare(strict_types=1);

namespace Amp\Http\Client\GuzzleAdapter;

use Amp\Http\Client\HttpClientBuilder;

/**
 * Builds an {@see HttpClientBuilder} with Guzzle 7 defaults applied.
 *
 * Like Guzzle's own handlers, the client does not follow redirects: Guzzle's RedirectMiddleware follows them
 * as configured by the "allow_redirects" request option.
 */
function createHttpClientBuilder(): HttpClientBuilder
{
    return (new HttpClientBuilder())
        ->followRedirects(0)
        ->retry(0); // Guzzle doesn't support retries. Sad!
}
