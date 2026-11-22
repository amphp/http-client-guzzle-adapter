<?php declare(strict_types=1);

namespace Amp\Http\Client\GuzzleAdapter;

use Amp\Http\Client\HttpClientBuilder;

/**
 * Builds an {@see HttpClientBuilder} with Guzzle 7 defaults applied. The built client does not follow redirects,
 * passing that responsibility to Guzzle's RedirectMiddleware which follows redirects as set by the "allow_redirects"
 * request option.
 */
function createHttpClientBuilder(): HttpClientBuilder
{
    return (new HttpClientBuilder())
        ->followRedirects(0)
        ->retry(0); // Guzzle doesn't support retries. Sad!
}
