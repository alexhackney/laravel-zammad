<?php

declare(strict_types=1);

use AlexHackney\Zammad\Facades\Zammad;
use AlexHackney\Zammad\Requests\Users\AllUsersRequest;
use AlexHackney\Zammad\Requests\Users\GetUserRequest;
use AlexHackney\Zammad\Resources\UserResource;
use AlexHackney\Zammad\Testing\FakeZammad;
use Saloon\Exceptions\NoMockResponseFoundException;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function (): void {
    FakeZammad::reset();
});

afterEach(function (): void {
    FakeZammad::reset();
});

it('installs global mocks and delegates request assertions', function (): void {
    $client = FakeZammad::fake([
        GetUserRequest::class => MockResponse::make(fakeZammadUserPayload()),
    ]);

    expect($client)->toBe(MockClient::getGlobal());
    (new UserResource)->me();

    FakeZammad::assertSent(GetUserRequest::class);
    FakeZammad::assertNotSent(AllUsersRequest::class);
    FakeZammad::assertSentCount(1);
});

it('provides the fake helper on the Zammad facade', function (): void {
    $client = Zammad::fake();

    expect($client)->toBe(MockClient::getGlobal());
});

it('replaces previous global mocks and request history on each fake call', function (bool $facade): void {
    $first = FakeZammad::fake([GetUserRequest::class => MockResponse::make(fakeZammadUserPayload())]);
    (new UserResource)->me();
    FakeZammad::assertSentCount(1);

    $mocks = [AllUsersRequest::class => MockResponse::make([])];
    $second = $facade ? Zammad::fake($mocks) : FakeZammad::fake($mocks);

    FakeZammad::assertNothingSent();
    expect((new UserResource)->list())->toBeEmpty()
        ->and($second)->toBe(MockClient::getGlobal())->not->toBe($first);
    FakeZammad::assertSent(AllUsersRequest::class);
    FakeZammad::assertNotSent(GetUserRequest::class);
    FakeZammad::assertSentCount(1);
})->with([false, true]);

it('prevents stray requests when a fake has no matching mock', function (): void {
    FakeZammad::fake();

    expect(fn () => (new UserResource)->me())
        ->toThrow(NoMockResponseFoundException::class);
});

it('can assert that no requests were sent', function (): void {
    FakeZammad::fake();

    FakeZammad::assertNothingSent();
});

/** @return array<string, mixed> */
function fakeZammadUserPayload(): array
{
    return [
        // derived from DTO fields, not a captured Zammad response
        'id' => 42,
        'firstname' => 'Ada',
        'lastname' => 'Lovelace',
        'login' => 'ada@example.test',
        'email' => 'ada@example.test',
        'last_login' => '2024-01-01T10:00:00Z',
        'updated_at' => '2024-01-01T10:00:00Z',
        'created_at' => '2023-01-01T10:00:00Z',
    ];
}
