<?php declare(strict_types=1);

namespace Amp\Http\Client\Psr7;

use Amp\PHPUnit\AsyncTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\PromiseInterface;

use function Amp\async;
use function Amp\delay;

/**
 * @covers \Amp\Http\Client\Psr7\AmpHandler
 */
class GuzzleAdapterTest extends AsyncTestCase
{
    public function testRequest(): void
    {
        $client = new Client(['handler' => HandlerStack::create(new AmpHandler)]);
        $this->assertNotEmpty((string) $client->get('https://example.com/')->getBody());
    }
    public function testRequestDelay(): void
    {
        $client = new Client(['handler' => HandlerStack::create(new AmpHandler)]);
        $future = async($client->get(...), 'https://example.com/', ['delay' => 1000]);
        $this->assertFalse($future->isComplete());
        delay(1);
        $t = \microtime(true);
        $this->assertNotEmpty((string) $future->await()->getBody());
        $this->assertTrue(\microtime(true)-$t < 1);
    }
    public function testRequestDelayGuzzleAsync(): void
    {
        $client = new Client(['handler' => HandlerStack::create(new AmpHandler)]);
        $promise = $client->getAsync('https://example.com/', ['delay' => 1000]);
        $this->assertEquals($promise->getState(), PromiseInterface::PENDING);
        delay(1);
        $t = \microtime(true);
        $this->assertNotEmpty((string) $promise->wait()->getBody());
        $this->assertTrue(\microtime(true)-$t < 1);
    }
    public function testRequestCancel(): void
    {
        $client = new Client(['handler' => HandlerStack::create(new AmpHandler)]);
        $promise = $client->getAsync('https://example.com/', ['delay' => 2000]);
        $promise->cancel();
        $this->assertEquals($promise->getState(), PromiseInterface::REJECTED);
    }
    public function testRequest404(): void
    {
        $this->expectExceptionMessageMatches('/404 Not Found/');
        $client = new Client(['handler' => HandlerStack::create(new AmpHandler)]);
        $client->get('https://example.com/test');
    }
}
