<?php declare(strict_types=1);

namespace Amp\Http\Client\GuzzleAdapter;

use Amp\Http\Client\DelegateHttpClient;
use Amp\Http\Client\Interceptor\FollowRedirects;
use Amp\Http\Client\Interceptor\RetryRequests;
use Amp\Http\Client\Request as AmpRequest;
use Amp\Http\Client\Response as AmpResponse;
use Amp\PHPUnit\AsyncTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RedirectMiddleware;
use GuzzleHttp\RequestOptions;
use function Amp\async;
use function Amp\delay;

/**
 * @covers \Amp\Http\Client\Psr7\GuzzleHandlerAdapter
 */
class GuzzleHandlerAdapterTest extends AsyncTestCase
{
    public function testRequest(): void
    {
        $client = new Client(['handler' => HandlerStack::create(new GuzzleHandlerAdapter())]);
        $this->assertNotEmpty((string) $client->get('https://example.com/')->getBody());
    }

    public function testRequestDelay(): void
    {
        $client = new Client(['handler' => HandlerStack::create(new GuzzleHandlerAdapter())]);
        $future = async($client->get(...), 'https://example.com/', ['delay' => 1000]);
        $this->assertFalse($future->isComplete());
        delay(1);
        $t = \microtime(true);
        $this->assertNotEmpty((string) $future->await()->getBody());
        $this->assertTrue(\microtime(true)-$t < 1);
    }

    /* leproxy/leproxy is out of date and was preventing upgraing psr/http-message, so skipping this for now.
    public function testRequestProxies(): void
    {
        $proxy = new LeProxyServer(Loop::get());
        $socket = $proxy->listen('127.0.0.1:0', false);

        $client = new Client(['handler' => HandlerStack::create(new GuzzleHandlerAdapter())]);
        foreach (['socks5://', 'http://'] as $scheme) {
            $uri = \str_replace('tcp://', $scheme, $socket->getAddress());

            $result = $client->get('https://example.com/', [RequestOptions::PROXY => [
                'https' => $uri
            ]]);
            $this->assertStringContainsString('Example Domain', (string) $result->getBody());
        }
    }
    */

    public function testRequestDelayGuzzleAsync(): void
    {
        $client = new Client(['handler' => HandlerStack::create(new GuzzleHandlerAdapter())]);
        $promise = $client->getAsync('https://example.com/', ['delay' => 1000]);
        $this->assertEquals($promise->getState(), PromiseInterface::PENDING);
        delay(1);
        $t = \microtime(true);
        $this->assertNotEmpty((string) $promise->wait()->getBody());
        $this->assertTrue(\microtime(true)-$t < 1);
    }

    public function testRequestCancel(): void
    {
        $client = new Client(['handler' => HandlerStack::create(new GuzzleHandlerAdapter())]);
        $promise = $client->getAsync('https://example.com/', ['delay' => 2000]);
        $promise->cancel();
        $this->assertEquals($promise->getState(), PromiseInterface::REJECTED);
    }

    public function testRequest404(): void
    {
        $this->expectExceptionMessageMatches('/404 Not Found/');
        $client = new Client(['handler' => HandlerStack::create(new GuzzleHandlerAdapter())]);
        $client->get('https://example.com/test');
    }

    /**
     * Tests that when creating a new GuzzleHandlerAdapter, the default application interceptors and their settings
     * match those of Guzzle 7: like Guzzle's own handlers, the client leaves redirects to Guzzle's RedirectMiddleware.
     */
    public function testApplicationInterceptorDefaults(): void
    {
        $adapter = new GuzzleHandlerAdapter();
        $client = TestTools::readProperty($adapter, 'client');

        self::assertNull(
            TestTools::findApplicationInterceptor($client, FollowRedirects::class),
            'Redirect interceptor is not present.',
        );

        foreach (TestTools::walkHttpClientStack($client) as $httpClient) {
            self::assertNull(
                TestTools::findApplicationInterceptor($httpClient, RetryRequests::class),
                'Retry interceptor is not present.',
            );
        }
    }

    /**
     * Tests that when creating a new GuzzleHandlerAdapter and sending an HTTP request, the default request settings
     * match those of Guzzle 7.
     */
    public function testRequestDefaults(): void
    {
        $promise = (new GuzzleHandlerAdapter($client = $this->createMock(DelegateHttpClient::class)))
            (new Request('GET', 'https://example.com'), []);

        $client->method('request')->willReturnCallback(
            function (AmpRequest $request): never {
                self::assertSame(0., $request->getTransferTimeout());
                self::assertSame(0., $request->getInactivityTimeout());
                self::assertSame(60., $request->getTcpConnectTimeout());

                throw new \LogicException('OK');
            },
        );

        $this->expectExceptionMessageMatches('[^OK$]');
        $promise->wait();
    }

    /**
     * Tests that the client built for a request with its own connection options does not follow redirects either.
     */
    public function testRequestSpecificClientDoesNotFollowRedirects(): void
    {
        $adapter = new GuzzleHandlerAdapter();
        $getClient = (fn (AmpRequest $request, array $options) => $this->getClient($request, $options))
            ->bindTo($adapter, GuzzleHandlerAdapter::class);

        $client = $getClient(new AmpRequest('https://example.com/'), [RequestOptions::VERIFY => false]);

        self::assertNotSame(TestTools::readProperty($adapter, 'client'), $client);
        self::assertNull(
            TestTools::findApplicationInterceptor($client, FollowRedirects::class),
            'Redirect interceptor is not present.',
        );
    }

    public function testRedirectFollowedByGuzzle(): void
    {
        $requestedUris = [];
        $client = $this->createRedirectingGuzzleClient($requestedUris);

        $response = $client->get('https://example.com/redirect', [
            RequestOptions::ALLOW_REDIRECTS => ['track_redirects' => true],
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['https://example.com/target'], $response->getHeader(RedirectMiddleware::HISTORY_HEADER));
        self::assertSame(['https://example.com/redirect', 'https://example.com/target'], $requestedUris);
    }

    public function testRedirectNotFollowedWhenDisallowed(): void
    {
        $requestedUris = [];
        $client = $this->createRedirectingGuzzleClient($requestedUris);

        $response = $client->get('https://example.com/redirect', [RequestOptions::ALLOW_REDIRECTS => false]);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame(['https://example.com/target'], $response->getHeader('location'));
        self::assertSame(['https://example.com/redirect'], $requestedUris);
    }

    /**
     * @param list<string> $requestedUris
     */
    private function createRedirectingGuzzleClient(array &$requestedUris): Client
    {
        $httpClient = $this->createMock(DelegateHttpClient::class);
        $httpClient->method('request')->willReturnCallback(
            function (AmpRequest $request) use (&$requestedUris): AmpResponse {
                $uri = (string) $request->getUri();
                $requestedUris[] = $uri;

                return $uri === 'https://example.com/redirect'
                    ? new AmpResponse('1.1', 302, 'Found', ['location' => 'https://example.com/target'], '', $request)
                    : new AmpResponse('1.1', 200, 'OK', [], 'target', $request);
            },
        );

        return new Client(['handler' => HandlerStack::create(new GuzzleHandlerAdapter($httpClient))]);
    }
}
