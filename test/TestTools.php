<?php
declare(strict_types=1);

namespace Amp\Http\Client\Psr7;

use Amp\Http\Client\ApplicationInterceptor;
use Amp\Http\Client\DelegateHttpClient;
use ReflectionProperty;

final class TestTools
{
    public static function readProperty(object $object, string $property): mixed
    {
        return (new ReflectionProperty($object, $property))->getValue($object);
    }

    public static function walkHttpClientStack(DelegateHttpClient $httpClient): \Generator
    {
        while ($httpClient) {
            try {
                yield $httpClient = self::readProperty($httpClient, 'httpClient');
            } catch (\ReflectionException) {
                return;
            }
        }
    }

    public static function findApplicationInterceptor(
        DelegateHttpClient $httpClient,
        string $interceptorClass,
    ): ?ApplicationInterceptor {
        foreach (self::walkHttpClientStack($httpClient) as $httpClient) {
            try {
                $interceptor = self::readProperty($httpClient, 'interceptor');
            } catch (\ReflectionException) {
                return null;
            }

            if ($interceptor instanceof $interceptorClass) {
                return $interceptor;
            }
        }

        return null;
    }
}
