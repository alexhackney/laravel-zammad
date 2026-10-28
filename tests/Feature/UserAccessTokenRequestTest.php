<?php

declare(strict_types=1);

namespace AlexHackney\Zammad\Tests\Feature;

use AlexHackney\Zammad\Events\ZammadResponseLog;
use AlexHackney\Zammad\Requests\AccessTokens\AllAccessTokensRequest;
use AlexHackney\Zammad\Requests\AccessTokens\CreateAccessTokenRequest;
use AlexHackney\Zammad\Requests\AccessTokens\DestroyAccessTokenRequest;
use AlexHackney\Zammad\Tests\Feature\Support\Mocks;
use AlexHackney\Zammad\Zammad;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Saloon\Enums\Method;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Request;

require_once __DIR__.'/Support/Mocks.php';

beforeEach(function () {
    config(['zammad.http_retry_delay' => 0]);
});

afterEach(function () {
    MockClient::destroyGlobal();
});

it('lists user tokens', function () {
    // derived from DTO fields, not a captured Zammad response
    $mock = MockClient::global([
        AllAccessTokensRequest::class => MockResponse::make([
            'tokens' => [Mocks::accessToken()],
        ], 200),
    ]);

    $tokens = (new Zammad)->userAccessToken()->list();

    $this->assertSame(['tokens' => [['id' => 7, 'name' => 'test-access-token']]], $tokens);
    $mock->assertSent(fn (Request $request): bool => $request instanceof AllAccessTokensRequest
        && $request->getMethod() === Method::GET
        && $request->resolveEndpoint() === '/user_access_token'
        && $request->query()->all() === []);
    Event::assertDispatched(ZammadResponseLog::class, 1);
})->group('users');

it('creates a user token', function () {
    $data = [
        'name' => Str::orderedUuid()->toString(),
        'permission' => ['admin'],
        'expires_at' => now()->addDay()->format('Y-m-d'),
    ];
    // derived from DTO fields, not a captured Zammad response
    $mock = MockClient::global([
        CreateAccessTokenRequest::class => MockResponse::make(
            Mocks::accessToken(['name' => $data['name']]),
            201,
        ),
    ]);

    $token = (new Zammad)->userAccessToken()->create($data);

    $this->assertSame(['id' => 7, 'name' => $data['name']], $token);
    $mock->assertSent(fn (Request $request): bool => $request instanceof CreateAccessTokenRequest
        && $request->getMethod() === Method::POST
        && $request->resolveEndpoint() === '/user_access_token'
        && $request->query()->all() === []
        && $request->body()->all() === $data);
    Event::assertDispatched(ZammadResponseLog::class, 1);
})->group('users');

it('deletes a user token', function () {
    // derived from DTO fields, not a captured Zammad response
    $mock = MockClient::global([
        DestroyAccessTokenRequest::class => MockResponse::make([], 200),
    ]);

    $result = (new Zammad)->userAccessToken()->delete(7);

    $this->assertSame([], $result);
    $mock->assertSent(fn (Request $request): bool => $request instanceof DestroyAccessTokenRequest
        && $request->getMethod() === Method::DELETE
        && $request->resolveEndpoint() === '/user_access_token/7'
        && $request->query()->all() === ['expand' => false]);
    Event::assertDispatched(ZammadResponseLog::class, 1);
})->group('users');

it('throws when listing user tokens fails', function () {
    // derived from DTO fields, not a captured Zammad response
    $mock = MockClient::global([
        AllAccessTokensRequest::class => MockResponse::make([], 404),
    ]);

    expect(fn () => (new Zammad)->userAccessToken()->list())
        ->toThrow(RequestException::class);

    $mock->assertSentCount(1);
    $mock->assertSent(fn (Request $request): bool => $request instanceof AllAccessTokensRequest
        && $request->getMethod() === Method::GET
        && $request->resolveEndpoint() === '/user_access_token'
        && $request->query()->all() === []);
})->group('users');

it('throws when creating a user token fails', function () {
    $payload = ['name' => 'token-name', 'permission' => ['admin']];
    // derived from DTO fields, not a captured Zammad response
    $mock = MockClient::global([
        CreateAccessTokenRequest::class => MockResponse::make([], 422),
    ]);

    expect(fn () => (new Zammad)->userAccessToken()->create($payload))
        ->toThrow(RequestException::class);

    $mock->assertSentCount(1);
    $mock->assertSent(fn (Request $request): bool => $request instanceof CreateAccessTokenRequest
        && $request->getMethod() === Method::POST
        && $request->resolveEndpoint() === '/user_access_token'
        && $request->query()->all() === []
        && $request->body()->all() === $payload);
})->group('users');

it('throws when deleting a user token fails', function () {
    // derived from DTO fields, not a captured Zammad response
    $mock = MockClient::global([
        DestroyAccessTokenRequest::class => MockResponse::make([], 404),
    ]);

    expect(fn () => (new Zammad)->userAccessToken()->delete(7))
        ->toThrow(RequestException::class);

    $mock->assertSentCount(1);
    $mock->assertSent(fn (Request $request): bool => $request instanceof DestroyAccessTokenRequest
        && $request->getMethod() === Method::DELETE
        && $request->resolveEndpoint() === '/user_access_token/7'
        && $request->query()->all() === ['expand' => false]);
})->group('users');
