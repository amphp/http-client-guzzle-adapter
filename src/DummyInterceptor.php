<?php

namespace Amp\Http\Client\Psr7;

use Amp\CancellationToken;
use Amp\Http\Client\Connection\Stream;
use Amp\Http\Client\NetworkInterceptor;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\Promise;
use function Amp\call;

final class DummyInterceptor implements NetworkInterceptor
{
    public function requestViaNetwork(Request $request, CancellationToken $cancellation, Stream $stream): Promise
    {
        return call(function () use ($request, $cancellation, $stream) {
            /** @var Response $response */
            $response = yield $stream->request($request, $cancellation);

            return $response;
        });
    }
}
