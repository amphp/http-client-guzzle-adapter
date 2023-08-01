<?php

namespace Amp\Http\Client\Psr7;

use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use Laminas\Diactoros\RequestFactory;
use Laminas\Diactoros\ResponseFactory;
use Psr\Http\Message\RequestInterface;
use Throwable;

use function Amp\async;
use function Amp\delay;

/**
 * Handler for guzzle which uses amphp/http-client.
 */
final class AmpHandler {
    private readonly PsrAdapter $psrAdapter;
    private readonly HttpClient $client;
    public function __construct(?HttpClient $client = null)
    {
        $this->client = $client ?? HttpClientBuilder::buildDefault();
        $this->psrAdapter = new PsrAdapter(new RequestFactory, new ResponseFactory);
    }

    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $deferred = new DeferredCancellation;
        $cancellation = $deferred->getCancellation();
        $future = async(function () use ($request, $options, $cancellation) {
            if (isset($options['delay'])) {
                delay($options['delay'] / 1000.0, cancellation: $cancellation);
            }
            $request = $this->psrAdapter->fromPsrRequest($request);
            if (isset($options['timeout'])) {
                $request->setTransferTimeout((float) $options['timeout']);
            }
            if (isset($options['connect_timeout'])) {
                $request->setTcpConnectTimeout((float) $options['connect_timeout']);
            }
            return $this->psrAdapter->toPsrResponse(
                $this->client->request(
                    $request,
                    $cancellation
                )
            );
        });
        $future->ignore();
        $promise = new Promise(function () use ($future, $cancellation, &$promise) {
            try {
                $promise->resolve($future->await());
            } catch (CancelledException $e) {
                if (!$cancellation->isRequested()) {
                    $promise->reject($e);
                }
            } catch (Throwable $e) {
                $promise->reject($e);
            }
        }, $deferred->cancel(...));
        return $promise;
    }
}
