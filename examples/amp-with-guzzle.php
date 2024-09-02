<?php declare(strict_types=1);

use Amp\Http\Client\GuzzleAdapter\GuzzleHandlerAdapter;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use function Amp\async;

require __DIR__ . '/../vendor/autoload.php';

$client = new Client(['handler' => HandlerStack::create(new GuzzleHandlerAdapter())]);

$future = async(fn () => $client->get('https://api.github.com/', ['delay' => 1000]));

$response = $client->get('https://api.github.com/');

echo "First output: " . $response->getBody() . PHP_EOL;

$response = $future->await();

echo "Deferred output: " . $response->getBody() . PHP_EOL;
