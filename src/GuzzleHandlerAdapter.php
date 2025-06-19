<?php declare(strict_types=1);

namespace Amp\Http\Client\GuzzleAdapter;

use Amp\ByteStream\StreamException;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\Dns\DnsRecord;
use Amp\File\File;
use Amp\File\FilesystemException;
use Amp\Http\Client\Connection\DefaultConnectionFactory;
use Amp\Http\Client\Connection\UnlimitedConnectionPool;
use Amp\Http\Client\DelegateHttpClient;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Psr7\PsrAdapter;
use Amp\Http\Client\Psr7\PsrHttpClientException;
use Amp\Http\Client\Request as AmpRequest;
use Amp\Http\Client\Response;
use Amp\Http\Tunnel\Http1TunnelConnector;
use Amp\Http\Tunnel\Https1TunnelConnector;
use Amp\Socket\Certificate;
use Amp\Socket\ClientTlsContext;
use Amp\Socket\ConnectContext;
use Amp\Socket\SocketConnector;
use Amp\Socket\Socks5SocketConnector;
use AssertionError;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use GuzzleHttp\Psr7\Uri as GuzzleUri;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\Utils;
use Psr\Http\Message\RequestFactoryInterface as PsrRequestFactory;
use Psr\Http\Message\RequestInterface as PsrRequest;
use Psr\Http\Message\ResponseFactoryInterface as PsrResponseFactory;
use Psr\Http\Message\ResponseInterface as PsrResponse;
use Psr\Http\Message\StreamInterface as PsrStream;
use function Amp\async;
use function Amp\ByteStream\pipe;
use function Amp\delay;
use function Amp\File\openFile;

/**
 * Handler for guzzle which uses amphp/http-client.
 */
final class GuzzleHandlerAdapter
{
    private static ?PsrAdapter $psrAdapter = null;

    private readonly DelegateHttpClient $client;

    /** @var array<string, HttpClient> */
    private array $cachedClients = [];

    /** @var \WeakMap<PsrStream, DeferredCancellation> */
    private \WeakMap $deferredCancellations;

    public function __construct(?DelegateHttpClient $client = null)
    {
        if (!\interface_exists(PromiseInterface::class)) {
            throw new AssertionError("Please require guzzlehttp/guzzle to use the Guzzle adapter!");
        }

        $this->client = $client ?? createHttpClientBuilder()->build();

        /** @var \WeakMap<PsrStream, DeferredCancellation> */
        $this->deferredCancellations = new \WeakMap();
    }

    public function __invoke(PsrRequest $request, array $options): PromiseInterface
    {
        if (isset($options['curl']) && !empty($options['curl'])) {
            throw new AssertionError("Cannot provide curl options when using AMP backend!");
        }

        $deferredCancellation = new DeferredCancellation();
        $cancellation = $deferredCancellation->getCancellation();
        $future = async(function () use ($request, $options, $cancellation): PsrResponse {
            if (isset($options[RequestOptions::DELAY])) {
                delay($options[RequestOptions::DELAY] / 1000.0, cancellation: $cancellation);
            }

            $ampRequest = self::getPsrAdapter()->fromPsrRequest($request);
            $ampRequest->setTransferTimeout((float) ($options[RequestOptions::TIMEOUT] ?? 0));
            $ampRequest->setInactivityTimeout((float) ($options[RequestOptions::TIMEOUT] ?? 0));
            $ampRequest->setTcpConnectTimeout((float) ($options[RequestOptions::CONNECT_TIMEOUT] ?? 60));

            $client = $this->getClient($ampRequest, $options);

            if (isset($options['amp']['protocols'])) {
                $ampRequest->setProtocolVersions($options['amp']['protocols']);
            }

            $response = $client->request($ampRequest, $cancellation);

            if (isset($options[RequestOptions::SINK])) {
                $filename = $options[RequestOptions::SINK];
                if (!\is_string($filename)) {
                    throw new AssertionError("Only a file name can be provided as sink!");
                }

                try {
                    $file = self::pipeResponseToFile($response, $filename, $cancellation);
                } catch (FilesystemException|StreamException $exception) {
                    throw new PsrHttpClientException(\sprintf(
                        'Failed streaming body to file "%s": %s',
                        $filename,
                        $exception->getMessage(),
                    ), request: $request, previous: $exception);
                }

                $response->setBody($file);
            }

            return self::getPsrAdapter()->toPsrResponse($response);
        });

        $future->ignore();

        /** @psalm-suppress UndefinedVariable Using $promise reference in definition expression. */
        $promise = new Promise(
            function () use (&$promise, $future, $cancellation, $deferredCancellation): void {
                if ($deferredCancellation->isCancelled()) {
                    return;
                }

                try {
                    /** @var PsrResponse $response */
                    $response = $future->await();

                    // Prevent destruction of the DeferredCancellation until the response body is destroyed.
                    $this->deferredCancellations[$response->getBody()] = $deferredCancellation;

                    $promise->resolve($response);
                } catch (CancelledException $e) {
                    if (!$cancellation->isRequested()) {
                        $promise->reject($e);
                    }
                } catch (\Throwable $e) {
                    $promise->reject($e);
                }
            },
            $deferredCancellation->cancel(...),
        );

        return $promise;
    }

