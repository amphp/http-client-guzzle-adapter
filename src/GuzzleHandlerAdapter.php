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
use Amp\Http\Client\Request as AmpRequest;
use Amp\Http\Tunnel\Http1TunnelConnector;
use Amp\Http\Tunnel\Https1TunnelConnector;
use Amp\Http\Tunnel\Socks5TunnelConnector;
use Amp\Socket\Certificate;
use Amp\Socket\ClientTlsContext;
use Amp\Socket\ConnectContext;
use Amp\Socket\SocketConnector;
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

    private readonly HttpClient $client;

    /** @var array<string, HttpClient> */
    private array $cachedClients = [];

    public function __construct(?HttpClient $client = null)
    {
        if (!\interface_exists(PromiseInterface::class)) {
            throw new AssertionError("Please require guzzlehttp/guzzle to use the Guzzle adapter!");
        }

        $this->client = $client ?? (new HttpClientBuilder())->followRedirects(0)->build();
    }

    public function __invoke(PsrRequest $request, array $options): PromiseInterface
    {
        if (isset($options['curl'])) {
            throw new AssertionError("Cannot provide curl options when using AMP backend!");
        }

        $deferredCancellation = new DeferredCancellation();
        $cancellation = $deferredCancellation->getCancellation();
        $future = async(function () use ($request, $options, $cancellation): PsrResponse {
            if (isset($options['delay'])) {
                delay($options['delay'] / 1000.0, cancellation: $cancellation);
            }

            $request = self::getPsrAdapter()->fromPsrRequest($request);
            if (isset($options[RequestOptions::TIMEOUT])) {
                $request->setTransferTimeout((float) $options[RequestOptions::TIMEOUT]);
                $request->setInactivityTimeout((float) $options[RequestOptions::TIMEOUT]);
            }
            if (isset($options[RequestOptions::CONNECT_TIMEOUT])) {
                $request->setTcpConnectTimeout((float) $options[RequestOptions::CONNECT_TIMEOUT]);
            }

            $client = $this->getClient($request, $options);

            if (isset($options['amp']['protocols'])) {
                $request->setProtocolVersions($options['amp']['protocols']);
            }

            $response = $client->request($request, $cancellation);

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

            return self::getPsrAdapter()->toPsrResponse($response);
        });

        $future->ignore();

        /** @psalm-suppress UndefinedVariable Using $promise reference in definition expression. */
        $promise = new Promise(static function () use (&$promise, $future, $cancellation): void {
            try {
                $promise->resolve($future->await());
            } catch (CancelledException $e) {
                if (!$cancellation->isRequested()) {
                    $promise->reject($e);
                }
            } catch (\Throwable $e) {
                $promise->reject($e);
            }
        }, $deferredCancellation->cancel(...));

        return $promise;
    }

    private function getClient(AmpRequest $request, array $options): HttpClient
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

            $client = $this->cachedClients[$cacheKey] = (new HttpClientBuilder())
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

        $uri = new GuzzleUri($proxy);

        $scheme = $uri->getScheme();
        $userInfo = \urldecode($uri->getUserInfo());
        if ($scheme === 'socks5') {
            $user = null;
            $password = null;
            if ($userInfo !== '') {
                [$user, $password] = \explode(':', $userInfo, 2) + [null, null];
            }
            return new Socks5TunnelConnector($uri->getHost() . ':' . $uri->getPort(), $user, $password);
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
}
