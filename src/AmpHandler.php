<?php declare(strict_types=1);

namespace Amp\Http\Client\Psr7;

use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\Dns\DnsRecord;
use Amp\File\File;
use Amp\Http\Client\Connection\DefaultConnectionFactory;
use Amp\Http\Client\Connection\UnlimitedConnectionPool;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Tunnel\Http1TunnelConnector;
use Amp\Http\Tunnel\Https1TunnelConnector;
use Amp\Http\Tunnel\Socks5TunnelConnector;
use Amp\Socket\Certificate;
use Amp\Socket\ClientTlsContext;
use Amp\Socket\ConnectContext;
use AssertionError;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\Utils;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

use function Amp\async;
use function Amp\ByteStream\pipe;
use function Amp\delay;
use function Amp\File\openFile;

/**
 * Handler for guzzle which uses amphp/http-client.
 */
final class AmpHandler
{
    private static ?PsrAdapter $psrAdapter;
    private readonly HttpClient $client;
    /** @var array<string, HttpClient> */
    private array $cachedClients = [];
    public function __construct(?HttpClient $client = null)
    {
        if (!\interface_exists(PromiseInterface::class)) {
            throw new AssertionError("Please require guzzle to use the guzzle AmpHandler!");
        }
        $this->client = $client ?? (
            (new HttpClientBuilder)
            ->followRedirects(0)
            ->build()
        );
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
        if (isset($options['curl'])) {
            //throw new AssertionError("Cannot provide curl options when using AMP backend!");
        }
        $deferred = new DeferredCancellation;
        $cancellation = $deferred->getCancellation();
        $future = async(function () use ($request, $options, $cancellation) {
            if (isset($options['delay'])) {
                delay($options['delay'] / 1000.0, cancellation: $cancellation);
            }
            /** @psalm-suppress PossiblyNullReference Initialized in the constructor */
            $request = self::$psrAdapter->fromPsrRequest($request);
            if (isset($options[RequestOptions::TIMEOUT])) {
                $request->setTransferTimeout((float) $options[RequestOptions::TIMEOUT]);
                $request->setInactivityTimeout((float) $options[RequestOptions::TIMEOUT]);
            }
            if (isset($options[RequestOptions::CONNECT_TIMEOUT])) {
                $request->setTcpConnectTimeout((float) $options[RequestOptions::CONNECT_TIMEOUT]);
            }

            $client = $this->client;
            if (isset($options[RequestOptions::CERT]) ||
                isset($options[RequestOptions::PROXY]) || (
                    isset($options[RequestOptions::VERIFY])
                    && $options[RequestOptions::VERIFY] !== true
                ) ||
                isset($options[RequestOptions::FORCE_IP_RESOLVE])
            ) {
                $cacheKey = [];
                foreach ([RequestOptions::FORCE_IP_RESOLVE, RequestOptions::VERIFY, RequestOptions::PROXY, RequestOptions::CERT] as $k) {
                    $cacheKey[$k] = $options[$k] ?? null;
                }
                $cacheKey = json_encode($cacheKey);
                if (isset($this->cachedClients[$cacheKey])) {
                    $client = $this->cachedClients[$cacheKey];
                } else {
                    $tlsContext = null;
                    if (isset($options[RequestOptions::CERT])) {
                        $tlsContext ??= new ClientTlsContext();
                        if (\is_string($options[RequestOptions::CERT])) {
                            $tlsContext = $tlsContext->withCertificate(new Certificate(
                                $options[RequestOptions::CERT],
                                $options[RequestOptions::SSL_KEY] ?? null,
                            ));
                        } else {
                            $tlsContext = $tlsContext->withCertificate(new Certificate(
                                $options[RequestOptions::CERT][0],
                                $options[RequestOptions::SSL_KEY] ?? null,
                                $options[RequestOptions::CERT][1]
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

                    $connector = null;
                    if (isset($options[RequestOptions::PROXY])) {
                        if (!\is_array($options['proxy'])) {
                            $connector = $options['proxy'];
                        } else {
                            $scheme = $request->getUri()->getScheme();
                            if (isset($options['proxy'][$scheme])) {
                                $host = $request->getUri()->getHost();
                                if (!isset($options['proxy']['no']) || !Utils::isHostInNoProxy($host, $options['proxy']['no'])) {
                                    $connector = $options['proxy'][$scheme];
                                }
                            }
                        }

                        if ($connector !== null) {
                            $connector = new Uri($connector);
                            $connector = match ($connector->getScheme()) {
                                'http' => new Http1TunnelConnector($connector->getHost().':'.$connector->getPort()),
                                'https' => new Https1TunnelConnector($connector->getHost().':'.$connector->getPort(), new ClientTlsContext($connector->getHost())),
                                'socks5' => new Socks5TunnelConnector($connector->getHost().':'.$connector->getPort())
                            };
                        }
                    }

                    $connectContext = new ConnectContext;
                    if ($tlsContext) {
                        $connectContext = $connectContext->withTlsContext($tlsContext);
                    }
                    if (isset($options[RequestOptions::FORCE_IP_RESOLVE])) {
                        $connectContext->withDnsTypeRestriction(match ($options[RequestOptions::FORCE_IP_RESOLVE]) {
                            'v4' => DnsRecord::A,
                            'v6' => DnsRecord::AAAA,
                        });
                    }

                    $client = (new HttpClientBuilder)
                        ->usingPool(new UnlimitedConnectionPool(new DefaultConnectionFactory(connector: $connector, connectContext: $connectContext)))
                        ->build();

                    $this->cachedClients[$cacheKey] = $client;
                }
            }
            if (isset($options['amp']['protocols'])) {
                $request->setProtocolVersions($options['amp']['protocols']);
            }
            $response = $client->request(
                $request,
                $cancellation
            );
            if (isset($options[RequestOptions::SINK])) {
                if (!\is_string($options[RequestOptions::SINK])) {
                    throw new AssertionError("Only a file name can be provided as sink!");
                }
                if (!\interface_exists(File::class)) {
                    throw new AssertionError("Please require amphp/file to use the sink option!");
                }
                $f = openFile($options[RequestOptions::SINK], 'w');
                pipe($response->getBody(), $f, $cancellation);
            }
            return self::$psrAdapter->toPsrResponse($response);
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