    private function getClient(AmpRequest $request, array $options): DelegateHttpClient
    {
        $client = $this->client;

        if (isset($options[RequestOptions::CERT])
            || isset($options[RequestOptions::PROXY])
            || (isset($options[RequestOptions::VERIFY]) && $options[RequestOptions::VERIFY] !== true)
            || isset($options[RequestOptions::FORCE_IP_RESOLVE])
        ) {
            $cacheKey = [];
            foreach ([
                RequestOptions::FORCE_IP_RESOLVE,
                RequestOptions::VERIFY,
                RequestOptions::PROXY,
                RequestOptions::CERT,
            ] as $k) {
                $cacheKey[$k] = $options[$k] ?? null;
            }

            $cacheKey = \json_encode($cacheKey, flags: \JSON_THROW_ON_ERROR);

            if (isset($this->cachedClients[$cacheKey])) {
                return $this->cachedClients[$cacheKey];
            }

            $connectContext = (new ConnectContext())->withTlsContext(self::getTlsContext($options));

            if (isset($options[RequestOptions::FORCE_IP_RESOLVE])) {
                $connectContext->withDnsTypeRestriction(match ($options[RequestOptions::FORCE_IP_RESOLVE]) {
                    'v4' => DnsRecord::A,
                    'v6' => DnsRecord::AAAA,
                    default => throw new \ValueError(\sprintf(
                        'Invalid value for request option "%s": %s',
                        RequestOptions::FORCE_IP_RESOLVE,
                        $options[RequestOptions::FORCE_IP_RESOLVE],
                    )),
                });
            }

            $client = $this->cachedClients[$cacheKey] = createHttpClientBuilder()
                ->usingPool(new UnlimitedConnectionPool(new DefaultConnectionFactory(
                    connector: self::getConnector($request, $options),
                    connectContext: $connectContext,
                )))->build();
        }

        return $client;
    }

    private static function getPsrAdapter(): PsrAdapter
    {
        return self::$psrAdapter ??= new PsrAdapter(
            new class implements PsrRequestFactory {
                public function createRequest(string $method, $uri): GuzzleRequest
                {
                    return new GuzzleRequest($method, $uri);
                }
            },
            new class implements PsrResponseFactory {
                public function createResponse(int $code = 200, string $reasonPhrase = ''): GuzzleResponse
                {
                    return new GuzzleResponse($code, reason: $reasonPhrase);
                }
            },
        );
    }

    private static function getConnector(AmpRequest $request, array $options): ?SocketConnector
    {
        if (!isset($options[RequestOptions::PROXY])) {
            return null;
        }

        $proxy = null;

        if (!\is_array($options[RequestOptions::PROXY])) {
            $proxy = $options[RequestOptions::PROXY];
        } else {
            $scheme = $request->getUri()->getScheme();
            if (isset($options[RequestOptions::PROXY][$scheme])) {
                $host = $request->getUri()->getHost();
                if (!isset($options[RequestOptions::PROXY]['no'])
                    || !Utils::isHostInNoProxy($host, $options[RequestOptions::PROXY]['no'])
                ) {
                    $proxy = $options[RequestOptions::PROXY][$scheme];
                }
            }
        }

        if ($proxy === null) {
            return null;
        }

        if (!\class_exists(Https1TunnelConnector::class)) {
            throw new AssertionError("Please require amphp/http-tunnel to use the proxy option!");
        }

        $uri = new GuzzleUri($proxy);

        $scheme = $uri->getScheme();
        $userInfo = \urldecode($uri->getUserInfo());
        if ($scheme === 'socks5') {
            $user = null;
            $password = null;
            if ($userInfo !== '') {
                [$user, $password] = \explode(':', $userInfo, 2) + [null, null];
            }
            return new Socks5SocketConnector($uri->getHost() . ':' . $uri->getPort(), $user, $password);
        }

        $headers = [];
        if ($userInfo !== '') {
            $headers = ['Proxy-Authorization' => 'Basic '.\base64_encode($userInfo)];
        }

        return match ($scheme) {
            'http' => new Http1TunnelConnector($uri->getHost() . ':' . $uri->getPort(), $headers),
            'https' => new Https1TunnelConnector(
                $uri->getHost() . ':' . $uri->getPort(),
                new ClientTlsContext($uri->getHost()),
                $headers
            ),
            default => throw new \ValueError('Unsupported protocol in proxy option: ' . $scheme),
        };
    }

    private static function getTlsContext(array $options): ?ClientTlsContext
    {
        $tlsContext = null;

        if (isset($options[RequestOptions::CERT])) {
            $tlsContext = new ClientTlsContext();
            if (\is_string($options[RequestOptions::CERT])) {
                $tlsContext = $tlsContext->withCertificate(new Certificate(
                    $options[RequestOptions::CERT],
                    $options[RequestOptions::SSL_KEY] ?? null,
                ));
            } else {
                $tlsContext = $tlsContext->withCertificate(new Certificate(
                    $options[RequestOptions::CERT][0],
                    $options[RequestOptions::SSL_KEY] ?? null,
                    $options[RequestOptions::CERT][1],
                ));
            }
        }

        if (isset($options[RequestOptions::VERIFY])) {
            $tlsContext ??= new ClientTlsContext();
            if ($options[RequestOptions::VERIFY] === false) {
                $tlsContext = $tlsContext->withoutPeerVerification();
            } elseif (\is_string($options[RequestOptions::VERIFY])) {
                $tlsContext = $tlsContext->withCaFile($options[RequestOptions::VERIFY]);
            }
        }

        return $tlsContext;
    }

    private static function pipeResponseToFile(Response $response, string $filename, Cancellation $cancellation): File
    {
        if (!\interface_exists(File::class)) {
            throw new AssertionError("Please require amphp/file to use the sink option!");
        }

        $file = openFile($filename, 'w');
        pipe($response->getBody(), $file, $cancellation);
        $file->seek(0);

        return $file;
    }
}
