<?php declare(strict_types=1);

use Amp\Http\Client\Psr7\AmpHandler;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;

use function Amp\async;
use function Amp\ByteStream\getStdout;

require __DIR__ . '/../vendor/autoload.php';

$client = new Client(['handler' => HandlerStack::create(new AmpHandler)]);

$future = async($client->get(...), 'https://api.github.com/', ['delay' => 1000]);

getStdout()->write("First output: ".$client->get('https://api.github.com/')->getBody().PHP_EOL);

getStdout()->write("Deferred output: ".$future->await()->getBody().PHP_EOL);
