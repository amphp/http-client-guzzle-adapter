<?php declare(strict_types=1);

namespace Amp\Http\Client\Psr7;

use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use AssertionError;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

use function Amp\async;
use function Amp\delay;

/**
 * Handler for guzzle which uses amphp/http-client.
 */
final class AmpHandler
{
    private static ?PsrAdapter $psrAdapter;
    private readonly HttpClient $client;
    public function __construct(?HttpClient $client = null)
    {
        if (!\interface_exists(PromiseInterface::class)) {
            throw new AssertionError("Please require guzzle to use the guzzle AmpHandler!");
        }
        $this->client = $client ?? HttpClientBuilder::buildDefault();
        self::$psrAdapter ??= new PsrAdapter(new class implements RequestFactoryInterface {
            public function createRequest(string $method, $uri): RequestInterface
            {
                return new Request($method, $uri);
            }
        }, new class implements ResponseFactoryInterface {
            public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface
            {
                return new Response($code, reason: $reasonPhrase);
            }
        });
    }

    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $deferred = new DeferredCancellation;
        $cancellation = $deferred->getCancellation();
        $future = async(function () use ($request, $options, $cancellation) {
            if (isset($options['delay'])) {
                delay($options['delay'] / 1000.0, cancellation: $cancellation);
            }
            /** @psalm-suppress PossiblyNullReference Initialized in the constructor */
            $request = self::$psrAdapter->fromPsrRequest($request);
            if (isset($options['timeout'])) {
                $request->setTransferTimeout((float) $options['timeout']);
            }
            if (isset($options['connect_timeout'])) {
                $request->setTcpConnectTimeout((float) $options['connect_timeout']);
            }
            return self::$psrAdapter->toPsrResponse(
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
