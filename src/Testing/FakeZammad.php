<?php

declare(strict_types=1);

namespace AlexHackney\Zammad\Testing;

use Saloon\Config;
use Saloon\Http\Faking\MockClient;

final class FakeZammad
{
    /**
     * Install Saloon's global mock client and prevent requests without a mock.
     * Each call replaces the previous global client, mocks and request history.
     *
     * @param  array<int|string, mixed>  $mocks
     */
    public static function fake(array $mocks = []): MockClient
    {
        MockClient::destroyGlobal();
        $client = MockClient::global($mocks);
        Config::preventStrayRequests();

        return $client;
    }

    /**
     * Assert that the mock client sent a request matching the class or callback.
     */
    public static function assertSent(string|callable $request): void
    {
        self::client()->assertSent($request);
    }

    /**
     * Assert that the mock client did not send a request matching the class or callback.
     */
    public static function assertNotSent(string|callable $request): void
    {
        self::client()->assertNotSent($request);
    }

    /**
     * Assert that the mock client sent no requests.
     */
    public static function assertNothingSent(): void
    {
        self::client()->assertNothingSent();
    }

    /**
     * Assert the total number of requests sent by the mock client.
     */
    public static function assertSentCount(int $count): void
    {
        self::client()->assertSentCount($count);
    }

    /**
     * Remove Saloon's global mock client.
     */
    public static function reset(): void
    {
        MockClient::destroyGlobal();
    }

    private static function client(): MockClient
    {
        return MockClient::getGlobal() ?? throw new \LogicException('Call FakeZammad::fake() before making fake assertions.');
    }
}
